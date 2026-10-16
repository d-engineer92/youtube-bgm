<?php

namespace Tests\Feature;

use App\Jobs\UpdateTracking;
use App\Models\AnalysisRevision;
use App\Models\Comparison;
use App\Models\FetchRun;
use App\Models\MetadataVersion;
use App\Models\Observation;
use App\Models\ProductionBrief;
use App\Models\Target;
use App\Models\TrackingTarget;
use App\Services\Retention;
use App\Services\Tracking\Cutover;
use App\Services\Tracking\Dataset;
use App\Services\Tracking\Interval;
use App\Services\Tracking\Recorder;
use App\Services\Tracking\Review;
use App\Services\Tracking\SyntheticFixture;
use App\Services\Tracking\Updater;
use App\Services\YouTube\Collector;
use App\Services\YouTube\DemoGateway;
use App\Services\YouTube\Gateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\RecordingGateway;
use Tests\TestCase;

class TrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['youtube.mode' => 'demo']);
        $this->app->instance(Gateway::class, new RecordingGateway);
        $this->travelTo(now()->startOfSecond());
    }

    private function register(): Target
    {
        app(Collector::class)->register(['kind' => 'video', 'id' => 'demoVideo01'], 10);

        return Target::where('external_id', 'demoVideo01')->first();
    }

    public function test_g01_g02_all_paths_register_atomic_observations_and_zero_baseline(): void
    {
        $gateway = app(Gateway::class);
        $gateway->intercept = function ($m, $p) {
            $data = (new DemoGateway)->fetch($m, $p);
            if ($m === 'videos') {
                $data['items'][0]['statistics']['viewCount'] = '0';
            }

            return $data;
        };
        $t = $this->register();
        $this->assertFalse($t->watched);
        $this->assertNotNull($t->tracking);
        $this->assertSame(2, TrackingTarget::count());
        $gateway->intercept = function ($m, $p) {
            $data = (new DemoGateway)->fetch($m, $p);
            if ($m === 'videos') {
                $data['items'][0]['statistics']['viewCount'] = '500';
            }

            return $data;
        };
        $this->travel(24)->hours();
        app(Updater::class)->run(Dataset::id(), 'demo');
        $i = app(Interval::class)->resolve($t->fresh());
        $this->assertSame(500, $i['delta_views']);
        $this->assertNull($i['growth_percent']);
        $this->assertTrue($i['eligible']);
        $this->assertSame(2, TrackingTarget::count());
        $this->assertSame(1, DB::table('tracking_slots')->where('target_id', $t->id)->where('state', 'fulfilled')->count());
    }

    public function test_g03_freshness_due_slots_recovery_and_fixed_past_comparison(): void
    {
        $t = $this->register();
        $this->travel(24)->hours();
        $this->register();
        $at = now()->toImmutable();
        $i = app(Interval::class)->resolve($t->fresh());
        $this->assertTrue($i['eligible']);
        $this->travel(25)->hours();
        $this->assertSame('unfulfilled_due', app(Interval::class)->resolve($t->fresh())['status']);
        $this->travel(2)->hours();
        $this->assertSame('stale_latest', app(Interval::class)->resolve($t->fresh())['status']);
        $past = app(Interval::class)->resolve($t->fresh(), $at, true);
        $this->assertTrue($past['eligible']);
        $this->assertSame('fixed_past', $past['mode']);
        $this->register();
        $this->assertFalse(app(Interval::class)->resolve($t->fresh())['eligible']);
        $this->travel(24)->hours();
        $this->register();
        $this->assertTrue(app(Interval::class)->resolve($t->fresh())['eligible']);
        $this->assertSame(app(Interval::class)->resolve($t->fresh())['status'], app(Review::class)->pack(collect([$t->fresh()->load('observations')]))['subjects'][0]['interval']['status']);
    }

    public function test_g03_normal_jitter_boundaries_and_latest_missing_slot(): void
    {
        $t = $this->register();
        $this->travel(23)->hours();
        $this->travel(59)->minutes();
        $this->register();
        $this->assertTrue(app(Interval::class)->resolve($t->fresh())['eligible']);
        DB::table('tracking_slots')->insert(['dataset_id' => Dataset::id(), 'target_id' => $t->id, 'scheduled_for' => now()->subMinutes(20), 'state' => 'failed', 'reason' => 'network_error']);
        $this->assertSame('unfulfilled_due', app(Interval::class)->resolve($t->fresh())['status']);
        DB::table('tracking_slots')->update(['state' => 'fulfilled', 'fulfilled_at' => now()]);
        $this->assertTrue(app(Interval::class)->resolve($t->fresh())['eligible']);
    }

    public function test_g04_versions_include_stats_and_do_not_overwrite_old_content(): void
    {
        $t = $this->register();
        $old = $t->observations()->first();
        $hash = MetadataVersion::find($old->metadata_version_id)->content_hash;
        $this->travel(24)->hours();
        $gateway = app(Gateway::class);
        $gateway->intercept = function ($m, $p) {
            $d = (new DemoGateway)->fetch($m, $p);
            if ($m === 'videos') {
                $d['items'][0]['snippet']['title'] = '変更';
                $d['items'][0]['snippet']['tags'] = ['new'];
            }

            return $d;
        };
        $this->register();
        $new = $t->observations()->first();
        $this->assertNotSame($old->metadata_version_id, $new->metadata_version_id);
        $this->assertSame($hash, MetadataVersion::find($old->metadata_version_id)->content_hash);
        $this->assertNotNull($new->like_count);
        $this->assertArrayHasKey('comment_count', $new->field_status);
    }

    public function test_g05_search_expiration_cannot_cascade_recent_tracking_and_day31_continues(): void
    {
        $t = $this->register();
        $run = FetchRun::first();
        $this->travel(27)->days();
        $this->register();
        $this->travel(2)->days();
        app(Retention::class)->prune();
        $this->assertNull($run->fresh());
        $this->assertSame(1, $t->observations()->count());
        $this->assertNotNull($t->tracking);
        $this->travel(2)->days();
        $this->register();
        $this->assertSame(2, $t->observations()->count());
    }

    public function test_g06_old_generation_cannot_write_and_favorite_does_not_stop_tracking(): void
    {
        $t = $this->register();
        $t->update(['watched' => true]);
        $t->update(['watched' => false]);
        $this->travel(24)->hours();
        $before = Observation::count();
        (new UpdateTracking((string) Str::uuid(), 'demo'))->handle(app(Updater::class));
        $this->assertSame($before, Observation::count());
        app(Updater::class)->run(Dataset::id(), 'demo');
        $this->assertGreaterThan($before, Observation::count());
        $this->post('/tracking/'.$t->id.'/state', ['state' => 'paused'])->assertRedirect();
        $this->assertSame('paused', $t->tracking()->first()->state);
    }

    public function test_g08_g13_g15_fixed_comparison_analysis_and_brief_connect_without_ai(): void
    {
        app(SyntheticFixture::class)->seed(3);
        $ids = Target::where('kind', 'video')->limit(2)->pluck('id')->all();
        $this->post('/comparisons', ['ids' => $ids, 'comparison_type' => 'same_channel', 'purpose' => '異なる結果の比較'])->assertRedirect();
        $c = Comparison::first();
        $this->assertSame('cross_channel', $c->comparison_type);
        $this->post('/comparisons/'.$c->id.'/analysis', ['revision' => 0, 'state' => 'draft', 'facts' => '固定観測を見た', 'hypothesis' => '用途が伝わったかもしれない', 'counterexample' => '別の説明', 'unknown' => '楽器不明・画像未観察・コメント未読', 'next_check' => 'タイトルだけ変える'])->assertRedirect();
        $this->post('/briefs', ['comparison' => $c->id, 'revision' => 0, 'content' => ['one_change' => 'タイトル', 'budget' => '500円', 'hours' => '2時間', 'permission' => '未確認', 'validation' => 'Studioを手動確認'], 'reflection' => ['source' => 'Studio手入力', 'period' => '公開後7日', 'unit' => 'CTR %', 'result' => '未確認']])->assertRedirect();
        $brief = ProductionBrief::first();
        $this->assertSame(1, $brief->analysis_revision);
        $this->assertSame('タイトル', $brief->content['one_change']);
        $this->get('/briefs/'.$brief->id)->assertOk()->assertSee('Studio手入力');
        $saved = $c->evidence;
        $this->travel(1)->hours();
        $this->assertSame($saved, Comparison::first()->evidence);
        $this->get('/comparisons/'.$c->id.'/export')->assertOk()->assertJsonPath('subjects.0.interval.status', $saved['subjects'][0]['interval']['status']);
        $this->get('/comparisons/'.$c->id.'/export?format=csv')->assertOk()->assertStreamed();
    }

    public function test_g09_cutover_dry_run_protects_budget_and_only_erases_once(): void
    {
        $this->register();
        DB::table('api_days')->update(['blocked' => true]);
        $budget = DB::table('api_days')->get()->toJson();
        $old = Dataset::id();
        $operation = (string) Str::uuid();
        $service = app(Cutover::class);
        $result = $service->run($old, $operation);
        $this->assertSame('dry_run', $result['state']);
        $this->assertGreaterThan(0, Target::count());
        $result = $service->run($old, $operation, true);
        $this->assertSame('cut_over', $result['state']);
        $this->assertSame(0, Target::count());
        $this->assertSame($budget, DB::table('api_days')->get()->toJson());
        $this->assertSame('already_cut_over', $service->run($old, (string) Str::uuid(), true)['state']);
        $this->assertNotSame($old, Dataset::id());
    }

    public function test_g09_unknown_table_stops_before_erasure(): void
    {
        DB::statement('create table unknown_audit_fixture (id integer)');
        try {
            app(Cutover::class)->run(Dataset::id(), (string) Str::uuid(), true);
            $this->fail('must refuse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Unclassified', $e->getMessage());
        } finally {
            DB::statement('drop table unknown_audit_fixture');
        }
    }

    public function test_g10_130_distinct_videos_paged_and_zero_growth_is_visible(): void
    {
        app(SyntheticFixture::class)->seed();
        $this->assertSame(130, Target::where('kind', 'video')->distinct()->count('external_id'));
        $r = $this->get('/tracking');
        $r->assertOk()->assertSee('130本');
        $this->assertSame(24, $r->viewData('videos')->count());
        $this->get('/tracking?lens=recent_new&theme=theme-0')->assertOk();
    }

    public function test_g14_ai_disabled_and_evidence_expired_rejected_without_reviving_data(): void
    {
        app(SyntheticFixture::class)->seed(2);
        $c = app(Review::class)->save(Target::pluck('id')->all(), '期限確認');
        $this->post('/comparisons/'.$c->id.'/ai-result', ['observation_id' => 99999, 'dataset_id' => (string) Str::uuid()])->assertForbidden();
        $this->assertFalse(config('tracking.ai_enabled'));
        $this->assertFalse(config('tracking.image_evidence_enabled'));
        $this->post('/comparisons/'.$c->id.'/analysis', ['revision' => 0, 'state' => 'draft', 'hypothesis' => '根拠のある仮説'])->assertRedirect();
        $brief = ProductionBrief::create(['dataset_id' => $c->dataset_id, 'comparison_id' => $c->id, 'source' => 'demo', 'analysis_revision' => 1, 'content' => ['reference' => '架空API由来', 'avoid' => '架空反例', 'one_change' => '本人の企画']]);
        $c->update(['evidence_expires_at' => now()->subSecond()]);
        $this->get('/comparisons/'.$c->id.'/export')->assertGone();
        $this->post('/comparisons/'.$c->id.'/analysis', ['revision' => 0, 'state' => 'draft'])->assertGone();
        $this->assertNull($c->fresh()->evidence);
        $this->assertSame(['one_change' => '本人の企画'], $brief->fresh()->content);
    }

    public function test_g03_window_edges_and_equal_distance_prefer_earlier_point(): void
    {
        $t = $this->register();
        $this->travel(26)->hours();
        $this->register();
        $this->assertTrue(app(Interval::class)->resolve($t->fresh())['eligible']);
        $latest = $t->observations()->first();
        $early = $t->observations()->orderBy('id')->get()->last();
        $points = $t->observations()->get()->sortBy('fetched_at')->values();
        $earlier = $points->first();
        $middle = Observation::create(['dataset_id' => Dataset::id(), 'target_id' => $t->id, 'view_count' => 10, 'fetched_at' => $latest->fetched_at->subHours(22), 'expires_at' => now()->addDays(28)]);
        $result = app(Interval::class)->resolve($t->fresh());
        $this->assertSame($earlier->id, $result['start_id']);
        $earlier->update(['fetched_at' => $latest->fetched_at->subHours(26)->subSecond()]);
        $this->assertSame($middle->id, app(Interval::class)->resolve($t->fresh())['start_id']);
    }

    public function test_g06_130_videos_use_three_id_batches_and_do_not_repeat_search(): void
    {
        app(SyntheticFixture::class)->seed();
        TrackingTarget::query()->update(['next_due_at' => now()->subMinute(), 'last_attempt_at' => null]);
        $gateway = app(Gateway::class);
        $gateway->intercept = function ($method, $params) {
            $this->assertSame('videos', $method);
            $this->assertArrayNotHasKey('maxResults', $params);
            $this->assertArrayNotHasKey('pageToken', $params);

            return ['items' => array_map(fn ($id) => ['id' => $id, 'snippet' => ['title' => '架空更新', 'channelId' => 'UC'.str_repeat('0', 22), 'publishedAt' => now()->subDays(10)->toIso8601ZuluString()], 'contentDetails' => ['duration' => 'PT1H'], 'statistics' => ['viewCount' => '100']], explode(',', $params['id']))];
        };
        for ($n = 0; $n < 3; $n++) {
            app(Updater::class)->run(Dataset::id(), 'demo');
        }
        $this->assertCount(3, $gateway->calls);
        $this->assertSame(130, DB::table('tracking_slots')->where('state', 'fulfilled')->count());
        app(Updater::class)->run(Dataset::id(), 'demo');
        $this->assertCount(3, $gateway->calls);
    }

    public function test_g06_tracking_budget_reservation_prevents_new_discovery_without_dropping_low_views(): void
    {
        app(SyntheticFixture::class)->seed(3);
        TrackingTarget::query()->update(['next_due_at' => now()->subMinute()]);
        $this->post('/search', ['q' => 'ambient', 'order' => 'date', 'duration' => 'long', 'limit' => 10])->assertRedirect();
        $this->assertSame('tracking_budget_reserved', FetchRun::latest('id')->first()->error_code);
        $this->assertCount(0, app(Gateway::class)->calls);
        $this->assertSame(3, TrackingTarget::count());
    }

    public function test_g07_upload_scope_has_cursor_and_can_resume_independently_from_tracking(): void
    {
        $run = app(Collector::class)->register(['kind' => 'channel', 'id' => DemoGateway::CHANNEL_A], 1);
        $this->assertNotNull($run->next_page_token);
        $this->assertFalse($run->listing_done);
        $before = TrackingTarget::count();
        app(Collector::class)->resume($run, 20);
        $this->assertTrue($run->fresh()->listing_done);
        $this->assertGreaterThan($before, TrackingTarget::count());
    }

    public function test_g13_conflict_rejects_stale_analysis_revision(): void
    {
        app(SyntheticFixture::class)->seed(2);
        $c = app(Review::class)->save(Target::pluck('id')->all(), '競合更新');
        $this->from('/selection')->post('/comparisons/'.$c->id.'/analysis', ['revision' => 0, 'state' => 'draft', 'hypothesis' => '最初'])->assertRedirect('/comparisons/'.$c->id);
        $this->post('/comparisons/'.$c->id.'/analysis', ['revision' => 0, 'state' => 'draft', 'hypothesis' => '上書き'])->assertConflict();
        $this->assertSame('最初', $c->fresh()->analysis['hypothesis']);
        $brief = ProductionBrief::create(['dataset_id' => $c->dataset_id, 'comparison_id' => $c->id, 'source' => 'demo', 'analysis_revision' => 1, 'content' => ['one_change' => '変更は一つ']]);
        $this->post('/comparisons/'.$c->id.'/analysis', ['revision' => 1, 'state' => 'held', 'hypothesis' => '次の仮説'])->assertRedirect();
        $this->assertSame('最初', AnalysisRevision::where('comparison_id', $c->id)->where('revision', 1)->first()->content['hypothesis']);
        $this->get('/briefs/'.$brief->id)->assertOk()->assertSee('最初');
    }

    public function test_g03_requested_seven_day_period_is_fixed_in_exports(): void
    {
        app(SyntheticFixture::class)->seed(2, 8);
        $c = app(Review::class)->save(Target::pluck('id')->all(), '7日間の実測比較', 168);
        $this->assertSame(168, $c->evidence['requested_period_hours']);
        $this->assertSame(168 * 3600, $c->evidence['subjects'][0]['interval']['elapsed_seconds']);
        $this->get('/comparisons/'.$c->id.'/export')->assertOk()->assertJsonPath('requested_period_hours', 168);
    }

    public function test_g06_scheduler_queues_generation_and_daily_compatibility_uses_all_tracking(): void
    {
        $target = $this->register();
        $this->assertFalse($target->watched);
        $this->travel(24)->hours();
        $this->artisan('youtube:tracking-tick')->assertSuccessful();
        $this->assertSame(2, DB::table('jobs')->where('queue', 'tracking')->count());
        $payload = json_decode(DB::table('jobs')->first()->payload, true);
        $job = unserialize($payload['data']['command']);
        $this->assertInstanceOf(UpdateTracking::class, $job);
        $this->assertSame(Dataset::id(), $job->dataset);
        $before = $target->observations()->count();
        $this->artisan('youtube:refresh-watch --daily')->assertSuccessful();
        $this->assertSame($before + 1, $target->observations()->count());
        $this->assertTrue(DB::table('tracking_heartbeats')->where('name', 'scheduler')->exists());
    }

    public function test_g06_unexpected_storage_failure_is_sanitized_and_retried_without_partial_observation(): void
    {
        $target = $this->register();
        $before = Observation::count();
        $this->travel(24)->hours();
        $recorder = \Mockery::mock(Recorder::class);
        $recorder->shouldReceive('record')->andThrow(new \RuntimeException('private-api-body-must-not-be-logged'));
        $this->app->instance(Recorder::class, $recorder);
        app(Updater::class)->run(Dataset::id(), 'demo');
        $this->assertSame($before, Observation::count());
        $this->assertSame('worker_error', $target->tracking()->first()->reason);
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_g04_g16_related_channel_observations_are_fixed_and_exported_separately(): void
    {
        $first = $this->register();
        app(Collector::class)->register(['kind' => 'video', 'id' => 'demoVideo02'], 10);
        $c = app(Review::class)->save(Target::where('kind', 'video')->pluck('id')->all(), 'チャンネル背景を含む固定比較');
        $this->assertNotEmpty($c->evidence['channels']);
        $channel = $c->evidence['channels'][0];
        $this->assertSame('channel', $channel['subject_kind']);
        $this->assertNotNull($channel['observations'][0]['subscriber_count']);
        $this->get('/comparisons/'.$c->id)->assertOk()->assertSee('関連チャンネルの公開数値');
        $csv = $this->get('/comparisons/'.$c->id.'/export?format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString(',channel,', $csv);
    }

    public function test_g03_old_missed_slots_do_not_poison_comparison_after_multi_day_recovery(): void
    {
        $target = $this->register();
        $this->travel(3)->days();
        $this->register();
        $this->assertFalse(app(Interval::class)->resolve($target->fresh())['eligible']);
        $this->assertGreaterThan(0, DB::table('tracking_slots')->where('target_id', $target->id)->where('state', 'missed')->count());
        $this->travel(24)->hours();
        $this->register();
        $interval = app(Interval::class)->resolve($target->fresh());
        $this->assertTrue($interval['eligible']);
        $this->assertNotNull($interval['last_fulfilled_scheduled_for']);
        $this->assertNull($interval['unfulfilled_scheduled_for']);
    }
}
