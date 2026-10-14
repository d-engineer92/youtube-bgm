# YouTube BGM Research

YouTube BGMを初めて運営する人が、競合の公開情報を調べ、自分の比較目的を残すローカル用ツール。

**作業1と検索入口EXP-A・EXP-Bを実装済み。モック・デモ操作検証済み。APIキー未提供のため実API疎通・実データ確認は未実施。** 作業2の質問ガイド・制作指示書は未実装。

仕様の入口は[文書一覧](docs/README.md)、範囲は[MVP](docs/project/mvp-scope.md)、検証状況は[受入条件の記録](docs/development/acceptance-criteria.md#作業1の検証記録2026-10-14)。AI向けの作業案内は[AGENTS.md](AGENTS.md)。

## 起動手順（Windows / WSL / Docker）

Docker EngineまたはWSL連携済みDocker DesktopとCompose v2を用意し、WSL上のリポジトリルートで実行する。ホストへのPHP・Composer・Nodeのインストールは不要。WebとPostgreSQLは別コンテナー。起動後のURLは http://localhost:8088 。

```bash
cp .env.example .env
# キーなしで動作を見る場合は .env の YOUTUBE_MODE=demo に変更する
# 実取得する場合は YOUTUBE_MODE=live と YOUTUBE_API_KEY を設定する

docker compose build web
docker compose run --rm --no-deps web composer install --no-interaction
docker compose run --rm --no-deps web php artisan key:generate
docker compose up -d db
docker compose run --rm web php artisan migrate --force
docker compose up -d web worker
```

`.env.example`の既定は`live`。キー未設定の取得は失敗として表示し、架空データで埋めない。**今回作成したローカルの`.env`は`demo`で、画面にも架空データと明記する。** `.env`とAPIキーはGit対象外。APP_KEY生成は初回のみでよい。

デモでは検索語にかかわらず4本の固定架空候補を使う。直接登録の例は`demoVideo01`と`UCDEMO000000000000000001`。取得上限を1にして検索すると、取得結果画面で続き取得を試せる。実データとデモはDBのsource列で分離し、画面・ウォッチ・比較・実行の参照も分離する。

最上部の主ボタンから、検索語・用途・ジャンルを入力せずに8テーマの探索を開始できる。初期US/en、日本向けJP/jaへ任意切替。テーマ別の説明を読み、2〜6本比較 → ウォッチ → 古い作品／関連語の追加調査へ進む。既存のキーワード検索・直接登録も利用できる。少ない再生数の動画も選べる。期限切れの公開データは自動削除され、自分の目的と明示的な対象参照だけを残す。

設定変更後は次を実行する。ポートを変更する場合は`.env`の`WEB_PORT`と`APP_URL`を合わせる。

```bash
docker compose run --rm --no-deps web php artisan config:clear
docker compose up -d --force-recreate web worker
# schedulerを起動している場合は scheduler も再起動する
```

## 更新・期限削除

```bash
# 手動更新：その実行時刻の観測を追加
docker compose run --rm web php artisan youtube:refresh-watch
# 同日の再実行で成功済み観測を重複させない日次モード
docker compose run --rm web php artisan youtube:refresh-watch --daily
# 期限切れ公開データを明示的に削除
docker compose run --rm web php artisan youtube:prune
# 自動更新を使うときだけ起動（JST 09:00の更新、毎時の期限削除）
docker compose --profile scheduler up -d scheduler
```

ローカルが停止していた期間は観測できず、遡って補完しない。再生数差分は前回・今回の実取得時刻と区間を併記する。画面を開く前にも期限削除が動く。保存の正本は[保存期限](docs/compliance/data-retention.md)、更新・リトライ・利用上限は[API仕様](docs/integrations/youtube-data-api.md)。

停止は`docker compose --profile scheduler stop`。DBのデータは名前付きvolumeへ保存し、自動バックアップは作らない。

## テスト

DBは開発・通常運用・テスト・CIともPostgreSQLのみ。[ARC-002](docs/architecture/database-policy.md)に従う。PostgreSQLのメジャーはcompose.yamlのx-postgres-imageで一元管理し、CIも同じComposeを使用する。通常データはyoutube_bgmに集約し、自動テストだけを使い捨てのyoutube_bgm_testへ隔離する。SQLiteへの代替経路はない。`composer test`もテスト用コンテナー内で実行する。

```bash
# PostgreSQLの専用テストDBで同じテストを実行（通常DBへは接続しない。専用ロールbgm_testを使用）
docker compose --profile test run --rm test php artisan test
# 文法・書式・依存定義
docker compose run --rm --no-deps web vendor/bin/pint --test
docker compose run --rm --no-deps web composer validate --strict
```

テストはGatewayの架空応答および公式GoogleクライアントのモックHTTPを使う。実API通信はしない。実効ドライバー、環境、ホスト・ポート・DB名・専用ロールの許可リスト、DB識別コメントとロール権限を、初期化より前に検査する。通常DBの設定では停止する。モックの通過は実API接続成功を意味しない。

ブラウザー確認も専用PostgreSQLテストDBを使う。DockerだけでPlaywrightを実行できる。test-web起動時は許可先と実DB識別を検査した後、専用テストDBだけを初期化する。PHPUnitと同時実行しない。スクリプトは応答のテストDB識別を確認し、通常Webへの書込みを拒否する。必要なら`BGM_CHROME`でChromiumのパスを指定できる。

```bash
# 初回だけ、ブラウザー用イメージを準備
mkdir -p /tmp/bgm-exploration
docker compose --profile test build browser
# test-webが専用DBを初期化。PHPUnit・プロセステストとは直列で実行
# 既存の手動検索・比較・ウォッチ
docker compose --profile test up -d test-web
docker compose --profile test run --rm browser node tests/browser/smoke.cjs
docker compose --profile test stop test-web
# PC：未入力開始から深掘りまで。test-web起動後に単一workerを開始
docker compose --profile test up -d test-web test-worker
docker compose --profile test run --rm browser
docker compose --profile test stop test-worker test-web
# スマートフォン：専用DBを新しく初期化して同じ操作（予算上限は変更しない）
docker compose --profile test up -d test-web test-worker
docker compose --profile test run --rm -e BGM_VIEW=mobile browser
docker compose --profile test stop test-worker test-web
# 実SIGKILLと並列予算予約。専用テストDBだけを初期化
docker compose --profile test run --rm test php tests/Support/worker-crash.php
```

## 実データの確認に必要な操作

1. 同一Google CloudプロジェクトでYouTube Data API v3を有効にし、サーバーで使えるAPIキーと実際の割当を確認する。キーはサーバー側の`.env`だけに設定する。
2. `YOUTUBE_MODE=live`へ変更して設定を反映する。画面が「実APIモード」と表示することを確認する。
3. 実在する動画URLとチャンネルIDを登録し、キーワード検索、ID重複排除、uploadsの続き取得、取得本数・条件・時刻を確認する。
4. ウォッチを追加して更新する。翌日以降に再取得し、実取得間隔・再生数差分を確認する。非公開登録者数や返却なしを0と表示しないことも確認する。
5. 同程度の尺・公開経過の動画を並べ、YouTubeへ開くリンクと自分の比較目的を確認する。実際のAPI権限・クォータ失敗や、ローカル停止後の動作はモック結果と分けて記録する。

APIキー、実プロジェクトの権限・割当、実コンテンツの形態差、実際の複数日観測は今回未確認。探索の実API確認は[EXP検証記録](docs/discovery/verification.md)を参照。公式資料の再確認箇所は[出典](docs/references/sources.md#作業1実装時に再確認した公式資料2026-10-14)に記録した。包括的な商用利用・監査の適合判定は行っていない。

## 意図的に未実装

作業2の説明付き質問・観察回答・制作指示書・Markdown出力。楽曲／画像生成、有料AI連携、動画制作、自動投稿、OAuth、Studioデータ取込、独自スコア、音声DL、コメント収集は追加していない。

元の会員限定記事本文・音源・秘密情報はGitへ追加しない。旧設計は`docs/archive/`に凍結されており、今回の実装指示として参照していない。利用者の依頼で作業1を[PR #3](https://github.com/d-engineer92/youtube-bgm/pull/3)へ保存した。公開範囲・デプロイは変更していない。ローカルとGitHub CIの結果は[受入記録](docs/development/acceptance-criteria.md#アプリ変更の保存とgithub-ci2026-10-14)で区別する。

## 探索ワーカー・既存環境の更新

既存環境では.env・APP_KEY・DB volumeを保持する。.envのQUEUE_CONNECTIONをdatabaseへ変更し、次を実行する。migrateは追加的で、通常DBを初期化しない。

```bash
docker compose stop web worker
docker compose build web
docker compose run --rm --no-deps web composer install --no-interaction
docker compose run --rm --no-deps web php artisan config:clear
docker compose run --rm web php artisan migrate --force
docker compose up -d web worker
```

ワーカーだけの起動は`docker compose up -d worker`。手動で前面実行する場合は`docker compose run --rm web php artisan queue:work database --sleep=1 --timeout=120 --tries=20`。二つを同時に起動せず、単一ワーカーを使う。Webが受付けた探索はPostgreSQLキューへ保存され、画面を閉じても続く。ワーカー未稼働時は進捗画面に起動案内が出る。停止・枠不足は自動無限再投入せず、画面から明示再開する。強制終了時はretry_after=150秒、claim/収集ロック=180秒の失効後に同一task/FetchRunを復旧する。送信後保存前の消費は不明として表示し、保守的に送信試行へ計上する。

共通日次枠は検索20/read500の既定を維持。初回は16ページ、各10ヒットの上限（重複を含む）で、続きページは取得しない。探索ごとの送信試行上限・期間・地域・辞書版は開始時に保存する。結果は28日で失効し、検索所属・順位・キューも削除する。詳細は[収集仕様](docs/discovery/collection.md)と[検証記録・画面例](docs/discovery/verification.md)。

## 候補語JSONの検証・取込

[固定プロンプトとJSON契約](docs/discovery/keyword-expansion.md)に沿った最大8件・64 KiBのJSONを`private/candidates.json`へ保存する。API本文・統計・動画IDは受け付けない。使用するデータモードは.envのYOUTUBE_MODEに従う（デモと実環境の候補は分離）。

```bash
# プレビュー。DBを変更せず、検索もしない
docker compose run --rm web php artisan discovery:import-keywords /app/private/candidates.json --dry-run
# 原子的に未承認で保存。検索はしない
docker compose run --rm web php artisan discovery:import-keywords /app/private/candidates.json --apply
```

探索結果の下部で意味・提案理由を確認し、承認／保留／無効を保存する。承認後、別の「この候補で調べる」ボタンで検索を開始する。NULL親は新しいテーマとして表示できる。同梱カタログの正本は[config/discovery-catalog-v1.json](config/discovery-catalog-v1.json)。版とSHA-256を保存する。流行・需要の自動検知、有料AIへの接続はない。

## 既存LA-1・LA-2

利用者の指定でPR #2の既存コード・設定・テスト・CI・スキルを変更せず取り込んだ。検索入口から素材取得や分析を起動しない。実行手順と人工素材/実素材の区別は[専用README](docs/local-analysis/README.md#la-1la-2の実装と起動2026-10-14)。LA-3は未実装。
