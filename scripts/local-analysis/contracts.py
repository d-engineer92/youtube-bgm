"""Schema + semantic checks and deterministic, safely escaped exports."""

import csv
import hashlib
import html
import io
import re
from jsonschema import Draft202012Validator, FormatChecker
from common import Failure, REPO, read_json, encoded

UNITS = {
    "duration_seconds": "s",
    "integrated_loudness_lufs": "LUFS",
    "loudness_range_lu": "LU",
    "sample_peak_dbfs": "dBFS",
    "true_peak_dbtp": "dBTP",
    "silence_total_seconds": "s",
    "leading_silence_seconds": "s",
    "trailing_silence_seconds": "s",
    "silence_interval_seconds": "s",
    "rms_dbfs": "dBFS",
    "tempo_bpm": "BPM",
    "spectral_centroid_hz": "Hz",
    "low_band_ratio": "ratio",
    "high_band_ratio": "ratio",
}
REQUIRED = list(UNITS)[:8]
REASONS = {"all_silence", "short_duration", "below_loudness_gate"}
SCHEMA = REPO / "docs/local-analysis/schemas/analysis-v1.schema.json"


def schema_check(value, insights=False):
    schema = read_json(SCHEMA)
    if insights:
        schema = {"$ref": "#/$defs/interpretation", "$defs": schema["$defs"]}
    if list(
        Draft202012Validator(schema, format_checker=FormatChecker()).iter_errors(value)
    ):
        raise Failure("schema_validation_failed")


def validate_result(data, pack=None, pack_hash=None, mode="audio"):
    # Roundtrip also rejects nonfinite values for callers supplying in-memory objects.
    encoded(data)
    schema_check(data)
    duration = data["source"]["duration_seconds"]
    ids = []
    for section in ["measurements", "evidence"]:
        ids.extend(x["id"] for x in data[section])
    ids.extend(x["id"] for x in data["interpretation"]["observations"])
    if len(ids) != len(set(ids)):
        raise Failure("duplicate_evidence_id")
    for row in data["measurements"]:
        if row["unit"] != UNITS[row["metric"]]:
            raise Failure("metric_unit_mismatch")
        if not 0 <= row["start_seconds"] < row["end_seconds"] <= duration + 1e-6:
            raise Failure("invalid_measurement_interval")
        if row["kind"] == "unavailable" and row["reason"] not in REASONS:
            raise Failure("required_measurement_incomplete")
    for metric in REQUIRED:
        rows = [r for r in data["measurements"] if r["metric"] == metric]
        if (
            len(rows) != 1
            or rows[0]["start_seconds"] != 0
            or abs(rows[0]["end_seconds"] - duration) > 1e-6
        ):
            raise Failure("missing_full_measurement")
    windows = [r for r in data["measurements"] if r["metric"] == "rms_dbfs"]
    cursor = 0
    for window in windows:
        if (
            abs(window["start_seconds"] - cursor) > 1e-6
            or window["end_seconds"] - cursor > 10.000001
        ):
            raise Failure("rms_coverage_gap")
        cursor = window["end_seconds"]
    if abs(cursor - duration) > 1e-6:
        raise Failure("rms_coverage_gap")
    if not data["coverage"] or any(
        c["scope"] != "full"
        or c["status"] != "complete"
        or c["start_seconds"] != 0
        or abs(c["end_seconds"] - duration) > 1e-6
        for c in data["coverage"]
    ):
        raise Failure("incomplete_coverage")
    intervals = [
        r for r in data["measurements"] if r["metric"] == "silence_interval_seconds"
    ]
    last = 0
    for row in intervals:
        if (
            row["start_seconds"] < last
            or abs(row["value"] - (row["end_seconds"] - row["start_seconds"])) > 1e-6
        ):
            raise Failure("invalid_silence_union")
        last = row["end_seconds"]
    total = next(
        r["value"]
        for r in data["measurements"]
        if r["metric"] == "silence_total_seconds"
    )
    if total is None or abs(total - sum(r["value"] for r in intervals)) > 1e-5:
        raise Failure("invalid_silence_total")
    for e in data["evidence"]:
        if e["time_seconds"] is not None and e["time_seconds"] > duration:
            raise Failure("invalid_evidence_time")
    images = {e["id"] for e in data["evidence"] if e["kind"] in ["frame", "thumbnail"]}
    human = {e["id"] for e in data["evidence"] if e["kind"] == "human_note"}
    viewed = set(data["interpretation"]["viewed_evidence_ids"])
    if not viewed <= images or (mode == "audio" and (viewed or images)):
        raise Failure("unconfirmed_image")
    references = {r["id"] for r in data["measurements"] + data["evidence"]}
    exploratory = 0
    for row in data["interpretation"]["observations"]:
        refs = set(row["evidence_refs"])
        if not refs <= references:
            raise Failure("unknown_evidence_reference")
        if row["kind"] == "visual_observation" or row["category"] in [
            "visual",
            "alignment",
        ]:
            if not refs & viewed:
                raise Failure("unconfirmed_image")
        if row["kind"] == "human_observation" and not refs & human:
            raise Failure("unprovided_human_observation")
        if not row["alternative_explanations"]:
            raise Failure("missing_alternative_explanation")
        exploratory += row["category"] == "exploratory"
    if exploratory > 3:
        raise Failure("too_many_exploratory_observations")
    if pack:
        for key in [
            "schema_version",
            "run_id",
            "job_id",
            "analysis_status",
            "source",
            "coverage",
            "measurements",
            "evidence",
            "human_review",
        ]:
            if data[key] != pack[key]:
                raise Failure("measurement_pack_mismatch")
        expected = dict(pack["provenance"], measurement_pack_sha256=pack_hash)
        if data["provenance"] != expected:
            raise Failure("provenance_mismatch")
    if re.search(r"https?://", str(data["interpretation"])):
        raise Failure("external_url_in_result")
    return True


