@extends('layout')
@section('title','変化を見る')
@section('content')
@php use App\Support\Presentation as P; @endphp
<h1>変化を見る</h1>
<p>取得した全動画を継続観測します。0再生・初回待ちも残ります。お気に入りは追跡の条件ではありません。</p>
<section class="panel"><h2>観測状況</h2><p>全取得ユニーク {{ $counts['total'] }}本 ／ 現在比較可能 {{ $counts['comparable'] }}本 ／ 観測待ち・参考区間 {{ $counts['reference'] }}本</p>
<p>関連チャンネル {{ $counts['channels'] }}件 ／ 初回待ち {{ $counts['pending'] }}本 ／ 更新遅延 {{ $counts['late'] }}本 ／ 取得失敗 {{ $counts['failed'] }}本</p><p>共有API枠：検索 {{ $budget?->search_calls ?? 0 }}/{{ config('youtube.daily_search_calls') }}・読取 {{ $budget?->read_calls ?? 0 }}/{{ config('youtube.daily_read_calls') }}。追跡を優先し、不足時は新規発見を待機します。全対象1巡のID読取概算 {{ ceil($counts['total']/50)+ceil($counts['channels']/50) }}要求（再試行・uploadsは別）。</p><p>基準時刻 {{ P::date($asOf) }}。期間が近くても同一の時間帯とは限りません。</p>
@foreach(['scheduler'=>'予定確認','worker'=>'更新worker'] as $key=>$label)
@php $beat=$heartbeats->get($key); $alive=$beat && \Carbon\CarbonImmutable::parse($beat->seen_at)->addSeconds(config('tracking.heartbeat_seconds'))>now(); @endphp
<span class="badge">{{ $label }}：{{ $alive ? '稼働確認' : '未稼働／確認期限超過' }}</span>
@endforeach
</section>
<form method="get" class="panel filters"><label>テーマ<select name="theme"><option value="">すべて</option>@foreach($themes as $theme)<option value="{{ $theme->theme_id }}" @selected(request('theme')===$theme->theme_id)>{{ $theme->label_ja }}</option>@endforeach</select></label><label>検索枠<select name="lens"><option value="">すべて</option><option value="recent_new" @selected(request('lens')==='recent_new')>新着順</option><option value="recent_views" @selected(request('lens')==='recent_views')>再生数順</option></select></label><label>区分<select name="status"><option value="">すべて</option><option value="comparable" @selected(request('status')==='comparable')>現在比較可能</option><option value="reference" @selected(request('status')==='reference')>観測待ち・更新遅延・参考区間</option></select></label><label>並び順<select name="sort">@foreach(['delta'=>'現在の増分（比較可能群）','newest'=>'新着','popular'=>'累計再生数'] as $v=>$label)<option value="{{ $v }}" @selected(request('sort','delta')===$v)>{{ $label }}</option>@endforeach</select></label><button>条件を適用</button></form>
<p>絞込 {{ $videos->total() }}本 ／ このページ {{ $videos->count() }}本</p>
<div class="tracking-list">@if($videos->isEmpty())<p>この条件では対象がありません。<a href="{{ route('discovery') }}">語を入力せず探索を始める</a>、または絞込を解除してください。</p>@endif
@foreach($videos as $target) @php $v=$target->video; $i=$summary[$target->id]; @endphp
<article class="panel tracking-row" data-target="{{ $target->id }}">
<label class="check"><input type="checkbox" data-tray-id="{{ $target->id }}">比較に選ぶ</label>
@if($v?->thumbnail_url)<img loading="lazy" class="tracking-thumb" src="{{ $v->thumbnail_url }}" alt="{{ $v->title }}" referrerpolicy="no-referrer">@else<span class="no-image">画像未取得</span>@endif
<h2>{{ $v?->title ?? '公開情報は失効／未取得' }}</h2><p>{{ $v?->channel_title ?? 'チャンネル名未取得' }} · {{ P::duration($v?->duration_seconds) }} · 公開 {{ P::date($v?->published_at) }}</p>
<dl><dt>現在再生数</dt><dd>{{ P::number($v?->view_count) }}</dd><dt>実観測増分</dt><dd>{{ P::number($i['delta_views']) }} ／ {{ round(($i['elapsed_seconds'] ?? 0)/3600,2) }}時間</dd><dt>比較区分</dt><dd>{{ $i['eligible'] ? '現在比較可能' : '観測待ち／更新遅延・参考区間' }} <small>{{ $i['status'] }}</small></dd><dt>両端UTC</dt><dd>{{ $i['start_at'] ?? '初回のみ' }} → {{ $i['end_at'] ?? '未観測' }}</dd><dt>次回予定</dt><dd>{{ P::date($target->tracking->next_due_at) }} {{ $target->tracking->reason ? '（'.P::error($target->tracking->reason).'）' : '' }}</dd></dl>
<details><summary>追跡の停止・再開</summary><form method="post" action="{{ route('tracking.state',$target->id) }}">@csrf<label>本人による対象区分（API認可とは別）<select name="ownership">@foreach(['unknown'=>'未確認','competitor'=>'競合','own'=>'本人の動画'] as $v=>$label)<option value="{{ $v }}" @selected($target->ownership===$v)>{{ $label }}</option>@endforeach</select></label><label>追跡<select name="state"><option value="active">継続</option><option value="paused" @selected($target->tracking->state==='paused')>明示停止</option></select></label><button>追跡状態を保存</button></form></details>
</article>
@endforeach
</div>
<nav aria-label="変化一覧のページ">@if($videos->previousPageUrl())<a href="{{ $videos->previousPageUrl() }}">前のページ</a>@endif <span>{{ $videos->currentPage() }} / {{ $videos->lastPage() }}</span> @if($videos->nextPageUrl())<a href="{{ $videos->nextPageUrl() }}">次のページ</a>@endif</nav>
@endsection
