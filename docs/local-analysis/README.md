# ローカル素材分析：追加仕様の入口

> 文書ID：LMA-001  
> 状態：LA-1・LA-2実装済み・人工素材検証済み。実素材検証とLA-3は未実施。\
> 整理日：2026-10-14  
> 読む場面：ローカル取得→分析→結果保存→一時素材削除の追加実装を行うとき。

## 目的と範囲

許諾を確認した競合素材と自分の素材を同じ分析条件で調べ、計測値とCodexの考察を分けて保存する。追加の有料AI APIを接続しない。原素材を蓄積せず、検証済みの分析結果を残す。

現在進行中の競合検索・比較基盤とは別の小さな実装として扱う。既存の初回実装を止めたり、今回の追加機能をその完了条件に混ぜたりしない。

## 採用する分担

| 担当 | 責務 |
|---|---|
| ローカルバッチ | 入力検証、許諾確認状態の検査、取得、計測、排他制御、形式検証、結果確定、限定された一時領域の削除 |
| Codexスキル | 固定手順を呼び出し、固定プロンプトで証拠を読み、考察JSONを作る |
| 人 | 素材の利用根拠、試聴が必要な点、仮説の採否を確認する |
| 後続の取込バッチ | 検証済みJSONをPostgreSQLへ冪等に取り込む。初回のファイル出力はDBなしで動く |

スキルだけに削除・成功判定を任せない。スクリプトが安全条件を強制する。プロンプトを固定してもAIの文章・解釈の完全一致は保証しない。入力ハッシュ、計測条件、依存ツール、モデル、プロンプトの版を記録して比較可能にする。

## 読む文書

| 作業 | 正本 |
|---|---|
| 取得・ディレクトリ・状態・削除・復旧 | [ライフサイクル](workflow.md) |
| 分析項目・区間・計測条件 | [分析プロファイル](analysis-profile.md) |
| JSON/CSV/Markdown・DB取込 | [出力契約](output-contract.md)、[JSON Schema](schemas/analysis-v1.schema.json) |
| スキルの配置・起動・固定手順 | [Codexスキル仕様](codex-skill.md) |
| Codexが毎回読む分析指示 | [固定プロンプト review-v1](prompts/review-v1.md) |
| 実装順・テスト・Codexへの依頼文 | [実装依頼と受入条件](implementation.md) |
| 根拠・外部仕様・取得条件 | [出典と確認事項](sources.md) |

## 費用と実行場所

通常のChatGPTログインを使うCodexは、ローカルPCでコマンドを実行しても、モデルへの推論要求は外部サービスへ送られる。完全オフライン分析とは呼ばない。計測はローカル、考察に必要な要約・許可済み画像はCodexへ送信される構成である。[出典](sources.md)

追加APIキーによるGemini/OpenAI等の呼び出し、追加クレジットの自動購入、課金先の自動切替は実装しない。契約内のCodex利用枠は消費する。上限到達時は停止・再開し、無制限無料とは表示しない。

既定は音声のみ。音声本体をモデルへ送信しない。画像入力は権利・送信条件を別途確認したオプション。モデル名はこの文書で特定の製品名に固定せず、利用者が実環境で選択したモデルを実行設定として固定し記録する。

## 今回まだ作らないもの

新しいWeb画面、制作・音源生成、YouTube投稿、競合の大量巡回、コメント自動収集、音源分離、楽器や感情を断定する専用モデル、常時稼働のCodex自動実行基盤、複雑なワークフローエンジン。取得制限回避も実装しない。

[文書一覧へ戻る](../README.md)

## LA-1・LA-2の実装と起動（2026-10-14）

ローカルバッチと明示呼び出し専用スキルを実装。27件の人工素材・依存検査テストを、外部ネットワークとDBを接続しないコンテナーで確認した。実曲・実YouTube URLの取得・実画像のCodex入力は未確認。

WSL/LinuxとDockerを使う。元ファイルのフォルダーは管理ルートの外に置き、読み取り専用でマウントする。依存は固定したDockerイメージを明示的にビルドし、分析中に更新しない。

```bash
docker build -f scripts/local-analysis/Dockerfile -t youtube-bgm-local-analysis:la1-la2 .
scripts/local-analysis/run doctor
```

[マニフェスト例](examples/request-local.example.json)をGit対象外の`var/local-analysis/request.json`等へコピーして編集する。承認欄は人が確認した状態だけを記入し、自作原本は`/originals/曲名.wav`のようにマウント内のパスを指定する。

```bash
# 自分が許諾を確認した原本フォルダー。バッチからは読み取り専用。
export BGM_ORIGINALS=/absolute/path/to/authorized-originals
scripts/local-analysis/run submit /repo/var/local-analysis/request.json
scripts/local-analysis/run stage-local --job UUID
scripts/local-analysis/run prepare --job UUID
```

