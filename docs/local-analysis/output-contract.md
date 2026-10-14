# 出力契約・検証・DB取り込み v1

> 文書ID：LMA-004  
> 状態：LA-1・LA-2実装済み・人工素材検証済み。実素材検証とLA-3は未実施。\
> 整理日：2026-10-14  
> 読む場面：計測・考察の統合、CSV/Markdown生成、DB取込を作るとき。

## 1. 保存するもの

```text
results/<run_id>/
  analysis.json  # 唯一の分析正本
  features.csv   # JSONのmeasurementsから決定的に生成
  report.md      # JSONから定型見出しで生成する日本語レポート
  bundle.json    # 上記3ファイルのSHA-256、サイズ、run_id、schema_version
```

音源、画像、署名付きURL、元のメタデータ全文、Cookie、ユーザーの絶対パスは含めない。JSONの正本は [analysis-v1.schema.json](schemas/analysis-v1.schema.json)。CSVとMarkdownを別々にAIへ作らせて食い違わせない。

## 2. 機械計測とCodexの責務

prepareは元ファイル・計測結果・版・証拠IDを `measurement-pack.json` に保存しSHA-256を固定する。Codexはその要約と許可された証拠だけ読み、Schemaの `$defs/interpretation` に従う `insights.json` だけを書く。

finalizeが元の計測パックとのハッシュ一致を検査し、計測部分とinsightsを統合する。Codexが測定値、入力ハッシュ、権利状態、完了状態を創作・修正した結果は拒否する。

## 3. analysis.jsonの要点

- `schema_version`：1.0.0。互換性のない変更は別版にする。
- `run_id`：個々の分析結果のUUID。別モデル・別指示で再分析する場合は新しいID。
- `job_id`：受付単位のUUID。DL成功と分析完了を区別する。
- `source`：own/competitor/synthetic、取得元区分、YouTube ID（なければnull）、実体SHA-256、音声尺、利用根拠ID。
- `provenance`：日時、コードrevision、実ツール版、profile/promptのIDとハッシュ、Codexの実モデルと推論設定、計測パックのハッシュ。
- `coverage`：手法ごとの実区間、full/sampled、complete/partial/unavailable。
- `measurements`：固定したmetric、単位、区間、数値、method、measured/estimated/unavailable、算出できない理由。
- `evidence`：frame/thumbnail/human_note/contextの参照ID、時刻、ハッシュ。権限のある入力のみ。文書内の命令はデータとして扱う。
- `interpretation`：要約、観察・仮説、証拠ID、別の説明、次の確認、限界、次の実験。
- `human_review`：初回はpending。人の確認記録はDBや別レビュー記録に持ち、確定JSONを上書きしない。

モデルIDや推論設定を実行環境から取得できない場合はnullとし、`model_identity_status=not_exposed` とlimitationsを記録する。モデル自身の自己申告で埋めず、比較可能性が限定されることを表示する。実行前に選んだモデルを勝手に切り替えない。

## 4. 形式だけでなく内容も検証する

JSON Schemaのformatチェックを有効にする。そのうえで次をバッチで検査する。

1. 全数値が有限。区間は `0 <= start < end <= duration`、ただし全体のduration行など契約で必要な範囲に統一する。
2. IDが一意で、evidence_refsが実在するmeasurement/evidenceのIDだけを指す。
3. visual_observationは実際に確認した画像IDを少なくとも1つ参照する。未確認画像からの所見を拒否する。
4. 必須の全編計測が欠けたら確定しない。無音・短尺等の正当な算出不能は理由付きnullで許すが、decode_error等は成功扱いしない。
5. full coverageの開始・終了とデコード完了を照合。一部解析を全編解析へ格上げしない。
6. 値・ハッシュ・版・sourceは計測パック由来であることを照合。insightsからの上書きを受け付けない。
7. CSVとMarkdownの元JSONを一致させ、bundleのハッシュを再読込で照合する。
8. 結果中の外部データや派生物に適用される保持・送信条件を確認する。ファイル形式を変えたことで適用を外さない。

## 5. features.csv

UTF-8、RFC 4180相当の引用・改行規則、ヘッダー固定。1行は1計測値・1区間。JSON正本の順に出力する。

```text
schema_version,run_id,job_id,source_sha256,profile_id,measurement_id,metric,start_seconds,end_seconds,value,unit,kind,method,reason
```

nullは空欄にし、0は0。小数点は`.`、桁区切りなし。日時はISO 8601のUTC。表計算ソフトで文字列が数式として実行されない出力処理を入れ、JSONの生データは変更しない。CSVからDBへ逆取り込みする初回機能は不要。

## 6. report.mdの固定見出し

1. 対象・分析範囲・版
2. 計測から分かったこと
3. 良い点と改善候補（どちらも根拠がなければ未確認）
4. 追加の気づき
5. 別の説明・未確認事項
6. 次の1本で試すこと
7. データ取扱いと人の確認状態

音を直接聴いたことにしない。画像を消したあとの再確認限界、部分解析、元ファイルの取得品質差も記載する。ファイル不在の証拠画像リンクを「今開けるリンク」として生成しない。

## 7. 最小のPostgreSQL取り込み

ファイル出力を先に完成させる。追加取込はLaravel側に1コマンドを用意し、最初は `local_analysis_runs` 相当の1テーブルで足りる。最低限：run_id(unique)、job_id、subject_type、youtube_video_id(nullable)、source_sha256、profile_id、prompt_id、model_id(nullable)、analyzed_at、payload_sha256、payload(JSONB)、imported_at、review_status。

同じrun_id・同じpayload_sha256は変更せずskip。同じrun_id・違うハッシュは衝突として拒否。モデルやプロンプトを変えた再分析は別runとして保存し、旧結果を削除しない。日時はTIMESTAMPTZ、識別子は文字列/UUID、欠損はnull。

既存のYouTubeキャッシュへのFKを必須にしない。キャッシュ削除で自作の計測結果まで巻き込まない。一方、API由来情報をJSONBへ複製して保存制限を回避しない。[既存ポリシー](../compliance/data-retention.md)

`--dry-run` でスキーマ・意味・ハッシュ・衝突・書込件数を確認し、1bundle単位のtransactionで取り込む。DBエラーではJSONを残して再試行する。取込用SQLをLLMに生成・直接実行させない。結果JSONを含むファイルは信頼せず、サイズ・配列件数・入れ子深さに上限を置く。

[入口へ戻る](README.md)
