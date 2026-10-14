@extends('layout')
@section('title','テーマ別に探索する')
@section('content')
@php use App\Support\Presentation as P; use Illuminate\Support\Str; $taskMap=$run->tasks->keyBy('fetch_run_id'); @endphp
<p class="eyebrow">おまかせ探索 / {{ $run->preset_id }} / {{ $run->source }}</p>
<h1>テーマ別の結果と次の一歩</h1>
<p><a href="{{ route('discovery') }}">探索入口・手動検索へ戻る</a> @if($run->parent_exploration_id) / <a href="{{ route('explorations.show',$run->parent_exploration_id) }}">元の結果へ戻る</a>@endif</p>
<section class="panel" id="progress" data-status="{{ $run->status }}">
    <h2>収集の進捗：<span id="progress-status">{{ P::status($run->status) }}</span></h2>
    <p id="progress-count">計画 {{ $run->tasks->count() }}条件 ／ 完了 {{ $run->tasks->whereIn('status',['complete','empty'])->count() }}条件 ／ 異なる動画 {{ $valid->count() }}本</p>
    <p>検索ヒット {{ $run->tasks->sum(fn($t)=>$t->fetchRun?->search_hits ?? 0) }}件（重複込み）／ 同じ動画の重複ヒット {{ max(0,$run->tasks->sum(fn($t)=>$t->fetchRun?->search_hits ?? 0)-$paths->count()) }}件。チャンネル行は動画件数へ加えていません。</p>
    <p id="progress-budget">送信試行：検索 {{ $run->search_calls }}/{{ $run->search_limit }}、read {{ $run->read_calls }}/{{ $run->read_limit }}。日次枠は手動検索・ウォッチ・追加調査と共通（検索{{ config('youtube.daily_search_calls') }} / read{{ config('youtube.daily_read_calls') }}）。</p>
    <p class="hint">日次枠は米国太平洋時間の午前0時に切替。次の切替：{{ P::date(now('America/Los_Angeles')->addDay()->startOfDay()) }}。Cloudプロジェクトの外部利用分はこの台帳では分かりません。</p>
    @if($run->stop_reason)<p class="banner">{{ P::error($run->stop_reason) }}</p>@endif
    @if($run->consumption_uncertain)<p class="banner">ワーカー中断：送信後・保存前の消費は不明です。送信試行は保守的に計上し、再送の可能性を残しています。</p>@endif
    @if(in_array($run->status,['queued','running']))
        <p>画面を閉じても収集は進みます。停止は次のAPI呼出境界で反映します。</p>
        <form action="{{ route('explorations.stop',$run->id) }}" method="post">@csrf<button class="secondary">収集を停止する</button></form>
        <p id="worker-hint" class="banner">進捗が動かない場合、ワーカーが未稼働または他の取得を待っています。起動：<code>docker compose up -d worker</code>。単一ワーカーで使用してください。</p>
        <p><a href="{{ route('explorations.show',$run->id) }}">保存済みの途中結果を表示・更新</a></p>
    @elseif(in_array($run->status,['partial','paused','failed']))
        <form action="{{ route('explorations.resume',$run->id) }}" method="post">@csrf<button @disabled($run->stop_reason==='run_limit')>続きから再開する</button></form>
        @if($run->stop_reason==='run_limit')<p>このrunの枠はリセットしません。下の未完了条件の「同条件を新しく調べる」で新しい実行へ進めます。</p>@endif
    @elseif($run->status==='expired')
        <p class="banner">結果期限切れ。検索所属・順位・キャッシュは削除済みです。再開できません。</p>
        <form action="{{ route('explorations.start') }}" method="post">@csrf<input type="hidden" name="preset" value="{{ $run->preset_id }}"><input type="hidden" name="token" value="{{ Str::uuid() }}"><button>同じプリセットで新しく調べる</button></form>
    @endif
    <details><summary>固定した実行条件・保存期限</summary><p>T0（UTC）：{{ $run->t0->toIso8601ZuluString() }} ／ カタログ{{ $run->catalog_version }} ／ hash <code>{{ $run->catalog_hash }}</code></p><p>結果期限：{{ P::date($run->expires_at) }}。再開しても日付・語・予算は変えません。</p></details>
