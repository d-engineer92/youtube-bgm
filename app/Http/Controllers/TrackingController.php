<?php

namespace App\Http\Controllers;

use App\Models\AnalysisRevision;
use App\Models\Comparison;
use App\Models\ProductionBrief;
use App\Models\Target;
use App\Services\Tracking\Dataset;
use App\Services\Tracking\Interval;
use App\Services\Tracking\Review;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TrackingController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['theme' => ['nullable', 'string', 'max:100'], 'lens' => ['nullable', Rule::in(['recent_new', 'recent_views'])], 'status' => ['nullable', 'string', 'max:40'], 'sort' => ['nullable', Rule::in(['delta', 'newest', 'popular'])]]);
        $asOf = now()->toImmutable()->utc();
        $query = Target::inMode()->where('kind', 'video')->whereHas('tracking')->with(['video', 'tracking', 'slots']);
        if (! empty($filters['theme']) || ! empty($filters['lens'])) {
            $query->whereIn('id', function ($q) use ($filters) {
                $q->select('run_items.target_id')->from('run_items')->join('exploration_tasks', 'exploration_tasks.fetch_run_id', '=', 'run_items.fetch_run_id')->where('exploration_tasks.expires_at', '>', now());
                if (! empty($filters['theme'])) {
                    $q->where('theme_id', $filters['theme']);
                }
                if (! empty($filters['lens'])) {
                    $q->where('lens', $filters['lens']);
                }
            });
        }
        // Only compact interval summaries are retained; pages load full evidence for at most 24 subjects.
        $summary = [];
        (clone $query)->chunkById(1000, function ($targets) use (&$summary, $asOf) {
            $points = DB::table('observations')->select(['id', 'target_id', 'view_count', 'fetched_at', 'expires_at'])->whereIn('target_id', $targets->pluck('id'))->where('expires_at', '>', now())->get()->groupBy('target_id');
            foreach ($targets as $target) {
                $interval = app(Interval::class)->resolve($target, $asOf, false, $points->get($target->id, collect()));
                $summary[$target->id] = $interval + ['next_due_at' => $target->tracking?->getRawOriginal('next_due_at'), 'published' => $target->video?->published_at?->timestamp ?? 0, 'views' => $target->video?->view_count ?? 0];
            }
        });
        $filtered = collect($summary)->filter(fn ($row) => empty($filters['status']) || ($filters['status'] === 'reference' ? ! $row['eligible'] : $row['status'] === $filters['status']));
        $sort = $filters['sort'] ?? 'delta';
        $ids = $filtered->sort(function ($a, $b) use ($sort) {
            if ($sort === 'delta') {
                return [$b['eligible'], $b['eligible'] ? $b['delta_views'] : PHP_INT_MIN] <=> [$a['eligible'], $a['eligible'] ? $a['delta_views'] : PHP_INT_MIN];
            }

            return $b[$sort === 'newest' ? 'published' : 'views'] <=> $a[$sort === 'newest' ? 'published' : 'views'];
        })->keys()->all();
        $page = max(1, (int) $request->input('page', 1));
        $slice = array_slice($ids, ($page - 1) * 24, 24);
        $targets = Target::whereIn('id', $slice)->with(['video' => fn ($q) => $q->select('videos.*')->selectSub(fn ($c) => $c->from('channels')->join('targets as ct', 'ct.id', '=', 'channels.target_id')->whereColumn('ct.external_id', 'videos.channel_external_id')->where('ct.source', config('youtube.mode'))->select('channels.title')->limit(1), 'channel_title'), 'tracking'])->get()->sortBy(fn ($t) => array_search($t->id, $slice));
        $videos = new LengthAwarePaginator($targets, count($ids), 24, $page, ['path' => $request->url(), 'query' => $request->query()]);
        $heartbeats = DB::table('tracking_heartbeats')->selectRaw('name, seen_at, NULL::integer as search_calls, NULL::integer as read_calls')->unionAll(DB::table('api_days')->selectRaw("'budget' as name, NULL::timestamptz as seen_at, search_calls, read_calls")->where('source', config('youtube.mode'))->where('day', now('America/Los_Angeles')->toDateString()))->get()->keyBy('name');
        $budget = $heartbeats->pull('budget');
        $totals = DB::table('targets')->join('tracking_targets', 'tracking_targets.target_id', '=', 'targets.id')->where('targets.source', config('youtube.mode'))->selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind');
        $counts = ['total' => (int) ($totals['video'] ?? 0), 'channels' => (int) ($totals['channel'] ?? 0), 'comparable' => collect($summary)->where('eligible', true)->count(), 'reference' => collect($summary)->where('eligible', false)->count(), 'pending' => collect($summary)->whereIn('status', ['first_observation', 'no_valid_observation'])->count(), 'late' => collect($summary)->whereIn('status', ['unfulfilled_due', 'stale_latest'])->count(), 'failed' => collect($summary)->where('status', 'latest_attempt_failed')->count()];

        $themes = DB::table('exploration_tasks')->join('explorations', 'explorations.id', '=', 'exploration_tasks.exploration_id')->where('explorations.source', config('youtube.mode'))->where('exploration_tasks.expires_at', '>', now())->select('theme_id', 'label_ja')->distinct()->get();

        return view('tracking', compact('videos', 'summary', 'counts', 'heartbeats', 'asOf', 'themes', 'budget'));
    }

    public function selection(Request $request)
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:6'], 'ids.*' => ['integer', 'distinct']]);

        return response()->json(Target::inMode()->where('kind', 'video')->whereIn('id', $data['ids'])->with('video')->get()->map(fn ($t) => ['id' => $t->id, 'label' => $t->video?->title ?? '公開情報は失効／未取得']))->header('Cache-Control', 'no-store');
    }

    public function state(Request $request, int $id)
    {
        $target = Target::inMode()->findOrFail($id);
        $data = $request->validate(['state' => ['required', Rule::in(['active', 'paused'])], 'ownership' => ['nullable', Rule::in(['own', 'competitor', 'unknown'])]]);
        if (isset($data['ownership'])) {
            $target->update(['ownership' => $data['ownership']]);
        }
        unset($data['ownership']);
        $target->tracking()->update($data + ['reason' => $data['state'] === 'paused' ? 'user_paused' : null]);

        return redirect()->route('tracking');
    }

    public function export(Request $request, int $id, Review $review)
    {
        $comparison = Comparison::where('source', config('youtube.mode'))->findOrFail($id);
        $pack = $review->assertEvidence($comparison);
        $pack['comparison_id'] = $comparison->id;
        $pack['comparison_revision'] = $comparison->revision;
        if ($request->input('format') !== 'csv') {
            return response()->json($pack)->header('Cache-Control', 'no-store')->header('Content-Disposition', 'attachment; filename="tracking-review.json"');
        }

        return response()->streamDownload(function () use ($pack) {
            $f = fopen('php://output', 'w');
            $columns = json_decode(file_get_contents(config_path('schemas/tracking-review-v1.csv.json')), true, flags: JSON_THROW_ON_ERROR)['columns'];
            fputcsv($f, $columns, escape: '');
            foreach (array_merge($pack['subjects'], $pack['channels'] ?? []) as $subject) {
                $i = $subject['interval'];
                foreach ($subject['observations'] as $o) {
                    $endpoint = $o['observation_id'] === $i['end_id'];
                    $hidden = $o['hidden_subscribers'] ?? null;
                    $row = ['dataset_id' => $pack['dataset_id'], 'comparison_id' => $pack['comparison_id'], 'comparison_revision' => $pack['comparison_revision'], 'video_id' => $subject['video_id'] ?? null, 'channel_id' => $subject['channel_id'] ?? null, 'subject_kind' => $subject['subject_kind'] ?? 'video', 'source' => $pack['source'], 'origin' => $o['origin'] ?? 'manual', 'observation_id' => $o['observation_id'], 'metadata_version_id' => $o['metadata_version_id'], 'scheduled_for' => $o['scheduled_for'], 'observed_at' => $o['observed_at'], 'view_count' => $o['view_count'], 'like_count' => $o['like_count'], 'comment_count' => $o['comment_count'], 'subscriber_count' => $o['subscriber_count'], 'video_count' => $o['video_count'], 'hidden_subscribers' => $hidden === null ? null : ($hidden ? 'true' : 'false'), 'previous_observation_id' => $endpoint ? $i['start_id'] : null, 'delta_views' => $endpoint ? $i['delta_views'] : null, 'elapsed_seconds' => $endpoint ? $i['elapsed_seconds'] : null, 'growth_percent' => $endpoint ? $i['growth_percent'] : null, 'rate_reason' => $i['rate_reason'], 'comparison_status' => $i['status'], 'resolver_version' => $i['resolver_version'], 'as_of' => $pack['as_of'], 'expires_at' => $pack['expires_at'], 'requested_period_hours' => $pack['requested_period_hours'], 'field_status_json' => json_encode($o['field_status'], JSON_THROW_ON_ERROR)];
                    $row = array_map(fn ($column) => $row[$column] ?? null, $columns);
                    fputcsv($f, array_map(fn ($v) => is_string($v) && preg_match('/^[=+@\-\t\r]/', $v) ? "'".$v : $v, $row), escape: '');
                }
            }
            fclose($f);
        }, 'tracking-review.csv', ['Cache-Control' => 'no-store', 'Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function analysis(Request $request, int $id, Review $review)
    {
        $c = Comparison::where('source', config('youtube.mode'))->findOrFail($id);
        $review->assertEvidence($c);
        $data = $request->validate(['revision' => ['required', 'integer'], 'state' => ['required', Rule::in(['draft', 'held', 'adopted'])], 'facts' => ['nullable', 'string', 'max:4000'], 'hypothesis' => ['nullable', 'string', 'max:4000'], 'counterexample' => ['nullable', 'string', 'max:4000'], 'unknown' => ['nullable', 'string', 'max:4000'], 'next_check' => ['nullable', 'string', 'max:4000'], 'observed_audio' => ['nullable', 'string', 'max:2000'], 'observed_image' => ['nullable', 'string', 'max:2000'], 'observed_comments' => ['nullable', 'string', 'max:2000']]);
        $state = $data['state'];
        $revision = $data['revision'];
        unset($data['state'], $data['revision']);
        DB::transaction(function () use ($id, $revision, $state, $data) {
            $comparison = Comparison::lockForUpdate()->findOrFail($id);
            abort_unless($comparison->analysis_revision === (int) $revision, 409, '別画面で更新済みです。入力を保って再読込してください。');
            AnalysisRevision::create(['id' => (string) Str::uuid(), 'dataset_id' => $comparison->dataset_id, 'comparison_id' => $comparison->id, 'revision' => $revision + 1, 'state' => $state, 'template_version' => $comparison->template_version, 'content' => $data, 'expires_at' => $comparison->evidence_expires_at, 'created_at' => now()]);
            $comparison->update(['analysis' => $data, 'analysis_state' => $state, 'analysis_revision' => $revision + 1]);
        });

        return redirect()->route('comparisons.show', $c->id)->with('notice', '分析を途中保存しました。');
    }

    public function brief(Request $request, ?int $id = null)
    {
        $brief = $id ? ProductionBrief::where('source', config('youtube.mode'))->findOrFail($id) : null;
        $comparison = Comparison::where('source', config('youtube.mode'))->findOrFail($brief?->comparison_id ?? $request->integer('comparison'));
        Dataset::assert($comparison->dataset_id);

        $analysis = AnalysisRevision::where('comparison_id', $comparison->id)->where('revision', $brief?->analysis_revision ?? $comparison->analysis_revision)->first();

        return view('brief', compact('brief', 'comparison', 'analysis'));
    }

    public function saveBrief(Request $request, Review $review)
    {
        $data = $request->validate(['comparison' => ['required', 'integer'], 'brief_id' => ['nullable', 'integer'], 'revision' => ['required', 'integer'], 'content' => ['required', 'array:audience,use,reference,avoid,one_change,music,image,title,budget,hours,permission,validation'], 'content.*' => ['nullable', 'string', 'max:4000'], 'reflection' => ['nullable', 'array:source,period,unit,checked_at,result,next_change,own_video'], 'reflection.*' => ['nullable', 'string', 'max:4000']]);
        $c = Comparison::where('source', config('youtube.mode'))->findOrFail($data['comparison']);
        Dataset::assert($c->dataset_id);

        return DB::transaction(function () use ($data, $c) {
            if (! empty($data['brief_id'])) {
                $brief = ProductionBrief::where('source', $c->source)->where('comparison_id', $c->id)->lockForUpdate()->findOrFail($data['brief_id']);
                abort_unless($brief->revision === (int) $data['revision'], 409);
                $brief->update(['content' => $data['content'], 'reflection' => $data['reflection'] ?? null, 'revision' => $brief->revision + 1]);
            } else {
                abort_unless($c->analysis_revision > 0, 422, '先に仮説または未確認を途中保存してください。');
                $brief = ProductionBrief::create(['dataset_id' => $c->dataset_id, 'source' => $c->source, 'comparison_id' => $c->id, 'analysis_revision' => $c->analysis_revision, 'content' => $data['content'], 'reflection' => $data['reflection'] ?? null]);
            }

            return redirect()->route('briefs.show', $brief->id)->with('notice', '試作下書きを保存しました。');
        });
    }
}
