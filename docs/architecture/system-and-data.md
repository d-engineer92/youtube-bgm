# 最小構成とデータ設計

> 文書ID：ARC-001  
> 状態：作業1の構成を採用・実装済み（実データ未確認）\
> 読む場面：環境構築・Laravel構成・PostgreSQLの設計をするとき  
> 整理日：2026-10-14  
> 元資料：[初回実装仕様 v2](../archive/codex_research_first_mvp_v2.md) L17–L22、L222–L237。DB方式は利用者の追加指示を反映し、その他の要件は引き継いでいる。

## 技術前提

ユーザー資料の指定：Windows / WSL / Docker、WebとDBを分離、PHP / Laravel、公式Googleクライアントライブラリ、YouTube Data API、APIキーのみ、OAuthなし。[U2](../references/sources.md#u2)

DBは開発・通常運用・DB依存テスト・CI・将来の分析結果取込を含めPostgreSQLのみ。接続設定とテストの隔離は[ARC-002](database-policy.md)を正本とし、通常データを同じアプリDBへ集約し自動テストだけを隔離する。ローカル・単一利用者向けとし、新規VPS契約やSaaS化は不要。UIはLaravel Blade等の単純な構成でよく、既存の指定がなければ別SPAを追加しない。ライブラリの対応バージョンは実装時に確認する。

## データ設計は必要最小限にする

実テーブル名はCodexで調整可。初期の論理領域は以下に限定する。

| 領域 | 内容 |
|---|---|
| チャンネル・動画の現在情報 | API取得の元データ、取得時刻、更新・削除の期限 |
| 観測値 | 対象ID、実取得時刻、統計値、失効時刻、取得実行との対応 |
| 取得実行 | 検索条件、取得範囲、件数、状態、エラー、ページ送りの状態 |
| 調査ケース | 選択した動画・比較目的、質問への回答、テンプレート版 |
| 制作指示書 | 自分の企画、試作上限、利用許諾確認、振り返り |

回答欄は初期はJSONBでもよい。ただし外部ID・時刻・カウンター等、検索や整合性が必要な情報まで無秩序なJSONに押し込まない。動画ファイル・音源保存領域はまだ不要。

APIキャッシュを削除したとき、自分の企画が意図せずすべて削除されないよう参照関係を設計する。一方、削除対象のAPIデータだけ別テーブルに残ることも防ぐ。

## 作業1で採用した構成

PHP 8.5.10、Laravel 13、PostgreSQL 18、Blade、公式`google/apiclient`を採用。正確な依存バージョンは`composer.lock`、再現可能な起動手順は[README](../../README.md)を参照。別SPA・Nodeビルド・OAuthは不要。

物理スキーマは[マイグレーション](../../database/migrations/2026_10_14_000001_create_research_tables.php)が正本。`targets`は対象参照・自分の目的・ウォッチ設定、`channels`/`videos`は現在の公開情報、`observations`は取得時刻を持つ統計、`fetch_runs`/`run_items`は条件・ページ・結果、`api_days`は呼出上限管理、`comparisons`/中間表は自分の比較目的と参照だけを持つ。

実取得とデモは`source`でIDの一意性と画面・更新対象を分離する。APIキャッシュの削除は比較目的を削除しない。質問回答・制作指示書の領域は作業2で追加する。

## 関連文書

- [PostgreSQL専用DB方針](database-policy.md)
- [初回実装の範囲](../project/mvp-scope.md)
- [API取得仕様](../integrations/youtube-data-api.md)
- [保存期限](../compliance/data-retention.md)

[文書一覧へ戻る](../README.md)

## 追跡改修のデータ責務（2026-10-16採用・未実装）

切替前の対象/検索所属/観測/メタデータ版は[TRK-002](../tracking/storage-and-jobs.md)、期間計算は[RES-002](../research/metrics.md)、比較/分析/企画の参照は[TRK-003](../tracking/review-and-export.md)を正本とする。既存の観測→検索run cascadeとウォッチ限定は旧実装であり、改修後の契約として使用しない。同じPostgreSQL内で責務を分離し、通常の改善は追加migrationと版で進める。
