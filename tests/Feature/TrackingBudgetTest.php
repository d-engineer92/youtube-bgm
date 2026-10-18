<?php

namespace Tests\Feature;

use App\Jobs\UpdateTracking;
use App\Models\FetchRun;
use App\Models\Target;
use App\Models\TrackingTarget;
use App\Services\Tracking\Dataset;
use App\Services\Tracking\Updater;
use App\Services\YouTube\ApiBudget;
use App\Services\YouTube\ApiCaller;
use App\Services\YouTube\ApiFailure;
use App\Services\YouTube\Collector;
use App\Services\YouTube\DemoGateway;
use App\Services\YouTube\Gateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecordingGateway;
use Tests\TestCase;

class TrackingBudgetTest extends TestCase
{
    use RefreshDatabase;

    private RecordingGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        config(['youtube.mode' => 'demo', 'youtube.daily_read_calls' => 500, 'youtube.daily_tracking_read_calls' => 100, 'youtube.manual_read_reserve' => 100]);
        $this->gateway = new RecordingGateway;
        $this->app->instance(Gateway::class, $this->gateway);
        $this->travelTo(now()->startOfSecond());
    }

    private function dueVideo(): Target
    {
        app(Collector::class)->register(['kind' => 'video', 'id' => 'demoVideo01'], 10);
        $this->travel(24)->hours();
        $this->gateway->calls = [];

        return Target::where('kind', 'video')->firstOrFail();
    }

    private function tick(): void
    {
        app(Updater::class)->run(Dataset::id(), 'demo');
    }

    public function test_nonreturned_video_waits_24_hours_without_blocking_new_search_and_recovers(): void
    {
        $video = $this->dueVideo();
        $dueAt = $video->tracking->next_due_at;
        $this->gateway->intercept = fn ($method, $params) => $method === 'videos' ? ['items' => []] : (new DemoGateway)->fetch($method, $params);
        $this->tick();
        $this->assertCount(2, $this->gateway->calls);
        $this->assertSame('not_returned', $video->tracking()->first()->reason);
        $this->assertTrue($dueAt->equalTo($video->tracking()->first()->next_due_at));
        $this->assertSame(0, $video->observations()->count());
        $this->travel(5)->minutes();
        $this->tick();
        $this->assertCount(2, $this->gateway->calls);
        app(ApiCaller::class)->call('search', ['q' => 'ambient', 'maxResults' => 1]);
        $this->assertCount(3, $this->gateway->calls);
        $this->get('/tracking')->assertOk()->assertSee('再確認はこの時刻以降')->assertSee('Google Cloudの残量とは異なります');
        $this->travel(23)->hours();
        $this->travel(54)->minutes();
        $this->tick();
        $this->assertCount(3, $this->gateway->calls);
        $this->gateway->intercept = null;
        $this->travel(1)->minutes();
        $this->tick();
        $this->assertCount(5, $this->gateway->calls);
        $this->assertNull($video->tracking()->first()->reason);
        $this->assertSame(1, $video->observations()->count());
        $this->assertSame(0, DB::table('tracking_slots')->where('state', 'pending')->count());
    }

    public function test_temporary_failure_has_hour_cooldown_and_successful_channels_are_not_repeated(): void
    {
        $video = $this->dueVideo();
        $this->gateway->intercept = function ($method, $params) {
            if ($method === 'videos') {
                throw new ApiFailure('network_error', true);
            }

            return (new DemoGateway)->fetch($method, $params);
        };
        $this->tick();
        $this->assertCount(3, $this->gateway->calls);
        $this->travel(59)->minutes();
        $this->tick();
        $this->assertCount(3, $this->gateway->calls);
        $this->gateway->intercept = null;
        $this->travel(1)->minutes();
        $this->tick();
        $this->assertCount(4, $this->gateway->calls);
        $this->assertNull($video->tracking()->first()->reason);
        $this->assertSame(0, DB::table('tracking_slots')->where('state', '!=', 'fulfilled')->count());
    }

    public function test_tracking_cap_counts_retries_preserves_manual_capacity_and_does_not_requeue(): void
    {
        $video = $this->dueVideo();
        config(['youtube.daily_tracking_read_calls' => 2]);
        $this->gateway->intercept = fn () => throw new ApiFailure('network_error', true);
        $this->tick();
        $this->assertCount(2, $this->gateway->calls);
        $budget = app(ApiBudget::class)->today('demo');
        $this->assertSame(2, $budget->tracking_read_calls);
        $this->assertSame(2, $budget->read_calls);
        $this->assertSame('tracking_daily_limit', TrackingTarget::where('target_id', '!=', $video->id)->first()->reason);
        $runs = FetchRun::count();
        Queue::fake();
        $this->travel(65)->minutes();
        (new UpdateTracking(Dataset::id(), 'demo'))->handle(app(Updater::class));
        Queue::assertNothingPushed();
        $this->assertSame($runs, FetchRun::count());
        $this->gateway->intercept = null;
        app(ApiCaller::class)->call('videos', ['id' => 'demoVideo02']);
        $this->assertSame(3, app(ApiBudget::class)->today('demo')->read_calls);
        $this->assertSame(2, app(ApiBudget::class)->today('demo')->tracking_read_calls);
        $this->travelTo(now('America/Los_Angeles')->addDay()->startOfDay()->utc());
        $this->tick();
        $this->assertSame(2, app(ApiBudget::class)->today('demo')->tracking_read_calls);
        $this->assertNull($video->tracking()->first()->reason);
    }

    public function test_manual_floor_limits_tracking_even_when_tracking_limit_is_configured_too_high(): void
    {
        config(['youtube.daily_read_calls' => 5, 'youtube.daily_tracking_read_calls' => 500, 'youtube.manual_read_reserve' => 2]);
        $this->assertSame(3, app(ApiBudget::class)->trackingLimit());
        app()->instance('tracking_request', true);
        try {
            $api = app(ApiCaller::class);
            for ($i = 0; $i < 3; $i++) {
                $api->call('videos', ['id' => 'demoVideo01']);
            }
            try {
                $api->call('videos', ['id' => 'demoVideo01']);
                $this->fail('tracking must stop');
            } catch (ApiFailure $e) {
                $this->assertSame('tracking_daily_limit', $e->reason);
            }
        } finally {
            app()->forgetInstance('tracking_request');
        }
        app(ApiCaller::class)->call('videos', ['id' => 'demoVideo02']);
        app(ApiCaller::class)->call('channels', ['id' => DemoGateway::CHANNEL_A]);
        $this->assertCount(5, $this->gateway->calls);
        $this->assertSame(3, app(ApiBudget::class)->today('demo')->tracking_read_calls);
    }

    public function test_due_tracking_does_not_block_search_when_read_capacity_is_sufficient(): void
    {
        $this->dueVideo();
        $this->post('/search', ['q' => 'ambient', 'order' => 'date', 'duration' => 'long', 'limit' => 1])->assertRedirect();
        $this->assertNull(FetchRun::latest('id')->first()->error_code);
        $this->assertSame('search', $this->gateway->calls[0][0]);
        $this->assertSame(0, app(ApiBudget::class)->today('demo')->tracking_read_calls);
    }

    public function test_upstream_quota_block_stops_tracking_without_creating_runs_until_pacific_reset(): void
    {
        $this->dueVideo();
        $this->gateway->intercept = fn () => throw new ApiFailure('quota_exceeded');
        $this->tick();
        $this->assertCount(1, $this->gateway->calls);
        $runs = FetchRun::count();
        $this->tick();
        $this->assertSame($runs, FetchRun::count());
        $this->assertSame('quota_exceeded', app(ApiBudget::class)->trackingStopReason(app(ApiBudget::class)->today('demo')));
        $this->assertNull(app(ApiBudget::class)->today('live'));
        $this->gateway->intercept = null;
        $this->travelTo(now('America/Los_Angeles')->addDay()->startOfDay()->utc());
        $this->tick();
        $this->assertCount(3, $this->gateway->calls);
        $this->assertSame(0, TrackingTarget::whereNotNull('reason')->count());
    }
}
