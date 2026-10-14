import copy
import csv
import io
import importlib.metadata
import math
import multiprocessing
import os
from pathlib import Path
import shutil
import signal
import struct
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import patch
import uuid
import wave

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts/local-analysis"))
from common import Failure, digest, read_json, write_json
from engine import Engine, AREAS, youtube_id
from contracts import exports, validate_result, validate_bundle
import media


class Crash(BaseException):
    pass


def tone(path, seconds=3, channels=2, kind="tone"):
    rate = 8000
    with wave.open(str(path), "wb") as f:
        f.setnchannels(channels)
        f.setsampwidth(2)
        f.setframerate(rate)
        values = []
        for i in range(int(rate * seconds)):
            sample = (
                0
                if kind == "silence"
                else int(6000 * math.sin(2 * math.pi * 440 * i / rate))
            )
            for c in range(channels):
                values.append(0 if kind == "one_silent_channel" and c == 1 else sample)
        f.writeframes(struct.pack("<" + "h" * len(values), *values))


def lock_worker(root, queue, release):
    engine = Engine(root, clock=lambda: 1000000.0)
    with engine.locked():
        queue.put("locked")
        release.wait(10)


def synthetic_video(path):
    subprocess.run(
        [
            "ffmpeg",
            "-v",
            "error",
            "-f",
            "lavfi",
            "-i",
            "color=blue:size=64x64:rate=10",
            "-f",
            "lavfi",
            "-i",
            "sine=frequency=440:sample_rate=8000",
            "-t",
            "3",
            "-c:v",
            "ffv1",
            "-c:a",
            "pcm_s16le",
            str(path),
        ],
        check=True,
    )


class PipelineFixture:
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.base = Path(self.temp.name)
        self.original = self.base / "original.wav"
        tone(self.original)
        self.now = 1000000.0
        self.engine = Engine(self.base / "managed", clock=lambda: self.now)
        self.request = {
            "schema_version": "1.0.0",
            "job_id": str(uuid.uuid4()),
            "input_kind": "local_file",
            "input_value": str(self.original),
            "subject_type": "synthetic",
            "mode": "audio",
            "profile_id": "analysis-profile-v1",
            "created_at": "2026-10-14T00:00:00Z",
            "rights_record_id": "synthetic-generated-in-test",
            "acquisition_approved": True,
            "analysis_approved": True,
            "derived_text_to_codex_approved": True,
            "images_to_codex_approved": False,
            "context": {"research_question": "音量と無音だけを確認"},
        }
        self.manifest = self.base / "request.json"
        write_json(self.manifest, self.request)
        self.jid = self.request["job_id"]
        self.engine.submit(self.manifest)

    def tearDown(self):
        self.temp.cleanup()

    def staged(self):
        return self.engine.stage(self.jid)

    def prepared(self):
        self.staged()
        return self.engine.prepare(self.jid)

    def insights(self, s=None):
        s = s or self.engine.get(self.jid)
        pack = self.engine.load_pack(s)
        obj = {
            "summary": "人工音の計測値だけを確認しました。",
            "viewed_evidence_ids": [],
            "observations": [
                {
                    "id": "o01",
                    "category": "audio",
                    "kind": "hypothesis",
                    "text": "窓平均の音量を確認する。",
                    "evidence_refs": [pack["measurements"][-1]["id"]],
                    "alternative_explanations": ["窓内の変動は別途確認が必要。"],
                    "next_check": "原本で耳による確認を行う。",
                    "confidence": "unassessed",
                }
            ],
            "limitations": ["音は直接聴いていません。画像と音楽的特徴は未確認。"],
            "next_experiments": [],
        }
        path = self.engine.path(s, "work") / "insights.json"
        write_json(path, obj)
        return path

    def final_data(self):
        s = self.prepared()
        pack = self.engine.load_pack(s)
        data = {k: v for k, v in pack.items() if k not in ["context", "acquisition"]}
        data["provenance"] = dict(
            pack["provenance"], measurement_pack_sha256=s["pack_hash"]
        )
        data["interpretation"] = read_json(self.insights(s))
        return data


