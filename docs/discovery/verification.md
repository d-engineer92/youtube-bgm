# EXP-A・EXP-Bの実装・検証記録

> 文書ID：EXP-008\
> 状態：実装済み・モック/デモ検証済み、実API未確認\
> 確認日：2026-10-14\
> 読む場面：起動・レビュー・受入確認。[仕様とACの正本](implementation.md)。

PR #4は未マージのため、`docs/guided-discovery-entry`の内容を実装ブランチ`feat/guided-discovery-exp-a-b`へ取り込んだ。統合先はmain。PRのマージ・公開範囲変更・デプロイは行っていない。

## 状況の区分

| 区分 | 内容 |
|---|---|
| 実装済み | 未入力の8テーマ計画、US/en・JP/ja、固定UTC期間・カタログ版/ハッシュ、PostgreSQL非同期キュー、共有日次/run予算、進捗・停止・再開、テーマ別結果、比較・ウォッチ、古い作品・関連語・尺の深掘り、JSON検証/原子的取込/承認、28日期限、デモ分離 |
| モック/人工検証済み | PHPUnit、公式クライアントのモックHTTP、実SIGKILLとDBキュー再配信、別プロセスの同時予算予約、PC/スマートフォンのデモ操作、既存LAの人工素材27件 |
| 実API検証済み | なし。APIキー未設定で、実YouTube API呼出は0件 |
| 未確認 | 実Cloud権限・割当、実API応答/候補の質/重複率、実際の複数日観測、視聴者国、需要・RPM・収益性、実音源のLA検証 |
| 意図的に未実装 | 推定RPM・需要スコア・動画自動分類、有料AI、素材DLとの連携、LA-3、旧作業2の全面実装、制作・投稿、自動日次新規探索 |

LA-1/LA-2はPR #2の統合先が`docs/local-analysis-workflow`で、mainには存在しなかった。利用者の追加指定に従い、既存コード・設定・テスト・CI・スキル16ファイルを変更せず取り込み、バイト一致を確認した。専用の既存文書も引き継いだ。検索入口からLAを起動する経路はない。

## 実行した検証

- `docker compose --profile test run --rm test composer test`：91テスト・524アサーション成功。既存56件を含む。
- 実接続：PDO pgsql / `youtube_bgm_test` / 制限ロール`bgm_test` / PostgreSQL 18.6。初期化前のDB識別検査を維持。通常DBへのテスト投入は行っていない。
- `docker compose --profile test run --rm test php tests/Support/worker-crash.php`：成功。検索ページ保存後の詳細呼出で子ワーカーを実SIGKILLし、DBキューの予約ジョブを同じtask/FetchRunで再配信。検索を再実行せず詳細だけを解決し、観測5件・消費不明の表示を確認。テスト時計だけを181秒進め、運用のtimeout/retry/lease値は短縮していない。
- 同スクリプトで二つの独立PHPプロセスから同じ探索のread枠を同時予約。`reset()`後も上限1を超えず成功。
- `docker run --rm --user 1000:1000 --network none --volume /home/dkuro/youtube-bgm:/repo --entrypoint python youtube-bgm-local-analysis:la1-la2 -m unittest discover -s tests/local-analysis -v`：既存27テスト成功。人工素材のみ。モデルAPI・実YouTube・DBへの接続なし。
- Pint（65ファイル）、`composer validate --strict`、`git diff --check`は成功。
- Chromium/Playwright 1.56.1、1440×1000と390×844で`tests/browser/exploration.cjs`を実行。未入力開始→進捗→8テーマ/16条件→2本比較/目的保存→選択動画ウォッチ→古い作品/関連語→元の結果へ戻る、JP切替、画面エラーなし・横はみ出しなしを確認。各画面サイズの開始前に専用テストDBを再初期化し、日次枠20は引き上げていない。

## EXP-AC01〜20の確認先

