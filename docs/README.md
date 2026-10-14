# ドキュメント一覧・作業別の読み方

このフォルダーが初回実装の仕様入口。**現状は文書のみで、実装・疎通・実データ検証は未実施。**

## 読む順番

最初に[初回実装の範囲](project/mvp-scope.md)を読み、以下の表から対象作業の文書を選ぶ。受入確認では[AC一覧](development/acceptance-criteria.md)の該当項目を読む。旧v1・v2を含め、全docsを毎回一括取得しない。

## 作業別の参照先

| 作業 | 必要な文書 | 主な受入条件 |
|---|---|---|
| 開発環境・DB | [構成とデータ](architecture/system-and-data.md)、[保存期限](compliance/data-retention.md) | AC-09・10・12 |
| API取得・日次更新 | [API](integrations/youtube-data-api.md)、[保存期限](compliance/data-retention.md)、[数値](research/metrics.md)、[画面A](features/discovery.md) | AC-01〜04・09・10 |
| 検索・ウォッチ画面 | [画面A](features/discovery.md)、[API](integrations/youtube-data-api.md)、[数値](research/metrics.md) | AC-01〜04 |
| 比較・初心者ガイド | [画面B](features/comparison.md)、[分析ガイド](research/analysis-guide.md)、[数値](research/metrics.md) | AC-03・05〜07 |
| 制作指示書・出力 | [画面C](features/production-brief.md)、[検証手順](operations/validation-roadmap.md)、[保存期限](compliance/data-retention.md) | AC-06〜09・11 |
| Codexへの依頼・レビュー | [実装順](development/implementation-plan.md)、[AC一覧](development/acceptance-criteria.md)、対象機能の仕様 | 対象に該当するAC・12 |
| 仕様・方針の更新 | [文書管理](development/documentation-rules.md)、変更対象の正本、下記の決定記録 | 変更対象のAC |
| 出典・過去経緯の確認 | [出典](references/sources.md)、[移行対応表](references/migration-map.md)。必要な場合だけarchive | 実装の自動追加はしない |

API由来データの保存・表示・出力を変える作業では、表の作業名にかかわらず保存期限の文書も確認する。

## 現行の実装仕様（v2から分割）

| ID | 文書 | 読む場面 |
|---|---|---|
| PRJ-001 | [目的・初回実装の範囲](project/mvp-scope.md) | 初回作業の開始時、機能追加の可否を判断するとき |
| FTR-001 | [画面A：探す・追う](features/discovery.md) | 検索・直接登録・ウォッチ・取得状況のUIを作るとき |
| FTR-002 | [画面B：並べて分析する](features/comparison.md) | 比較画面・観察入力・YouTubeへのリンクを作るとき |
| FTR-003 | [画面C：制作指示書・振り返り](features/production-brief.md) | 企画・予算・許諾確認・Markdown出力を作るとき |
| RES-001 | [初心者向け分析ガイドと質問の管理](research/analysis-guide.md) | 質問・目的・回答例・注意点を実装または改善するとき |
| RES-002 | [公開数値・差分・比較条件の扱い](research/metrics.md) | 再生数表示・差分・並び替え・比較ラベルを実装するとき |
| ARC-001 | [最小構成とデータ設計](architecture/system-and-data.md) | 環境構築・Laravel構成・PostgreSQLの設計をするとき |
| INT-001 | [YouTube Data API：取得・更新・エラー処理](integrations/youtube-data-api.md) | APIクライアント・ページ送り・日次取得・失敗処理を作るとき |
| POL-001 | [APIデータの保存期限・削除・利用境界](compliance/data-retention.md) | API由来データを保存・表示・出力・復元するとき |
| OPS-001 | [調査から小さな試作・公開へ進む手順](operations/validation-roadmap.md) | 費用を使う順番と、次の試作に進む判断を確認するとき |
| DEV-001 | [Codexへの実装順と利用確認](development/implementation-plan.md) | Codexの作業を依頼・分割するとき |
| DEV-002 | [初回実装の受入条件](development/acceptance-criteria.md) | テスト設計・PRレビュー・完了報告を行うとき |
| REF-001 | [出典・確認状況・再確認が必要な事項](references/sources.md) | 根拠を調べるとき、外部仕様に依存する判断をするとき |

## 管理・履歴

| 文書 | 用途 |
|---|---|
| [文書管理ルール](development/documentation-rules.md) | 正本・分割粒度・更新方法 |
| [DEC-001：初回を調査・企画支援へ限定](decisions/0001-research-first-mvp.md) | v1から範囲を縮小した理由 |
| [DEC-002：責務別の文書構成](decisions/0002-modular-documentation.md) | 今回の分割方針とトレードオフ |
| [移行対応表](references/migration-map.md) | 元v2の対応箇所、原本のハッシュ |
| [archiveの案内](archive/README.md) | 凍結原本。通常の実装対象外 |

新しいユーザー指示で範囲が変わったら、対象の正本と受入条件を更新する。archiveの記述を根拠に現行の対象外機能を復活させない。文書間で矛盾を見つけたら、黙って機能を増やさず、矛盾と影響を報告する。

[プロジェクトREADMEへ戻る](../README.md)
