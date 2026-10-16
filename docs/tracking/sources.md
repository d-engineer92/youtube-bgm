# 根拠・外部確認・未確認事項

> 文書ID：TRK-006\
> 状態：公式本文・既存コード確認済み。新実装と通常DB操作は未実施。\
> 確認日：2026-10-16。

## 利用者の要件

最新指示は、新着を減らさず、取得後も再生数を追って蓄積し、伸びた事例を画面やAIで分析すること。既存レコードは引継ぎせず、改修時に全消去して再開始すること。元の「判断軸 v2」の「勢い」でも、1日おきの再取得・差分算出が明示されている。

会員限定URLは開いていない。提供テキストの観点を要件の背景として参照し、記事の経験則や仮説を公式な因果関係へ読み替えない。元の会員限定記事本文を公開リポジトリへ転載しない。

## 確認した実装

main `c2060e2eff02d7dfe35ffbb2d7c70544b0e52076`。`Collector` は成功した詳細を保存するが、日次更新対象は `watched=true`。`routes/console.php` には手動ウォッチ用の日次処理がある。`observations` のfetch_run参照はcascadeOnDeleteで、`Retention` は探索/取得実行の期限削除を行う。これらが今回の変更点。

## 公式情報

| ID | URL | 本仕様で用いる範囲 |
|---|---|---|
| T1 | https://developers.google.com/youtube/v3/docs/videos/list | IDリスト指定の動画取得。1回1unit。id指定ではmaxResults/pageTokenを使わない |
| T2 | https://developers.google.com/youtube/v3/docs/channels/list | チャンネルID指定で公開統計を取得する |
| T3 | https://developers.google.com/youtube/terms/developer-policies | III.E.4：未認可統計は通常30日超保存不可。現在情報と日時を明示した履歴を区別 |
| T4 | https://developers.google.com/youtube/terms/developer-policies-guide | Acceptable metrics：API値だけの加減算等、原始値の時系列グラフの例。独自スコア・視聴者推定等を自動的に許可するものではない |
| T5 | https://developers.google.com/youtube/terms/derived-metrics-policy | 受理された追加分析用途には、統計等最大36暦月の保存を認める追加条件がある。申請/受理が前提 |
| T6 | https://developers.google.com/youtube/v3/determine_quota_cost | 日次割当はPTの午前0時切替。アプリ内DB消去では外部割当をリセットしない |

T1/T3/T4/T5/T6の公式本文を今回再確認。T2は既存実装の利用先として記載し、本改修での実疎通は未確認。50IDをバッチ単位にすること、schedulerの5分間隔、約24時間観測、28日保存の安全余裕はプロジェクトの実装設計であり、需要の統計的基準ではない。

## 長期蓄積についての重要な境界

日次で取り続けることと、各日の過去統計を無期限に保存することは別。現行APIキーのみの競合統計は、POL-001の28日ローリングを維持する。取得日を更新して古い数値を残す、別DB/CSV/手書きメモへ移す、AIの文章へ転記して残す方法で保存期限を迂回しない。

全期間履歴が必要になった際の正規ルートは、T5の追加用途の申請・受理条件を確認すること。2026-10-16時点で本プロジェクトが受理された証跡は未確認。申請を代行・送信したり許可があると偽ったりしない。現在の改修で無期限履歴が実現したとは報告しない。

## AIへ渡すとき

今回は原始観測と単純差分を期限付きで出力し、人が対象を選ぶ。外部AIへ自動送信する機能は追加しない。定量事実の整理と仮説の記録を分け、カテゴリー推定・満足度推定・独自スコアなど、追加許諾が必要な利用を有効化しない。技術的に出力できることを利用許可の証明としない。

実数の伸びだけでは、推薦・流行・外部紹介・タイトルやサムネイルの変更等の原因は特定できない。自分のStudio実績、実際に確認した素材等の別の根拠がない部分は未確認とする。

[入口へ](README.md)

## 実装時のAPIパラメーター再確認（2026-10-16）

公式の[videos.list](https://developers.google.com/youtube/data/reference/rest/v3/videos/list)で、id指定とmaxResults/pageTokenを併用しない条件を再確認した。CollectorはIDを50件ずつまとめ、idだけを渡す。[channels.list](https://developers.google.com/youtube/data/reference/rest/v3/channels/list)も確認した。公式資料の確認は実API取得成功・個別AI用途の許可確認とは別であり、AI/長期保持/画像証跡の条件付き無効は維持する。
