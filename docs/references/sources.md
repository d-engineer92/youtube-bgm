# 出典・確認状況・再確認が必要な事項

> 文書ID：REF-001  
> 状態：初回実装用の提案仕様（未実装）  
> 読む場面：根拠を調べるとき、外部仕様に依存する判断をするとき  
> 整理日：2026-10-14  
> 元資料：[初回実装仕様 v2](../archive/codex_research_first_mvp_v2.md) L289–L320。見出し・参照リンクを整理し、要件の内容は引き継いでいる。

## この再編での確認範囲

本書以下の出典一覧と説明は、前回のv2に記載されていたものを移した。下記の「外部で確認した公式資料（2026-10-14）」という見出しも元文書の記録であり、今回の分割作業でYouTubeの仕様・利用規約を再検証したという意味ではない。

料金、ダウンロード上限、収益化条件、APIクォータ、保存条件は、実装・契約・公開などそれらに依存する作業の前に公式情報を確認する。v1の数値を最新の確定値として採用しない。不明点は不明のまま記録する。

会員限定記事のURL先は読みに行かない。今回のリポジトリ用一式には、会員限定記事本文を含む元の3つのTXTファイルを収録していない。下記の行番号はチャットで共有された資料の参照箇所であり、リポジトリ内のファイル行番号ではない。

## 元v2の出典記録

以下の資料から、手順は取り入れ、成功要因の断定・数値閾値は分離した。会員限定URL先は参照せず、ユーザー提供本文を根拠にした。機能範囲、件数例、保存28日という運用案等は本書の提案で、記事の実証結果ではない。

### ユーザー提供資料

<a id="u1-a"></a>

- [U1-a] `AI×BGM.txt`：「【YouTube】BGMチャンネルを14日で収益化した方法」、共有本文L227–243。伸びた動画・そうでない動画、コメント、小規模と大規模の異なる観察。
<a id="u1-b"></a>

- [U1-b] 同：「100日で月100万円稼ぐまでの間にやったこと」、L277–291。冒頭、テンポ、曲調、楽器を言語化。
<a id="u1-c"></a>

- [U1-c] 同：「【AI×BGM】音楽知識ゼロから始める作業用BGMの作り方」、L1401–1459。自分の言葉で説明し、候補を聴き比べる。
<a id="u1-d"></a>

- [U1-d] 同：「【BGM×YouTube】スキル・知識ゼロ！初心者でも３週間で収益化できた方法」、L1847–1864。視聴場面、音楽と見た目の一致、コメント、比較。
<a id="u1-e"></a>

- [U1-e] 同：「【AI×BGM】育休中・産後半年でも、初めてのチャンネルを4ヶ月で収益化できた方法」、L2342–2400。比較して一つずつ変える手順。VPDで完全に公平になるという読み方は採用しない。
<a id="u1-f"></a>

- [U1-f] 同：「【YouTubeの始め方②】5本で0→1を叶えた！絶対にやるべきリサーチ②」、L2657–2733。良い点・改善点・自分への活用。
<a id="u1-g"></a>

- [U1-g] 同：「【半年で月200万円】副業のYouTube AI✕BGMで結果を出した戦略と行動を徹底紹介」、L539–566。数値は筆者自身の目安との記載。
<a id="u2"></a>

- [U2] `YouTube Data API.txt`：L407–424 公開統計の意味、L709–717 技術前提。API説明の最新性は別途公式確認が必要。
<a id="u3"></a>

- [U3] `YouTube×BGM.txt`：L35–58 閾値検証の意向、L80–97 コメント、L115–125 サムネイルと言語化からチャンネル化へ。

### 外部で確認した公式資料（2026-10-14）

<a id="w1"></a>

- [W1] Complying with YouTube's Developer Policies。単純計算の例、独自指標等の制約、追加許諾。
  `https://developers.google.com/youtube/terms/developer-policies-guide`
<a id="w2"></a>

- [W2] YouTube API Services – Developer Policies。III.E.4の非認可データ保存・更新・削除、追加許諾の扱い。
  `https://developers.google.com/youtube/terms/developer-policies`
<a id="w3"></a>

- [W3] Search: list。公開期間、並び順、ページ送り、概数のtotalResults。
  `https://developers.google.com/youtube/v3/docs/search/list`
<a id="w4"></a>

- [W4] PlaylistItems: list。投稿一覧の取得とページ送り。
  `https://developers.google.com/youtube/v3/docs/playlistItems/list`
<a id="w5"></a>

- [W5] Videos。公開動画情報のフィールド。
  `https://developers.google.com/youtube/v3/docs/videos`
<a id="w6"></a>

- [W6] YouTube Analytics and Reporting APIs。詳細レポートはチャンネル所有者等のデータを扱う別領域。
  `https://developers.google.com/youtube/analytics`

これらは設計時の参照。商用公開や分析機能の拡張前には、実装する具体的なユースケースについて最新の公式条件を再確認する。

## 今回の文書構成で追加した公式参照

<a id="o1"></a>

- [O1] OpenAI「Custom instructions with AGENTS.md」。今回確認日：2026-10-14。Codexのプロジェクト指示の入口としてAGENTS.mdを配置する根拠。YouTubeの機能・事業仮説の根拠ではない。  
  https://learn.chatgpt.com/docs/agent-configuration/agents-md
- [O2] OpenAI「Best practices」。今回確認日：2026-10-14。共通指示を簡潔にし、作業別の文書を参照する構成の参考。  
  https://learn.chatgpt.com/guides/best-practices

## 作業1実装時に再確認した公式資料（2026-10-14）

これは文書分割時の記録とは別に、今回の実装時にページ内容を読み直した記録。実API疎通やプロジェクトの実割当、商用利用の監査を確認した意味ではない。

- [Laravel 13 releases](https://laravel.com/framework/docs/13.x/releases)：PHP対応範囲を確認。実装はPHP 8.5.10で動作確認。
- [公式Google PHPクライアント](https://github.com/googleapis/google-api-php-client)：APIキーとYouTubeサービスに使用。取得したバージョンはlockファイルに固定し、公式クライアントをモックHTTPで確認。
- [Developer Policies](https://developers.google.com/youtube/terms/developer-policies)、[Policies guide](https://developers.google.com/youtube/terms/developer-policies-guide)：非認可統計の30日保存制限と単純計算の説明を確認。28日は本プロジェクトの運用判断。個別利用の包括的な適合判定は行っていない。
- [Search list](https://developers.google.com/youtube/v3/docs/search/list)、[Channels list](https://developers.google.com/youtube/v3/docs/channels/list)、[PlaylistItems list](https://developers.google.com/youtube/v3/docs/playlistItems/list)、[Videos list](https://developers.google.com/youtube/v3/docs/videos/list)：取得パラメーター・ページ送りを確認。概数totalResultsで完全性を判断しない。forHandleは公式対応を確認したが今回は実装していない。
- [Quota calculator](https://developers.google.com/youtube/v3/determine_quota_cost)：確認時の資料では検索用の独立した日次バケットを説明。固定の昔のクォータ計算を採用せず、アプリの呼出回数上限を設定可能にした。実プロジェクトの割当・他のクライアントの消費は未確認。

[文書一覧へ戻る](../README.md)
