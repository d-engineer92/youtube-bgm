<?php

namespace Tests\Feature;

use App\Models\Comparison;
use App\Models\FetchRun;
use App\Models\Observation;
use App\Models\RunItem;
use App\Models\Target;
use App\Models\Video;
use App\Services\Retention;
use App\Services\YouTube\ApiFailure;
use App\Services\YouTube\Collector;
use App\Services\YouTube\DemoGateway;
use App\Services\YouTube\Gateway;
use App\Support\Presentation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecordingGateway;
use Tests\TestCase;

class CollectionTest extends TestCase
{
    use RefreshDatabase;

    private RecordingGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-14T00:00:00Z'));
        config(['youtube.mode' => 'demo', 'youtube.retries' => 1]);
        $this->gateway = new RecordingGateway;
        $this->app->instance(Gateway::class, $this->gateway);
    }

    private function search(int $limit = 20): FetchRun
    {
        return app(Collector::class)->search(['q' => '読書 ピアノ', 'order' => 'relevance', 'duration' => 'any', 'limit' => $limit]);
    }

    private function register(string $id = 'demoVideo01'): FetchRun
    {
        return app(Collector::class)->register(['kind' => 'video', 'id' => $id], 20);
    }

    public function test_search_direct_registration_and_uploads_deduplicate_by_id(): void
    {
        $this->search();
        $this->register();
        app(Collector::class)->register(['kind' => 'channel', 'id' => DemoGateway::CHANNEL_A], 20);
        $this->assertSame(4, Video::count());
        $this->assertSame(6, Target::count());
        $this->assertSame(2, DB::table('channels')->count());
    }

    public function test_pagination_can_resume_without_duplicate_observations(): void
    {
        $run = $this->search(1);
        $first = Observation::first()->fetched_at;
        $this->assertSame('1', $run->next_page_token);
        $this->assertFalse($run->listing_done);
        $this->assertSame(1, Video::count());
        $this->travel(2)->hours();
        $run = app(Collector::class)->resume($run, 2);
        $this->assertSame(3, Video::count());
        $this->assertSame($first->toIso8601String(), Observation::first()->fetched_at->toIso8601String());
        app(Collector::class)->resume($run, 20);
        $count = Observation::count();
        app(Collector::class)->resume($run->fresh(), 20);
        $this->assertSame($count, Observation::count());
        $this->assertTrue($run->fresh()->listing_done);
    }

    public function test_duplicate_ids_across_pages_and_approximate_total_do_not_prove_completeness(): void
    {
        $this->gateway->intercept = function ($method, $params) {
            if ($method !== 'search') {
                return (new DemoGateway)->fetch($method, $params);
            }

            return ['items' => [['id' => ['videoId' => 'demoVideo01']]], 'nextPageToken' => isset($params['pageToken']) ? null : 'next', 'pageInfo' => ['totalResults' => 1]];
        };
        $run = $this->search(1);
        $this->assertFalse($run->listing_done);
        $run = app(Collector::class)->resume($run, 1);
        $this->assertTrue($run->listing_done);
        $this->assertSame(1, Video::count());
        $this->assertSame(1, Observation::where('target_id', Target::where('kind', 'video')->value('id'))->count());
    }

    public function test_timeout_retries_are_bounded_and_success_is_observed_once(): void
    {
        $attempts = 0;
        $this->gateway->intercept = function ($method, $params) use (&$attempts) {
            if ($method === 'videos' && ++$attempts === 1) {
                throw new ApiFailure('network_error', true);
            }

            return (new DemoGateway)->fetch($method, $params);
        };
        $run = $this->register();
        $this->assertSame('complete', $run->status);
        $this->assertSame(2, $attempts);
        $this->assertSame(2, Observation::count());
        $this->assertSame(3, DB::table('api_days')->value('read_calls'));
    }

    public function test_exhausted_retry_is_failure_then_same_run_can_recover(): void
    {
        $this->gateway->intercept = fn () => throw new ApiFailure('network_error', true);
        $run = $this->register();
        $this->assertSame('failed', $run->status);
        $this->assertCount(2, $this->gateway->calls);
        $this->assertSame(0, Observation::count());
        $this->gateway->intercept = null;
        $run = app(Collector::class)->resume($run, 20);
        $this->assertSame('complete', $run->status);
        $this->assertSame(2, Observation::count());
    }

    public function test_partial_channel_failure_preserves_video_and_recovers_without_duplicates(): void
    {
        $this->gateway->intercept = fn ($method, $params) => $method === 'channels' ? throw new ApiFailure('access_denied') : (new DemoGateway)->fetch($method, $params);
        $run = $this->register();
        $this->assertSame('partial', $run->status);
        $this->assertSame(1, $run->items()->where('status', 'success')->count());
        $this->assertSame(1, $run->items()->where('status', 'failed')->count());
        $this->gateway->intercept = null;
        app(Collector::class)->resume($run, 20);
        $this->assertSame(2, Observation::count());
    }

    public function test_operation_limit_keeps_pending_and_committed_page_for_resume(): void
    {
        config(['youtube.max_calls' => 1]);
        $run = $this->search(1);
        $this->assertSame('operation_limit', $run->error_code);
        $this->assertSame('1', $run->next_page_token);
        $this->assertSame(1, $run->items()->where('status', 'failed')->count());
        config(['youtube.max_calls' => 20]);
        app(Collector::class)->resume($run, 20);
        $this->assertSame(4, Video::count());
    }

    public function test_daily_and_manual_requests_share_budget_and_do_not_retry_quota(): void
    {
        $this->gateway->intercept = fn () => throw new ApiFailure('quota_exceeded');
        $run = $this->register();
        $this->assertSame('quota_exceeded', $run->error_code);
        $this->assertCount(1, $this->gateway->calls);
        $this->gateway->intercept = null;
        $next = $this->search();
        $this->assertSame('daily_limit', $next->error_code);
        $this->assertCount(1, $this->gateway->calls);
    }

    public function test_app_budget_counts_retries_and_resets_on_pacific_day(): void
    {
        config(['youtube.daily_read_calls' => 1]);
        $run = $this->register();
        $this->assertSame('partial', $run->status);
        $this->assertSame('daily_limit', $run->error_code);
        $this->travel(24)->hours();
        config(['youtube.daily_read_calls' => 20]);
        app(Collector::class)->resume($run, 20);
        $this->assertSame(2, DB::table('api_days')->count());
    }

    public function test_watch_updates_only_selected_scope_and_is_idempotent_per_day(): void
    {
        $this->search();
        Target::where('external_id', 'demoVideo01')->update(['watched' => true]);
        $this->gateway->calls = [];
        $this->travel(24)->hours();
        $run = app(Collector::class)->refreshWatch(true);
        $this->assertSame('complete', $run->status);
        $this->assertNotContains('search', array_column($this->gateway->calls, 0));
        $this->assertSame('demoVideo01', $this->gateway->calls[0][1]['id']);
        $count = Observation::count();
        $again = app(Collector::class)->refreshWatch(true);
        $this->assertSame($run->id, $again->id);
        $this->assertSame($count, Observation::count());
    }

    public function test_watched_channel_updates_known_videos_and_checks_recent_uploads(): void
    {
        $this->search();
        Target::where('external_id', DemoGateway::CHANNEL_A)->update(['watched' => true]);
        $this->gateway->calls = [];
        $this->travel(24)->hours();
        $run = app(Collector::class)->refreshWatch();
        $this->assertContains('playlistItems', array_column($this->gateway->calls, 0));
        $this->assertNotContains('search', array_column($this->gateway->calls, 0));
        $this->assertCount(1, $run->conditions['scans']);
        $this->assertSame(3, $run->items()->where('status', 'success')->count());
    }

    public function test_first_observation_missing_counts_and_hidden_subscribers_are_not_zero(): void
    {
        $this->search();
        $t = Target::where('external_id', 'demoVideo04')->first();
        $this->assertNull($t->video->view_count);
        $this->assertNull(DB::table('channels')->where('hidden_subscribers', true)->value('subscriber_count'));
        $this->assertStringContainsString('未観測', Presentation::delta($t)['label']);
        $this->get('/')->assertOk()->assertSee('未取得／非公開')->assertSee('未観測');
    }

    public function test_negative_delta_uses_real_48_hour_interval_and_keeps_original_expiry(): void
    {
        $this->register();
        $original = Observation::first()->expires_at;
        $old = Video::first()->view_count;
        $this->travel(48)->hours();
        $this->gateway->intercept = function ($method, $params) use ($old) {
            $data = (new DemoGateway)->fetch($method, $params);
            if ($method === 'videos') {
                $data['items'][0]['statistics']['viewCount'] = (string) ($old - 100);
            }

            return $data;
        };
        $this->register();
        $delta = Presentation::delta(Target::where('external_id', 'demoVideo01')->first());
        $this->assertSame('-100', $delta['label']);
        $this->assertStringContainsString('48時間', $delta['interval']);
        $this->assertStringNotContainsString('24時間', $delta['interval']);
        $this->assertStringContainsString('負の差分', $delta['note']);
        $this->assertEquals($original, Observation::first()->expires_at);
    }

    public function test_failed_refresh_does_not_convert_cached_success_to_zero(): void
    {
        $this->register();
        $old = Video::first()->view_count;
        $this->travel(24)->hours();
        $this->gateway->intercept = fn () => throw new ApiFailure('network_error', true);
        $this->register();
        $this->assertSame($old, Video::first()->view_count);
        $this->assertSame('failed', Target::where('external_id', 'demoVideo01')->value('last_status'));
        $this->get('/')->assertOk()->assertSee('取得失敗')->assertSee('今回の増分は未確認');
    }

    public function test_unavailable_resource_is_not_zero_and_old_api_data_is_removed(): void
    {
        $this->register();
        $this->gateway->intercept = fn () => ['items' => []];
        $run = $this->register();
        $this->assertSame('failed', $run->status);
        $this->assertSame(0, Video::count());
        $this->assertSame('unavailable', $run->items()->first()->status);
        $this->assertSame(0, Observation::where('target_id', $run->items()->first()->target_id)->count());
    }

    public function test_expired_data_is_deleted_before_read_and_own_comparison_purpose_survives(): void
    {
        $this->search();
        $ids = Target::where('kind', 'video')->limit(2)->pluck('id')->all();
        $this->post('/comparisons', ['ids' => $ids, 'comparison_type' => 'same_channel', 'purpose' => '次回は雨と静けさを聴き比べる'])->assertRedirect();
        Target::where('id', $ids[0])->update(['watched' => true, 'purpose' => '参考目的']);
        $this->travel(28)->days();
        $this->get('/')->assertOk()->assertDontSee('45,')->assertSee('公開情報は未取得または期限切れ');
        $this->assertSame(0, Video::count());
        $this->assertSame(0, Observation::count());
        $this->assertSame(0, FetchRun::count());
        $this->assertSame(0, RunItem::count());
        $this->assertSame(0, DB::table('channels')->count());
        $this->assertSame(1, Comparison::count());
        $this->get('/comparisons/'.Comparison::first()->id)->assertOk()->assertSee('次回は雨と静けさを聴き比べる')->assertSee('期限切れ');
    }

    public function test_refresh_does_not_extend_old_observation_retention(): void
    {
        $this->register();
        $this->travel(27)->days();
        $this->register();
        $this->assertSame(4, Observation::count());
        $this->travel(1)->days();
        app(Retention::class)->prune();
        $this->assertSame(2, Observation::count());
        $this->assertSame(1, Video::count());
        $this->assertStringContainsString('未観測', Presentation::delta(Target::where('external_id', 'demoVideo01')->first())['label']);
    }

    public function test_commands_prune_and_refresh_report_the_mode(): void
    {
        $this->artisan('youtube:refresh-watch', ['--daily' => true])->expectsOutputToContain('mode=demo')->assertSuccessful();
        $this->artisan('youtube:prune')->expectsOutputToContain('削除した期限切れレコード')->assertSuccessful();
    }

    public function test_live_key_missing_never_falls_back_to_demo(): void
    {
        config(['youtube.mode' => 'live', 'youtube.key' => '']);
        $run = $this->register();
        $this->assertSame('key_missing', $run->error_code);
        $this->assertSame(0, Video::count());
        $this->assertCount(0, $this->gateway->calls);
        $this->get('/runs/'.$run->id)->assertOk()->assertSee('APIキー未設定')->assertDontSee('架空データのデモ');
    }

    public function test_live_and_demo_same_external_ids_and_routes_are_isolated(): void
    {
        $demo = $this->register();
        config(['youtube.mode' => 'live', 'youtube.key' => 'test-placeholder']);
        $live = $this->register();
        $this->assertSame(2, Video::count());
        $this->assertSame(2, Target::where('external_id', 'demoVideo01')->count());
        $this->get('/runs/'.$demo->id)->assertNotFound();
        $this->get('/runs/'.$live->id)->assertOk();
        $demoTarget = Target::where('source', 'demo')->where('kind', 'video')->first();
        $this->post('/targets/'.$demoTarget->id.'/watch', ['watched' => true])->assertNotFound();
    }
}