def csv_cell(value):
    if value is None:
        return ""
    if isinstance(value, str) and value.lstrip().startswith(("=", "+", "-", "@")):
        return "'" + value
    return str(value)


def exports(data):
    columns = [
        "schema_version",
        "run_id",
        "job_id",
        "source_sha256",
        "profile_id",
        "measurement_id",
        "metric",
        "start_seconds",
        "end_seconds",
        "value",
        "unit",
        "kind",
        "method",
        "reason",
    ]
    stream = io.StringIO(newline="")
    writer = csv.writer(stream, lineterminator="\r\n")
    writer.writerow(columns)
    for row in data["measurements"]:
        values = [
            data["schema_version"],
            data["run_id"],
            data["job_id"],
            data["source"]["sha256"],
            data["provenance"]["profile_id"],
            row["id"],
        ] + [
            row[k]
            for k in [
                "metric",
                "start_seconds",
                "end_seconds",
                "value",
                "unit",
                "kind",
                "method",
                "reason",
            ]
        ]
        writer.writerow([csv_cell(v) for v in values])

    def safe(text):
        # Escape HTML and Markdown links/images/code/headings; user text never becomes executable markup.
        text = html.escape(str(text), quote=True)
        return re.sub(r"([\\`*_{}\[\]()#+.!|>~-])", r"\\\1", text).replace("\n", " ")

    headings = [
        "対象・分析範囲・版",
        "計測から分かったこと",
        "良い点と改善候補",
        "追加の気づき",
        "別の説明・未確認事項",
        "次の1本で試すこと",
        "データ取扱いと人の確認状態",
    ]
    sections = [
        f"run: {safe(data['run_id'])} / profile: {safe(data['provenance']['profile_id'])}",
        safe(data["interpretation"]["summary"]),
        "\n".join(
            "- " + safe(o["text"]) + " / 根拠: " + safe(", ".join(o["evidence_refs"]))
            for o in data["interpretation"]["observations"]
            if o["category"] != "exploratory"
        )
        or "未確認",
        "\n".join(
            "- " + safe(o["text"])
            for o in data["interpretation"]["observations"]
            if o["category"] == "exploratory"
        )
        or "追加なし",
        "\n".join("- " + safe(t) for t in data["interpretation"]["limitations"])
        + "\n"
        + "\n".join(
            "- " + safe(t)
            for o in data["interpretation"]["observations"]
            for t in o["alternative_explanations"]
        ),
        "\n".join("- " + safe(t) for t in data["interpretation"]["next_experiments"])
        or "未確認",
        "人の確認: pending。音の直接聴取・楽器・ジャンル・テンポ推定は未実施。素材削除後の再確認には原本または再取得が必要。",
    ]
    report = (
        "\n\n".join(
            "## " + h + "\n\n" + content for h, content in zip(headings, sections)
        )
        + "\n"
    )
    return {
        "analysis.json": encoded(data),
        "features.csv": stream.getvalue().encode(),
        "report.md": report.encode(),
    }


def validate_bundle(folder, pack=None, pack_hash=None, mode="audio"):
    from common import digest, regular

    bundle = read_json(folder / "bundle.json")
    if set(bundle) != {"schema_version", "run_id", "files"} or set(bundle["files"]) != {
        "analysis.json",
        "features.csv",
        "report.md",
    }:
        raise Failure("invalid_bundle")
    if {p.name for p in folder.iterdir()} != {
        "analysis.json",
        "features.csv",
        "report.md",
        "bundle.json",
    }:
        raise Failure("unexpected_result_file")
    for name, info in bundle["files"].items():
        if (
            set(info) != {"sha256", "size"}
            or regular(folder / name).st_size != info["size"]
            or digest(folder / name) != info["sha256"]
        ):
            raise Failure("bundle_hash_mismatch")
    data = read_json(folder / "analysis.json")
    validate_result(data, pack, pack_hash, mode)
    if (
        data["run_id"] != bundle["run_id"]
        or data["schema_version"] != bundle["schema_version"]
    ):
        raise Failure("bundle_identity_mismatch")
    for name, payload in exports(data).items():
        if hashlib.sha256(payload).hexdigest() != bundle["files"][name]["sha256"]:
            raise Failure("export_json_mismatch")
    return data