YouTube URLを承認した場合だけ`stage-local`の代わりに`download --job UUID`を使う。このコマンドだけネットワークを有効にする。Node 24.21.0と、採用版yt-dlpに対応するyt-dlp-ejs 0.8.0をDockerビルド時に固定導入する。実行時は既定のJS候補をリセットして固定Nodeだけを有効にし、外部EJS取得・プラグイン探索・自己更新を無効にする。Cookiesや制限回避は追加していない。必要な条件を満たさない動画は失敗し、全公開動画の取得対応は保証しない。[根拠・固定版の検査](sources.md#ejs依存と再試行修正の確認2026-10-14)

`prepare`が返す管理下のmeasurement-packを使い、リポジトリの`$bgm-local-analyze`を明示呼び出しする。固定プロンプトに従う考察だけを`var/local-analysis/work/UUID/insights.json`へ書く。音声はモデルへ渡さない。画像も送信確認・実際の画像入力がない場合は所見を作らない。

```bash
scripts/local-analysis/run validate --kind insights --job UUID --input /repo/var/local-analysis/work/UUID/insights.json
scripts/local-analysis/run finalize --job UUID --insights /repo/var/local-analysis/work/UUID/insights.json
scripts/local-analysis/run status --job UUID
```

finalizeがJSON/CSV/Markdown/bundleの検証・確定と削除を続けて実施する。DONEでは`results/<run_id>`だけが残り、原本は不変。失敗時は終了コードが非ゼロ。CLIの出力を成功へ丸めない。

```bash
# 各操作の前後にも復旧・期限処理を実施する
scripts/local-analysis/run recover
scripts/local-analysis/run cleanup --job UUID --dry-run
scripts/local-analysis/run cleanup --job UUID
```

確定前の素材を期限内に手動削除するコマンドはない。期限切れはEXPIREDとして削除し、新しいUUIDで明示的に再受付する。同じjob/同じ設定は再DL・再考察しない。コード、profile、prompt、運用設定を変えた分析は新しいjob/runが必要。結果のcode_revisionは解析コード一式のSHA-256、実モデル識別子が環境から取得できない場合はnullとする。

`--root`と`--limits`はmain.pyのグローバル引数（サブコマンドの前）である。既定以外の専用ルートも識別マーカー・所有者・リンク検査が必要。通常の操作は復旧・期限処理を先に実施する。cleanupのdry-runはプレビュー専用で、期限切れを含め素材を削除せず、対象の件数と安全条件だけを確認する。実際の処理は次の通常操作で実施する。

### 実行した検証

```bash
docker run --rm --user "$(id -u):$(id -g)" --network none --volume "$PWD:/repo" --entrypoint python youtube-bgm-local-analysis:la1-la2 -m unittest discover -s tests/local-analysis -v
```

人工素材・依存検査の27テスト成功。全無音、短尺、10秒窓の余り、片チャンネル無音、途中デコード失敗、元ファイル保護、参照・単位・区間・非有限値の拒否、同一依頼の再投入、同時claim、TTL不延長、保存失敗、削除失敗、確定rename前後と削除途中の復旧を確認。SIGKILLでの中断・親停止後の子プロセス終了も確認した。CIも同じコンテナーと人工素材を使う。

画像付きの人工動画は、1枚のframe-f00.png公開後に実SIGKILL、または2枚目の抽出で失敗させ、同じjob/runで再試行した。毎回新しい所有下のサブディレクトリへ抽出し、PTSと通常ファイルを確認して固定画像名へ原子的に保存する。既存画像へのFFmpeg上書きは行わない。リンク・別所有者の領域は拒否し、失敗時の中間ファイルは結果確定後のcleanupまで保つ。再試行から結果確定・JSON由来のCSV/Markdown一致・全一時領域削除・原本ハッシュ/サイズ不変まで成功した。画像をCodexで観察するテストとは区別する。

doctorは固定Nodeの実版、EJSの版とyt-dlpの互換条件、同梱スクリプトのハッシュ、権限制限付きNodeでのロード、固定CLI設定をネットワークなしで検査する。欠落・不一致・スクリプト改変・NODE_OPTIONSによる設定上書きは停止し、自動導入や自動更新を行わない。

追加で12.5秒の人工音声をCLI経由で登録・計測し、Codexが固定プロンプトから数値だけの考察JSONを作成してfinalizeまで通した。3形式・bundleが残り、downloads/work/tmpの対象素材がなく、保護した人工原本のSHA-256・サイズが不変だった。実音楽の分析確認とは分ける。

実素材検証済みの項目はなし。今回の修正確認では利用者から実曲・承認済みYouTube URLとも未提供のため、音声のみ・画像付き双方の実素材確認と実取得は未実施。yt-dlpの実ネットワーク対応、実モデル/推論設定の取得、画像の実送信・観察、長時間の実素材・実機強制電源断での挙動は未確認。通常削除は物理消去や外部バックアップの完全消去を保証しない。

DB取込LA-3、新Web画面、有料AI API、楽曲生成、動画制作、投稿は未実装。ファイル出力と削除はPostgreSQL停止に依存しない。
