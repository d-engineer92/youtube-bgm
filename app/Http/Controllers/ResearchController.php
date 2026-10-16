<?php

namespace App\Http\Controllers;

use App\Models\Comparison;
use App\Models\Exploration;
use App\Models\FetchRun;
use App\Models\Target;
use App\Models\Video;
use App\Services\Discovery\Catalog;
use App\Services\Tracking\Review;
use App\Services\YouTube\Collector;
use App\Services\YouTube\ReferenceParser;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ResearchController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'sort' => ['nullable', Rule::in(['popular', 'newest', 'oldest'])],
            'channel' => ['nullable', 'string', 'max:64'], 'watch_only' => ['nullable', 'boolean'],
            'min_minutes' => ['nullable', 'numeric', 'min:0', 'max:100000'], 'max_minutes' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'min_days' => ['nullable', 'numeric', 'min:0', 'max:100000'], 'max_days' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);
        $q = Video::whereHas('target', fn ($q) => $q->inMode())->where('expires_at', '>', now())->with(['target.observations']);
        if (! empty($filters['channel'])) {
            $q->where('channel_external_id', $filters['channel']);
        }
        if (! empty($filters['watch_only'])) {
            $q->whereHas('target', fn ($q) => $q->where('watched', true));
        }
        foreach (['min_minutes' => '>=', 'max_minutes' => '<='] as $key => $operator) {
            if (isset($filters[$key])) {
                $q->where('duration_seconds', $operator, $filters[$key] * 60);
            }
        }
        foreach (['min_days' => '<=', 'max_days' => '>='] as $key => $operator) {
            if (isset($filters[$key])) {
                $q->where('published_at', $operator, now()->subSeconds((int) ($filters[$key] * 86400)));
            }
        }
        $sort = $filters['sort'] ?? 'newest';
        $column = $sort === 'popular' ? 'view_count' : 'published_at';
        $q->orderByRaw("CASE WHEN $column IS NULL THEN 1 ELSE 0 END")->orderBy($column, $sort === 'oldest' ? 'asc' : 'desc')->orderBy('id');

        return view('discovery', [
            'videos' => $q->paginate(24)->withQueryString(),
            'channels' => Target::inMode()->where('kind', 'channel')->with('channel')->get(),
            'watched' => Target::inMode()->where('watched', true)->with(['channel', 'video'])->get(),
            'runs' => FetchRun::inMode()->latest('id')->limit(10)->get(),
            'comparisons' => Comparison::where('source', config('youtube.mode'))->latest('id')->limit(10)->get(),
            'catalog' => app(Catalog::class)->load(),
            'explorations' => Exploration::where('source', config('youtube.mode'))->latest('created_at')->limit(10)->get(),
            'startToken' => (string) Str::uuid(),
        ]);
    }

    public function search(Request $request, Collector $collector)
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'max:200'],
            'published_after' => ['nullable', 'date_format:Y-m-d'],
            'published_before' => ['nullable', 'date_format:Y-m-d'],
            'order' => ['required', Rule::in(['relevance', 'date', 'viewCount'])],
            'duration' => ['required', Rule::in(['any', 'short', 'medium', 'long'])],
            'limit' => ['required', 'integer', 'min:1', 'max:'.config('youtube.max_items')],
        ]);
        if (! empty($data['published_after']) && ! empty($data['published_before']) && $data['published_after'] > $data['published_before']) {
            throw ValidationException::withMessages(['published_before' => '終了日は開始日以降にしてください。']);
        }

        return redirect()->route('runs.show', $collector->search($data)->id);
    }

    public function register(Request $request, ReferenceParser $parser, Collector $collector)
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:500'], 'limit' => ['required', 'integer', 'min:1', 'max:'.config('youtube.max_items')]]);

        return redirect()->route('runs.show', $collector->register($parser->parse($data['reference']), $data['limit'])->id);
    }

    public function run(int $id)
    {
        $run = FetchRun::inMode()->where('expires_at', '>', now())->findOrFail($id);

        return view('run', ['run' => $run, 'items' => $run->items()->with(['target.video', 'target.channel'])->get()]);
    }

    public function resume(Request $request, int $id, Collector $collector)
    {
        $data = $request->validate(['limit' => ['required', 'integer', 'min:1', 'max:'.config('youtube.max_items')]]);
        $collector->resume(FetchRun::inMode()->where('expires_at', '>', now())->findOrFail($id), $data['limit']);

        return redirect()->route('runs.show', $id);
    }

    public function watch(Request $request, int $id)
    {
        $origin = $this->origin($request);
        $data = $request->validate(['watched' => ['required', 'boolean'], 'purpose' => ['nullable', 'string', 'max:2000']]);
        Target::inMode()->findOrFail($id)->update($data);

        return ($origin ? redirect()->route('explorations.show', $origin->id) : redirect()->route('discovery'))->with('notice', 'ウォッチ設定と自分の目的を保存しました。');
    }

    private function origin(Request $request): ?Exploration
    {
        $data = $request->validate(['exploration' => ['nullable', 'uuid']]);

        return isset($data['exploration']) ? Exploration::where('source', config('youtube.mode'))->findOrFail($data['exploration']) : null;
    }

    public function watchSelected(Request $request)
    {
        $origin = $this->origin($request);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:6'], 'ids.*' => ['integer', 'distinct']]);
        $targets = Target::inMode()->where('kind', 'video')->whereIn('id', $data['ids'])->get();
        abort_if($targets->count() !== count($data['ids']), 404);
        foreach ($targets as $target) {
            $target->update(['watched' => true]);
        }

        return ($origin ? redirect()->route('explorations.show', $origin->id) : redirect()->route('discovery'))->with('notice', '選んだ動画をウォッチに登録しました。停止中の観測は補完しません。');
    }

    public function refresh(Collector $collector)
    {
        return redirect()->route('runs.show', $collector->refreshWatch()->id);
    }

    public function compare(Request $request, ?int $id = null)
    {
        $saved = $id ? Comparison::where('source', config('youtube.mode'))->with('targets')->findOrFail($id) : null;
        $data = $request->validate(['ids' => ['sometimes', 'array', 'min:2', 'max:6'], 'ids.*' => ['integer', 'distinct'], 'period_hours' => ['nullable', Rule::in([24, 168])]]);
        $ids = $saved ? $saved->targets->pluck('id')->all() : ($data['ids'] ?? []);
        if (! $ids) {
            return redirect()->route('discovery')->with('notice', '比較する動画を2〜6本選んでください。');
        }
        $targets = Target::inMode()->where('kind', 'video')->whereIn('id', $ids)->with(['video', 'observations', 'tracking', 'slots'])->get()->sortBy(fn ($target) => array_search($target->id, $ids))->values();
        abort_if($targets->count() !== count($ids), 404);
        $channels = Target::inMode()->where('kind', 'channel')->whereIn('external_id', $targets->pluck('video.channel_external_id')->filter())->with('channel')->get()->keyBy('external_id');

        $origin = $this->origin($request);

        $pack = $saved?->evidence;
        if ($saved && $saved->evidence_expires_at <= now()) {
            $pack = null;
        }
        if (! $saved) {
            $pack = app(Review::class)->pack($targets, null, (int) $request->input('period_hours', 24));
        }

        return view('comparison', compact('targets', 'channels', 'saved', 'origin', 'pack'));
    }

    public function saveComparison(Request $request)
    {
        $origin = $this->origin($request);
        $data = $request->validate(['ids' => ['required', 'array', 'min:2', 'max:6'], 'ids.*' => ['integer', 'distinct'], 'comparison_type' => ['required', Rule::in(['same_channel', 'cross_channel'])], 'purpose' => ['required', 'string', 'max:2000'], 'period_hours' => ['nullable', Rule::in([24, 168])]]);
        $targets = Target::inMode()->where('kind', 'video')->whereIn('id', $data['ids'])->get();
        abort_if($targets->count() !== count($data['ids']), 404);
        $comparison = app(Review::class)->save($data['ids'], $data['purpose'], (int) ($data['period_hours'] ?? 24));

        return redirect()->route('comparisons.show', ['id' => $comparison->id, 'exploration' => $origin?->id]);
    }
}
