<?php

namespace Tests\Feature;

use App\Jobs\CollectExploration;
use App\Models\Exploration;
use App\Models\ExplorationKeyword;
use App\Models\FetchRun;
use App\Models\Observation;
use App\Models\Target;
use App\Models\Video;
use App\Services\Discovery\Catalog;
use App\Services\Discovery\KeywordImport;
use App\Services\Discovery\Planner;
use App\Services\Discovery\Runner;
use App\Services\YouTube\ApiCaller;
use App\Services\YouTube\ApiFailure;
use App\Services\YouTube\Collector;
use App\Services\YouTube\DemoGateway;
use App\Services\YouTube\Gateway;
use App\Support\Presentation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RecordingGateway;
use Tests\TestCase;

class ExplorationTest extends TestCase
{
    use RefreshDatabase;

    private RecordingGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-14T00:12:34Z'));
        config(['youtube.mode' => 'demo', 'youtube.retries' => 0]);
        $this->gateway = new RecordingGateway;
        $this->app->instance(Gateway::class, $this->gateway);
    }

    private function start(string $preset = 'en-US'): Exploration
    {
        return app(Planner::class)->start($preset, (string) Str::uuid());
    }

    private function drain(Exploration $run): void
    {
        for ($i = 0; $i < 20; $i++) {
            $next = app(Runner::class)->step($run->id);
            if ($next === null) {
                return;
            }
            $this->assertSame(0, $next);
        }
        $this->fail('Runner did not finish within the planned scope');
    }

    private function proposal(array $changes = []): array
    {
        return array_replace_recursive(['schema_version' => '1.0.0', 'prompt_id' => 'keyword-proposal-v1', 'source_kind' => 'codex_proposal', 'preset_id' => 'en-US', 'demand_status' => 'unverified', 'candidates' => [['id' => '7d8925aa-7db6-4ea6-b73d-74e0e1015431', 'parent_theme_id' => null, 'label_ja' => '新しい切り口', 'query' => 'instrumental music for deep work', 'meaning_ja' => '深い作業の音楽', 'reason_ja' => '場面を絞って比較する']]], $changes);
    }

    public function test_http_start_is_async_and_double_click_uses_one_saved_plan(): void
    {
        $token = (string) Str::uuid();
        $data = ['preset' => 'en-US', 'token' => $token];
        $this->post('/explorations', $data)->assertRedirect();
        $this->post('/explorations', $data)->assertRedirect();
        $this->assertSame(1, Exploration::count());
        $this->assertSame(16, Exploration::first()->tasks()->count());
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertCount(0, $this->gateway->calls);
        $this->assertSame(0, Target::count());
        $run = Exploration::first();
        $this->get('/explorations/'.$run->id)->assertOk()->assertSee('ワーカーが未稼働')->assertSee('US')->assertSee('en');
        $this->get('/explorations/'.$run->id.'/progress')->assertOk()->assertJsonPath('status', 'queued');
        $this->assertCount(0, $this->gateway->calls);
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_preset_utc_bounds_catalog_and_single_page_are_fixed(): void
    {
        $run = $this->start('ja-JP');
        $tasks = $run->tasks()->get();
        $this->assertSame(array_column(app(Catalog::class)->load()['themes'], 'id'), $tasks->take(8)->pluck('theme_id')->all());
        $this->assertSame(['recent_views'], $tasks->take(8)->pluck('lens')->unique()->all());
        $params = $tasks->first()->conditions;
        $this->assertSame('2026-09-14T00:12:34Z', $params['publishedAfter']);
        $this->assertSame('2026-10-14T00:12:34Z', $params['publishedBefore']);
        $this->assertSame('long', $params['duration']);
        $this->gateway->intercept = fn ($method, $p) => $method === 'search' ? ['items' => [['id' => ['videoId' => 'demoVideo01']]], 'nextPageToken' => 'do-not-follow', 'regionCode' => 'US'] : (new DemoGateway)->fetch($method, $p);
        $this->drain($run);
        $searches = array_values(array_filter($this->gateway->calls, fn ($c) => $c[0] === 'search'));
        $this->assertCount(16, $searches);
        foreach ($searches as [, $p]) {
            $this->assertSame('JP', $p['regionCode']);
            $this->assertSame('ja', $p['relevanceLanguage']);
            $this->assertSame(10, $p['maxResults']);
            $this->assertArrayNotHasKey('pageToken', $p);
            $this->assertSame('2026-10-14T00:12:34Z', $p['publishedBefore']);
        }
        $this->assertSame('complete', $run->fresh()->status);
        $this->assertSame(1, Video::count());
        $this->get('/explorations/'.$run->id)->assertOk()->assertSee('要求地域と不一致')->assertSee('追加ページあり')->assertSee('異なる動画 1本')->assertSee('重複ヒット 15件')->assertSee('全検索経路');
    }

    public function test_details_resume_same_fetch_run_without_search_or_successful_ids(): void
    {
        $run = $this->start();
        $this->gateway->intercept = fn ($m, $p) => $m === 'channels' ? throw new ApiFailure('access_denied') : (new DemoGateway)->fetch($m, $p);
        app(Runner::class)->step($run->id);
        $task = $run->tasks()->first();
        $fetchId = $task->fetch_run_id;
        $this->assertSame('paused', $run->fresh()->status);
        $this->assertSame(1, $task->fetchRun->pages);
        $successes = Observation::count();
        $this->gateway->calls = [];
        $this->gateway->intercept = null;
        $this->travel(25)->hours();
        app(Planner::class)->resume($run->fresh());
        app(Runner::class)->step($run->id);
        $this->assertSame($fetchId, $task->fresh()->fetch_run_id);
        $this->assertSame(['channels'], array_column($this->gateway->calls, 0));
        $this->assertSame($successes + 2, Observation::count());
        $this->assertSame('2026-10-14T00:12:34Z', $task->fresh()->conditions['publishedBefore']);
        $this->assertSame(1, $run->fresh()->search_calls);
    }

    public function test_daily_budget_is_shared_with_manual_search_deepening_and_retry(): void
    {
        config(['youtube.daily_search_calls' => 2]);
        app(Collector::class)->search(['q' => 'manual', 'order' => 'date', 'duration' => 'any', 'limit' => 20]);
        $run = $this->start();
        $this->drain($run);
        $this->assertSame('daily_limit', $run->fresh()->stop_reason);
        $this->assertSame(2, DB::table('api_days')->value('search_calls'));
        $task = $run->tasks()->first();
        $child = app(Planner::class)->deepen($run, $task, ['token' => (string) Str::uuid(), 'lens' => 'older_reference']);
        app(Runner::class)->step($child->id);
        $this->assertSame('daily_limit', $child->fresh()->stop_reason);
        $this->assertSame(2, count(array_filter($this->gateway->calls, fn ($c) => $c[0] === 'search')));
        $this->travel(24)->hours();
        app(Planner::class)->resume($run->fresh());
        $this->drain($run);
        $this->assertSame(2, DB::table('api_days')->orderByDesc('day')->value('search_calls'));
    }

    public function test_run_budget_survives_collector_reset_and_cannot_be_reset_by_resume(): void
    {
        config(['discovery.search_limit' => 2]);
        $run = $this->start();
        $this->drain($run);
        $this->assertSame(2, $run->fresh()->search_calls);
        $this->assertSame('run_limit', $run->fresh()->stop_reason);
        $this->post('/explorations/'.$run->id.'/resume')->assertSessionHasErrors('run');
        $this->assertSame(2, $run->fresh()->search_calls);
        $this->assertSame(2, FetchRun::where('pages', 1)->count());
    }

    public function test_separate_callers_and_retries_cannot_exceed_persistent_run_read_limit(): void
    {
        config(['discovery.read_limit' => 1, 'youtube.retries' => 1]);
        $run = $this->start();
        $this->gateway->intercept = fn ($m, $p) => $m === 'videos' ? throw new ApiFailure('temporary_error', true) : (new DemoGateway)->fetch($m, $p);
        app(Runner::class)->step($run->id);
        $this->assertSame('run_limit', $run->fresh()->stop_reason);
        $this->assertSame(1, $run->fresh()->read_calls);
        $anotherCaller = app(ApiCaller::class);
        $anotherCaller->setExploration($run->id);
        $anotherCaller->reset();
        try {
            $anotherCaller->call('videos', ['id' => 'demoVideo01']);
            $this->fail('Expected budget refusal');
        } catch (ApiFailure $e) {
            $this->assertSame('run_limit', $e->reason);
        }
        $this->assertSame(1, DB::table('api_days')->value('read_calls'));
    }

    public function test_zero_success_partial_error_and_unavailable_are_separate(): void
    {
        $run = $this->start();
        $first = true;
        $this->gateway->intercept = function ($method, $p) use (&$first) {
            if ($method === 'search' && $first) {
                $first = false;
                throw new ApiFailure('network_error');
            }

            return ['items' => []];
        };
        $this->drain($run);
        $this->assertSame('partial', $run->fresh()->status);
        $this->assertSame(15, $run->tasks()->where('status', 'empty')->count());
        $this->get('/explorations/'.$run->id)->assertOk()->assertSee('需要ゼロではありません')->assertSee('通信失敗');
        $this->gateway->intercept = fn ($m) => $m === 'search' ? ['items' => [['id' => ['videoId' => 'demoVideo01']]]] : ['items' => []];
        app(Planner::class)->resume($run->fresh());
        $this->drain($run);
        $this->assertSame('unavailable', $run->tasks()->first()->fetchRun->items()->first()->status);
        $this->assertSame(0, Video::count());
    }

    public function test_stop_at_call_boundary_preserves_search_then_resume_details(): void
    {
        $run = $this->start();
        $this->gateway->intercept = function ($m, $p) use ($run) {
            if ($m === 'search') {
                $run->update(['stop_requested' => true]);
            }

            return (new DemoGateway)->fetch($m, $p);
        };
        app(Runner::class)->step($run->id);
        $this->assertSame('halt_requested', $run->fresh()->stop_reason);
        $task = $run->tasks()->first();
        $this->assertSame(1, $task->fetchRun->pages);
        $this->assertCount(1, $this->gateway->calls);
        $this->gateway->calls = [];
        $this->gateway->intercept = null;
        app(Planner::class)->resume($run->fresh());
        app(Runner::class)->step($run->id);
        $this->assertNotContains('search', array_column($this->gateway->calls, 0));
        $count = Observation::count();
        // Duplicate delivery proceeds to the next task, never recollects a completed task.
        (new CollectExploration($run->id))->handle(app(Runner::class));
        $this->assertSame(1, FetchRun::find($task->fetch_run_id)->pages);
        $this->assertSame($count, Observation::where('fetch_run_id', $task->fetch_run_id)->count());
    }

    public function test_busy_is_bounded_and_worker_lease_recovery_marks_uncertain_consumption(): void
    {
        $run = $this->start();
        $lock = Cache::lock('youtube:collection', 180);
        $lock->get();
        $this->assertSame(30, app(Runner::class)->step($run->id));
        $this->assertSame(0, FetchRun::count());
        $lock->release();
        $task = $run->tasks()->first();
        $task->update(['status' => 'running', 'lease_until' => now()->addSeconds(180), 'claim' => (string) Str::uuid()]);
        $run->update(['status' => 'running', 'in_flight' => true, 'search_calls' => 1]);
        $this->assertSame(30, app(Runner::class)->step($run->id));
        $this->assertCount(0, $this->gateway->calls);
        $this->travel(181)->seconds();
        app(Runner::class)->step($run->id);
        $this->assertTrue($run->fresh()->consumption_uncertain);
        $this->assertSame('complete', $task->fresh()->status);
        $this->assertSame(2, $run->fresh()->search_calls);
        $this->get('/explorations/'.$run->id)->assertOk()->assertSee('送信後・保存前の消費は不明');
    }

    public function test_live_key_missing_never_creates_plan_or_consumes_budget(): void
    {
        config(['youtube.mode' => 'live', 'youtube.key' => '']);
        $this->get('/')->assertOk()->assertSee('APIキー未設定')->assertSee('架空データで操作を試す');
        $this->post('/explorations', ['preset' => 'en-US', 'token' => (string) Str::uuid()])->assertSessionHasErrors('key');
        $this->assertSame(0, Exploration::count());
        $this->assertSame(0, DB::table('api_days')->count());
    }

    public function test_quota_stops_other_themes_and_never_becomes_empty(): void
    {
        $run = $this->start();
        $this->gateway->intercept = fn () => throw new ApiFailure('quota_exceeded');
        $this->drain($run);
        $this->assertSame('quota_exceeded', $run->fresh()->stop_reason);
        $this->assertSame(15, $run->tasks()->where('status', 'pending')->count());
        $this->assertSame(0, $run->tasks()->where('status', 'empty')->count());
        $this->assertCount(1, $this->gateway->calls);
    }

    public function test_older_related_and_duration_deepening_need_no_query_input(): void
    {
        $run = $this->start();
        $task = $run->tasks()->first();
        $older = app(Planner::class)->deepen($run, $task, ['token' => (string) Str::uuid(), 'lens' => 'older_reference']);
        $c = $older->tasks()->first()->conditions;
        $this->assertSame('2021-10-14T00:12:34Z', $c['publishedAfter']);
        $this->assertSame('2026-04-17T00:12:34Z', $c['publishedBefore']);
        $this->assertSame($run->id, $older->parent_exploration_id);
        $this->assertSame(1, $older->tasks()->count());
        $related = app(Planner::class)->deepen($run, $task, ['token' => (string) Str::uuid(), 'lens' => 'recent_views', 'related' => 0, 'duration' => 'any']);
        $this->assertSame('instrumental music', $related->tasks()->first()->conditions['q']);
        $this->assertSame('any', $related->tasks()->first()->conditions['duration']);
        $this->assertSame(16, $run->tasks()->count());
        $this->assertCount(0, $this->gateway->calls);
    }

    public function test_candidates_dry_run_apply_approval_then_explicit_search(): void
    {
        $json = json_encode($this->proposal());
        $report = app(KeywordImport::class)->import($json);
        $this->assertSame('追加候補（未承認）', $report[0]['result']);
        $this->assertSame(0, ExplorationKeyword::count());
        app(KeywordImport::class)->import($json, true);
        $keyword = ExplorationKeyword::first();
        $this->assertSame('proposed', $keyword->status);
        $this->assertNull($keyword->parent_theme_id);
        $run = $this->start();
        $data = ['token' => (string) Str::uuid(), 'lens' => 'recent_views', 'keyword' => $keyword->id];
        $url = '/explorations/'.$run->id.'/tasks/'.$run->tasks()->first()->id.'/deepen';
        $this->post($url, $data)->assertNotFound();
        $this->post('/discovery-keywords/'.$keyword->id, ['status' => 'approved'])->assertRedirect();
        $this->get('/explorations/'.$run->id)->assertOk()->assertSee('この候補で調べる');
        $this->assertCount(0, $this->gateway->calls);
        $this->post($url, $data)->assertRedirect();
        $child = Exploration::where('parent_exploration_id', $run->id)->first();
        $this->assertSame($keyword->id, $child->tasks()->first()->theme_id);
        $this->assertSame($keyword->query, $child->tasks()->first()->conditions['q']);
        $this->drain($child);
        $this->assertNotEmpty($this->gateway->calls);
        $this->post('/discovery-keywords/'.$keyword->id, ['status' => 'held'])->assertRedirect();
        $data['token'] = (string) Str::uuid();
        $this->post($url, $data)->assertNotFound();
    }

    public function test_import_duplicate_normalization_uuid_conflict_and_atomic_rollback(): void
    {
        $proposal = $this->proposal();
        app(KeywordImport::class)->import(json_encode($proposal), true);
        ExplorationKeyword::first()->update(['status' => 'approved']);
        $proposal['candidates'][0]['id'] = (string) Str::uuid();
        $proposal['candidates'][0]['query'] = 'ＩＮＳＴＲＵＭＥＮＴＡＬ   MUSIC for deep work';
        app(KeywordImport::class)->import(json_encode($proposal), true);
        $this->assertSame(1, ExplorationKeyword::count());
        $this->assertSame('approved', ExplorationKeyword::first()->status);
        $proposal['candidates'][0]['query'] = 'different query';
        $proposal['candidates'][] = $this->proposal()['candidates'][0];
        $proposal['candidates'][1]['reason_ja'] = 'conflicting content';
        try {
            app(KeywordImport::class)->import(json_encode($proposal), true);
            $this->fail('Expected UUID conflict');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('競合', $e->getMessage());
        }
        $this->assertSame(1, ExplorationKeyword::count());
    }

    public static function invalidProposals(): array
    {
        return [['unknown'], ['type'], ['control'], ['length'], ['uuid'], ['preset'], ['parent'], ['count'], ['ids'], ['queries'], ['oversize']];
    }

    #[DataProvider('invalidProposals')]
    public function test_import_rejects_invalid_contract_without_side_effects(string $kind): void
    {
        $p = $this->proposal();
        match ($kind) {
            'unknown' => $p['candidates'][0]['view_count'] = 100,
            'type' => $p['candidates'][0]['query'] = 123,
            'control' => $p['candidates'][0]['query'] = "bad\nquery",
            'length' => $p['candidates'][0]['query'] = str_repeat('a', 121),
            'uuid' => $p['candidates'][0]['id'] = 'not-uuid',
            'preset' => $p['preset_id'] = 'en-GB',
            'parent' => $p['candidates'][0]['parent_theme_id'] = 'unknown',
            'count' => $p['candidates'] = array_fill(0, 9, $p['candidates'][0]),
            'ids' => $p['candidates'][] = $p['candidates'][0] + ['query' => 'different'],
            'queries' => $p['candidates'][] = array_replace($p['candidates'][0], ['id' => (string) Str::uuid()]),
            'oversize' => $p['candidates'][0]['reason_ja'] = str_repeat('a', 65536),
        };
        try {
            app(KeywordImport::class)->import(json_encode($p), true);
            $this->fail('Expected invalid proposal');
        } catch (ValidationException) {
            $this->assertSame(0, ExplorationKeyword::count());
        }
        $this->assertCount(0, $this->gateway->calls);
    }

    public function test_expiry_removes_memberships_queue_cache_and_keeps_user_purpose(): void
    {
        $run = $this->start();
        app(Runner::class)->step($run->id);
        $ids = Target::where('kind', 'video')->limit(2)->pluck('id')->all();
        $this->post('/comparisons', ['ids' => $ids, 'comparison_type' => 'same_channel', 'purpose' => '自分の調査目的'])->assertRedirect();
        $this->travel(28)->days();
        $this->get('/explorations/'.$run->id)->assertOk()->assertSee('結果期限切れ')->assertDontSee('架空：雨音とピアノ・読書用');
        $this->assertSame('expired', $run->fresh()->status);
        $this->assertSame(0, $run->tasks()->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, FetchRun::count());
        $this->assertSame(0, Observation::count());
        $this->post('/explorations/'.$run->id.'/resume')->assertSessionHasErrors('run');
        $this->assertSame('自分の調査目的', DB::table('comparisons')->value('purpose'));
    }

    public function test_demo_live_are_isolated_but_live_us_jp_share_video_records(): void
    {
        $demo = $this->start();
        app(Runner::class)->step($demo->id);
        config(['youtube.mode' => 'live', 'youtube.key' => 'fixture-not-real']);
        $us = $this->start();
        $jp = $this->start('ja-JP');
        app(Runner::class)->step($us->id);
        app(Runner::class)->step($jp->id);
        $this->assertSame(6, Video::count());
        $this->assertSame(3, Video::whereHas('target', fn ($q) => $q->where('source', 'live'))->count());
        $this->get('/explorations/'.$demo->id)->assertNotFound();
        $this->get('/explorations/'.$us->id)->assertOk()->assertDontSee('架空データのデモ');
        $this->assertSame(1, $us->fresh()->search_calls);
        $this->assertSame(1, $jp->fresh()->search_calls);
        $this->assertSame(2, DB::table('api_days')->where('source', 'live')->value('search_calls'));
    }

    public function test_daily_reference_requires_24h_valid_points_and_keeps_negative_gaps(): void
    {
        $run = app(Collector::class)->register(['kind' => 'video', 'id' => 'demoVideo01'], 10);
        $target = Target::where('kind', 'video')->first();
        $this->assertStringContainsString('未観測', Presentation::dailyDelta($target)['label']);
        $this->travel(1)->hours();
        app(Collector::class)->register(['kind' => 'video', 'id' => 'demoVideo01'], 10);
        $this->assertStringContainsString('未観測', Presentation::dailyDelta($target->fresh())['label']);
        $this->travel(47)->hours();
        $old = Video::first()->view_count;
        $this->gateway->intercept = function ($m, $p) use ($old) {
            $data = (new DemoGateway)->fetch($m, $p);
            if ($m === 'videos') {
                $data['items'][0]['statistics']['viewCount'] = (string) ($old - 100);
            }

            return $data;
        };
        app(Collector::class)->register(['kind' => 'video', 'id' => 'demoVideo01'], 10);
        $delta = Presentation::dailyDelta($target->fresh());
        $this->assertStringContainsString('-100', $delta['label']);
        $this->assertStringContainsString('47時間', $delta['interval']);
        $this->assertStringContainsString('負の差分', $delta['note']);
        $target->update(['last_status' => 'failed']);
        $this->assertStringContainsString('観測停止', Presentation::dailyDelta($target->fresh())['label']);
        $target->update(['last_status' => 'success']);
        Observation::latest('id')->where('target_id', $target->id)->first()->update(['view_count' => null]);
        $this->assertStringContainsString('数値欠損', Presentation::dailyDelta($target->fresh())['label']);
    }

    public function test_catalog_validation_hash_and_saved_conditions_survive_version_change(): void
    {
        $original = app(Catalog::class)->load();
        $run = $this->start();
        $task = $run->tasks()->first();
        $path = tempnam(sys_get_temp_dir(), 'bgm-catalog-');
        $file = json_decode(file_get_contents(config('discovery.catalog')), true);
        try {
            $file['version'] = '1.1.0';
            $file['themes'][0]['queries']['en-US']['primary'] = 'changed hypothesis';
            file_put_contents($path, json_encode($file));
            config(['discovery.catalog' => $path]);
            $new = app(Catalog::class)->load();
            $this->assertNotSame($original['hash'], $new['hash']);
            $this->assertSame('1.0.0', $run->fresh()->catalog_version);
            $this->assertSame('background music', $task->fresh()->conditions['q']);
            $file['themes'][0]['queries']['en-US']['unrecognized'] = true;
            file_put_contents($path, json_encode($file));
            try {
                app(Catalog::class)->load();
                $this->fail('Invalid catalog accepted');
            } catch (ValidationException) {
                $this->assertSame(16, $run->tasks()->count());
            }
        } finally {
            unlink($path);
        }
    }

    public function test_cli_import_dry_run_and_apply_never_dispatch_search_and_escape_preview(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bgm-keywords-');
        $p = $this->proposal();
        $p['candidates'][0]['label_ja'] = '<script>alert(1)</script>';
        file_put_contents($path, json_encode($p));
        try {
            $this->artisan('discovery:import-keywords', ['path' => $path, '--dry-run' => true])->expectsOutputToContain('DB変更なし')->assertSuccessful();
            $this->assertSame(0, ExplorationKeyword::count());
            $this->artisan('discovery:import-keywords', ['path' => $path, '--apply' => true])->assertSuccessful();
            $this->assertSame(1, ExplorationKeyword::count());
            $run = $this->start();
            $beforeJobs = DB::table('jobs')->count();
            $this->get('/explorations/'.$run->id)->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
            $this->assertSame($beforeJobs, DB::table('jobs')->count());
            $this->assertCount(0, $this->gateway->calls);
            $this->artisan('discovery:import-keywords', ['path' => $path, '--apply' => true, '--dry-run' => true])->assertFailed();
        } finally {
            unlink($path);
        }
    }
}