| AC | 検証と結果 |
|---|---|
| 01 | HTTP未入力開始・同じtokenの二重POSTで1run/16task/1job。APIを待たない。PC/スマートフォンでも主ボタンから操作成功 |
| 02 | US/en・JP/jaの全条件をRecordingGatewayとGoogle公式クライアントのモックHTTPまで確認。プリセットごとに別run、要求/返却地域不一致を表示 |
| 03 | T0=2026-10-14T00:12:34Z fixtureで30日、180日、5暦年、longを検証。翌日再開・辞書版変更後も保存条件不変 |
| 04 | 16ページ・maxResults=10。nextPageToken付きfixtureでも2ページ目を取得しない。最大160は重複込みの上限と明記 |
| 05 | カタログ順・テーマ/条件別。原題、サムネイルまたは未取得、公開数値と各取得時刻、日本語の意味/問い/次操作。通常動画とライブ/履歴/不明を分離 |
| 06 | 同じ1動画を16条件で返すfixtureで1動画/15重複ヒット・全経路。チャンネル行を動画総数へ加えない |
| 07 | 0件成功、部分失敗、キーなし、quota、未観測、期限切れのテスト成功。失敗を需要へ読み替えない |
| 08 | 手動/探索/深掘り/再開/リトライが日次枠を共有。run search/read上限、reset後、実並列予約も成功 |
| 09 | 検索後channels失敗から、同一FetchRunの未解決チャンネルだけ再開。search/videos/成功観測は繰り返さない |
| 10 | 二重POST、重複ジョブ、busy、停止/再開、lease失効fixture、実SIGKILL再配信。成功観測と取得ページ保持・不明消費の表示を確認 |
| 11 | HTTP受付時API0回、GET/ポーリングで取得開始なし。DBワーカーで継続、停止時起動案内、画面の再表示を確認 |
| 12 | 初回/1時間の取得は未観測。24h以上の有効点、47hの負差分、NULL、失敗/停止、非補間をfixtureで確認 |
| 13 | 古い作品・related・尺変更は語の入力不要。デモの古い低再生80回の例も表示し、比較/元結果へ戻れる |
| 14 | dry-run無変更、apply原子性、NFKC/空白/大小重複、UUID競合、未知キー、型/制御文字/長さ/件数/64KiB、NULL親、未承認・保留の拒否を確認 |
| 15 | Import/閲覧でGateway呼出0。追加の外部AI/サジェスト/素材取得/分析スキルを実装していない。HTMLはエスケープ |
| 16 | 28日で所属/順位/FetchRun/観測/キューを削除しrunはexpired。再開拒否。本人の比較目的と明示対象参照は保持 |
| 17 | demo/liveの画面・予算・候補・観測を分離。US/JP検索で同一live動画のレコードを共有。値は世界全体と表示 |
| 18 | 専用PostgreSQL、既存手動/直接/比較/ウォッチ回帰、LA27件。両CIを同じブランチで実行・確認する |
| 19 | PC/スマートフォンで一連のデモ操作成功。[画面証跡](screenshots/README.md) |
| 20 | キー未設定。実API疎通・実データ・需要検証は未確認。モックの成功と区別 |

## 起動・運用

新規clone・既存環境の起動、ワーカーの単一起動、候補JSONのdry-run/apply、ブラウザーテストの再実行は[プロジェクトREADME](../../README.md)を正本とする。既存環境の追加マイグレーションは成功し、既存テーブル/データの初期化は行っていない。新規cloneは`/tmp/bgm-exp-clean-verified`の別チェックアウト・別Composeプロジェクト・別DB volumeで確認した。composer install、APP_KEY生成、全マイグレーション、Web/worker同時起動に成功。HTTP 200、未入力入口とデモ表示、worker稼働を確認して停止した。通常環境は追加マイグレーションのみ実行済み。実装PRは[PR #5](https://github.com/d-engineer92/youtube-bgm/pull/5)。未マージ。

APIキー・実結果・素材・ログ・個人の候補JSONはGitへ追加していない。画面例は架空データだけ。28日処理は取得した所属自体にも適用し、現在値更新で過去所属を延命しない。

## GitHub CIで確認した結果

`500b0f8`に対する[PostgreSQLアプリCI](https://github.com/d-engineer92/youtube-bgm/actions/runs/38517257810)と[LA人工素材CI](https://github.com/d-engineer92/youtube-bgm/actions/runs/38517257849)は両方成功。CIログでもpgsql / youtube_bgm_test / bgm_test / PostgreSQL 18.6、88テスト・509アサーション、SIGKILL再配信・同時予算予約、PCとスマートフォンの全導線成功、LA27件を確認した。

その後の最終確認で、未保存メモを自動更新から保護し、不正なitems型・10件上限違反・途中の不正IDで検索所属が部分保存されない3ケースを追加。ローカル最終結果は上記91件・524アサーション。最終コミットに対する同じ両CIとデモ画面artifactは[PRのチェック](https://github.com/d-engineer92/youtube-bgm/pull/5/checks)で確認できる。
