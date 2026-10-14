# デモ画面の操作証跡

2026-10-14、専用PostgreSQLテストDBの架空データで撮影。原題・ID・数値はすべて架空で、実APIの需要検証ではない。1440×1000と390×844のChromium。

| 操作 | PC | スマートフォン |
|---|---|---|
| 未入力開始 | [入口](desktop-entry.png) | [入口](mobile-entry.png) |
| テーマ別の説明と結果 | [結果](desktop-results.png) | [結果](mobile-results.png) |
| 2本を比較 | [比較](desktop-compare.png) | [比較](mobile-compare.png) |
| 古い作品の追加調査 | [古い作品](desktop-older.png) | [古い作品](mobile-older.png) |

ウォッチ登録・関連語検索・比較目的の保存・元結果への復帰も`tests/browser/exploration.cjs`で実操作した。[受入記録](../verification.md)。