class PipelineTest(PipelineFixture, unittest.TestCase):
    def test_complete_audio_lifecycle_preserves_original_and_has_only_verified_exports(
        self,
    ):
        original_hash = digest(self.original)
        size = self.original.stat().st_size
        s = self.prepared()
        self.assertFalse(self.engine.path(s, "downloads").exists())
        done = self.engine.finalize(self.jid, self.insights(s))
        self.assertEqual("DONE", done["state"])
        self.assertEqual(original_hash, digest(self.original))
        self.assertEqual(size, self.original.stat().st_size)
        self.assertTrue(all(not self.engine.path(done, a).exists() for a in AREAS))
        self.assertEqual(4, len(list(self.engine.result_path(done).iterdir())))
        result = validate_bundle(self.engine.result_path(done))
        self.assertEqual("not_exposed", result["provenance"]["model_identity_status"])
        self.assertEqual("not_imported", done["db_import_status"])
        self.assertGreater(done["deleted_files"], 0)

    def test_broken_empty_non_audio_and_truncated_never_enter_ready(self):
        for kind in ["empty", "broken", "truncated", "video_only"]:
            with self.subTest(kind=kind):
                if kind == "empty":
                    self.original.write_bytes(b"")
                elif kind == "broken":
                    self.original.write_bytes(b"not-media")
                elif kind == "truncated":
                    tone(self.original)
                    self.original.write_bytes(self.original.read_bytes()[:-8000])
                else:
                    subprocess.run(
                        [
                            "ffmpeg",
                            "-v",
                            "error",
                            "-f",
                            "lavfi",
                            "-i",
                            "color=size=32x32:rate=1",
                            "-t",
                            "1",
                            "-c:v",
                            "ffv1",
                            "-f",
                            "matroska",
                            "-y",
                            str(self.original),
                        ],
                        check=True,
                    )
                with self.assertRaises((Failure, OSError)):
                    self.engine.stage(self.jid)
                self.assertFalse(
                    self.engine.path(self.engine.get(self.jid), "downloads").exists()
                )

    def test_original_inside_managed_tree_symlink_and_hardlink_are_rejected(self):
        for kind in ["inside", "symlink", "hardlink"]:
            with self.subTest(kind=kind):
                request = dict(self.request, job_id=str(uuid.uuid4()))
                path = self.base / (kind + ".wav")
                if kind == "inside":
                    path = self.engine.root / "bad-original.wav"
                    tone(path)
                elif kind == "symlink":
                    path.symlink_to(self.original)
                else:
                    os.link(self.original, path)
                request["input_value"] = str(path)
                write_json(self.manifest, request)
                with self.assertRaises(Failure):
                    self.engine.submit(self.manifest)
                path.unlink()

    def test_silence_short_and_single_silent_channel_have_correct_rms_and_units(self):
        for kind, seconds in [
            ("silence", 1),
            ("tone", 0.25),
            ("one_silent_channel", 3),
        ]:
            with self.subTest(kind=kind):
                tone(self.original, seconds=seconds, kind=kind)
                result = media.measure(
                    self.original,
                    self.base,
                    self.engine.profile,
                    self.engine.limits,
                    "audio",
                )
                by = {r["metric"]: r for r in result["measurements"]}
                self.assertAlmostEqual(seconds, result["duration"], places=4)
                if kind == "silence":
                    self.assertIsNone(by["rms_dbfs"]["value"])
                    self.assertEqual(seconds, by["silence_total_seconds"]["value"])
                    self.assertIsNone(by["integrated_loudness_lufs"]["value"])
                elif kind == "tone":
                    self.assertEqual(
                        "short_duration", by["loudness_range_lu"]["reason"]
                    )
                else:
                    self.assertEqual(0, by["silence_total_seconds"]["value"])
                    self.assertIsNotNone(by["rms_dbfs"]["value"])
                self.assertEqual("LU", by["loudness_range_lu"]["unit"])
                self.assertEqual("dBTP", by["true_peak_dbtp"]["unit"])

    def test_last_rms_window_and_numeric_reproducibility(self):
        tone(self.original, seconds=10.2)
        a = media.measure(
            self.original, self.base, self.engine.profile, self.engine.limits, "audio"
        )
        b = media.measure(
            self.original, self.base, self.engine.profile, self.engine.limits, "audio"
        )
        windows = [r for r in a["measurements"] if r["metric"] == "rms_dbfs"]
        self.assertEqual(2, len(windows))
        self.assertEqual(10.2, windows[-1]["end_seconds"])
        for x, y in zip(a["measurements"], b["measurements"]):
            if x["value"] is not None:
                self.assertAlmostEqual(x["value"], y["value"], delta=1e-6)

    def test_analysis_and_save_failures_keep_copy_for_bounded_retry(self):
        s = self.staged()
        with patch("engine.media.measure", side_effect=Failure("decode_error")):
            with self.assertRaises(Failure):
                self.engine.prepare(self.jid)
        self.assertEqual("ANALYSIS_FAILED", self.engine.get(self.jid)["state"])
        self.assertTrue(self.engine.path(s, "work").exists())
        s = self.engine.prepare(self.jid)
        insights = self.insights(s)
        with patch("engine.write_bytes", side_effect=OSError("disk full")):
            with self.assertRaises(OSError):
                self.engine.finalize(self.jid, insights)
        self.assertTrue(self.engine.path(s, "work").exists())
        self.assertFalse(self.engine.result_path(s).exists())
        done = self.engine.finalize(self.jid, insights)
        self.assertEqual("DONE", done["state"])

    def test_bad_json_references_images_units_intervals_ids_and_pack_modification_are_rejected(
        self,
    ):
        data = self.final_data()
        mutations = [
            lambda d: d["measurements"][0].update(value=float("nan")),
            lambda d: d["measurements"][2].update(unit="s"),
            lambda d: d["measurements"][0].update(end_seconds=100),
            lambda d: d["measurements"][1].update(id=d["measurements"][0]["id"]),
            lambda d: d["interpretation"]["observations"][0].update(
                evidence_refs=["missing"]
            ),
            lambda d: d["interpretation"].update(viewed_evidence_ids=["f00"]),
            lambda d: d["measurements"][0].update(
                kind="unavailable", value=None, reason="decode_error"
            ),
        ]
        for mutation in mutations:
            d = copy.deepcopy(data)
            mutation(d)
            with (
                self.subTest(mutation=mutation),
                self.assertRaises((Failure, ValueError)),
            ):
                validate_result(d)
        s = self.engine.get(self.jid)
        p = self.engine.path(s, "work") / "measurement-pack.json"
        p.write_bytes(p.read_bytes() + b" ")
        with self.assertRaises(Failure):
            self.engine.finalize(self.jid, self.insights_path_for_invalid_pack(s))
        self.assertTrue(self.engine.path(s, "work").exists())

    def insights_path_for_invalid_pack(self, s):
        p = self.engine.path(s, "work") / "bad-insights.json"
        write_json(p, {})
        return p

    def test_cleanup_failure_retries_without_reanalysis(self):
        s = self.prepared()
        insights = self.insights(s)
        with patch.object(
            self.engine, "delete_area", side_effect=OSError("cannot delete")
        ):
            with self.assertRaises(OSError):
                self.engine.finalize(self.jid, insights)
        self.assertTrue(self.engine.result_path(s).exists())
        self.assertTrue(self.engine.path(s, "work").exists())
        with patch(
            "engine.media.measure",
            side_effect=AssertionError("analysis must not repeat"),
        ):
            self.engine.maintenance()
        self.assertEqual("DONE", self.engine.get(self.jid)["state"])

    def test_commit_and_cleanup_crash_boundaries_recover(self):
        for point in [
            "before_commit_rename",
            "after_commit_rename",
            "during_cleanup",
            "before_done_state",
        ]:
            with self.subTest(point=point):
                if self.engine.get(self.jid)["state"] == "DONE":
                    self.request["job_id"] = str(uuid.uuid4())
                    self.jid = self.request["job_id"]
                    write_json(self.manifest, self.request)
                    self.engine.submit(self.manifest)
                s = self.prepared()
                insights = self.insights(s)
                self.engine.checkpoint = (
                    lambda name: (_ for _ in ()).throw(Crash())
                    if name == point
                    else None
                )
                with self.assertRaises(Crash):
                    self.engine.finalize(self.jid, insights)
                self.engine.checkpoint = lambda _: None
                self.engine.maintenance()
                if point == "before_commit_rename":
                    self.engine.finalize(self.jid, insights)
                self.assertEqual("DONE", self.engine.get(self.jid)["state"])
                validate_bundle(self.engine.result_path(s))

    def test_material_rename_crash_recovers_ready_and_claim(self):
        self.engine.checkpoint = (
            lambda name: (_ for _ in ()).throw(Crash())
            if name == "after_material_rename"
            else None
        )
        with self.assertRaises(Crash):
            self.staged()
        self.engine.checkpoint = lambda _: None
        self.engine.maintenance()
        self.assertEqual("READY", self.engine.get(self.jid)["state"])
        self.engine.checkpoint = (
            lambda name: (_ for _ in ()).throw(Crash())
            if name == "after_material_rename"
            else None
        )
        with self.assertRaises(Crash):
            self.engine.prepare(self.jid)
        self.engine.checkpoint = lambda _: None
        self.engine.maintenance()
        self.assertEqual("ANALYZING", self.engine.get(self.jid)["state"])
        self.assertEqual("ANALYZING", self.engine.prepare(self.jid)["state"])

    def test_global_lock_prevents_two_claims_and_expiry_cleanup_race_and_recovers_after_kill(
        self,
    ):
        self.staged()
        queue = multiprocessing.Queue()
        release = multiprocessing.Event()
        child = multiprocessing.Process(
            target=lock_worker, args=(self.engine.root, queue, release)
        )
        child.start()
        self.assertEqual("locked", queue.get(timeout=5))
        try:
            with self.assertRaises(Failure), self.engine.locked():
                pass
            self.assertTrue((self.engine.root / "downloads" / self.jid).exists())
        finally:
            child.kill()
            child.join(5)
        with self.engine.locked():
            self.assertTrue(True)

    def test_ttl_never_extends_and_next_start_expires_before_new_work(self):
        s = self.staged()
        deadline = s["expires_at"]
        self.now += 100
        self.engine.stage(self.jid)
        self.assertEqual(deadline, self.engine.get(self.jid)["expires_at"])
        self.now = deadline + 1
        with self.engine.locked():
            self.assertEqual("EXPIRED", self.engine.get(self.jid)["state"])
            with self.assertRaises(Failure):
                self.engine.stage(self.jid)
        self.assertFalse((self.engine.root / "downloads" / self.jid).exists())
        self.assertTrue(self.original.exists())

    def test_cleanup_rejects_links_foreign_owner_and_invalid_id(self):
        s = self.prepared()
        work = self.engine.path(s, "work")
        for kind in ["symlink", "hardlink", "foreign_owner"]:
            with self.subTest(kind=kind):
                if kind == "symlink":
                    (work / "attack").symlink_to(self.original)
                elif kind == "hardlink":
                    os.link(self.original, work / "attack")
                else:
                    write_json(
                        work / ".owner.json",
                        {
                            "root_id": self.engine.root_id,
                            "job_id": str(uuid.uuid4()),
                            "run_id": s["run_id"],
                        },
                    )
                with self.assertRaises(Failure):
                    self.engine.delete_area(s, "work")
                self.assertTrue(self.original.exists())
                self.assertTrue((work / s["material_name"]).exists())
                if kind != "foreign_owner":
                    (work / "attack").unlink()
        for jid in ["..", "/", self.engine.root.name]:
            with self.assertRaises(Failure):
                self.engine.cleanup(jid)

    def test_dry_run_does_not_delete_and_repeated_request_and_prepare_are_idempotent(
        self,
    ):
        s = self.prepared()
        old = s["run_id"]
        before = s["pack_hash"]
        self.assertEqual(old, self.engine.submit(self.manifest)["run_id"])
        with patch("engine.media.measure", side_effect=AssertionError("no repeat")):
            self.assertEqual(before, self.engine.prepare(self.jid)["pack_hash"])
        self.engine.finalize(self.jid, self.insights(s))
        self.assertEqual(
            {}, {k: v for k, v in self.engine.cleanup(self.jid, True).items() if v}
        )
        self.assertEqual(old, self.engine.stage(self.jid)["run_id"])
        changed = dict(self.request, context={"research_question": "changed"})
        write_json(self.manifest, changed)
        with self.assertRaises(Failure):
            self.engine.submit(self.manifest)

    def test_exports_escape_spreadsheet_formulas_and_html_markdown(self):
        data = self.final_data()
        data["measurements"][0]["method"] = '=HYPERLINK("bad")'
        data["interpretation"]["summary"] = "<script>alert(1)</script> ![x](bad)"
        files = exports(data)
        rows = list(csv.reader(io.StringIO(files["features.csv"].decode())))
        self.assertTrue(rows[1][-2].startswith("'="))
        self.assertNotIn("<script>", files["report.md"].decode())
        self.assertNotIn("![x](bad)", files["report.md"].decode())

    def test_approvals_and_network_url_scope_are_enforced_without_network(self):
        self.request["acquisition_approved"] = False
        self.request["job_id"] = str(uuid.uuid4())
        write_json(self.manifest, self.request)
        s = self.engine.submit(self.manifest)
        with self.assertRaises(Failure):
            self.engine.stage(s["job_id"])
        self.assertEqual(
            "demoVideo01",
            youtube_id("https://www.youtube.com/watch?v=demoVideo01&list=abc"),
        )
        for url in [
            "https://evil.test/watch?v=demoVideo01",
            "https://youtube.com/live/demoVideo01",
            "https://youtube.com/playlist?list=abc",
            "https://user@youtube.com/watch?v=demoVideo01",
        ]:
            with self.assertRaises(Failure):
                youtube_id(url)

    def test_network_adapter_checks_metadata_limits_and_keeps_fixed_options(self):
        self.request.update(
            job_id=str(uuid.uuid4()),
            input_kind="youtube_url",
            input_value="https://youtu.be/demoVideo01",
        )
        write_json(self.manifest, self.request)
        s = self.engine.submit(self.manifest)
        target = self.engine.new_tree(s, "tmp/downloading")
        s["expires_at"] = self.now + 100
        self.engine.save(s)
        calls = []

        def fake(argv, folder, *args):
            calls.append(argv)
            if "--dump-single-json" in argv:
                return (
                    '{"id":"demoVideo01","duration":3,"is_live":false,"live_status":"not_live"}',
                    "",
                )
            shutil.copyfile(self.original, folder / "media.wav")
            return "", ""

        with patch("engine.media.external", side_effect=fake):
            path, meta = self.engine.download(self.request, target, s)
        self.assertTrue(path.exists())
        self.assertIn("--ignore-config", calls[0])
        self.assertIn("--no-playlist", calls[0])
        self.assertIn("--no-js-runtimes", calls[0])
        self.assertEqual(
            "node:/usr/local/bin/node", calls[0][calls[0].index("--js-runtimes") + 1]
        )
        self.assertIn("--no-remote-components", calls[0])
        self.assertIn("--no-plugin-dirs", calls[0])
        self.assertIn("--no-update", calls[0])
        self.assertIn("never", calls[0])
        self.assertNotIn("--cookies", calls[0])
        self.assertIn("--max-filesize", calls[1])
        self.assertEqual("demoVideo01", meta["video_id"])
        with patch(
            "engine.media.external",
            return_value=('{"id":"demoVideo01","duration":null}', ""),
        ):
            with self.assertRaises(Failure):
                self.engine.download(self.request, target, s)


