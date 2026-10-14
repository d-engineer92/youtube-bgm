"""File-backed lifecycle. All mutations are serialized; deletion accepts only a validated job UUID."""

from contextlib import contextmanager
from datetime import datetime, timezone
import fcntl
import hashlib
import importlib.metadata
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import time
from urllib.parse import parse_qs, urlsplit
import uuid

from common import (
    Failure,
    REPO,
    canonical_id,
    check_path,
    digest,
    encoded,
    fsync_dir,
    read_json,
    regular,
    write_bytes,
    write_json,
)
from contracts import exports, schema_check, validate_bundle, validate_result
import media

AREAS = ["tmp/downloading", "downloads", "work", "results/.staging"]
FINAL = {"DONE", "EXPIRED"}


def download_runtime_options():
    settings = read_json(REPO / "config/local-analysis/toolchain.json")
    if (
        settings.get("javascript_runtime") != "node"
        or settings.get("javascript_runtime_path") != "/usr/local/bin/node"
        or settings.get("remote_components") != []
        or os.environ.get("NODE_OPTIONS")
    ):
        raise Failure("unsafe_javascript_settings")
    return [
        "--no-js-runtimes",  # Reset defaults, then enable only the pinned runtime.
        "--js-runtimes",
        "node:/usr/local/bin/node",
        "--no-remote-components",
        "--no-plugin-dirs",
        "--no-update",
    ]


def youtube_id(value):
    import re

    u = urlsplit(value)
    if u.scheme != "https" or u.username or u.password or u.port:
        raise Failure("unsafe_youtube_url")
    identifier = None
    if u.hostname in ["youtu.be", "www.youtu.be"]:
        identifier = u.path.strip("/")
    elif (
        u.hostname in ["youtube.com", "www.youtube.com", "m.youtube.com"]
        and u.path == "/watch"
    ):
        values = parse_qs(u.query).get("v", [])
        if len(values) == 1:
            identifier = values[0]
    if not identifier or not re.fullmatch(r"[A-Za-z0-9_-]{11}", identifier):
        raise Failure("not_single_video")
    return identifier


def request_check(data):
    keys = {
        "schema_version",
        "job_id",
        "input_kind",
        "input_value",
        "subject_type",
        "mode",
        "profile_id",
        "created_at",
        "rights_record_id",
        "acquisition_approved",
        "analysis_approved",
        "derived_text_to_codex_approved",
        "images_to_codex_approved",
        "context",
    }
    if set(data) != keys:
        raise Failure("invalid_request_keys")
    canonical_id(data["job_id"])
    if data["schema_version"] != "1.0.0" or data["profile_id"] != "analysis-profile-v1":
        raise Failure("unknown_profile")
    if (
        data["input_kind"] not in ["local_file", "youtube_url"]
        or data["subject_type"] not in ["own", "competitor", "synthetic"]
        or data["mode"] not in ["audio", "audio_visual"]
    ):
        raise Failure("invalid_request_enum")
    if (
        not isinstance(data["input_value"], str)
        or not 1 <= len(data["input_value"]) <= 4096
    ):
        raise Failure("invalid_input")
    if (
        not isinstance(data["rights_record_id"], str)
        or not 1 <= len(data["rights_record_id"]) <= 200
        or "REPLACE" in data["rights_record_id"]
    ):
        raise Failure("rights_record_missing")
    for key in [
        "acquisition_approved",
        "analysis_approved",
        "derived_text_to_codex_approved",
        "images_to_codex_approved",
    ]:
        if type(data[key]) is not bool:
            raise Failure("approval_must_be_boolean")
    try:
        created = datetime.fromisoformat(data["created_at"].replace("Z", "+00:00"))
        if created.tzinfo is None:
            raise ValueError()
    except (ValueError, TypeError, AttributeError):
        raise Failure("invalid_created_at") from None
    if not isinstance(data["context"], dict) or set(data["context"]) - {
        "intended_use",
        "research_question",
    }:
        raise Failure("invalid_context")
    if any(not isinstance(v, str) or len(v) > 2000 for v in data["context"].values()):
        raise Failure("context_limit")
    if data["input_kind"] == "youtube_url":
        youtube_id(data["input_value"])
    return data


