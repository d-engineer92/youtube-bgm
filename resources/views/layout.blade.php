<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', '探す・追う') · YouTube BGM Research</title>
    <link rel="stylesheet" href="{{ asset('research.css') }}">
</head>
<body>
<header><div class="wrap"><a class="brand" href="{{ route('discovery') }}">BGM Research</a><span>公開情報から、次に確かめることを見つける</span></div></header>
<nav class="wrap"><a href="{{ route('tracking') }}">変化を見る</a> · <a href="{{ route('discovery') }}">探す</a> · <a href="{{ route('policy') }}">利用・保存の説明</a></nav><main class="wrap">
    @if(config('youtube.mode') === 'demo')
        <div class="banner demo"><strong>架空データのデモ</strong> — タイトル・ID・数値はすべて架空です。実API通信は行いません。検索語にかかわらず固定候補を使います。</div>
    @else
        <div class="banner"><strong>実APIモード</strong> — YouTube Data APIの公開情報。@if(!config('youtube.key'))<strong>APIキー未設定・実データ取得未実施</strong>@endif</div>
    @endif
    @if(session('notice'))<div class="banner">{{ session('notice') }}</div>@endif
    @if($errors->any())<div class="banner error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @yield('content')
    <footer>公開数値は仮説づくりの参考です。収益化・需要・成功率は判断しません。時刻はJST（UTC欄・Z表記はUTC）。<br>期限切れの公開データは閲覧前に削除します。<a href="https://www.youtube.com/" target="_blank" rel="noopener noreferrer">YouTube</a>で視聴・コメントを確認できます。</footer>
</main>
@include('selection-tray')
@yield('scripts')
</body>
</html>
