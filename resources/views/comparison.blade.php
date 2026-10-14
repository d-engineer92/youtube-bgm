@extends('layout')
@section('title','並べて比較する')
@section('content')
@php use App\Support\Presentation as P; @endphp
<p class="eyebrow">画面B / COMPARE</p><h1>公開情報を並べる</h1><p><a href="{{ route('discovery') }}">候補一覧へ戻る</a></p>
@if($origin)<p><a href="{{ route('explorations.show',$origin->id) }}">元のテーマ別結果へ戻る</a></p>@endif
<div class="banner">取得済み範囲から選んだ {{ $targets->count() }}本の比較です。公開経過・尺が近くても、推薦量・既存ファン・外部流入・音楽などの違いは残ります。特徴が再生数の原因だとは確定できません。</div>
<section class="panel"><h2>自分の比較目的</h2>
@if($saved)<p><span class="badge">{{ $saved->comparison_type==='same_channel' ? '同一チャンネル内比較' : '複数チャンネル間比較' }}</span> {{ $saved->purpose }}</p>@else
<form action="{{ route('comparisons.store') }}" method="post">@csrf @foreach($targets as $target)<input type="hidden" name="ids[]" value="{{ $target->id }}">@endforeach
@if($origin)<input type="hidden" name="exploration" value="{{ $origin->id }}">@endif
<label>比較の種類<select name="comparison_type"><option value="same_channel">同一チャンネル内比較</option><option value="cross_channel">複数チャンネル間比較</option></select></label>
<label>何を比べるか<input name="purpose" required maxlength="2000" value="{{ $origin ? '同じ探索条件で見つかった作品について、公開経過・サムネイル・伝える用途の違いを確かめる' : '' }}" placeholder="例：同じ尺の動画を並べ、再生数が多いものと少ないものを聴き比べる"></label><button>比較対象と目的を保存する</button><p class="hint">自分の目的を保存します。公開数値・タイトルのコピーは保存せず、各表示時に期限内の情報を参照します。</p></form>@endif
<p class="hint">この段階は公開情報の比較までです。説明付きの観察質問・回答保存は作業2で追加します。動画・コメントはYouTubeで人が確認してください。</p></section>
<div class="compare-grid">
@foreach($targets as $target)@php $v=$target->video; $c=$v ? $channels->get($v->channel_external_id)?->channel : null; $delta=P::delta($target); @endphp
<article class="panel"><h2>{{ $v?->title ?? '公開情報は期限切れ／未取得' }}</h2>
@if($v)
@if($v->thumbnail_url)<img class="thumbnail" src="{{ $v->thumbnail_url }}" alt="{{ $v->title }}" referrerpolicy="no-referrer">@else<div class="no-image">サムネイル未取得</div>@endif
<p class="badge">{{ config('youtube.mode')==='demo' ? '架空の公開情報' : 'YouTubeの公開情報' }}</p>
<dl><dt>チャンネル</dt><dd>{{ $c?->title ?? '未取得／期限切れ' }}</dd><dt>公開日時</dt><dd>{{ P::date($v->published_at) }}</dd><dt>単純計算：公開後の経過</dt><dd>{{ $v->published_at ? round($v->published_at->diffInHours(now(),false),1).'時間' : '未取得' }} @if($v->published_at && $v->published_at > now()->subDay())<small>公開直後・予定公開。数値から不人気と判断しません。</small>@endif</dd><dt>動画尺</dt><dd>{{ P::duration($v->duration_seconds) }}</dd><dt>APIの配信状態</dt><dd>{{ ['none'=>'ライブ配信中／予定ではない','live'=>'ライブ配信中','upcoming'=>'予定配信','unknown'=>'未取得'][$v->broadcast_status] }}<small>{{ $v->has_live_details ? 'ライブ詳細あり（ライブ／配信アーカイブ等）' : 'ライブ詳細なし。Shorts等の形式は尺だけでは確定しません。' }}</small></dd><dt>公開再生数</dt><dd class="number">{{ P::number($v->view_count) }}</dd><dt>公開高評価数</dt><dd>{{ P::number($v->like_count) }}</dd><dt>公開コメント数</dt><dd>{{ P::number($v->comment_count) }}</dd><dt>公開登録者数</dt><dd>{{ P::number($c?->subscriber_count) }}（丸めあり）</dd><dt>動画の最終成功取得</dt><dd>{{ P::date($v->fetched_at) }}</dd><dt>チャンネルの最終成功取得</dt><dd>{{ P::date($c?->fetched_at) }}</dd><dt>最新取得の状態</dt><dd>{{ P::status($target->last_status) }}</dd><dt>単純計算：再生数差分</dt><dd>{{ $delta['label'] }}<small>{{ $delta['interval'] }} {{ $delta['note'] }}</small></dd></dl>
@else<p>期限切れの数値・本文は表示しません。IDから再登録して取得し直してください。</p>@endif
<a href="https://www.youtube.com/watch?v={{ $target->external_id }}" target="_blank" rel="noopener noreferrer">YouTubeで視聴・コメントを確認 ↗</a>
<div class="own"><strong>自分の制作候補・調査メモ</strong><p>{{ $target->purpose ?? '参考にする目的は未記入' }}</p></div>
</article>
@endforeach
</div>
@endsection
