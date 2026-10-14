# 根拠・確認日・設計判断

> 文書ID：EXP-007\
> 状態：公開仕様の確認と今回の設計判断を分離／2026-10-14\
> 読む場面：外部仕様の再確認、実装レビュー、カタログ更新。

## ユーザー指定と提供テキスト

利用者は、キーワードを熟知しないと入口を使えないことを指摘した。英語圏も視野に、調査から言葉と用途を理解したい。追加費用と開発量を抑え、Codex実装、PostgreSQL専用、責務別docsという方針を維持する。

提供資料はテキストとして読んだ。会員限定URLへアクセスしていない。本文は公開リポジトリへ転載しない。

| 資料内の箇所 | 引き継ぐ観点 | 一般的事実と断定しない内容 |
|---|---|---|
| AI×BGM.txt L1113–1124 | 検索前にAIから検索語候補を得る | 想定属性に合うと言っただけで需要があるという結論 |
| 同 L407–420・L851–864 | 最近の例と長く再生される例の両方を見る | 累計だけで現在の需要を証明すること、特定の月収の保証 |
| 同 L425–448 | 米国を中心とした英語圏を企画の仮説にする | 米国の単価が必ず最高という著者の主張 |
| 同 L2657–2668・L2718–2733 | 見つけた後、理由と次の制作への活かし方まで考える | 成功例の外観を真似れば同じ結果が出るという保証 |
| YouTube Data API.txt L146–156・L709–717 | 地域と言語の違い、Laravel/公式クライアント/WSL/Docker/APIキー | 古いクォータ記載を現行の値として固定すること |
| YouTube×BGM.txt L1–14・L36–58 | 計算式先行ではなく、仮説と検証を分ける | 閾値や比率を万能のバズ判定にすること |

## 今回確認した公式資料

以下は2026-10-14に公開本文を確認した。実API疎通をした意味ではない。実装時に適用先のCloud Consoleと採用ライブラリ版も確認する。

- [search.list](https://developers.google.com/youtube/v3/docs/search/list)：qは省略可能だが、今回の未入力開始はサーバーが語を供給する方式。regionCodeは視聴可能地域、relevanceLanguageは関連言語。他言語が返る場合もある。maxResultsは最大50、longは20分超。totalResultsは概数で検索人数ではない。
- [クォータ](https://developers.google.com/youtube/v3/determine_quota_cost)：本文ではsearch.list専用100回/日、他のAPIは別枠。ページ・試行を計上し、PT午前0時に日次リセット。アプリの20検索/日とは別。ページ要約と本文に差がある場合、本文・実プロジェクトの割当を確認する。
- [Developer Policies Guide](https://developers.google.com/youtube/terms/developer-policies-guide)：単純集計の例と、独自スコア・視聴者属性推定・内容分類等の制約。検索テーマは検索条件の整理として扱い、動画の内容を認定するラベルにしない。追加の派生分析は今回導入しない。
- [Developer Policies](https://developers.google.com/youtube/terms/developer-policies)：通常の非認可APIデータの保持・更新/削除。今回の28日実装を維持。追加許諾なしの長期保存や、外部スクレイピングでの置換は行わない。
- [Laravel queues](https://laravel.com/framework/docs/13.x/queues)：DBキューとワーカーの公式機構を利用候補とした根拠。実装ではリポジトリのcomposer.lockが採用する版の仕様も確認し、timeout/retry_after/ジョブ再実行の条件を合わせる。

## リポジトリ確認

main=`f85440c35ffe6c65f2d83caf49a32b27f89ceb86`の資料入口・AGENTS・DB方針・既存の手動検索実装を確認した。直前に確認された画面/コントローラーではq必須。Collectorの検索条件には地域・関連言語なし。ApiCallerには共通日次台帳あり、configの既定は検索20/read500。これらを再利用・拡張する仕様である。

## 今回の設計値と未確認事項

8テーマ、16検索ページ、10件/ページ、30日、180日、5年、初期US/en、run上限20検索/100read、候補最大8件、24hの日次参考表示は今回の提案設計値。流行の実測・広告単価・最適な検索量・母集団の必要数を示さない。

実APIでの候補の質、条件別の重複率、辞書の網羅性、継続観測の結果、視聴者国、RPM、収益性は未確認。カタログは実APIの調査結果ではない。語の追加、期間や尺の変更は結果を見て版管理しながら行う。

[入口へ](README.md)

## EXP実装時の再確認（2026-10-14）

search.list、Developer Policies、採用版Laravel 13のQueuesの公式本文を再確認した。composer.lockのLaravel v13.36.0と公式クライアントを使用。DBキューの実挙動・timeout/retry_after/lease・強制停止後の再配信は専用PostgreSQLで確認した。Cloud Consoleの割当と実APIキーは未提供。[検証記録](verification.md)を参照。
