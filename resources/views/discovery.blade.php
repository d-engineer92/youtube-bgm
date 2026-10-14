@extends('layout')
@section('content')
@php use App\Support\Presentation as P; $channelMap = $channels->keyBy('external_id'); @endphp
<div class="intro"><p class="eyebrow">画面A / DISCOVER & WATCH</p><h1>探す・追う</h1><p>用途や雰囲気で候補を探し、取得できた範囲から比較する動画を選びます。</p></div>
<section class="panel exploration-entry">
    <p class="eyebrow">言葉が決まっていなくても、ここから</p>
    <h2>どんなBGMを作るか、まだ決まっていなくても大丈夫です。</h2>
    <p>用途・雰囲気・音楽の方向を横断して、候補を探します。検索語・ジャンル・用途の入力は不要です。</p>
    <form action="{{ route('explorations.start') }}" method="post">@csrf
        <input type="hidden" name="token" value="{{ $startToken }}">
        <label>調査プリセット<select name="preset" id="exploration-preset">@foreach($catalog['presets'] as $id=>$preset)<option value="{{ $id }}">{{ $preset['label_ja'] }}（{{ $preset['region_code'] }}/{{ $preset['relevance_language'] }}）</option>@endforeach</select></label>
        <p>8テーマ・最近30日・20分超。16検索ページ／最大160検索ヒット（重複を含む）。詳細取得・再試行は別途呼出が必要です。</p>
        <button id="exploration-start" @disabled(config('youtube.mode')==='live' && !config('youtube.key'))>{{ config('youtube.mode')==='demo' ? '架空データで操作を試す' : '英語圏のBGMを広く調べる' }}</button>
    </form>
    @if(config('youtube.mode')==='live' && !config('youtube.key'))
        <p class="banner">APIキー未設定。サーバーの.envに YOUTUBE_API_KEY を設定してWebとワーカーを再起動してください。</p>
        <form action="{{ route('research.mode') }}" method="post">@csrf<input type="hidden" name="mode" value="demo"><button class="secondary">架空データで操作を試す</button></form>
    @elseif(session('research_mode')==='demo')
        <form action="{{ route('research.mode') }}" method="post">@csrf<input type="hidden" name="mode" value="configured"><button class="secondary">環境設定のモードへ戻る</button></form>
    @endif
    <p class="hint">USは視聴可能地域、enは関連言語です。実際の視聴者国・RPMは未確認。カタログは需要未検証の検索仮説で、動画のジャンル判定ではありません。</p>
    @if($explorations->isNotEmpty())<p><a href="{{ route('explorations.show',$explorations->first()->id) }}">前回の結果を見る</a></p><details><summary>これまでの探索</summary><ul>@foreach($explorations as $e)<li><a href="{{ route('explorations.show',$e->id) }}">{{ $e->preset_id }} / {{ P::date($e->t0) }} / {{ P::status($e->status) }}</a></li>@endforeach</ul></details>@endif
</section>
<details class="manual-entry" open><summary>キーワード・URLを指定して探す（既存機能）</summary>
<div class="two-column">
<section class="panel">
    <h2>キーワードで候補を探す</h2>
    <p class="hint">検索例：「読書 ピアノ 雨」「作業用 アンビエント 夜」。需要が確認されたジャンルを意味する例ではありません。</p>
    <form action="{{ route('search') }}" method="post">@csrf
        <label>検索キーワード<input name="q" required maxlength="200" value="{{ old('q', request('q')) }}" placeholder="読書 ピアノ 雨"></label>
        <div class="fields"><label>公開日：開始<input type="date" name="published_after" value="{{ old('published_after', request('published_after')) }}"></label><label>公開日：終了（当日含む）<input type="date" name="published_before" value="{{ old('published_before', request('published_before')) }}"></label></div>
        <div class="fields"><label>APIの並び順<select name="order">@foreach(['relevance'=>'関連順','date'=>'新着順','viewCount'=>'再生数順'] as $v=>$label)<option value="{{ $v }}" @selected(old('order',request('order','relevance'))===$v)>{{ $label }}</option>@endforeach</select></label><label>尺の条件<select name="duration">@foreach(['any'=>'すべて','short'=>'4分未満','medium'=>'4〜20分','long'=>'20分超'] as $v=>$label)<option value="{{ $v }}" @selected(old('duration',request('duration','any'))===$v)>{{ $label }}</option>@endforeach</select></label></div>
        <label>今回取得する件数上限<input type="number" name="limit" min="1" max="{{ config('youtube.max_items') }}" value="{{ old('limit', request('limit',config('youtube.initial_limit'))) }}" required></label>
        <button>候補を取得する</button>
    </form>
