@extends('layout')
@section('title','取得範囲・結果')
@section('content')
@php use App\Support\Presentation as P; $videos=$items->filter(fn($item)=>$item->target->kind==='video'); @endphp
<p class="eyebrow">取得実行 #{{ $run->id }}</p><h1>取得範囲と結果</h1><p><a href="{{ route('discovery') }}">候補一覧へ戻る</a></p>
<section class="panel"><h2>{{ P::status($run->status) }}</h2>@if($run->error_code)<p class="banner error">{{ P::error($run->error_code) }}</p>@endif
<dl><dt>取得種別</dt><dd>{{ $run->kind }}</dd><dt>開始日時</dt><dd>{{ P::date($run->started_at) }}</dd><dt>今回処理終了</dt><dd>{{ P::date($run->finished_at) }}</dd><dt>取得条件</dt><dd><pre>{{ json_encode(collect($run->conditions)->except(['scans'])->all(),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }}</pre></dd><dt>実取得本数（この実行内で成功）</dt><dd>{{ $videos->where('status','success')->count() }}本 / 発見したID {{ $videos->count() }}本</dd><dt>取得したページ</dt><dd>{{ $run->pages }}</dd><dt>追加取得</dt><dd>@if($run->kind==='watch')ウォッチチャンネルの最近の投稿を上限付きで確認。全投稿ではありません。@elseif($run->next_page_token)続きあり・部分取得（追加取得できます）@elseif($run->listing_done)APIの続きトークンなし。公開され取得可能な応答範囲です。@else取得範囲は未確認@endif</dd></dl>
<p class="hint">すべての件数はこの取得実行の範囲です。チャンネルの全動画・最初の投稿やジャンルの市場規模は保証しません。数値の取得時刻は候補一覧で各対象に表示します。</p>
@if($run->kind==='watch')@foreach($run->conditions['scans']??[] as $channelId=>$scan)<p>チャンネル対象 #{{ $channelId }}：投稿一覧 {{ $scan['items'] }}件 @if($scan['has_more'])・続きあり（部分取得）@else・続きトークンなし@endif</p>@endforeach
@endif
@if($run->next_page_token || !$run->listing_done || $run->status!=='complete')<form action="{{ route('runs.resume',$run->id) }}" method="post">@csrf<label>今回の追加取得上限<input type="number" name="limit" value="{{ config('youtube.initial_limit') }}" min="1" max="{{ config('youtube.max_items') }}" required></label><button>未取得・失敗分を再試行して続きを取得</button></form>@endif
@if($run->kind==='search')<p><a href="{{ route('discovery',collect($run->conditions)->only(['q','order','duration','published_after','published_before','limit'])->all()) }}">この検索条件を再利用する</a></p>@endif
</section>
<section class="panel"><h2>成功・失敗・未取得</h2><p>成功 {{ $items->where('status','success')->count() }} / 失敗 {{ $items->where('status','failed')->count() }} / 返却なし {{ $items->where('status','unavailable')->count() }} / 未取得 {{ $items->where('status','pending')->count() }}（チャンネルを含む）</p><div class="table-scroll"><table><thead><tr><th>対象</th><th>種類</th><th>状態</th><th>理由</th></tr></thead><tbody>@foreach($items as $item)<tr><td>{{ $item->target->video?->title ?? $item->target->channel?->title ?? $item->target->external_id }}</td><td>{{ $item->target->kind }}</td><td>{{ P::status($item->status) }}</td><td>{{ P::error($item->error_code) }}</td></tr>@endforeach</tbody></table></div></section>
@endsection
