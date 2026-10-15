# ドキュメント一覧・作業別の読み方

このフォルダーが初回実装の仕様入口。**作業1とEXP-A/Bの取得・比較・探索入口を実装済み。モックと実APIの基本取得・画面操作を確認済み。複数日観測と作業2は未実施。** 起動手順は[プロジェクトREADME](../README.md#起動手順windows--wsl--docker)、既存検証記録は[AC一覧](development/acceptance-criteria.md#作業1の検証記録2026-10-14)と[EXP検証記録](discovery/verification.md)を参照。

## 今回の改修：新着を含む全動画の自動追跡と再開始

2026-10-16の最新指示。新着と0〜一桁再生も維持し、全取得動画の初回からの日次蓄積を標準にする。改修後の利用切替で、旧調査データは全消去して再収集する。**以下は新しい仕様であり、まだ実装・DB消去は行っていない。**

- [TRK-001：入口・範囲・旧仕様との優先関係](tracking/README.md)
- [TRK-002：自動追跡・履歴・ジョブ・保存](tracking/storage-and-jobs.md)
- [TRK-003：画面・JSON/CSV・固定考察](tracking/review-and-export.md)
- [TRK-004：安全な全消去と切替](tracking/reset-cutover.md)
- [TRK-005：実装依頼と24項目の受入条件](tracking/implementation.md)
- [TRK-006：根拠と長期保存の制約](tracking/sources.md)／[DEC-005](decisions/0005-observe-all-from-first-fetch.md)

## キーワード不要の探索入口

2026-10-14の追加依頼。検索語・用途・ジャンルを未入力で始め、英語圏想定の結果を日本語で理解できる入口。EXP機能は実装済みで、確認範囲の正本は[EXP検証記録](discovery/verification.md)。その後の追跡対象と今回のリセットについてはTRKを優先する。

- [専用入口・範囲（EXP-001）](discovery/README.md)
- [利用体験（EXP-002）](discovery/experience.md)／[収集・API・復旧（EXP-003）](discovery/collection.md)
- [DB・観測・保存（EXP-004）](discovery/data-and-retention.md)／[候補語拡張（EXP-005）](discovery/keyword-expansion.md)
- [実装依頼と受入条件（EXP-006）](discovery/implementation.md)
- [初期カタログ](../config/discovery-catalog-v1.json)／[出典（EXP-007）](discovery/sources.md)／[決定記録DEC-004](decisions/0004-guided-discovery.md)

## 読む順番

最初に[初回実装の範囲](project/mvp-scope.md)を読み、以下の表から対象作業の文書を選ぶ。最新の追加改修を依頼された場合は、その専用入口の対象範囲と優先関係も読む。旧v1・v2を含め、全docsを毎回一括取得しない。

## 作業別の参照先

| 作業 | 必要な文書 | 主な受入条件 |
|---|---|---|
| 全動画追跡・時系列・DBリセット | [TRK専用入口](tracking/README.md)から対象文書とARC-002/POL-001 | TRK-AC01〜24と既存回帰 |
| キーワード不要の探索入口 | [EXP専用入口](discovery/README.md)から作業に必要な仕様だけを読む | EXP-AC01〜20と既存回帰 |
| 開発環境・DB | [構成とデータ](architecture/system-and-data.md)、[PostgreSQL専用方針](architecture/database-policy.md)、[保存期限](compliance/data-retention.md) | AC-09・10・12・13 |
| テスト・CI・接続設定 | [PostgreSQL専用方針](architecture/database-policy.md)、[AC一覧](development/acceptance-criteria.md) | AC-10・12・13 |
| API取得・日次更新 | [API](integrations/youtube-data-api.md)、[保存期限](compliance/data-retention.md)、[数値](research/metrics.md)、[画面A](features/discovery.md)、[追跡改修](tracking/storage-and-jobs.md) | AC-01〜04・09・10、TRK |
| 検索・ウォッチ画面 | [画面A](features/discovery.md)、[API](integrations/youtube-data-api.md)、[数値](research/metrics.md) | AC-01〜04 |
| 比較・初心者ガイド | [画面B](features/comparison.md)、[分析ガイド](research/analysis-guide.md)、[数値](research/metrics.md) | AC-03・05〜07 |
| 制作指示書・出力 | [画面C](features/production-brief.md)、[検証手順](operations/validation-roadmap.md)、[保存期限](compliance/data-retention.md) | AC-06〜09・11 |
| Codexへの依頼・レビュー | [実装順](development/implementation-plan.md)、[AC一覧](development/acceptance-criteria.md)、対象機能の仕様 | 対象に該当するAC・12 |
| 仕様・方針の更新 | [文書管理](development/documentation-rules.md)、変更対象の正本、下記の決定記録 | 変更対象のAC |
| 出典・過去経緯の確認 | [出典](references/sources.md)、[移行対応表](references/migration-map.md)。必要な場合だけarchive | 実装の自動追加はしない |
| ローカル素材の取得・分析・削除（追加依頼） | [専用入口](local-analysis/README.md)から対象仕様だけを読む | LA-AC01〜19。DBを使う場合はAC-13も適用 |

API由来データの保存・表示・出力を変える作業では、表の作業名にかかわらず保存期限の文書も確認する。DB・テスト・CI・バッチの保存先を扱う作業では、[PostgreSQL専用方針](architecture/database-policy.md)も確認する。

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
| ARC-002 | [PostgreSQL専用DB方針](architecture/database-policy.md) | DB設定・DB依存テスト・CI・分析結果取込を扱うとき |
| INT-001 | [YouTube Data API：取得・更新・エラー処理](integrations/youtube-data-api.md) | APIクライアント・ページ送り・日次取得・失敗処理を作るとき |
| POL-001 | [APIデータの保存期限・削除・利用境界](compliance/data-retention.md) | API由来データを保存・表示・出力・復元するとき |
| OPS-001 | [調査から小さな試作・公開へ進む手順](operations/validation-roadmap.md) | 費用を使う順番と、次の試作に進む判断を確認するとき |
| DEV-001 | [Codexへの実装順と利用確認](development/implementation-plan.md) | Codexの作業を依頼・分割するとき |
| DEV-002 | [初回実装の受入条件](development/acceptance-criteria.md) | テスト設計・PRレビュー・完了報告を行うとき |
| REF-001 | [出典・確認状況・再確認が必要な事項](references/sources.md) | 根拠を調べるとき、外部仕様に依存する判断をするとき |

## 追加仕様：ローカル素材分析

初回の検索・比較基盤とは別作業。既存の[PR #2](https://github.com/d-engineer92/youtube-bgm/pull/2)と[EXP検証記録](discovery/verification.md)に実装・統合の記録がある。今回のTRKはローカル音源分析を変更しない。

| ID | 文書 |
|---|---|
| LMA-001 | [目的・追加範囲・作業別の読み方](local-analysis/README.md) |
| LMA-002 | [取得・状態・結果確定・安全な削除・復旧](local-analysis/workflow.md) |
| LMA-003 | [固定する分析項目・区間・解釈の限界](local-analysis/analysis-profile.md) |
| LMA-004 | [JSON/CSV/Markdownと後続DB取込](local-analysis/output-contract.md) |
| LMA-005 | [Codexスキル仕様・ひな形](local-analysis/codex-skill.md) |
| LMA-006 | [実装順・依頼文・19項目の受入条件](local-analysis/implementation.md) |
| LMA-007 | [根拠・公式資料・未確認事項](local-analysis/sources.md) |
| review-v1 | [毎回使用する固定考察プロンプト](local-analysis/prompts/review-v1.md) |
| schema-v1 | [出力JSON Schema](local-analysis/schemas/analysis-v1.schema.json)、[架空の書式例](local-analysis/examples/README.md) |

## 管理・履歴

| 文書 | 用途 |
|---|---|
| [文書管理ルール](development/documentation-rules.md) | 正本・分割粒度・更新方法 |
| [DEC-001：初回を調査・企画支援へ限定](decisions/0001-research-first-mvp.md) | v1から範囲を縮小した理由 |
| [DEC-002：責務別の文書構成](decisions/0002-modular-documentation.md) | 今回の分割方針とトレードオフ |
| [DEC-003：素材を保持しないローカル分析](decisions/0003-local-media-analysis.md) | 追加分析と初回の境界、スキルとバッチの分担 |
| [DEC-004：検索語未定からの探索](decisions/0004-guided-discovery.md) | 入口改修の優先順位と英語圏想定・日本語案内 |
| [DEC-005：初回から全動画を追跡](decisions/0005-observe-all-from-first-fetch.md) | 足切りせず日次履歴を蓄積し、旧調査データを消して再開始 |
| [移行対応表](references/migration-map.md) | 元v2の対応箇所、原本のハッシュ |
| [archiveの案内](archive/README.md) | 凍結原本。通常の実装対象外 |

新しいユーザー指示で範囲が変わったら、対象の正本と受入条件を更新する。archiveの記述を根拠に現行の対象外機能を復活させない。文書間で矛盾を見つけたら、黙って機能を増やさず、矛盾と影響を報告する。

[プロジェクトREADMEへ戻る](../README.md)