</section>
<section class="panel">
    <h2>ID・URLから直接登録</h2><p class="hint">チャンネルID（UCから始まる24文字）、動画ID、httpsのYouTube動画URLに対応します。</p>
    <form action="{{ route('register') }}" method="post">@csrf
        <label>チャンネルID・動画ID・動画URL<input name="reference" required maxlength="500" value="{{ old('reference') }}" placeholder="https://www.youtube.com/watch?v=..."></label>
        <label>投稿一覧の取得上限<input type="number" name="limit" min="1" max="{{ config('youtube.max_items') }}" value="{{ config('youtube.initial_limit') }}" required></label><button>直接登録する</button>
    </form>
    @if(config('youtube.mode')==='demo')<p class="hint">デモの入力例：<code>demoVideo01</code>、<code>UCDEMO000000000000000001</code></p>@endif
    <div class="divider"><h3>ウォッチを更新</h3><p class="hint">選択した動画・チャンネルを更新します。検索は繰り返しません。停止中の観測は補完しません。</p><form action="{{ route('refresh') }}" method="post">@csrf<button class="secondary">ウォッチ対象を手動更新</button></form></div>
</section>
</div>
</details>
<section class="panel">
    <h2>取得済みの動画 <span class="badge">{{ $videos->total() }}本</span></h2>
    <p class="hint">並び替え・絞り込みの対象は常に取得済み範囲です。「全動画」や「最初の投稿」を意味しません。少ない再生数の動画も比較できます。</p>
    <form method="get" class="filters">
        <label>取得済み範囲の並び順<select name="sort">@foreach(['newest'=>'新着順','oldest'=>'古い順','popular'=>'再生数順'] as $v=>$label)<option value="{{ $v }}" @selected(request('sort','newest')===$v)>{{ $label }}</option>@endforeach</select></label>
        <label>チャンネル<select name="channel"><option value="">すべて</option>@foreach($channels as $c)<option value="{{ $c->external_id }}" @selected(request('channel')===$c->external_id)>{{ $c->channel?->title ?? $c->external_id }}</option>@endforeach</select></label>
        <label>尺：最小（分）<input type="number" name="min_minutes" min="0" step="any" value="{{ request('min_minutes') }}"></label><label>最大（分）<input type="number" name="max_minutes" min="0" step="any" value="{{ request('max_minutes') }}"></label>
        <label>公開経過：最小（日）<input type="number" name="min_days" min="0" step="any" value="{{ request('min_days') }}"></label><label>最大（日）<input type="number" name="max_days" min="0" step="any" value="{{ request('max_days') }}"></label>
        <label class="check"><input type="checkbox" name="watch_only" value="1" @checked(request('watch_only'))>動画ウォッチのみ</label><button class="secondary">条件を適用</button><a href="{{ route('discovery') }}">解除</a>
    </form>
    <form id="compare-form" action="{{ route('compare') }}" method="get"></form>
    <div class="action-row"><span>動画を2〜6本選択</span><button form="compare-form">選んだ動画を並べる</button></div>
    <div class="cards">
    @forelse($videos as $video)
        @php $target=$video->target; $channel=$channelMap->get($video->channel_external_id)?->channel; $delta=P::delta($target); @endphp
        <article class="card">
            <label class="check"><input form="compare-form" type="checkbox" name="ids[]" value="{{ $target->id }}">比較に選ぶ</label>
            @if($video->thumbnail_url)<img loading="lazy" src="{{ $video->thumbnail_url }}" alt="{{ $video->title }}" referrerpolicy="no-referrer">@else<div class="no-image">サムネイル未取得</div>@endif
            <h3><a href="https://www.youtube.com/watch?v={{ $target->external_id }}" target="_blank" rel="noopener noreferrer">{{ $video->title }}</a></h3>
            <p>{{ $channel?->title ?? 'チャンネル名未取得' }}</p><span class="badge">{{ P::status($target->last_status) }}</span>
            <dl><dt>公開日時</dt><dd>{{ P::date($video->published_at) }}</dd><dt>動画尺</dt><dd>{{ P::duration($video->duration_seconds) }}</dd><dt>公開再生数</dt><dd class="number">{{ P::number($video->view_count) }}</dd><dt>公開登録者数</dt><dd>{{ P::number($channel?->subscriber_count) }} <small>（丸めあり）</small></dd><dt>最終成功取得</dt><dd>{{ P::date($video->fetched_at) }}</dd><dt>単純計算：再生数差分</dt><dd>{{ $delta['label'] }}<small>{{ $delta['interval'] }} {{ $delta['note'] }}</small></dd></dl>
            <div class="own"><strong>自分の制作候補・調査メモ</strong><p class="hint">YouTube公式データとは別の記録。APIの数値・本文の転記先には使わず、参考にする目的を書きます。</p><form action="{{ route('watch',$target->id) }}" method="post">@csrf<label>ウォッチ設定<select name="watched"><option value="0" @selected(!$target->watched)>ウォッチしない</option><option value="1" @selected($target->watched)>ウォッチする</option></select></label><label>参考にする目的<input name="purpose" maxlength="2000" value="{{ $target->purpose }}" placeholder="例：同じ尺で結果の異なる動画を聴き比べる"></label><button class="secondary">設定と目的を保存</button></form></div>
        </article>
    @empty<p>取得済み動画はありません。検索または直接登録から始めてください。</p>@endforelse
    </div>
    <nav aria-label="取得済み一覧のページ">@if($videos->previousPageUrl())<a href="{{ $videos->previousPageUrl() }}">前のページ</a>@endif <span>{{ $videos->currentPage() }} / {{ $videos->lastPage() }}ページ</span> @if($videos->nextPageUrl())<a href="{{ $videos->nextPageUrl() }}">次のページ</a>@endif</nav>