class RuntimeTest(PipelineFixture, unittest.TestCase):
    def test_doctor_verifies_offline_node_ejs_and_fixed_settings(self):
        versions = self.engine.tool_versions()
        self.assertEqual("v24.21.0", versions["node"])
        self.assertEqual("0.8.0", versions["yt-dlp-ejs"])

    def test_doctor_rejects_missing_wrong_or_tampered_js_dependencies(self):
        real_version = importlib.metadata.version
        real_run = subprocess.run

        for failure in [
            "missing_ejs",
            "wrong_ejs",
            "incompatible_ejs",
            "tampered_ejs",
            "missing_node",
            "wrong_node",
            "node_options",
        ]:
            with self.subTest(failure=failure):

                def version(package):
                    if package == "yt-dlp-ejs":
                        if failure == "missing_ejs":
                            raise importlib.metadata.PackageNotFoundError(package)
                        if failure == "wrong_ejs":
                            return "0.0.0"
                    return real_version(package)

                def run(argv, *args, **kwargs):
                    if argv == ["/usr/local/bin/node", "--version"]:
                        if failure == "missing_node":
                            raise FileNotFoundError()
                        if failure == "wrong_node":
                            return subprocess.CompletedProcess(argv, 0, "v18.0.0\n", "")
                    return real_run(argv, *args, **kwargs)

                with (
                    patch("engine.importlib.metadata.version", side_effect=version),
                    patch("engine.subprocess.run", side_effect=run),
                ):
                    if failure == "incompatible_ejs":
                        with patch(
                            "engine.importlib.metadata.requires",
                            return_value=["yt-dlp-ejs==0.0.0"],
                        ):
                            with self.assertRaisesRegex(
                                Failure, "ejs_compatibility_mismatch"
                            ):
                                self.engine.tool_versions()
                    elif failure == "tampered_ejs":
                        with patch(
                            "yt_dlp_ejs.yt.solver.lib", return_value="invalid solver"
                        ):
                            with self.assertRaisesRegex(
                                Failure, "ejs_script_hash_mismatch"
                            ):
                                self.engine.tool_versions()
                    elif failure == "node_options":
                        with patch.dict(
                            os.environ, {"NODE_OPTIONS": "--require=/tmp/untrusted.js"}
                        ):
                            with self.assertRaisesRegex(
                                Failure, "unsafe_javascript_settings"
                            ):
                                self.engine.tool_versions()
                    else:
                        with self.assertRaises(Failure):
                            self.engine.tool_versions()


class VisualAndProcessTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.base = Path(self.temp.name)
        self.engine = Engine(self.base / "managed")

    def tearDown(self):
        self.temp.cleanup()

    def staged_visual(self):
        original = self.base / (str(uuid.uuid4()) + ".mkv")
        synthetic_video(original)
        req = {
            "schema_version": "1.0.0",
            "job_id": str(uuid.uuid4()),
            "input_kind": "local_file",
            "input_value": str(original),
            "subject_type": "synthetic",
            "mode": "audio_visual",
            "profile_id": "analysis-profile-v1",
            "created_at": "2026-10-14T00:00:00Z",
            "rights_record_id": "synthetic-test",
            "acquisition_approved": True,
            "analysis_approved": True,
            "derived_text_to_codex_approved": True,
            "images_to_codex_approved": True,
            "context": {},
        }
        manifest = self.base / "request.json"
        write_json(manifest, req)
        s = self.engine.submit(manifest)
        self.engine.stage(req["job_id"])
        return original, self.engine.get(s["job_id"])

    def finish_visual(self, s, original):
        pack = self.engine.load_pack(s)
        insights = {
            "summary": "人工画像は未確認。音量だけを確認。",
            "viewed_evidence_ids": [],
            "observations": [],
            "limitations": ["画像入力を行っていない。"],
            "next_experiments": [],
        }
        path = self.engine.path(s, "work") / "insights.json"
        write_json(path, insights)
        done = self.engine.finalize(s["job_id"], path)
        self.assertEqual("DONE", done["state"])
        self.assertEqual("deleted", done["cleanup_status"])
        self.assertTrue(original.exists())
        for area in AREAS:
            self.assertFalse(self.engine.path(s, area).exists())
        result = self.engine.result_path(done)
        data = validate_bundle(result, pack, s["pack_hash"], "audio_visual")
        for name, contents in exports(data).items():
            self.assertEqual(contents, (result / name).read_bytes())
        return done

    def test_authorized_synthetic_frames_pts_and_cleanup(self):
        original, staged = self.staged_visual()
        before = (digest(original), original.stat().st_size)
        s = self.engine.prepare(staged["job_id"])
        pack = self.engine.load_pack(s)
        self.assertGreaterEqual(len(pack["evidence"]), 2)
        for e in pack["evidence"]:
            self.assertTrue(0 <= e["time_seconds"] <= 3)
        self.finish_visual(s, original)
        self.assertEqual(before, (digest(original), original.stat().st_size))

    def test_frame_retry_after_real_sigkill_commits_and_cleans_same_job(self):
        original, staged = self.staged_visual()
        before = (digest(original), original.stat().st_size)
        outside = self.base / "unmanaged.png"
        outside.write_bytes(b"unmanaged file must stay unchanged")
        outside_hash = digest(outside)

        def worker():
            def stop(point):
                if point == "after_frame_publish":
                    os.kill(os.getpid(), signal.SIGKILL)

            engine = Engine(self.engine.root, checkpoint=stop)
            with engine.locked():
                engine.prepare(staged["job_id"])

        child = multiprocessing.Process(target=worker)
        child.start()
        child.join(20)
        if child.is_alive():
            child.kill()
            child.join()
            self.fail("Frame checkpoint was not reached")
        self.assertEqual(-signal.SIGKILL, child.exitcode)
        work = self.engine.path(staged, "work")
        self.assertTrue((work / "frame-f00.png").is_file())
        self.assertFalse((work / "measurement-pack.json").exists())
        self.assertGreaterEqual(len(list(work.glob("frames-*/frame-*.png"))), 1)
        with self.engine.locked():
            prepared = self.engine.prepare(staged["job_id"])
            self.assertEqual(staged["run_id"], prepared["run_id"])
            self.finish_visual(prepared, original)
        self.assertEqual(before, (digest(original), original.stat().st_size))
        self.assertEqual(outside_hash, digest(outside))

    def test_frame_retry_after_second_extract_failure(self):
        original, staged = self.staged_visual()
        before = (digest(original), original.stat().st_size)
        real_external = media.external
        frames = 0

        def fail_second(argv, *args, **kwargs):
            nonlocal frames
            if "-frames:v" in argv:
                frames += 1
                if frames == 2:
                    raise Failure("injected_frame_failure")
            return real_external(argv, *args, **kwargs)

        with patch("media.external", side_effect=fail_second):
            with self.assertRaisesRegex(Failure, "injected_frame_failure"):
                self.engine.prepare(staged["job_id"])
        self.assertEqual("ANALYSIS_FAILED", self.engine.get(staged["job_id"])["state"])
        self.assertTrue((self.engine.path(staged, "work") / "frame-f00.png").exists())
        prepared = self.engine.prepare(staged["job_id"])
        self.assertEqual(staged["run_id"], prepared["run_id"])
        self.finish_visual(prepared, original)
        self.assertEqual(before, (digest(original), original.stat().st_size))

    def test_frame_replacement_rejects_links_and_foreign_owner(self):
        for kind in ["symlink", "hardlink", "foreign_owner"]:
            with self.subTest(kind=kind):
                original, staged = self.staged_visual()
                self.engine.move(staged, "downloads", "work")
                staged["state"] = "ANALYZING"
                self.engine.save(staged)
                work = self.engine.path(staged, "work")
                outside = self.base / (str(uuid.uuid4()) + ".png")
                outside.write_bytes(b"protected outside material")
                before = (
                    outside.read_bytes(),
                    digest(original),
                    original.stat().st_size,
                )
                target = work / "frame-f00.png"
                if kind == "symlink":
                    target.symlink_to(outside)
                elif kind == "hardlink":
                    os.link(outside, target)
                else:
                    owner = read_json(work / ".owner.json")
                    owner["job_id"] = str(uuid.uuid4())
                    write_json(work / ".owner.json", owner)
                with self.assertRaises(Failure):
                    self.engine.prepare(staged["job_id"])
                self.assertEqual(
                    before,
                    (outside.read_bytes(), digest(original), original.stat().st_size),
                )

    def test_supervisor_stops_timeouts_and_children_after_parent_death(self):
        work = self.base / "work"
        work.mkdir()
        limits = dict(self.engine.limits, operation_seconds=1)
        with self.assertRaises(Failure):
            media.external(
                [sys.executable, "-c", "import time;time.sleep(60)"], work, limits
            )
        token = "local-analysis-child-" + str(uuid.uuid4())
        script = "import sys,time;sys.path.insert(0,'/repo/scripts/local-analysis');import media;from pathlib import Path;from common import REPO,read_json;media.external([sys.executable,'-c','import time;time.sleep(60)',sys.argv[2]],Path(sys.argv[1]),read_json(REPO/'config/local-analysis/limits.json'))"
        parent = subprocess.Popen([sys.executable, "-c", script, str(work), token])
        time.sleep(0.5)
        parent.kill()
        parent.wait()
        time.sleep(0.5)
        matches = []
        for p in Path("/proc").glob("[0-9]*/cmdline"):
            try:
                if token.encode() in p.read_bytes():
                    matches.append(p.parent.name)
            except OSError:
                pass
        self.assertEqual([], matches)


