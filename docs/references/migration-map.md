# 文書分割の対応表・原本照合

> 文書ID：REF-002  
> 状態：今回の再編内容の記録  
> 整理日：2026-10-14

## 編集方針

作成済みv2の第1〜13節を、以下の正本へ分割した。意味を変えずに見出し階層・参照リンクを調整し、「次節」「本書の質問」の参照を分析ガイドへの参照に置換した。受入条件には追跡用のAC番号を付けた。

v2の本文L7〜L320は全範囲がいずれか一つの分割先に対応する。L1〜L6の表題・作成日・優先関係は原本に保全し、新しい表題・文書ID・状態・入口案内を付加した。

今回新規に加えたのは、文書一覧、作業別の参照先、文書管理ルール、方針の記録、原本の照合情報、AGENTS.mdである。事業の成功条件や初回機能を追加する再設計はしていない。

## v2の分割先

| 元v2の物理行番号 | 文書ID | 現行の正本 |
|---|---|---|
| 7–16、23–46 | PRJ-001 | [目的・初回実装の範囲](../project/mvp-scope.md) |
| 47–71 | FTR-001 | [画面A：探す・追う](../features/discovery.md) |
| 72–85 | FTR-002 | [画面B：並べて分析する](../features/comparison.md) |
| 86–104 | FTR-003 | [画面C：制作指示書・振り返り](../features/production-brief.md) |
| 105–158 | RES-001 | [初心者向け分析ガイドと質問の管理](../research/analysis-guide.md) |
| 159–183 | RES-002 | [公開数値・差分・比較条件の扱い](../research/metrics.md) |
| 17–22、222–237 | ARC-001 | [最小構成とデータ設計](../architecture/system-and-data.md) |
| 184–207 | INT-001 | [YouTube Data API：取得・更新・エラー処理](../integrations/youtube-data-api.md) |
| 208–221 | POL-001 | [APIデータの保存期限・削除・利用境界](../compliance/data-retention.md) |
| 238–257 | OPS-001 | [調査から小さな試作・公開へ進む手順](../operations/validation-roadmap.md) |
| 258–273 | DEV-001 | [Codexへの実装順と利用確認](../development/implementation-plan.md) |
| 274–288 | DEV-002 | [初回実装の受入条件](../development/acceptance-criteria.md) |
| 289–320 | REF-001 | [出典・確認状況・再確認が必要な事項](sources.md) |

## v1の扱い

v1は全文をarchiveへ保存する。制作パイプライン、広いDB設計、料金比較、収益化・収益試算等を現行MVPへ再転載しない。元の情報を消すことと、初回実装の指示にしないことは区別する。

## 原本のSHA-256

以下は、今回コンテナ上にある作成済み設計書のバイト列から算出した値。archive内のファイルとの一致を確認した。

| 原本 | SHA-256 |
|---|---|
| `roadmap_and_system_design_v1.md` | `ee5a5057e8925ed87cb4f6fe28ab94da8cf0d44e18bf4bd8ddca0f92a04ea902` |
| `codex_research_first_mvp_v2.md` | `360d404506705e49526bf0996cd7075bfa4dd0ee6d82626128ad6edec60f304c` |

## 収録していない資料

`AI×BGM.txt`、`YouTube Data API.txt`、`YouTube×BGM.txt`は、今回の「作成済み設計書をdocsへ整理する」範囲では収録していない。出典名・チャット共有本文の参照箇所だけを[出典一覧](sources.md)に保持する。本文未提供の記事を補完しない。

[文書一覧へ戻る](../README.md)