</section>
<section class="panel"><h2>チャンネルをウォッチ</h2><p class="hint">チャンネルの公開日時は最初の動画投稿日とは異なります。公開登録者数には丸めがあります。</p><div class="table-scroll"><table><thead><tr><th>チャンネル</th><th>公開登録者数</th><th>累計公開再生数</th><th>公開日時・最終取得</th><th>自分の目的・ウォッチ設定</th></tr></thead><tbody>@foreach($channels as $target)@php $c=$target->channel; @endphp<tr><td><a href="https://www.youtube.com/channel/{{ $target->external_id }}" target="_blank" rel="noopener noreferrer">{{ $c?->title ?? $target->external_id }}</a><small>{{ P::status($target->last_status) }}</small></td><td>{{ P::number($c?->subscriber_count) }}</td><td>{{ P::number($c?->view_count) }}</td><td>{{ P::date($c?->published_at) }}<small>取得 {{ P::date($c?->fetched_at) }}</small></td><td><form method="post" action="{{ route('watch',$target->id) }}">@csrf<label>自分の目的<input name="purpose" maxlength="2000" value="{{ $target->purpose }}"></label><label>ウォッチ設定<select name="watched"><option value="0" @selected(!$target->watched)>ウォッチしない</option><option value="1" @selected($target->watched)>ウォッチする</option></select></label><button class="secondary">設定と目的を保存</button></form></td></tr>@endforeach</tbody></table></div></section>
@if($watched->isNotEmpty())<section class="panel"><h2>ウォッチ中の対象</h2><ul>@foreach($watched as $target)<li>{{ $target->video?->title ?? $target->channel?->title ?? $target->external_id }} — {{ P::status($target->last_status) }} @if(!$target->video && !$target->channel)（公開情報は未取得または期限切れ）@endif</li>@endforeach</ul></section>@endif
<section class="panel"><h2>最近の取得実行</h2><ul>@forelse($runs as $run)<li><a href="{{ route('runs.show',$run->id) }}">#{{ $run->id }} {{ $run->kind }} — {{ P::status($run->status) }}</a> {{ P::date($run->started_at) }}</li>@empty<li>取得実行はまだありません。</li>@endforelse</ul></section>
@if($comparisons->isNotEmpty())<section class="panel"><h2>保存した比較目的</h2><ul>@foreach($comparisons as $c)<li><a href="{{ route('comparisons.show',$c->id) }}">{{ $c->purpose }}</a></li>@endforeach</ul></section>@endif
@endsection
@section('scripts')
<script>
document.getElementById('exploration-preset').addEventListener('change', event => {
    const button=document.getElementById('exploration-start');
    if (!{{ config('youtube.mode')==='demo' ? 'true' : 'false' }}) button.textContent=event.target.value==='ja-JP'?'日本向けのBGMを広く調べる':'英語圏のBGMを広く調べる';
});
</script>
@endsection