</section>
<div class="banner">テーマは検索条件のグループです。件数の違いは市場規模ではありません。公開再生数は世界全体の現在値で、US/JPの再生数ではありません。実際の視聴者国・RPMは未確認です。</div>
<nav class="theme-nav" aria-label="探索テーマ">@foreach($run->tasks->unique('theme_id') as $theme)<a href="#theme-{{ $theme->theme_id }}">{{ $theme->label_ja }}</a>@endforeach</nav>
@foreach($run->tasks->groupBy('theme_id') as $themeId=>$tasks)
<section class="panel theme" id="theme-{{ $themeId }}">
    <h2>{{ $tasks->first()->label_ja }}</h2><p>{{ $tasks->first()->purpose_ja }}</p>
    @foreach($tasks as $task)
    @php
        $fetch=$task->fetchRun;
        $items=($fetch?->items ?? collect())->filter(fn($i)=>$i->target->kind==='video')->sortBy('search_rank');
        $videos=$items->filter(fn($i)=>$i->status==='success' && $i->target->video?->expires_at>now());
        $normal=$videos->filter(fn($i)=>$i->target->video->broadcast_status==='none' && !$i->target->video->has_live_details);
        $special=$videos->diff($normal);
        $formId='select-'.$task->id;
    @endphp
    <article class="lens" data-task="{{ $task->id }}">
        <h3>{{ P::lens($task->lens) }} <span class="badge">{{ P::status($task->status) }}</span></h3>
        <p><strong>{{ $task->conditions['q'] }}</strong> — {{ $task->meaning_ja }}</p><p class="hint">出どころ：{{ $task->origin }}</p>
        <details><summary>実際の検索条件</summary><p>regionCode={{ $task->conditions['regionCode'] }} / relevanceLanguage={{ $task->conditions['relevanceLanguage'] }} / order={{ $task->conditions['order'] }} / videoDuration={{ $task->conditions['duration'] }} / type=video / part=snippet / maxResults=10 / 1ページ上限</p><p>UTC期間：{{ $task->conditions['publishedAfter'] }} 〜 {{ $task->conditions['publishedBefore'] }}</p><p>検索取得：{{ P::date($fetch?->listed_at) }} ／ API返却地域：{{ $fetch?->response_region ?? '未返却' }} @if($fetch?->response_region && $fetch->response_region!==$task->conditions['regionCode'])（要求地域と不一致）@endif</p></details>
        <p>ヒット{{ $fetch?->search_hits ?? 0 }}件 ／ 詳細取得できた動画{{ $videos->count() }}本 ／ 異なるチャンネル{{ $videos->pluck('target.video.channel_external_id')->unique()->count() }} ／ 重複{{ max(0,($fetch?->search_hits ?? 0)-$items->count()) }}件 ／ {{ $fetch?->next_page_token ? '追加ページあり（今回は取得しません）' : ($fetch?->pages ? '続きトークンなし' : '検索未完了') }}</p>
        @if($task->error_code)<p class="banner">{{ P::error($task->error_code) }} 収集失敗は人気・需要の判定材料ではありません。</p>@endif
        @if($task->status==='empty')<p>この条件と取得範囲では0件でした。需要ゼロではありません。別語・期間・尺の変更を試せます。</p>@endif
        <p class="hint">現在値だけでは今の増え方は未観測です。新着も無作為標本ではありません。公開経過と低再生の例を確認しましょう。</p>
        @if($videos->count())
        <p>{{ $videos->pluck('target.video.channel_external_id')->unique()->count()>1 ? '各チャンネルで用途・見せ方にどんな違いがありますか？ 2〜6本を比較しましょう。' : '一つのチャンネルに偏っています。一つの例だけで用途全体の需要と判断せず、新着や別語も見ましょう。' }}</p>
        <form id="{{ $formId }}" action="{{ route('compare') }}" method="get"><input type="hidden" name="exploration" value="{{ $run->id }}"><div class="selection-actions"><button>2〜6本を比較</button><button class="secondary" formaction="{{ route('watch.selected') }}" formmethod="post" name="_token" value="{{ csrf_token() }}">選んだ動画をウォッチ</button></div></form>
        <div class="cards">@foreach($normal->take(3) as $item)@include('exploration-video')@endforeach</div>
        @if($normal->count()>3)<details><summary>全取得結果を開く（残り{{ $normal->count()-3 }}本）</summary><div class="cards">@foreach($normal->slice(3) as $item)@include('exploration-video')@endforeach</div></details>@endif
        @if($special->isNotEmpty())<details><summary>ライブ・予約・履歴あり・形態不明（{{ $special->count() }}本）</summary><p>通常動画との比較は分けて確認してください。</p><div class="cards">@foreach($special as $item)@include('exploration-video')@endforeach</div></details>@endif
        @endif
        @foreach($items->where('status','!=','success') as $item)<p>{{ $item->target->external_id }} — {{ P::status($item->status) }}</p>@endforeach
        <div class="deepen-actions">
            @foreach(['older_reference'=>'古い作品も調べる','recent_new'=>'新着を追加調査する'] as $lens=>$label)
            <form action="{{ route('explorations.deepen',[$run->id,$task->id]) }}" method="post">@csrf<input type="hidden" name="token" value="{{ Str::uuid() }}"><input type="hidden" name="lens" value="{{ $lens }}"><button class="secondary">{{ $label }}</button></form>
            @endforeach
        </div>
        <p class="hint">古い作品はT0の180日以上前〜5年前。累計が多いだけでは現在も再生されていると分かりません。選んでウォッチし、24時間以上離れた観測を確認します。</p>
        <details><summary>別の切り口を探す・尺を変える</summary>
            @php $catalogTheme=collect($catalogData['themes'])->firstWhere('id',$themeId); @endphp
            @foreach($catalogTheme['queries'][$run->preset_id]['related'] ?? [] as $index=>$related)
                <form class="related" action="{{ route('explorations.deepen',[$run->id,$task->id]) }}" method="post">@csrf<input type="hidden" name="token" value="{{ Str::uuid() }}"><input type="hidden" name="lens" value="recent_views"><input type="hidden" name="related" value="{{ $index }}"><p>{{ $related['query'] }} — {{ $related['meaning_ja'] }}（同梱・需要未検証）</p><button class="secondary">この言い回しで調べる</button></form>
            @endforeach
            <form action="{{ route('explorations.deepen',[$run->id,$task->id]) }}" method="post">@csrf<input type="hidden" name="token" value="{{ Str::uuid() }}"><input type="hidden" name="lens" value="{{ $task->lens }}"><label>尺条件<select name="duration">@foreach(['any'=>'すべて','short'=>'4分未満','medium'=>'4〜20分','long'=>'20分超'] as $value=>$label)<option value="{{ $value }}" @selected($task->conditions['duration']===$value)>{{ $label }}</option>@endforeach</select></label><button class="secondary">同条件を新しく調べる</button></form>
        </details>
    </article>
    @endforeach
