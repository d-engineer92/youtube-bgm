<?php

namespace Tests\Feature;

use App\Models\Comparison;
use App\Models\FetchRun;
use App\Models\Target;
use App\Models\Video;
use App\Services\YouTube\Collector;
use App\Services\YouTube\DemoGateway;
use App\Services\YouTube\Gateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RecordingGateway;
use Tests\TestCase;

class ResearchUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['youtube.mode' => 'demo']);
        $this->app->instance(Gateway::class, new RecordingGateway);
    }

    private function seedVideos(): void
    {
        app(Collector::class)->search(['q' => '読書', 'order' => 'relevance', 'duration' => 'any', 'limit' => 20]);
    }

    public function test_search_conditions_partial_range_and_reuse_are_visible(): void
    {
        $this->post('/search', ['q' => '雨', 'published_after' => '2026-01-01', 'published_before' => '2026-12-31', 'order' => 'date', 'duration' => 'long', 'limit' => 1])->assertRedirect();
        $parameters = app(Gateway::class)->calls[0][1];
        $this->assertSame('2025-12-31T15:00:00Z', $parameters['publishedAfter']);
        $this->assertSame('2026-12-31T14:59:59Z', $parameters['publishedBefore']);
        $run = FetchRun::first();
        $this->assertSame('雨', $run->conditions['q']);
        $this->get('/runs/'.$run->id)->assertOk()->assertSee('部分取得')->assertSee('1本')->assertSee('この検索条件を再利用')->assertSee('雨');
        $this->get('/')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee('架空データのデモ');
    }

    public function test_direct_url_and_id_add_one_candidate(): void
    {
        $this->post('/register', ['reference' => 'https://www.youtube.com/watch?v=demoVideo01', 'limit' => 20])->assertRedirect();
        $this->post('/register', ['reference' => 'demoVideo01', 'limit' => 20])->assertRedirect();
        $this->assertSame(1, Video::count());
    }

    public function test_unsafe_url_and_invalid_conditions_never_call_api(): void
    {
        $this->post('/register', ['reference' => 'https://evil.example/demoVideo01', 'limit' => 20])->assertSessionHasErrors('reference');
        $this->post('/search', ['q' => '雨', 'order' => 'score', 'duration' => 'any', 'limit' => 2000])->assertSessionHasErrors(['order', 'limit']);
        $this->post('/search', ['q' => '雨', 'order' => 'date', 'duration' => 'any', 'limit' => 1, 'published_after' => '2026-10-10', 'published_before' => '2026-10-01'])->assertSessionHasErrors('published_before');
        $this->assertSame(0, FetchRun::count());
    }

    public function test_filters_and_sort_include_low_and_high_view_videos(): void
    {
        $this->seedVideos();
        $response = $this->get('/?sort=popular&min_minutes=40&max_minutes=46&min_days=9&max_days=11');
        $response->assertOk()->assertSee('架空：雨音とピアノ・読書用')->assertSee('架空：雨音とピアノ・静かな机')->assertDontSee('架空：公開直後・数値欠損の例');
        $this->assertSame(2, $response->viewData('videos')->total());
        $this->assertSame('demoVideo01', $response->viewData('videos')->first()->target->external_id);
        $this->get('/?channel='.DemoGateway::CHANNEL_A)->assertOk()->assertSee('架空：雨音とピアノ・静かな机');
    }

    public function test_two_different_results_can_be_compared_and_purpose_saved(): void
    {
        $this->seedVideos();
        $ids = Target::whereIn('external_id', ['demoVideo01', 'demoVideo02'])->pluck('id')->all();
        $this->get('/compare?'.http_build_query(['ids' => $ids]))->assertOk()->assertSee('架空：雨音とピアノ・読書用')->assertSee('架空：雨音とピアノ・静かな机')->assertSee('公開後の経過')->assertSee('公開再生数')->assertSee('YouTubeで視聴');
        $this->post('/comparisons', ['ids' => $ids, 'comparison_type' => 'same_channel', 'purpose' => '同じ尺で結果が違う理由を聴いて確認'])->assertRedirect();
        $this->assertSame('same_channel', Comparison::first()->comparison_type);
        $this->get('/comparisons/'.Comparison::first()->id)->assertOk()->assertSee('同じ尺で結果が違う理由を聴いて確認');
    }

    public function test_comparison_rejects_duplicates_one_video_or_channel_target(): void
    {
        $this->seedVideos();
        $video = Target::where('kind', 'video')->first();
        $channel = Target::where('kind', 'channel')->first();
        $this->get('/compare?'.http_build_query(['ids' => [$video->id, $video->id]]))->assertSessionHasErrors('ids.0');
        $this->get('/compare?'.http_build_query(['ids' => [$video->id]]))->assertSessionHasErrors('ids');
        $this->get('/compare?'.http_build_query(['ids' => [$video->id, $channel->id]]))->assertNotFound();
    }

    public function test_api_text_and_own_memo_are_escaped(): void
    {
        $this->seedVideos();
        $video = Video::first();
        $video->update(['title' => '<script>alert("api")</script>']);
        $video->target->update(['purpose' => '<script>alert("memo")</script>']);
        $response = $this->get('/');
        $response->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(', false);
    }

    public function test_watch_can_be_enabled_disabled_and_memo_kept_separate(): void
    {
        $this->seedVideos();
        $t = Target::where('kind', 'video')->first();
        $this->post('/targets/'.$t->id.'/watch', ['watched' => 1, 'purpose' => '静かな場面の参考'])->assertRedirect();
        $this->assertTrue($t->fresh()->watched);
        $this->post('/targets/'.$t->id.'/watch', ['watched' => 0, 'purpose' => '静かな場面の参考'])->assertRedirect();
        $this->assertFalse($t->fresh()->watched);
        $this->get('/')->assertSee('自分の制作候補・調査メモ')->assertSee('静かな場面の参考');
    }
}
