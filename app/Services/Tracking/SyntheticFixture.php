<?php

namespace App\Services\Tracking;

use App\Models\Exploration;
use App\Models\ExplorationTask;
use App\Models\FetchRun;
use App\Models\MetadataVersion;
use App\Models\Target;
use App\Models\TrackingTarget;
use App\Models\Video;
use App\Support\DatabasePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyntheticFixture
{
    public function seed(int $count = 130, int $days = 3): void
    {
        DatabasePolicy::assertTestConnection(DB::connection(), app()->environment());
        if ($count < 2 || $count > 1000 || $days < 2 || $days > 28) {
            throw new \InvalidArgumentException('Fixture bounds');
        }
        $dataset = Dataset::id();
        $time = now()->toImmutable()->startOfSecond();
        $runs = [];
        $e = Exploration::create(['dataset_id' => $dataset, 'id' => (string) Str::uuid(), 'source' => 'demo', 'idempotency_key' => (string) Str::uuid(), 'preset_id' => 'en-US', 'catalog_version' => 'fixture-v1', 'catalog_hash' => str_repeat('0', 64), 't0' => $time, 'status' => 'complete', 'search_limit' => 20, 'read_limit' => 500, 'expires_at' => $time->addDays(28)]);
        for ($n = 0; $n < 16; $n++) {
            $run = FetchRun::create(['dataset_id' => $dataset, 'source' => 'demo', 'kind' => 'search', 'run_key' => (string) Str::uuid(), 'conditions' => ['fixture' => true, 'regionCode' => $n % 2 ? 'JP' : 'US'], 'status' => 'complete', 'started_at' => $time, 'expires_at' => $time->addDays(28)]);
            ExplorationTask::create(['exploration_id' => $e->id, 'task_key' => 'fixture-'.$n, 'theme_id' => 'theme-'.($n % 8), 'label_ja' => '架空テーマ'.($n % 8), 'purpose_ja' => '比較の操作確認', 'meaning_ja' => '架空の検索', 'origin' => '架空fixture', 'lens' => $n < 8 ? 'recent_views' : 'recent_new', 'conditions' => ['q' => 'synthetic'], 'position' => $n, 'status' => 'complete', 'fetch_run_id' => $run->id, 'expires_at' => $time->addDays(28)]);
            $runs[] = $run;
        }
        $observations = [];
        for ($n = 0; $n < $count; $n++) {
            $end = $days < 28 && $n % 17 === 16 ? $time->subDays(3) : $time;
            $target = Target::create(['dataset_id' => $dataset, 'source' => 'demo', 'kind' => 'video', 'external_id' => 'f'.str_pad((string) $n, 10, '0', STR_PAD_LEFT), 'last_status' => 'success', 'ownership' => $n === 5 ? 'own' : 'competitor']);
            $title = '架空'.str_pad((string) $n, 4, '0', STR_PAD_LEFT).' '.($n % 11 === 10 ? str_repeat('Long ambient 音楽の架空題名 ', 10) : '静かな作業用BGM');
            $channel = 'UC'.str_pad((string) ($n % 12), 22, '0', STR_PAD_LEFT);
            Video::create(['target_id' => $target->id, 'title' => $title, 'published_at' => $time->subDays(10 + $n % 20), 'channel_external_id' => $channel, 'duration_seconds' => 3600, 'view_count' => $n === 0 ? 520 : $n + 8, 'fetched_at' => $end, 'expires_at' => $end->addDays(28)]);
            TrackingTarget::create(['dataset_id' => $dataset, 'target_id' => $target->id, 'first_seen_at' => $end->subDays($days - 1), 'last_success_at' => $end, 'next_due_at' => $end->addDay()]);
            foreach (array_unique([$n % 16, ($n + 8) % 16]) as $r) {
                DB::table('run_items')->insert(['fetch_run_id' => $runs[$r]->id, 'target_id' => $target->id, 'status' => 'success', 'search_rank' => $n + 1]);
            }
            $version = MetadataVersion::create(['dataset_id' => $dataset, 'target_id' => $target->id, 'content_hash' => hash('sha256', $title), 'content' => ['title' => $title, 'description' => '架空説明', 'tags' => ['synthetic'], 'publishedAt' => $time->subDays(10)->toIso8601ZuluString(), 'duration' => 'PT1H'], 'fetched_at' => $end->subDays($days - 1), 'expires_at' => $end->addDays(1)]);
            for ($d = $days - 1; $d >= 0; $d--) {
                $at = $end->subDays($d);
                $views = $n === 0 ? ($d >= 2 ? 0 : ($d === 1 ? 500 : 520)) : ($n === 1 ? ($d >= 2 ? 3 : ($d === 1 ? 5 : 8)) : max(0, $n + 8 - $d));
                $observations[] = ['dataset_id' => $dataset, 'target_id' => $target->id, 'metadata_version_id' => $version->id, 'view_count' => $views, 'like_count' => null, 'comment_count' => 0, 'field_status' => '{"view_count":"returned","like_count":"not_returned","comment_count":"returned"}', 'origin' => 'synthetic', 'fetched_at' => $at->toIso8601ZuluString(), 'expires_at' => $at->addDays(28)->toIso8601ZuluString()];
            }
            if (count($observations) >= 500) {
                DB::table('observations')->insert($observations);
                $observations = [];
            }
        }
        foreach (array_chunk($observations, 500) as $chunk) {
            DB::table('observations')->insert($chunk);
        }
        Dataset::heartbeat('scheduler');
        Dataset::heartbeat('worker');
    }
}
