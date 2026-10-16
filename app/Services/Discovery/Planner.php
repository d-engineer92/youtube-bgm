<?php

namespace App\Services\Discovery;

use App\Jobs\CollectExploration;
use App\Models\Exploration;
use App\Models\ExplorationKeyword;
use App\Models\ExplorationTask;
use App\Services\Tracking\Dataset;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Planner
{
    public function __construct(private Catalog $catalog) {}

    public function start(string $preset, string $token, ?Exploration $parent = null, ?array $selection = null): Exploration
    {
        $catalog = $this->catalog->load();
        if (! isset($catalog['presets'][$preset])) {
            throw ValidationException::withMessages(['preset' => '調査プリセットが不正です。']);
        }
        if (config('youtube.mode') === 'live' && ! config('youtube.key')) {
            throw ValidationException::withMessages(['key' => 'APIキー未設定。.envにYOUTUBE_API_KEYを設定してください。架空データは明示的に切り替えて試せます。']);
        }

        return DB::transaction(function () use ($preset, $token, $parent, $selection, $catalog) {
            DB::select('select pg_advisory_xact_lock(4781101)');
            $existing = Exploration::where('source', config('youtube.mode'))->where('idempotency_key', $token)->first();
            if ($existing) {
                return $existing;
            }
            $t0 = CarbonImmutable::now('UTC')->startOfSecond();
            $run = Exploration::create(['dataset_id' => Dataset::id(), 'id' => (string) Str::uuid(), 'source' => config('youtube.mode'), 'idempotency_key' => $token, 'preset_id' => $preset, 'parent_exploration_id' => $parent?->id, 't0' => $t0, 'catalog_version' => $catalog['version'], 'catalog_hash' => $catalog['hash'], 'search_limit' => config('discovery.search_limit'), 'read_limit' => config('discovery.read_limit'), 'expires_at' => now()->addDays(config('youtube.retention_days'))]);
            $position = 0;
            if ($selection) {
                $this->task($run, $selection, $selection['lens'], $catalog, $position++);
            } else {
                foreach (['recent_views', 'recent_new'] as $lens) {
                    foreach ($catalog['themes'] as $theme) {
                        $query = $theme['queries'][$preset];
                        $this->task($run, ['theme_id' => $theme['id'], 'label_ja' => $theme['label_ja'], 'purpose_ja' => $theme['purpose_ja'], 'q' => $query['primary'], 'meaning_ja' => $query['meaning_ja'], 'origin' => '同梱カタログ（需要未検証）'], $lens, $catalog, $position++);
                    }
                }
            }
            CollectExploration::dispatch($run->id)->onConnection('database')->afterCommit();

            return $run;
        });
    }

    private function task(Exploration $run, array $s, string $lens, array $catalog, int $position): void
    {
        $preset = $catalog['presets'][$run->preset_id];
        $older = $lens === 'older_reference';
        $conditions = ['q' => $s['q'], 'order' => $lens === 'recent_new' ? 'date' : 'viewCount', 'duration' => $s['duration'] ?? 'long', 'regionCode' => $preset['region_code'], 'relevanceLanguage' => $preset['relevance_language'], 'publishedAfter' => ($older ? $run->t0->subYearsNoOverflow(5) : $run->t0->subDays(30))->toIso8601ZuluString(), 'publishedBefore' => ($older ? $run->t0->subDays(180) : $run->t0)->toIso8601ZuluString(), 'limit' => 10, 'page_limit' => 1, 'exploration_id' => $run->id];
        ExplorationTask::create(['exploration_id' => $run->id, 'task_key' => $s['theme_id'].':'.$lens, 'theme_id' => $s['theme_id'], 'label_ja' => $s['label_ja'], 'purpose_ja' => $s['purpose_ja'], 'meaning_ja' => $s['meaning_ja'], 'origin' => $s['origin'], 'lens' => $lens, 'position' => $position, 'conditions' => $conditions, 'expires_at' => $run->expires_at]);
    }

    public function deepen(Exploration $parent, ExplorationTask $task, array $data): Exploration
    {
        if ($parent->expires_at <= now() || $parent->status === 'expired') {
            throw ValidationException::withMessages(['run' => '結果期限切れ。同条件で新しく調べてください。']);
        }
        $selection = ['theme_id' => $task->theme_id, 'label_ja' => $task->label_ja, 'purpose_ja' => $task->purpose_ja, 'q' => $task->conditions['q'], 'meaning_ja' => $task->meaning_ja, 'origin' => $task->origin, 'lens' => $data['lens'], 'duration' => $data['duration'] ?? $task->conditions['duration']];
        if (isset($data['related'])) {
            $theme = $this->catalog->theme($task->theme_id);
            $related = $theme['queries'][$parent->preset_id]['related'][$data['related']] ?? null;
            if (! $related) {
                throw ValidationException::withMessages(['related' => '候補語が不正です。']);
            }
            $selection['q'] = $related['query'];
            $selection['meaning_ja'] = $related['meaning_ja'];
        }
        if (isset($data['keyword'])) {
            $keyword = ExplorationKeyword::where('source', $parent->source)->where('preset_id', $parent->preset_id)->where('status', 'approved')->findOrFail($data['keyword']);
            $selection = ['theme_id' => $keyword->parent_theme_id ?? $keyword->id, 'label_ja' => $keyword->label_ja, 'purpose_ja' => $keyword->reason_ja, 'q' => $keyword->query, 'meaning_ja' => $keyword->meaning_ja, 'origin' => $keyword->source_kind.' / 承認済み・需要未検証', 'lens' => $data['lens'], 'duration' => $data['duration'] ?? 'long'];
        }

        return $this->start($parent->preset_id, $data['token'], $parent, $selection);
    }

    public function resume(Exploration $run): void
    {
        DB::transaction(function () use ($run) {
            $run = Exploration::lockForUpdate()->findOrFail($run->id);
            if ($run->expires_at <= now() || $run->status === 'expired') {
                throw ValidationException::withMessages(['run' => '結果期限切れ。再開できません。']);
            }
            if (in_array($run->status, ['queued', 'complete'], true) || ($run->status === 'running' && $run->tasks()->where('lease_until', '>', now())->exists())) {
                return;
            }
            if ($run->stop_reason === 'run_limit') {
                throw ValidationException::withMessages(['run' => 'この探索の予算は使い切りました。未完了条件は新しい明示実行で調べてください。']);
            }
            $run->tasks()->whereIn('status', ['failed', 'partial', 'skipped'])->update(['status' => 'pending']);
            $run->update(['status' => 'queued', 'stop_reason' => null, 'stop_requested' => false, 'busy_attempts' => 0, 'finished_at' => null]);
            CollectExploration::dispatch($run->id)->onConnection('database')->afterCommit();
        });
    }
}
