# 監査の根拠・対象・未確認範囲

> 文書ID：AUD-006 / 確認日：2026-10-16  
> 会員限定記事は提供されたテキストのみ。記事本文・実動画結果・秘密情報はこの監査文書へ収録しない。

## 原資料

| ID | 提供ファイル | SHA-256（提供バイト列） |
|---|---|---|
| S1 | AI×BGM.txt | `92508f2b0b350a9d871ccb47db82d4c7f814279c32c561dbdaf75d21a0d4161f` |
| S2 | YouTube Data API.txt | `7bc6f763f3056032a8ff6811a7c562828a1bffa894d14f43d71c52fba30b56f1` |
| S3 | YouTube×BGM.txt | `f80050a2a308f653330cde2db07dc1a601cf6348f70ef6b0c1e846b70d3c5c1c` |

本文がある部分とURL・見出しだけの部分を区別した。原資料の主張を採用済み要件に一律変換していない。引用行は提供時の行番号で、実行環境のsplitlinesと末尾空行の扱いが異なる場合がある。

## コード・文書で確認した根拠

基準commitはmain `c2060e2eff02d7dfe35ffbb2d7c70544b0e52076`。コードへのリンクはこの時点に固定する。

| 確認した点 | パス・箇所 |
|---|---|
| 探索条件 | `app/Services/Discovery/Planner.php` start()/task() |
| 既知対象の更新・保存する項目 | `app/Services/YouTube/Collector.php` refreshWatch()/store() |
| 現在値と観測、検索FK | `database/migrations/2026_10_14_000001_create_research_tables.php` |
| 期限とcascadeの関係 | `app/Services/Retention.php`、上記migration |
| 差分の二つの基準 | `app/Support/Presentation.php` delta()/dailyDelta() |
| 比較種別と保存項目 | `app/Http/Controllers/ResearchController.php` compare()/saveComparison() |
| 入口・条件ごとの選択・ガイド未実装 | `resources/views/discovery.blade.php`、`exploration.blade.php`、`comparison.blade.php` |
| スマホ比較の構造・字の設定 | `public/research.css` |
| 規約等の画面導線 | `resources/views/layout.blade.php` |
| ブラウザーテストの範囲 | `tests/browser/exploration.cjs`：3ユニーク動画、同一lensの2本比較、pageerror/noOverflow |
| 音源分析の計測範囲 | `config/local-analysis/profile-v1.json`、既存LA仕様 |
| ガイド・企画の既存予定 | `docs/research/analysis-guide.md`、`docs/features/production-brief.md` |
| 実API確認の報告範囲 | `docs/discovery/verification.md` |

[基準コード](https://github.com/d-engineer92/youtube-bgm/tree/c2060e2eff02d7dfe35ffbb2d7c70544b0e52076)

PR #6は `6477977c074cf2a6e64cedf7d65b4748d63f4da3` の入口、storage-and-jobs.md、review-and-export.md、および会話中のreset-cutover.mdの確認範囲・PR説明を対象とする。すでに含まれる改善と不足する具体化を分けた。PR #6は本監査時点で文書のみ。未公開のCodex作業ツリーは確認していない。

[確認した追跡仕様](https://github.com/d-engineer92/youtube-bgm/tree/6477977c074cf2a6e64cedf7d65b4748d63f4da3/docs/tracking)

## 公式情報の照合（原資料とは別）

| ID | 根拠URL | 確認する内容 |
|---|---|---|
| W1 | https://developers.google.com/youtube/terms/developer-policies | III.Aの利用規約・プライバシー、III.E.1の映像音声取得、III.E.4の保存条件 |
| W2 | https://developers.google.com/youtube/terms/developer-policies-guide | 許容例のある単純計算と、追加の派生用途・長期保存条件。個別用途に不明があれば確認が必要 |
| W3 | https://developers.google.com/youtube/terms/derived-metrics-policy | 追加条件が受理された用途の統計等36暦月、他メタデータの30日更新・削除 |
| W4 | https://developers.google.com/youtube/v3/docs/search/list | regionCodeは視聴可能地域、relevanceLanguageは関連性。検索結果は全市場・検索人口を表さない |
| W5 | https://developers.google.com/youtube/v3/docs/channels | subscriberCount丸め、チャンネル作成日時、総再生数・公開動画数とuploads |
| W6 | https://developers.google.com/youtube/v3/sample_requests | uploads playlistのplaylistItems取得と検索の役割の違い |
| W7 | https://developers.google.com/youtube/analytics/channel_reports | 自分の詳細Analyticsには所有者側の認可が必要。競合のCTR・維持率・収益をAPIキーで取得する仕様にしない |
| W8 | https://developers.google.com/youtube/data/determine_quota_cost | 公式の既定割当とアプリ20/500の運用制限を区別。実Cloud残量は別確認 |

確認日は2026-10-16。これらは提供記事の成功論を証明する資料ではなく、データの意味と利用可能範囲を確認する資料。API互換パス・新しいドキュメント間で差異がある場合は、採用endpointの契約を記録して再確認し、収集済みデータを黙って別定義に置き換えない。

追加の分類・AI用途・長期保存についてこのプロジェクトが受理された証拠は未確認。権利者の素材許諾もYouTube側の取得条件の代わりにはならない。規約への完全適合や権利の安全を本監査で認定していない。

## 検証の限界

- 実APIの成功、92テスト等は既存検証記録の確認。今回こちらでPHPUnit・PostgreSQL・CI・実APIを実行していない。
- localhostの画面・実DB・実際に収集した130本の内容はこの監査環境から直接確認していない。
- UIのBlade/CSS/操作テストを確認。リポジトリのPNGは取得を試みたが本監査で画像として表示・評価できていないため、スクリーンショットを目視したとは報告しない。
- タップ領域・配色コントラスト・読みやすさ・キーボード・実性能・130本での実利用は、AUD-005の実装時検証に残す。
- 全リポジトリの行単位セキュリティ監査ではなく、資料の目的に対する機能・データ・UI/UXの設計監査。未読のコードに不具合がないと保証しない。
- 本PRではコード、稼働サービス、課金設定、DB、ライブラリ、音源に変更を加えていない。

[入口へ](README.md)
