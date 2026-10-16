@extends('layout')
@section('title','並べて比較する')
@section('content')
@php use App\Support\Presentation as P; $subjectMap=collect($pack['subjects'] ?? [])->keyBy('target_id'); @endphp
<h1>公開情報を並べる</h1><p><a id="return-to-selection" href="{{ route('tracking') }}">選択した一覧へ戻る</a> · <a href="{{ route('discovery') }}">候補一覧へ戻る</a></p>
@if($origin)<a href="{{ route('explorations.show',$origin->id) }}">元のテーマ別結果へ戻る</a>@endif
<p class="banner">取得済み範囲の{{ $targets->count() }}本。公開後の経過・尺・観測間隔を確かめます。公開再生数の変化だけで原因は確定できません。</p>
@if($saved)<p>固定した過去比較 #{{ $saved->id }} / revision {{ $saved->revision }} ／ {{ P::date($saved->as_of) }} <strong>{{ $pack ? '保存時点の判定（現在の順位とは別）' : '根拠失効' }}</strong></p><p>{{ ['same_channel'=>'同一チャンネル内比較','cross_channel'=>'複数チャンネル間比較','unknown'=>'チャンネル関係未確認'][$saved->comparison_type] }} · {{ $saved->purpose }}</p><a href="{{ route('compare',['ids'=>$targets->pluck('id')->all()]) }}">最新値で新しい比較を作る</a>
@if($pack)<p><a href="{{ route('tracking.export',$saved->id) }}">期限付きJSON</a> · <a href="{{ route('tracking.export',['id'=>$saved->id,'format'=>'csv']) }}">期限付きCSV</a>（外部AI送信は無効）</p>@endif
@else
<form method="get" action="{{ route('compare') }}">@foreach($targets as $target)<input type="hidden" name="ids[]" value="{{ $target->id }}">@endforeach<label>比較期間<select name="period_hours"><option value="24" @selected(request('period_hours',24)==24)>約24時間</option><option value="168" @selected(request('period_hours')==168)>約7日（両端がある場合だけ）</option></select></label><button>期間を変えて見る</button></form>
<form class="panel" data-draft="compare-{{ $targets->pluck('id')->join('-') }}" action="{{ route('comparisons.store') }}" method="post">@csrf
@foreach($targets as $target)<input type="hidden" name="ids[]" value="{{ $target->id }}">@endforeach
@if($origin)<input type="hidden" name="exploration" value="{{ $origin->id }}">@endif
<input type="hidden" name="comparison_type" value="same_channel"><input type="hidden" name="period_hours" value="{{ request('period_hours',24) }}">
<label>何を比べるか<input name="purpose" required maxlength="2000" placeholder="例：同じ尺で増えた例と増えなかった例を比べる"></label><span data-save-state>未保存</span><button>比較対象と目的を保存する</button></form>
@endif
<p>要求した比較期間：{{ $pack['requested_period_hours'] ?? '失効' }}時間。実際の区間は各対象の両端を確認してください。</p><div class="compare-table">
@foreach($targets->chunk(2) as $pair)
<section class="panel"><h2>二対象の主要項目を見比べる</h2>
@foreach(['title'=>'タイトル','ownership'=>'本人による対象区分','image'=>'サムネイル（現在表示と過去証跡は別）','views'=>'動画の公開再生数','likes'=>'動画の高評価・コメント数','channel'=>'関連チャンネルの公開数値（丸めあり）','delta'=>'実観測差分と区間（UTC）','status'=>'比較条件','published'=>'公開日時・公開後の経過','duration'=>'動画尺'] as $field=>$label)
<h3>{{ $label }}</h3><div class="compare-pair">
@foreach($pair as $target) @php $v=$target->video; $subject=$subjectMap->get($target->id); $i=$subject['interval'] ?? null; $last=collect($subject['observations'] ?? [])->firstWhere('observation_id',$i['end_id'] ?? null) ?? collect($subject['observations'] ?? [])->first(); $meta=collect($pack['metadata_versions'] ?? [])->firstWhere('id',$last['metadata_version_id'] ?? null); @endphp
<div class="comparison-value" data-field="{{ $field }}">
@if($field==='title'){{ $meta['content']['title'] ?? ($saved ? '根拠失効／未観測' : $v?->title) }}<small>メタデータ取得UTC {{ $meta['fetched_at'] ?? '未確認' }}</small>
@elseif($field==='ownership'){{ ['own'=>'本人動画（申告）','competitor'=>'競合（申告）','unknown'=>'未確認'][$subject['ownership'] ?? 'unknown'] }}
@elseif($field==='image')@if(!$saved && $v?->thumbnail_url)<img src="{{ $v->thumbnail_url }}" alt="{{ $v->title }}" referrerpolicy="no-referrer">@else 未観察（過去の画像本体は保存していません） @endif
@elseif($field==='views'){{ P::number($last['view_count'] ?? null) }}
@elseif($field==='likes')高評価 {{ P::number($last['like_count'] ?? null) }} ／ コメント {{ P::number($last['comment_count'] ?? null) }}
@elseif($field==='channel')@php $ch=collect($pack['channels'] ?? [])->firstWhere('channel_id',$subject['channel_id'] ?? null); $co=collect($ch['observations'] ?? [])->first(); @endphp
登録者 {{ P::number($co['subscriber_count'] ?? null) }} ／ 総再生 {{ P::number($co['view_count'] ?? null) }} ／ 公開動画数 {{ P::number($co['video_count'] ?? null) }}<small>取得UTC {{ $co['observed_at'] ?? '未確認' }}。動画の観測時刻とは別。チャンネル全形式の値であり、収集BGMの合計や新規投稿数ではありません。</small>
@elseif($field==='delta'){{ P::number($i['delta_views'] ?? null) }} ／ {{ round(($i['elapsed_seconds'] ?? 0)/3600,2) }}時間 <small>{{ $i['start_at'] ?? '初回のみ' }} → {{ $i['end_at'] ?? '未観測' }}</small>
@elseif($field==='status'){{ ($i['eligible'] ?? false) ? '比較可能な指定期間の区間' : '更新遅延／参考区間・未観測' }} <small>{{ $i['status'] ?? 'evidence_expired' }}</small>
@elseif($field==='published'){{ $meta['content']['publishedAt'] ?? '未確認' }}
@elseif($field==='duration'){{ $meta['content']['duration'] ?? '未確認' }}
@endif
</div>@endforeach
</div>@endforeach
</section>@endforeach
</div>
<details><summary>対象と履歴の詳細・YouTubeで確認</summary><div class="compare-grid">@foreach($targets as $target)<article class="panel"><h2>{{ $target->video?->title ?? '公開情報は期限切れ／未取得' }}</h2><a href="https://www.youtube.com/watch?v={{ $target->external_id }}" target="_blank" rel="noopener noreferrer">YouTubeで視聴・コメントを確認 ↗</a><p>公開高評価数・公開コメント数は観測JSONにNULLと区別して記録します。</p>@php $plot=collect($subjectMap->get($target->id)['observations'] ?? [])->sortBy('observed_at')->filter(fn($p)=>$p['view_count']!==null)->values(); $max=max(1,$plot->max('view_count') ?? 1); $tMin=$plot->isNotEmpty()?strtotime($plot->first()['observed_at']):0; $tSpan=$plot->isNotEmpty()?max(1,strtotime($plot->last()['observed_at'])-$tMin):1; @endphp
@if($plot->count()>0)<figure><figcaption>期限内の実観測（欠測区間は線で補いません）</figcaption><svg role="img" aria-label="再生数の実観測推移" viewBox="0 0 300 100" style="width:100%">
@foreach($plot as $n=>$p)@php $x=10+280*(strtotime($p['observed_at'])-$tMin)/$tSpan; $y=90-80*$p['view_count']/$max; $prev=$n ? $plot[$n-1] : null; $gap=$prev ? strtotime($p['observed_at'])-strtotime($prev['observed_at']) : 0; @endphp
@if($prev && $gap>=22*3600 && $gap<=26*3600)<line x1="{{ 10+280*(strtotime($prev['observed_at'])-$tMin)/$tSpan }}" y1="{{ 90-80*$prev['view_count']/$max }}" x2="{{ $x }}" y2="{{ $y }}" stroke="#235e46"/>@endif
<circle cx="{{ $x }}" cy="{{ $y }}" r="3" fill="#235e46"><title>{{ $p['observed_at'] }}: {{ $p['view_count'] }}</title></circle>@endforeach</svg></figure>@endif
<div class="table-scroll"><table><thead><tr><th>取得UTC</th><th>再生数</th></tr></thead><tbody>@foreach(($subjectMap->get($target->id)['observations'] ?? []) as $o)<tr><td>{{ $o['observed_at'] }}</td><td>{{ P::number($o['view_count']) }}</td></tr>@endforeach</tbody></table></div></article>@endforeach</div></details>
@if($saved && $pack)
<section class="panel guide"><h2>違いから仮説を考える</h2><p>事実、仮説、反例、次の確認を分けます。専門語は不要。不明のまま途中保存できます。AIは利用しません。</p>
<form data-expires="{{ $saved?->evidence_expires_at?->toIso8601ZuluString() }}" data-draft="analysis-{{ $saved->id }}-{{ $saved->analysis_revision }}" action="{{ route('analysis.save',$saved->id) }}" method="post">@csrf<input type="hidden" name="revision" value="{{ $saved->analysis_revision }}">
@foreach(['facts'=>['確認した変化','例：一方は増分500、他方は2。根拠はこの固定比較の観測ID。'],'hypothesis'=>['考えられる理由','例：読書向けの約束が伝わったのかもしれない。原因は未確定。'],'counterexample'=>['反例・別の説明','例：似た画像でも増えていない。公開時刻や外部流入の違いでは？'],'unknown'=>['未確認','例：競合CTR・視聴者国は不明。'],'next_check'=>['次に確認・変える一要素','例：音楽は保ち、タイトルで伝える用途だけを変える。'],'observed_audio'=>['冒頭・全体の音','未試聴／楽器不明を許容。試聴した日時と区間10秒/30秒等を記す。音量計測は楽器の証明ではない。'],'observed_image'=>['画像の観察','未観察／主役・構図・文字・余白・期待体験。サムネイルと動画フレームは別。'],'observed_comments'=>['コメント','未読／無コメント／取得不可を区別。用途・要望・不満・反例を自分の言葉で。全文転載しない。']] as $key=>$guide)
<label>{{ $guide[0] }}<small>{{ $guide[1] }}</small><textarea name="{{ $key }}" maxlength="4000">{{ old($key,$saved->analysis[$key] ?? '') }}</textarea></label>@endforeach
<label>本人の確認状態<select name="state">@foreach(['draft'=>'下書き','held'=>'保留','adopted'=>'仮説として採用'] as $v=>$label)<option value="{{ $v }}" @selected($saved->analysis_state===$v)>{{ $label }}</option>@endforeach</select></label><span data-save-state>{{ $saved->analysis_revision ? '保存済み' : '未保存' }}</span><button>分析を途中保存</button></form>
@if($saved->analysis_revision)<p><a href="{{ route('briefs.new',['comparison'=>$saved->id]) }}">仮説から試作下書きへ →</a></p>@endif</section>
@endif
@endsection
