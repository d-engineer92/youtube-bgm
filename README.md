# YouTube BGM Research

YouTube BGMを初めて運営する人が、競合の公開情報を調べ、観察・仮説・不明点を整理し、予算内で試す1本の制作指示書を作るためのプロジェクト。

**検索・比較アプリはこのブランチには未収録。別枠のローカル分析LA-1・LA-2を実装済み、実素材・実API検証は未実施。** 実装はCodexへ依頼する。自動生成・自動投稿を初回の目的にはしない。

## 入口

- [文書一覧・作業別の読み方](docs/README.md)
- [初回実装の範囲](docs/project/mvp-scope.md)
- [Codexへの実装順](docs/development/implementation-plan.md)
- [受入条件](docs/development/acceptance-criteria.md)

AI向けの短い作業案内はルートの[AGENTS.md](AGENTS.md)。詳細仕様はすべて`docs/`に置く。

## 技術方針

環境とDBの提案は[最小構成とデータ設計](docs/architecture/system-and-data.md)を参照する。実装前のため、起動・マイグレーション・テストのコマンドはまだ定義していない。

## 原資料と公開範囲

作成済みのv1・v2設計書は[archive](docs/archive/README.md)へ原文のまま保存した。通常の実装では現行の分割文書を使用する。元の会員限定記事本文を含むTXTファイルは収録していない。

公開範囲は非公開を推奨する。公開リポジトリへ変更する前に、転載情報・秘密情報・権利証跡の有無を確認する。

ローカル分析の実際の起動・検証・復旧手順は[専用入口](docs/local-analysis/README.md#la-1la-2の実装と起動2026-10-14)を参照する。