class Engine:
    def __init__(
        self, root=None, limits=None, clock=time.time, checkpoint=lambda _: None
    ):
        self.root = check_path(root or REPO / "var/local-analysis")
        if (
            self.root in [Path("/"), Path.home(), REPO]
            or ".git" in self.root.parts
            or len(self.root.parts) < 3
        ):
            raise Failure("unsafe_root")
        marker = self.root / ".local-analysis-root.json"
        if self.root.exists():
            if not marker.exists() and any(self.root.iterdir()):
                raise Failure("unmarked_root")
        else:
            self.root.mkdir(parents=True, mode=0o700)
        if not marker.exists():
            write_json(marker, {"format": 1, "root_id": str(uuid.uuid4())})
        self.root_id = canonical_id(read_json(marker)["root_id"])
        for area in ["requests", "jobs", *AREAS, "results"]:
            check_path(self.root / area).mkdir(parents=True, exist_ok=True, mode=0o700)
        self.limits = read_json(limits or REPO / "config/local-analysis/limits.json")
        defaults = read_json(REPO / "config/local-analysis/limits.json")
        if set(self.limits) != set(defaults) or any(
            type(v) is not int or v < 0 for v in self.limits.values()
        ):
            raise Failure("invalid_limits")
        if (
            not 1 <= self.limits["ttl_seconds"] <= 86400
            or not 1 <= self.limits["max_ready"] <= 2
            or not 1 <= self.limits["operation_seconds"] <= 1200
            or not 1 <= self.limits["download_seconds"] <= 600
            or not 0 <= self.limits["download_retries"] <= 3
        ):
            raise Failure("unsafe_limits")
        self.clock = clock
        self.checkpoint = checkpoint
        self.profile = read_json(REPO / "config/local-analysis/profile-v1.json")
        self.profile_hash = digest(REPO / "config/local-analysis/profile-v1.json")
        self.prompt_hash = digest(REPO / "docs/local-analysis/prompts/review-v1.md")
        self.config_hash = hashlib.sha256(encoded(self.limits)).hexdigest()
        self.code_hash = hashlib.sha256(
            b"".join(
                p.name.encode() + p.read_bytes()
                for p in sorted((REPO / "scripts/local-analysis").glob("*.py"))
            )
        ).hexdigest()

    @contextmanager
    def locked(self, maintenance=True):
        path = self.root / ".batch.lock"
        fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
        if os.fstat(fd).st_nlink != 1:
            os.close(fd)
            raise Failure("hardlinked_lock")
        try:
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                raise Failure("batch_busy") from None
            if maintenance:
                self.maintenance()
            yield
            if maintenance:
                self.maintenance()
        finally:
            os.close(fd)

    def state_path(self, jid):
        return self.root / "jobs" / (canonical_id(jid) + ".json")

    def get(self, jid):
        s = read_json(self.state_path(jid))
        if s["job_id"] != jid or s["root_id"] != self.root_id:
            raise Failure("job_owner_mismatch")
        canonical_id(s["run_id"])
        if digest(self.root / "requests" / (jid + ".json")) != s["request_hash"]:
            raise Failure("request_modified")
        return s

    def save(self, s):
        write_json(self.state_path(s["job_id"]), s)

    def request(self, s):
        return request_check(
            read_json(self.root / "requests" / (s["job_id"] + ".json"))
        )

    def path(self, s, area):
        return (
            self.root
            / area
            / (s["run_id"] if area == "results/.staging" else s["job_id"])
        )

    def result_path(self, s):
        return self.root / "results" / s["run_id"]

    def approve(self, s, *keys):
        req = self.request(s)
        for key in keys:
            if not req[key]:
                raise Failure("approval_missing:" + key)
        return req

    def unchanged(self, s):
        if (s["profile_hash"], s["prompt_hash"], s["config_hash"], s["code_hash"]) != (
            self.profile_hash,
            self.prompt_hash,
            self.config_hash,
            self.code_hash,
        ):
            raise Failure("settings_changed_new_job_required")

    def new_tree(self, s, area):
        path = self.path(s, area)
        check_path(path).mkdir(mode=0o700)
        write_json(
            path / ".owner.json",
            {"root_id": self.root_id, "job_id": s["job_id"], "run_id": s["run_id"]},
        )
        info = path.stat()
        s["owners"][area] = [info.st_dev, info.st_ino]
        self.save(s)
        return path

    def tree_owner(self, s, area):
        path = check_path(self.path(s, area))
        info = path.lstat()
        if not stat.S_ISDIR(info.st_mode):
            raise Failure("non_directory_tree")
        owner = path / ".owner.json"
        if owner.exists():
            if read_json(owner) != {
                "root_id": self.root_id,
                "job_id": s["job_id"],
                "run_id": s["run_id"],
            }:
                raise Failure("tree_owner_mismatch")
        elif s["owners"].get(area) != [info.st_dev, info.st_ino] or (
            area != "results/.staging"
            and (list(path.iterdir()) or not s.get("cleanup_intent"))
        ):
            raise Failure("tree_owner_missing")
        return path

    def move(self, s, source, target):
        path = self.tree_owner(s, source)
        dest = check_path(self.path(s, target))
        if dest.exists():
            raise Failure("material_location_collision")
        os.rename(path, dest)
        fsync_dir(path.parent)
        fsync_dir(dest.parent)
        self.checkpoint("after_material_rename")
        info = dest.stat()
        s["owners"][target] = [info.st_dev, info.st_ino]
        s["owners"].pop(source, None)
        self.save(s)

    def private_limits(self, s):
        limits = dict(self.limits)
        remaining = min(limits["operation_seconds"], s["expires_at"] - self.clock())
        if remaining <= 0:
            raise Failure("expired_job")
        limits["_deadline"] = time.monotonic() + remaining
        return limits

    def submit(self, path):
        data = request_check(read_json(path, 1024 * 1024))
        jid = data["job_id"]
        if self.state_path(jid).exists():
            s = self.get(jid)
            if hashlib.sha256(encoded(data)).hexdigest() != s["request_hash"]:
                raise Failure("job_id_conflict")
            self.unchanged(s)
            return s
        if data["input_kind"] == "local_file":
            original = check_path(data["input_value"])
            if self.root == original or self.root in original.parents:
                raise Failure("original_inside_managed_root")
            regular(original)
        req_path = self.root / "requests" / (jid + ".json")
        write_json(req_path, data)
        s = {
            "job_id": jid,
            "run_id": str(uuid.uuid4()),
            "root_id": self.root_id,
            "request_hash": digest(req_path),
            "state": "REQUESTED",
            "code_hash": self.code_hash,
            "profile_hash": self.profile_hash,
            "prompt_hash": self.prompt_hash,
            "config_hash": self.config_hash,
            "owners": {},
            "ingested_at": None,
            "expires_at": None,
            "pack_hash": None,
            "material_hash": None,
            "cleanup_intent": False,
            "deleted_at": None,
            "deleted_files": 0,
            "cleanup_status": "not_started",
            "db_import_status": "not_imported",
            "error": None,
        }
        self.save(s)
        return s

    def tool_versions(self):
        expected = read_json(REPO / "config/local-analysis/toolchain.json")
        if (
            f"{sys.version_info.major}.{sys.version_info.minor}"
            != expected["python_minor"]
        ):
            raise Failure("python_version_mismatch")
        versions = {"python": sys.version.split()[0]}
        for tool in ["ffmpeg", "ffprobe"]:
            try:
                line = subprocess.run(
                    [tool, "-version"],
                    capture_output=True,
                    text=True,
                    timeout=10,
                    check=True,
                ).stdout.splitlines()[0]
            except (OSError, subprocess.SubprocessError):
                raise Failure("tool_missing:" + tool) from None
            if not line.startswith(expected[tool + "_prefix"]):
                raise Failure("tool_version_mismatch:" + tool)
            versions[tool] = line[:200]
        for line in (
            (REPO / "config/local-analysis/requirements.lock").read_text().splitlines()
        ):
            package, version = line.split("==")
            try:
                installed = importlib.metadata.version(package)
            except importlib.metadata.PackageNotFoundError:
                raise Failure("tool_missing:" + package) from None
            if installed != version:
                raise Failure("tool_version_mismatch:" + package)
        versions["yt-dlp"] = expected["yt_dlp"]
        versions["jsonschema"] = importlib.metadata.version("jsonschema")
        runtime_options = download_runtime_options()
        if not any(
            requirement.split(";", 1)[0].strip()
            == "yt-dlp-ejs==" + expected["yt_dlp_ejs"]
            for requirement in importlib.metadata.requires("yt-dlp") or []
        ):
            raise Failure("ejs_compatibility_mismatch")
        try:
            import yt_dlp
            from yt_dlp.extractor.youtube.jsc._builtin import vendor
            from yt_dlp_ejs.yt import solver

            # The pinned yt-dlp release authenticates its bundled solver with
            # these hashes too. Reject missing/tampered scripts; never fetch them.
            scripts = []
            for name, code in [("lib", solver.lib()), ("core", solver.core())]:
                if (
                    hashlib.sha3_512(code.encode()).hexdigest()
                    != vendor.HASHES[f"yt.solver.{name}.min.js"]
                ):
                    raise Failure("ejs_script_hash_mismatch")
                scripts.append(code)
            options = yt_dlp.parse_options(["--ignore-config", *runtime_options])
            if (
                options.ydl_opts["js_runtimes"]
                != {"node": {"path": "/usr/local/bin/node"}}
                or options.ydl_opts["remote_components"]
                or options.options.plugin_dirs
                or options.options.update_self
            ):
                raise Failure("unsafe_javascript_settings")
            node = subprocess.run(
                [expected["javascript_runtime_path"], "--version"],
                capture_output=True,
                text=True,
                timeout=10,
                check=True,
            ).stdout.strip()
            if node != expected["node_version"]:
                raise Failure("tool_version_mismatch:node")
            smoke = subprocess.run(
                [expected["javascript_runtime_path"], "--permission", "-"],
                input=scripts[0]
                + "\nObject.assign(globalThis,lib);\n"
                + scripts[1]
                + "\nif(typeof jsc !== 'function') throw Error('missing solver'); console.log('ejs-ready');\n",
                capture_output=True,
                text=True,
                timeout=10,
                check=True,
            )
            if smoke.stdout.strip() != "ejs-ready":
                raise Failure("ejs_runtime_check_failed")
        except (ImportError, OSError, subprocess.SubprocessError):
            raise Failure("javascript_dependency_unavailable") from None
        versions["node"] = node
        versions["yt-dlp-ejs"] = expected["yt_dlp_ejs"]
        return versions

    def stage(self, jid, network=False):
        s = self.get(jid)
        self.unchanged(s)
        if s["state"] in [
            "READY",
            "ANALYZING",
            "ANALYSIS_FAILED",
            "VALIDATION_FAILED",
            "RESULT_COMMITTED",
            "CLEANUP_PENDING",
            "CLEANUP_FAILED",
            "DONE",
        ]:
            return s
        if s["state"] == "EXPIRED":
            raise Failure("expired_job_submit_new_uuid")
        req = self.approve(s, "acquisition_approved")
        if req["input_kind"] != ("youtube_url" if network else "local_file"):
            raise Failure("wrong_acquisition_adapter")
        self.tool_versions()
        if len(list((self.root / "downloads").iterdir())) >= self.limits["max_ready"]:
            raise Failure("ready_capacity_limit")
        if (
            shutil.disk_usage(self.root).free
            < self.limits["min_free_bytes"] + self.limits["max_bytes"]
        ):
            raise Failure("disk_capacity_limit")
        if s["ingested_at"] is None:
            s["ingested_at"] = self.clock()
            s["expires_at"] = self.clock() + self.limits["ttl_seconds"]
        s["state"] = "DOWNLOADING"
        s["error"] = None
        self.save(s)
        area = "tmp/downloading"
        try:
            if self.path(s, area).exists():
                self.delete_area(s, area)
            target = self.new_tree(s, area)
            if network:
                path, acquisition = self.download(req, target, s)
            else:
                original = check_path(req["input_value"])
                info = regular(original)
                if (
                    self.root in original.parents
                    or info.st_size <= 0
                    or info.st_size > self.limits["max_bytes"]
                ):
                    raise Failure("invalid_original_size_or_location")
                before = digest(original)
                path = target / "media.bin"
                fd = os.open(original, os.O_RDONLY | os.O_NOFOLLOW)
                try:
                    with os.fdopen(fd, "rb") as src, open(path, "xb") as dst:
                        for block in iter(lambda: src.read(1048576), b""):
                            if self.clock() > s["expires_at"]:
                                raise Failure("expired_job")
                            dst.write(block)
                            if dst.tell() > self.limits["max_bytes"]:
                                raise Failure("material_size_limit")
                        dst.flush()
                        os.fsync(dst.fileno())
                finally:
                    pass
                if (
                    regular(original).st_size != info.st_size
                    or digest(original) != before
                    or digest(path) != before
                ):
                    raise Failure("original_changed_during_copy")
                s["original_sha256"] = before
                s["original_size"] = info.st_size
                acquisition = {
                    "adapter": "regular_copy",
                    "codec": None,
                    "format_id": None,
                    "acquired_at": datetime.fromtimestamp(
                        self.clock(), timezone.utc
                    ).isoformat(),
                }
            if (
                regular(path).st_size <= 0
                or path.stat().st_size > self.limits["max_bytes"]
            ):
                raise Failure("material_size_limit")
            limits = self.private_limits(s)
            info = media.probe(path, target, limits)
            media.decode(path, target, info, self.profile, limits)
            s["material_name"] = path.name
            s["material_hash"] = digest(path)
            s["material_size"] = path.stat().st_size
            s["acquisition"] = dict(acquisition, codec=info["codec"])
            self.save(s)
            # No logs, .part or metadata files are published as READY.
            for child in list(target.iterdir()):
                if child.name not in [path.name, ".owner.json"]:
                    if child.is_dir():
                        self._delete_contents(child)
                    else:
                        regular(child)
                        child.unlink()
                    if child.is_dir():
                        child.rmdir()
            self.move(s, area, "downloads")
            s["state"] = "READY"
            self.save(s)
        except Exception as e:
            s["state"] = "DOWNLOAD_FAILED"
            s["error"] = str(e) if isinstance(e, Failure) else "acquisition_error"
            self.save(s)
            raise
        return s

    def download(self, req, target, s):
        identifier = youtube_id(req["input_value"])
        url = "https://www.youtube.com/watch?v=" + identifier
        limits = self.private_limits(s)
        base = [
            "yt-dlp",
            "--ignore-config",
            "--no-playlist",
            "--no-cache-dir",
            "--no-progress",
            "--no-warnings",
            "--xff",
            "never",
            "--retries",
            str(limits["download_retries"]),
            "--fragment-retries",
            str(limits["download_retries"]),
            "--extractor-retries",
            str(limits["download_retries"]),
            "--socket-timeout",
            "15",
            "--no-write-info-json",
            "--no-write-thumbnail",
            "--no-write-subs",
            *download_runtime_options(),
        ]
        # Metadata stays in this owned temporary tree, never in final results or model input.
        out, _ = media.external(
            base + ["--skip-download", "--dump-single-json", url],
            target,
            limits,
            limits["download_seconds"],
        )
        import json

        try:
            info = json.loads(out)
        except ValueError:
            raise Failure("invalid_download_metadata") from None
        if (
            info.get("_type", "video") != "video"
            or info.get("id") != identifier
            or info.get("is_live")
            or info.get("live_status") not in [None, "not_live"]
        ):
            raise Failure("live_or_playlist_rejected")
        duration = info.get("duration")
        if (
            not isinstance(duration, (int, float))
            or not 0 < duration <= limits["max_duration_seconds"]
        ):
            raise Failure("unknown_or_excess_duration")
        format_selector = (
            "bestaudio[vcodec=none]"
            if req["mode"] == "audio"
            else "best[height<=360][acodec!=none][vcodec!=none]"
        )
        argv = base + [
            "--format",
            format_selector,
            "--paths",
            "home:" + str(target),
            "--paths",
            "temp:" + str(target),
            "--output",
            "media.%(ext)s",
            "--max-filesize",
            str(min(limits["max_bytes"], limits["max_transfer_bytes"])),
            "--limit-rate",
            str(limits["transfer_bytes_per_second"]),
            "--match-filters",
            f"duration <= {limits['max_duration_seconds']} & !is_live",
            url,
        ]
        media.external(argv, target, limits, limits["download_seconds"])
        files = [
            p for p in target.glob("media.*") if p.suffix not in [".part", ".ytdl"]
        ]
        if len(files) != 1:
            raise Failure("incomplete_download")
        return files[0], {
            "adapter": "yt-dlp",
            "format_id": str(info.get("format_id", "selected_by_fixed_selector"))[:80],
            "format_selector": format_selector,
            "video_id": identifier,
            "acquired_at": datetime.fromtimestamp(
                self.clock(), timezone.utc
            ).isoformat(),
            "argument_template_sha256": hashlib.sha256(
                encoded(base + [format_selector])
            ).hexdigest(),
        }

    def prepare(self, jid):
        s = self.get(jid)
        self.unchanged(s)
        req = self.approve(s, "analysis_approved", "derived_text_to_codex_approved")
        if req["mode"] == "audio_visual":
            self.approve(s, "images_to_codex_approved")
        self.tool_versions()
        if s["state"] == "DONE":
            return s
        if s["state"] not in [
            "READY",
            "ANALYZING",
            "ANALYSIS_FAILED",
            "VALIDATION_FAILED",
        ]:
            raise Failure("job_not_ready")
        try:
            if s["state"] == "READY":
                self.move(s, "downloads", "work")
                s["state"] = "ANALYZING"
                self.save(s)
            work = self.tree_owner(s, "work")
            material = work / s["material_name"]
            if digest(material) != s["material_hash"]:
                raise Failure("material_hash_mismatch")
            if s["pack_hash"] and (work / "measurement-pack.json").exists():
                if digest(work / "measurement-pack.json") != s["pack_hash"]:
                    raise Failure("measurement_pack_modified")
                return s
            results = media.measure(
                material,
                work,
                self.profile,
                self.private_limits(s),
                req["mode"],
                frame_checkpoint=self.checkpoint,
            )
            revision = "source-sha256:" + self.code_hash
            pack = {
                "schema_version": "1.0.0",
                "run_id": s["run_id"],
                "job_id": jid,
                "analysis_status": "complete",
                "human_review": "pending",
                "source": {
                    "subject_type": req["subject_type"],
                    "origin": "youtube_download"
                    if req["input_kind"] == "youtube_url"
                    else (
                        "synthetic"
                        if req["subject_type"] == "synthetic"
                        else "local_copy"
                    ),
                    "youtube_video_id": s["acquisition"].get("video_id"),
                    "sha256": s["material_hash"],
                    "duration_seconds": results["duration"],
                    "rights_record_id": req["rights_record_id"],
                },
                "provenance": {
                    "analyzed_at": datetime.fromtimestamp(
                        self.clock(), timezone.utc
                    ).isoformat(),
                    "code_revision": revision,
                    "tool_versions": self.tool_versions(),
                    "profile_id": "analysis-profile-v1",
                    "profile_sha256": self.profile_hash,
                    "prompt_id": "review-v1",
                    "prompt_sha256": self.prompt_hash,
                    "model_id": None,
                    "model_identity_status": "not_exposed",
                    "reasoning_effort": None,
                },
                "coverage": results["coverage"],
                "measurements": results["measurements"],
                "evidence": results["evidence"],
                "context": req["context"],
                "acquisition": s["acquisition"],
            }
            write_json(work / "measurement-pack.json", pack)
            s["pack_hash"] = digest(work / "measurement-pack.json")
            s["state"] = "ANALYZING"
            s["error"] = None
            self.save(s)
        except Exception as e:
            s["state"] = "ANALYSIS_FAILED"
            s["error"] = str(e) if isinstance(e, Failure) else "analysis_error"
            self.save(s)
            raise
        return s

    def load_pack(self, s):
        pack_path = self.tree_owner(s, "work") / "measurement-pack.json"
        if digest(pack_path) != s["pack_hash"]:
            raise Failure("measurement_pack_modified")
        pack = read_json(pack_path)
        if (
            pack["job_id"],
            pack["run_id"],
            pack["source"]["sha256"],
            pack["provenance"]["profile_sha256"],
            pack["provenance"]["prompt_sha256"],
        ) != (
            s["job_id"],
            s["run_id"],
            s["material_hash"],
            s["profile_hash"],
            s["prompt_hash"],
        ):
            raise Failure("pack_identity_mismatch")
        for e in pack["evidence"]:
            if (
                e["kind"] == "frame"
                and digest(self.path(s, "work") / ("frame-" + e["id"] + ".png"))
                != e["sha256"]
            ):
                raise Failure("evidence_changed")
        return pack

    def finalize(self, jid, insights):
        s = self.get(jid)
        self.unchanged(s)
        if self.result_path(s).exists():
            validate_bundle(self.result_path(s), mode=self.request(s)["mode"])
            return self.cleanup(jid)
        if s["state"] not in ["ANALYZING", "VALIDATION_FAILED"]:
            raise Failure("job_not_prepared")
        req = self.approve(s, "analysis_approved", "derived_text_to_codex_approved")
        try:
            pack = self.load_pack(s)
            interpretation = read_json(insights, 1024 * 1024)
            schema_check(interpretation, True)
            if req["input_kind"] == "local_file" and req["input_value"] in str(
                interpretation
            ):
                raise Failure("original_path_in_result")
            data = {
                k: v for k, v in pack.items() if k not in ["context", "acquisition"]
            }
            data["provenance"] = dict(
                pack["provenance"], measurement_pack_sha256=s["pack_hash"]
            )
            data["interpretation"] = interpretation
            # Model identity is not self-reported; unknown identity remains an explicit limitation.
            data["interpretation"]["limitations"] = list(
                dict.fromkeys(
                    interpretation["limitations"]
                    + [
                        "実モデル識別子・推論設定は環境から取得できず未確認。音の直接聴取・楽器・ジャンル・テンポ推定は未実施。",
                        "素材削除後は同じ音声・画像を再確認できません。",
                    ]
                )
            )
            validate_result(data, pack, s["pack_hash"], req["mode"])
            if self.path(s, "results/.staging").exists():
                self.delete_area(s, "results/.staging")
            staging = self.new_tree(s, "results/.staging")
            files = exports(data)
            for name, payload in files.items():
                write_bytes(staging / name, payload)
            write_json(
                staging / "bundle.json",
                {
                    "schema_version": "1.0.0",
                    "run_id": s["run_id"],
                    "files": {
                        name: {"sha256": digest(staging / name), "size": len(payload)}
                        for name, payload in files.items()
                    },
                },
            )
            # The durable job ownership manifest remains outside the result tree.
            (staging / ".owner.json").unlink()
            fsync_dir(staging)
            validate_bundle(staging, pack, s["pack_hash"], req["mode"])
            s["result_bundle_hash"] = digest(staging / "bundle.json")
            self.save(s)
            self.checkpoint("before_commit_rename")
            os.rename(staging, self.result_path(s))
            fsync_dir(self.root / "results")
            self.checkpoint("after_commit_rename")
            validate_bundle(self.result_path(s), pack, s["pack_hash"], req["mode"])
            s["state"] = "RESULT_COMMITTED"
            s["result_bundle_hash"] = digest(self.result_path(s) / "bundle.json")
            self.save(s)
        except Exception as e:
            s["state"] = "VALIDATION_FAILED"
            s["error"] = str(e) if isinstance(e, Failure) else "result_save_error"
            self.save(s)
            raise
        return self.cleanup(jid)

    def _delete_contents(self, path):
        # Preflight the entire tree before deleting any file. Descriptor-based traversal never follows links.
        def inspect(p):
            for child in p.iterdir():
                info = child.lstat()
                if stat.S_ISLNK(info.st_mode) or (
                    stat.S_ISREG(info.st_mode) and info.st_nlink != 1
                ):
                    raise Failure("unsafe_delete_link")
                if stat.S_ISDIR(info.st_mode):
                    inspect(child)
                elif not stat.S_ISREG(info.st_mode):
                    raise Failure("unsafe_delete_special_file")

        inspect(path)

        def remove(fd):
            count = 0
            for name in sorted(os.listdir(fd), key=lambda n: n == ".owner.json"):
                info = os.stat(name, dir_fd=fd, follow_symlinks=False)
                if stat.S_ISDIR(info.st_mode):
                    childfd = os.open(
                        name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd
                    )
                    try:
                        count += remove(childfd)
                    finally:
                        os.close(childfd)
                    os.rmdir(name, dir_fd=fd)
                elif stat.S_ISREG(info.st_mode) and info.st_nlink == 1:
                    os.unlink(name, dir_fd=fd)
                    count += 1
                    self.checkpoint("during_cleanup")
                    if name == ".owner.json":
                        self.checkpoint("after_owner_unlink")
                else:
                    raise Failure("unsafe_delete_race")
            os.fsync(fd)
            return count

        fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            return remove(fd)
        finally:
            os.close(fd)

    def delete_area(self, s, area, dry=False):
        if area not in AREAS:
            raise Failure("unsafe_delete_area")
        path = self.path(s, area)
        if not path.exists() and not path.is_symlink():
            return 0
        self.tree_owner(s, area)
        if dry:
            files = 0
            for child in path.rglob("*"):
                info = child.lstat()
                if stat.S_ISLNK(info.st_mode) or (
                    stat.S_ISREG(info.st_mode) and info.st_nlink != 1
                ):
                    raise Failure("unsafe_delete_link")
                if stat.S_ISREG(info.st_mode):
                    files += 1
            return files
        info = path.stat()
        if s["owners"].get(area) != [info.st_dev, info.st_ino]:
            s["owners"][area] = [info.st_dev, info.st_ino]
            self.save(s)
        count = self._delete_contents(path)
        path.rmdir()
        fsync_dir(path.parent)
        return count

    def cleanup(self, jid, dry=False, expired=False):
        s = self.get(jid)
        if not expired:
            result = validate_bundle(self.result_path(s), mode=self.request(s)["mode"])
            if result["job_id"] != jid or result["run_id"] != s["run_id"]:
                raise Failure("result_owner_mismatch")
            expected = s.get("result_bundle_hash")
            if expected and digest(self.result_path(s) / "bundle.json") != expected:
                raise Failure("committed_result_changed")
        if dry:
            return {area: self.delete_area(s, area, True) for area in AREAS}
        planned = s.get("cleanup_intent", False)
        s["state"] = "CLEANUP_PENDING"
        s["cleanup_intent"] = True
        s["cleanup_status"] = "pending"
        self.save(s)
        try:
            if not planned:
                s["cleanup_target_files"] = sum(
                    self.delete_area(s, area, True) for area in AREAS
                )
                self.save(s)
            count = 0
            for area in AREAS:
                count += self.delete_area(s, area)
            if any(self.path(s, a).exists() for a in AREAS):
                raise Failure("material_still_present")
            self.checkpoint("before_done_state")
            s["state"] = "EXPIRED" if expired else "DONE"
            s["cleanup_status"] = "deleted"
            s["deleted_at"] = self.clock()
            s["deleted_files"] = s.get("cleanup_target_files", count)
            s["error"] = None
            self.save(s)
        except Exception as e:
            s["state"] = "CLEANUP_FAILED"
            s["cleanup_status"] = "failed"
            s["error"] = str(e) if isinstance(e, Failure) else "cleanup_error"
            self.save(s)
            raise
        return s

    def maintenance(self):
        for path in sorted((self.root / "jobs").glob("*.json")):
            s = self.get(path.stem)
            result = self.result_path(s)
            if result.exists():
                valid = False
                try:
                    data = validate_bundle(result, mode=self.request(s)["mode"])
                    if data["job_id"] != s["job_id"] or data["run_id"] != s["run_id"]:
                        raise Failure("result_owner_mismatch")
                    if (
                        not s.get("result_bundle_hash")
                        or digest(result / "bundle.json") != s["result_bundle_hash"]
                    ):
                        raise Failure("committed_result_changed")
                    valid = True
                except (Failure, OSError):
                    s["state"] = "VALIDATION_FAILED"
                    s["error"] = "committed_result_invalid"
                    self.save(s)
                if valid:
                    if s["state"] != "DONE" or any(
                        self.path(s, a).exists() for a in AREAS
                    ):
                        try:
                            self.cleanup(s["job_id"])
                        except (Failure, OSError):
                            pass
                    continue
            if (
                s["expires_at"] is not None
                and s["expires_at"] <= self.clock()
                and s["state"] != "EXPIRED"
            ):
                self.cleanup(s["job_id"], expired=True)
                continue
            # Atomic material rename can precede its state update.
            if self.path(s, "work").exists() and s["state"] in ["READY", "DOWNLOADING"]:
                self.tree_owner(s, "work")
                s["state"] = "ANALYZING"
                self.save(s)
            elif self.path(s, "downloads").exists() and s["state"] == "DOWNLOADING":
                folder = self.tree_owner(s, "downloads")
                if digest(folder / s["material_name"]) != s["material_hash"]:
                    raise Failure("ready_hash_mismatch")
                s["state"] = "READY"
                self.save(s)
            elif s["state"] == "DOWNLOADING":
                s["state"] = "DOWNLOAD_FAILED"
                s["error"] = "interrupted_download"
                self.save(s)
