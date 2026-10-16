@extends('layout')
@section('title','試作下書き')
@section('content')
<h1>試作下書き・手動振り返り</h1><p>参照：<a href="{{ route('comparisons.show',$comparison->id) }}">比較 #{{ $comparison->id }}</a> ／ 分析版 {{ $brief?->analysis_revision ?? $comparison->analysis_revision }} @if(!$comparison->evidence)（根拠失効）@endif</p>
<p>参照した仮説：{{ $analysis?->content['hypothesis'] ?? '未確認／根拠失効' }}（比較の分析を後で変更しても、この下書きの参照版は変わりません）</p><p>自分の企画だけを記入します。参考要素と反例の欄は根拠失効時に削除されます。APIの数値や本文を転記して長期保存しません。制作・投稿は手動です。</p>
<form class="panel guide" data-draft="brief-{{ $brief?->id ?? 'new-'.$comparison->id }}-{{ $brief?->revision ?? 0 }}" action="{{ route('briefs.save') }}" method="post">@csrf
<input type="hidden" name="comparison" value="{{ $comparison->id }}"><input type="hidden" name="brief_id" value="{{ $brief?->id }}"><input type="hidden" name="revision" value="{{ $brief?->revision ?? 0 }}">
@foreach(['audience'=>'想定する人（仮説）','use'=>'使う場面・提供する体験','reference'=>'参考にする要素（根拠は比較への参照）','avoid'=>'模倣しない部分・反例','one_change'=>'次に変える一要素','music'=>'音楽・冒頭・曲間の要件','image'=>'画像で伝える体験','title'=>'タイトル案と音楽との整合','budget'=>'試作費用・試行数の上限','hours'=>'作業時間の上限','permission'=>'許諾：サービス/プラン/確認日/根拠/未確認','validation'=>'検証方法と見直し条件'] as $key=>$label)
<label>{{ $label }}<textarea name="content[{{ $key }}]" maxlength="4000">{{ old('content.'.$key,$brief?->content[$key] ?? '') }}</textarea></label>@endforeach
<h2>自作動画の振り返り（手入力・不明可）</h2>
@foreach(['own_video'=>'本人動画の参照（本人宣言とAPI認可は別）','source'=>'出所（YouTube Studio等。競合の公開値と混ぜない）','period'=>'測定期間','unit'=>'単位（CTR%・平均視聴時間等）','checked_at'=>'確認日時','result'=>'自分の結果・実費・作業時間','next_change'=>'次に変える一要素'] as $key=>$label)
<label>{{ $label }}<textarea name="reflection[{{ $key }}]" maxlength="4000">{{ old('reflection.'.$key,$brief?->reflection[$key] ?? '') }}</textarea></label>@endforeach
<span data-save-state>{{ $brief ? '保存済み' : '未保存' }}</span><button>試作下書きを保存</button>
</form>
@endsection
