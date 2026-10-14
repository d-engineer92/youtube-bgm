# 出典・確認事項

> 文書ID：LMA-007  
> 状態：設計根拠と外部参照。個別素材の利用許諾・実環境の疎通は未確認。  
> 確認日：2026-10-14

## ユーザー指定

ダウンロードした素材をCodexとローカルツールで分析し、固定プロンプト・固定形式で結果を保存する。DLディレクトリは分析可能なものだけにし、分析後の素材は削除する。追加有料APIは当面使わず、GitHubのdocsへ分割して実装指示を置く。実装はCodexへ依頼する。

この文書群のディレクトリ名、24時間TTL、件数・容量上限、10秒窓、スキーマ、状態名はプロジェクトの設計提案であり、YouTubeが求める数値や成功法則ではない。

既存のユーザー提供本文 `AI×BGM.txt` には、冒頭・テンポ・曲調・楽器を言語化する観点（L277–291）、利用場面と音楽・見た目の一致（L1847–1864）がある。これを観察の問いとして参照するが、モデルが未試聴で楽器を確定できる根拠にはしない。会員限定URL先は参照せず、記事本文はリポジトリへ収録しない。

## 公式資料

### S-L1：Codexのスキル

https://developers.openai.com/codex/skills

現在は https://learn.chatgpt.com/docs/build-skills へ転送。SKILL.md、name/description、任意のscripts/references、repoの.agents/skills、agents/openai.yamlのallow_implicit_invocationを確認。スキルは手順の再利用であり、常駐処理や結果の完全一致を保証しない。

### S-L2：Codexの推論とローカル実行

https://openai.com/index/unrolling-the-codex-agent-loop/

ChatGPTログイン時のCodex CLIが推論要求を送信する仕組みを確認。ローカルコマンド実行と、完全オフラインのモデル推論は区別する。

https://developers.openai.com/codex/auth

認証方式の公式入口。実環境のログイン方式と契約利用枠を確認する。追加API課金なしと利用枠の無制限を混同しない。

### S-L3：yt-dlp

https://github.com/yt-dlp/yt-dlp

単一URL・batch入力、pathsのhome/temp、出力テンプレートを参照。実装時は採用版と依存（FFmpeg等、YouTube対応に必要な実行環境）を確認する。対応状況が変わり得るため、全公開URLの取得成功は保証しない。

### S-L4：YouTube利用規約

https://www.youtube.com/static?template=terms

Permissions and Restrictionsにダウンロード等の使用と自動アクセスの制限が記載されている。公開URL、権利者への帰属、分析後削除だけを取得許可の根拠にしない。サービス側と権利者側の条件を個別確認する。自作原本や正式に提供された素材を優先できる入力経路を用意する。

### S-L5：FFmpeg

https://ffmpeg.org/ffmpeg-filters.html

ebur128、astats、silencedetect等の計測機能を参照。採用版におけるパラメータ、出力、欠損・無音時の挙動は実装テストで確認する。

## 既存仕様との境界

YouTube Data API由来データの扱いは [POL-001](../compliance/data-retention.md) を維持する。ローカル解析結果を加えることは、APIの保存制限や独自指標に関する条件を免除するものではない。今回の基本動作はAPI統計を入力せず実行できるようにする。

個別の競合素材の取得・分析許諾、Codexへのテキスト・画像送信条件、実際のモデル識別子、yt-dlpの対象URL対応、音楽的特徴の推定精度は今回の文書作成では検証していない。

[入口へ戻る](README.md)

## LA-1・LA-2実装時の確認（2026-10-14）

上記S-L1・S-L3・S-L4・S-L5を今回の実装時に再確認した。Codexのrepo-localスキルと明示呼び出しの設定は[公式Build skills](https://learn.chatgpt.com/docs/build-skills)の案内を使用。FFmpegフィルターの実際の出力は固定版7.1.5で人工素材により確認。yt-dlpは2026.8.19の固定版のhelp/versionで採用オプションを確認したが、実動画の取得は行っていない。

Python 3.12.15、jsonschema 4.26.0、yt-dlp 2026.8.19、FFmpeg/ffprobe 7.1.5のDocker環境を使用。直接・間接Python依存はrequirements.lock、コンテナーベースはDockerfileのdigestで固定する。実モデル識別子と推論設定は未取得でnullと記録。バッチからモデルAPIやDBへの接続は行わない。

## EJS依存と再試行修正の確認（2026-10-14）

[yt-dlp公式EJS案内](https://github.com/yt-dlp/yt-dlp/wiki/EJS)を再確認した。YouTube対応にはJSランタイムとEJSが必要で、Nodeは22以上が対応範囲、明示的な有効化が必要。PyPIのEJS同梱パッケージを直接導入する場合は、採用yt-dlp版のpyproject.tomlが指定する版に合わせる。

[2026.08.19のpyproject.toml](https://github.com/yt-dlp/yt-dlp/blob/2026.08.19/pyproject.toml)と実際のパッケージメタデータはyt-dlp-ejs==0.8.0を指定している。DockerではNode 24.21.0の公式イメージをdigestで固定し、EJS 0.8.0はrequirements.lockに追加した。最新版への自動更新、GitHub/npmからの実行時EJS取得は採用しない。doctorで同梱スクリプトを採用yt-dlpの期待ハッシュと照合し、固定Nodeの権限制限付きロードとCLI設定まで検査した。

公式案内は依存の導入根拠であり、個別URLの取得許可や取得成功の証明ではない。利用者から素材・承認URLとも未提供と回答があり、実YouTube取得と実曲の音声のみ/画像付き検証は未確認。人工動画のフレーム途中停止・再試行と出力/原本保護はREADMEの実行記録を参照する。