</section>
@endforeach
@if($run->tasks->isNotEmpty())
<section class="panel"><h2>新しい検索候補を確認する</h2><p>候補JSONはCLIで検証・取込します。取込と承認ではAPIを呼びません。承認後の「この候補で調べる」で初めて検索します。</p><p><code>php artisan discovery:import-keywords /app/private/candidates.json</code><br>適用は <code>--apply</code>。固定依頼と形式は <a href="https://github.com/d-engineer92/youtube-bgm/blob/feat/guided-discovery-exp-a-b/docs/discovery/keyword-expansion.md" target="_blank" rel="noopener noreferrer">候補語の作り方 ↗</a> を参照。</p>
@forelse($keywords as $keyword)
<article class="keyword"><h3>{{ $keyword->label_ja }}</h3><p>{{ $keyword->query }} — {{ $keyword->meaning_ja }}</p><p>{{ $keyword->reason_ja }}</p><p>出どころ：{{ $keyword->source_kind==='manual' ? '本人の提案' : 'Codex候補案' }} / {{ $keyword->prompt_id }} / {{ ['proposed'=>'未承認','approved'=>'承認済み','held'=>'保留','disabled'=>'無効'][$keyword->status] }} / 需要未検証 / {{ $keyword->parent_theme_id ?? '新しいテーマ' }}</p>
<form action="{{ route('keywords.update',$keyword->id) }}" method="post">@csrf<input type="hidden" name="exploration" value="{{ $run->id }}"><label>候補の状態<select name="status">@foreach(['approved'=>'承認','held'=>'保留','disabled'=>'無効'] as $value=>$label)<option value="{{ $value }}" @selected($keyword->status===$value)>{{ $label }}</option>@endforeach</select></label><button class="secondary">候補の状態を保存</button></form>
@if($keyword->status==='approved')<form action="{{ route('explorations.deepen',[$run->id,$run->tasks->first()->id]) }}" method="post">@csrf<input type="hidden" name="token" value="{{ Str::uuid() }}"><input type="hidden" name="lens" value="recent_views"><input type="hidden" name="keyword" value="{{ $keyword->id }}"><button>この候補で調べる</button></form>@endif
</article>@empty<p>取込済みの候補はありません。同梱の関連語は各テーマの「別の切り口を探す」から選べます。</p>@endforelse
</section>
@endif
@endsection
@section('scripts')
@if(in_array($run->status,['queued','running']))
<script>
let lastDone={{ $run->tasks->whereIn('status',['complete','empty'])->count() }};
let edited=false;
document.addEventListener('input',event=>{ if(event.target.matches('input[name=purpose]')) edited=true; });
const labels={queued:'待機中',running:'収集中',complete:'計画完了',partial:'一部失敗',paused:'停止中',failed:'失敗',expired:'期限切れ'};
setInterval(async()=>{
    try {
        const response=await fetch(@json(route('explorations.progress',$run->id)),{headers:{Accept:'application/json'}});
        if(!response.ok) return;
        const data=await response.json();
        for (const task of data.tasks) {
            const badge=document.querySelector(`[data-task="${task.id}"] h3 .badge`);
            if (badge) badge.textContent={pending:'未取得',running:'処理中',complete:'取得成功',empty:'成功・0件',partial:'一部失敗',failed:'取得失敗',skipped:'未実施'}[task.status]||task.status;
        }
        document.getElementById('progress-status').textContent=labels[data.status]||data.status;
        document.getElementById('progress-count').textContent=`完了 ${data.done} / ${data.tasks.length}条件`;
        document.getElementById('progress-budget').textContent=`送信試行：検索 ${data.search_calls} / read ${data.read_calls}`;
        // Show committed results when ready, without interrupting selection/editing.
        if((data.done>lastDone || !['queued','running'].includes(data.status)) && !edited && !document.querySelector('input[type=checkbox]:checked') && !document.querySelector('input[name=purpose]:focus')) location.reload();
    } catch(_) { document.getElementById('worker-hint').textContent='進捗を取得できません。接続を確認して画面を更新してください。'; }
},2500);
</script>
@endif
@endsection