class AdditionalRecoveryTest(PipelineFixture, unittest.TestCase):
    def test_real_sigkill_after_commit_and_mid_cleanup(self):
        for point in ["after_commit_rename", "during_cleanup", "after_owner_unlink"]:
            if self.engine.get(self.jid)["state"] == "DONE":
                self.request["job_id"] = str(uuid.uuid4())
                self.jid = self.request["job_id"]
                write_json(self.manifest, self.request)
                self.engine.submit(self.manifest)
            s = self.prepared()
            insights = self.insights(s)

            def child():
                engine = Engine(
                    self.engine.root,
                    clock=lambda: self.now,
                    checkpoint=lambda n: os.kill(os.getpid(), signal.SIGKILL)
                    if n == point
                    else None,
                )
                with engine.locked():
                    engine.finalize(self.jid, insights)

            proc = multiprocessing.Process(target=child)
            proc.start()
            proc.join(10)
            self.assertEqual(-signal.SIGKILL, proc.exitcode)
            with self.engine.locked():
                pass
            self.assertEqual("DONE", self.engine.get(self.jid)["state"])
            self.assertTrue(self.original.exists())

    def test_cli_expired_dry_run_never_deletes_or_changes_state(self):
        import contextlib
        import main

        state = self.staged()
        before = digest(self.engine.state_path(self.jid))
        with contextlib.redirect_stdout(io.StringIO()):
            code = main.main(
                [
                    "--root",
                    str(self.engine.root),
                    "cleanup",
                    "--job",
                    self.jid,
                    "--dry-run",
                ]
            )
        self.assertEqual(0, code)
        self.assertEqual(before, digest(self.engine.state_path(self.jid)))
        self.assertTrue(self.engine.path(state, "downloads").exists())

    def test_corrupted_committed_result_keeps_material_until_expiry(self):
        s = self.prepared()
        insights = self.insights(s)
        self.engine.checkpoint = (
            lambda n: (_ for _ in ()).throw(Crash())
            if n == "after_commit_rename"
            else None
        )
        with self.assertRaises(Crash):
            self.engine.finalize(self.jid, insights)
        self.engine.checkpoint = lambda _: None
        path = self.engine.result_path(s) / "analysis.json"
        path.write_bytes(path.read_bytes() + b" ")
        self.engine.maintenance()
        self.assertEqual("VALIDATION_FAILED", self.engine.get(self.jid)["state"])
        self.assertTrue(self.engine.path(s, "work").exists())
        with self.assertRaises(Failure):
            self.engine.cleanup(self.jid)
        self.now = s["expires_at"] + 1
        self.engine.maintenance()
        self.assertEqual("EXPIRED", self.engine.get(self.jid)["state"])
        self.assertTrue(path.exists())
        self.assertFalse(self.engine.path(s, "work").exists())


if __name__ == "__main__":
    unittest.main()
