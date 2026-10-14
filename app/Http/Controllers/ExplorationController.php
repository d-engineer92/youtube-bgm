<?php

namespace App\Http\Controllers;

use App\Models\Exploration;
use App\Models\ExplorationKeyword;
use App\Models\Target;
use App\Services\Discovery\Catalog;
use App\Services\Discovery\Planner;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExplorationController extends Controller
{
    private function find(string $id): Exploration
    {
        return Exploration::where('source', config('youtube.mode'))->findOrFail($id);
    }

    public function mode(Request $request)
    {
        $data = $request->validate(['mode' => ['required', Rule::in(['demo', 'configured'])]]);
        $request->session()->put('research_mode', $data['mode']);

        return redirect()->route('discovery');
    }

    public function start(Request $request, Planner $planner)
    {
        $data = $request->validate(['preset' => ['required', Rule::in(['en-US', 'ja-JP'])], 'token' => ['required', 'uuid']]);

        return redirect()->route('explorations.show', $planner->start($data['preset'], $data['token'])->id);
    }

    public function show(string $id, Catalog $catalog)
    {
        $run = $this->find($id);
        $run->load('tasks.fetchRun.items.target.video', 'tasks.fetchRun.items.target.observations');
        $items = $run->tasks->flatMap(fn ($task) => $task->fetchRun?->items ?? collect())->filter(fn ($item) => $item->target->kind === 'video');
        $paths = $items->groupBy('target_id');
        $valid = $items->filter(fn ($item) => $item->status === 'success' && $item->target->video?->expires_at > now())->unique('target_id');
        $channels = Target::inMode()->where('kind', 'channel')->whereIn('external_id', $valid->pluck('target.video.channel_external_id'))->with('channel')->get()->keyBy('external_id');
        $catalogData = $catalog->load();
        $keywords = ExplorationKeyword::where('source', $run->source)->where('preset_id', $run->preset_id)->orderBy('created_at')->get();

        return view('exploration', compact('run', 'paths', 'valid', 'channels', 'catalogData', 'keywords'));
    }

    public function progress(string $id)
    {
        $run = $this->find($id);

        return response()->json(['status' => $run->status, 'stop_reason' => $run->stop_reason, 'search_calls' => $run->search_calls, 'read_calls' => $run->read_calls, 'done' => $run->tasks()->whereIn('status', ['complete', 'empty'])->count(), 'tasks' => $run->tasks()->get(['id', 'status', 'error_code'])]);
    }

    public function stop(string $id)
    {
        $run = $this->find($id);
        if (in_array($run->status, ['queued', 'running'], true)) {
            $run->update(['stop_requested' => true]);
        }

        return redirect()->route('explorations.show', $id);
    }

    public function resume(string $id, Planner $planner)
    {
        $planner->resume($this->find($id));

        return redirect()->route('explorations.show', $id);
    }

    public function deepen(Request $request, string $id, int $taskId, Planner $planner)
    {
        $data = $request->validate(['token' => ['required', 'uuid'], 'lens' => ['required', Rule::in(['recent_views', 'recent_new', 'older_reference'])], 'related' => ['nullable', 'integer', 'min:0', 'max:7'], 'keyword' => ['nullable', 'uuid'], 'duration' => ['nullable', Rule::in(['any', 'short', 'medium', 'long'])]]);
        $run = $this->find($id);

        return redirect()->route('explorations.show', $planner->deepen($run, $run->tasks()->findOrFail($taskId), array_filter($data, fn ($v) => $v !== null))->id);
    }

    public function keyword(Request $request, string $id)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['approved', 'held', 'disabled'])]]);
        $keyword = ExplorationKeyword::where('source', config('youtube.mode'))->findOrFail($id);
        $keyword->update(['status' => $data['status'], 'approved_at' => $data['status'] === 'approved' ? now() : null]);

        return back()->with('notice', '候補語の状態を保存しました。検索はまだ実行していません。');
    }
}
