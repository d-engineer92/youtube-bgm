#!/usr/bin/env python3
"""LA-1/LA-2 CLI. No database, model API, media production or publication code."""

import argparse
import json
from pathlib import Path
import sys

from common import Failure, read_json
from contracts import validate_bundle, validate_result
from engine import Engine


def summary(state):
    keys = [
        "job_id",
        "run_id",
        "state",
        "ingested_at",
        "expires_at",
        "error",
        "cleanup_status",
        "deleted_at",
        "deleted_files",
        "db_import_status",
    ]
    out = {k: state.get(k) for k in keys}
    if state["pack_hash"] and state["state"] not in ["DONE", "EXPIRED"]:
        out["measurement_pack"] = "work/" + state["job_id"] + "/measurement-pack.json"
    if state["state"] == "DONE":
        out["result"] = "results/" + state["run_id"]
    return out


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path)
    parser.add_argument("--limits", type=Path)
    subs = parser.add_subparsers(dest="command", required=True)
    subs.add_parser("doctor")
    subs.add_parser("recover")
    submit = subs.add_parser("submit")
    submit.add_argument("manifest", type=Path)
    for cmd in [
        "stage-local",
        "download",
        "prepare",
        "finalize",
        "cleanup",
        "status",
        "validate",
    ]:
        p = subs.add_parser(cmd)
        p.add_argument("--job", required=cmd != "status")
        if cmd == "finalize":
            p.add_argument("--insights", type=Path, required=True)
        if cmd == "cleanup":
            p.add_argument("--dry-run", action="store_true")
        if cmd == "validate":
            p.add_argument("--kind", choices=["insights", "bundle"], required=True)
            p.add_argument("--input", type=Path)
    args = parser.parse_args(argv)
    try:
        engine = Engine(args.root, args.limits)
        with engine.locked(
            maintenance=not (args.command == "cleanup" and args.dry_run)
        ):
            if args.command == "doctor":
                out = {
                    "status": "ok",
                    "tool_versions": engine.tool_versions(),
                    "profile_sha256": engine.profile_hash,
                    "prompt_sha256": engine.prompt_hash,
                    "mode_default": "audio",
                    "model_identity_status": "not_exposed",
                }
            elif args.command == "submit":
                out = summary(engine.submit(args.manifest))
            elif args.command in ["stage-local", "download"]:
                out = summary(engine.stage(args.job, args.command == "download"))
            elif args.command == "prepare":
                out = summary(engine.prepare(args.job))
            elif args.command == "finalize":
                out = summary(engine.finalize(args.job, args.insights))
            elif args.command == "cleanup":
                state = engine.get(args.job)
                expired = (
                    state["expires_at"] is not None
                    and state["expires_at"] <= engine.clock()
                )
                out = engine.cleanup(
                    args.job,
                    dry=args.dry_run,
                    expired=expired and not engine.result_path(state).exists(),
                )
                out = out if args.dry_run else summary(out)
            elif args.command in ["status", "recover"]:
                paths = (
                    [engine.state_path(args.job)]
                    if getattr(args, "job", None)
                    else sorted((engine.root / "jobs").glob("*.json"))
                )
                out = [summary(engine.get(p.stem)) for p in paths]
            elif args.command == "validate":
                state = engine.get(args.job)
                if args.kind == "bundle":
                    validate_bundle(
                        engine.result_path(state), mode=engine.request(state)["mode"]
                    )
                else:
                    if args.input is None:
                        raise Failure("insights_input_required")
                    pack = engine.load_pack(state)
                    data = {
                        k: v
                        for k, v in pack.items()
                        if k not in ["context", "acquisition"]
                    }
                    data["provenance"] = dict(
                        pack["provenance"], measurement_pack_sha256=state["pack_hash"]
                    )
                    data["interpretation"] = read_json(args.input, 1024 * 1024)
                    validate_result(
                        data, pack, state["pack_hash"], engine.request(state)["mode"]
                    )
                out = {"status": "valid", "job_id": args.job, "kind": args.kind}
        print(json.dumps(out, ensure_ascii=False, allow_nan=False))
        states = out if isinstance(out, list) else [out]
        return (
            1
            if any(
                item.get("state")
                in [
                    "DOWNLOAD_FAILED",
                    "ANALYSIS_FAILED",
                    "VALIDATION_FAILED",
                    "CLEANUP_FAILED",
                    "EXPIRED",
                ]
                for item in states
            )
            else 0
        )
    except (Failure, OSError, ValueError, KeyError, TypeError, RuntimeError) as e:
        # Error codes only: raw subprocess output/paths/URLs/tracebacks are private.
        reason = str(e) if isinstance(e, Failure) else "operation_failed"
        print(json.dumps({"status": "error", "reason": reason}), file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
