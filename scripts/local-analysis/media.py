"""Read-only FFmpeg measurements; audio is decoded locally and never sent to a model."""

import array
import math
import os
import re
import selectors
import signal
import subprocess
import sys
import time
import uuid

from common import Failure, REPO, digest, read_json, regular, write_json

SAFE_INPUT = [
    "-protocol_whitelist",
    "file,pipe",
    "-format_whitelist",
    "wav,flac,mp3,ogg,mov,matroska,webm,aac,aiff",
]


def external(argv, work, limits, seconds=None):
    scratch = work / ("scratch-" + str(uuid.uuid4()))
    scratch.mkdir(mode=0o700)
    spec = {
        "argv": argv,
        "cwd": str(work),
        "stdout": str(scratch / "out"),
        "stderr": str(scratch / "err"),
        "result": str(scratch / "result.json"),
        "seconds": min(
            seconds or limits["operation_seconds"],
            max(
                0,
                limits.get("_deadline", time.monotonic() + limits["operation_seconds"])
                - time.monotonic(),
            ),
        ),
        "log_bytes": 8 * 1024 * 1024,
        "tree_bytes": limits["max_bytes"] + 16 * 1024 * 1024,
    }
    write_json(scratch / "spec.json", spec)
    supervisor = subprocess.Popen(
        [
            sys.executable,
            str(REPO / "scripts/local-analysis/process_guard.py"),
            str(scratch / "spec.json"),
        ],
        stdin=subprocess.DEVNULL,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    try:
        supervisor.wait(timeout=spec["seconds"] + 10)
    finally:
        if supervisor.poll() is None:
            supervisor.kill()
            supervisor.wait()
    result = read_json(scratch / "result.json")
    if result["status"] != "ok":
        raise Failure(result["status"])
    return (scratch / "out").read_text(errors="replace"), (scratch / "err").read_text(
        errors="replace"
    )


def probe(path, work, limits):
    regular(path)
    out, _ = external(
        [
            "ffprobe",
            "-v",
            "error",
            *SAFE_INPUT,
            "-show_entries",
            "format=duration,format_name:stream=index,codec_type,codec_name,sample_rate,channels,duration",
            "-of",
            "json",
            str(path),
        ],
        work,
        limits,
        30,
    )
    import json

    try:
        info = json.loads(out)
    except ValueError:
        raise Failure("invalid_probe") from None
    audio = next(
        (s for s in info.get("streams", []) if s.get("codec_type") == "audio"), None
    )
    if audio is None:
        raise Failure("no_audio")
    try:
        duration = float(
            audio.get("duration", info.get("format", {}).get("duration", "nan"))
        )
        rate, channels = int(audio["sample_rate"]), int(audio["channels"])
    except (ValueError, KeyError, TypeError):
        raise Failure("unknown_audio_parameters") from None
    if (
        not math.isfinite(duration)
        or duration <= 0
        or duration > limits["max_duration_seconds"]
    ):
        raise Failure("unknown_or_excess_duration")
    if (
        not 1 <= channels <= limits["max_channels"]
        or not 1 <= rate <= limits["max_sample_rate"]
    ):
        raise Failure("audio_parameter_limit")
    return {
        "duration": duration,
        "rate": rate,
        "channels": channels,
        "codec": audio.get("codec_name", "unknown"),
        "video": any(s.get("codec_type") == "video" for s in info.get("streams", [])),
    }


def _parent_death():
    import ctypes

    ctypes.CDLL(None).prctl(1, signal.SIGKILL)


def decode(path, work, info, profile, limits):
    rate, channels = info["rate"], info["channels"]
    threshold = 10 ** (profile["silence_dbfs"] / 20)
    window_frames = rate * profile["rms_window_seconds"]
    argv = [
        "ffmpeg",
        "-nostdin",
        "-v",
        "error",
        "-xerror",
        "-err_detect",
        "explode",
        *SAFE_INPUT,
        "-i",
        str(path),
        "-map",
        "0:a:0",
        "-vn",
        "-c:a",
        "pcm_f32le",
        "-f",
        "f32le",
        "pipe:1",
    ]
    frames = 0
    peak = 0.0
    sums = 0.0
    in_window = 0
    silence_start = None
    windows = []
    intervals = []
    carry = b""
    start = time.monotonic()
    err_path = work / ("decode-" + str(uuid.uuid4()) + ".log")
    with open(err_path, "wb") as err:
        p = subprocess.Popen(
            argv,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=err,
            start_new_session=True,
            preexec_fn=_parent_death,
        )
        selector = selectors.DefaultSelector()
        selector.register(p.stdout, selectors.EVENT_READ)
        try:
            while True:
                if time.monotonic() - start > limits[
                    "operation_seconds"
                ] or time.monotonic() > limits.get("_deadline", float("inf")):
                    raise Failure("decode_timeout")
                events = selector.select(0.1)
                if not events:
                    continue
                chunk = os.read(p.stdout.fileno(), 65536)
                if not chunk:
                    break
                chunk = carry + chunk
                size = len(chunk) // (channels * 4) * (channels * 4)
                carry = chunk[size:]
                data = array.array("f")
                data.frombytes(chunk[:size])
                if sys.byteorder != "little":
                    data.byteswap()
                for i in range(0, len(data), channels):
                    values = data[i : i + channels]
                    if not all(math.isfinite(x) for x in values):
                        raise Failure("nonfinite_decode")
                    maximum = max(abs(x) for x in values)
                    peak = max(peak, maximum)
                    sums += math.fsum(x * x for x in values) / channels
                    if maximum < threshold:
                        if silence_start is None:
                            silence_start = frames
                    elif silence_start is not None:
                        if (
                            frames - silence_start
                            >= rate * profile["silence_min_seconds"]
                        ):
                            intervals.append((silence_start / rate, frames / rate))
                        silence_start = None
                    frames += 1
                    in_window += 1
                    if frames > limits["max_duration_seconds"] * rate:
                        raise Failure("decoded_duration_limit")
                    if in_window == window_frames:
                        windows.append(
                            (
                                (frames - in_window) / rate,
                                frames / rate,
                                sums / in_window,
                            )
                        )
                        sums = 0.0
                        in_window = 0
            code = p.wait(timeout=10)
            if code != 0 or carry or frames == 0:
                raise Failure("decode_error")
            if in_window:
                windows.append(
                    ((frames - in_window) / rate, frames / rate, sums / in_window)
                )
            if (
                silence_start is not None
                and frames - silence_start >= rate * profile["silence_min_seconds"]
            ):
                intervals.append((silence_start / rate, frames / rate))
        finally:
            selector.close()
            p.stdout.close()
            try:
                os.killpg(p.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            p.wait()
    duration = frames / rate
    if abs(duration - info["duration"]) > max(0.25, info["duration"] * 0.001):
        raise Failure("incomplete_decode")
    return duration, peak, windows, intervals


def measure(path, work, profile, limits, mode):
    info = probe(path, work, limits)
    duration, peak, windows, intervals = decode(path, work, info, profile, limits)
    _, logs = external(
        [
            "ffmpeg",
            "-nostdin",
            "-hide_banner",
            "-v",
            "info",
            "-xerror",
            *SAFE_INPUT,
            "-i",
            str(path),
            "-map",
            "0:a:0",
            "-vn",
            "-af",
            profile["loudness_filter"],
            "-f",
            "null",
            "-",
        ],
        work,
        limits,
    )
    summary = logs.rsplit("Summary:", 1)[-1]

    def field(pattern):
        match = re.search(pattern, summary)
        if not match:
            raise Failure("loudness_parse_error")
        value = float(match[1])
        return value if math.isfinite(value) else None

    loudness = field(r"\bI:\s*([-+\w.]+)\s*LUFS")
    lra = field(r"LRA:\s*([-+\w.]+)\s*LU")
    true_peak = field(r"True peak:\s*Peak:\s*([-+\w.]+)\s*dBFS")
    rows = []

    def add(
        metric,
        value,
        unit,
        start=0,
        end=None,
        reason=None,
        method="native PCM/FFmpeg ebur128",
    ):
        rows.append(
            {
                "id": f"m{len(rows) + 1:05d}",
                "metric": metric,
                "start_seconds": round(start, 6),
                "end_seconds": round(duration if end is None else end, 6),
                "value": round(value, 6) if value is not None else None,
                "unit": unit,
                "kind": "measured" if value is not None else "unavailable",
                "method": method,
                "reason": reason,
            }
        )

    all_silence = peak == 0
    no_gate = loudness is None or loudness <= -69.9
    add(
        "duration_seconds",
        duration,
        "s",
        method="full decoded sample count / native sample rate; ffprobe cross-check",
    )
    add(
        "integrated_loudness_lufs",
        None if all_silence or no_gate else loudness,
        "LUFS",
        reason="all_silence"
        if all_silence
        else ("below_loudness_gate" if no_gate else None),
    )
    lra_reason = (
        "all_silence"
        if all_silence
        else (
            "short_duration"
            if duration < profile["lra_min_seconds"]
            else ("below_loudness_gate" if no_gate else None)
        )
    )
    add("loudness_range_lu", None if lra_reason else lra, "LU", reason=lra_reason)
    add(
        "sample_peak_dbfs",
        20 * math.log10(peak) if peak else None,
        "dBFS",
        reason="all_silence" if all_silence else None,
    )
    add(
        "true_peak_dbtp",
        true_peak if not all_silence and true_peak is not None else None,
        "dBTP",
        reason="all_silence" if all_silence else None,
    )
    add("silence_total_seconds", sum(b - a for a, b in intervals), "s")
    add(
        "leading_silence_seconds",
        intervals[0][1] if intervals and intervals[0][0] == 0 else 0,
        "s",
    )
    add(
        "trailing_silence_seconds",
        duration - intervals[-1][0]
        if intervals and abs(intervals[-1][1] - duration) < 1e-6
        else 0,
        "s",
    )
    for a, b in intervals:
        add("silence_interval_seconds", b - a, "s", a, b)
    for a, b, mean_square in windows:
        add(
            "rms_dbfs",
            10 * math.log10(mean_square) if mean_square else None,
            "dBFS",
            a,
            b,
            "all_silence" if mean_square == 0 else None,
            profile["rms_method"],
        )
    evidence = []
    if mode == "audio_visual":
        if not info["video"]:
            raise Failure("no_video")
        times = sorted(
            set(
                max(0, min(t, max(0, duration - 1)))
                for t in profile["frame_times"] + [duration / 2, max(0, duration - 1)]
            )
        )
        for i, t in enumerate(times):
            name = f"frame-f{i:02d}.png"
            _, trace = external(
                [
                    "ffmpeg",
                    "-nostdin",
                    "-hide_banner",
                    "-v",
                    "info",
                    "-xerror",
                    *SAFE_INPUT,
                    "-i",
                    str(path),
                    "-map",
                    "0:v:0",
                    "-vf",
                    f"select=gte(t\\,{t:.6f}),showinfo,scale=-2:'min({profile['frame_height_limit']},ih)'",
                    "-frames:v",
                    "1",
                    "-fps_mode",
                    "passthrough",
                    str(work / name),
                ],
                work,
                limits,
            )
            match = re.search(r"\bpts_time:([-\d.]+)", trace)
            if not match or not (work / name).exists():
                raise Failure("frame_extract_error")
            pts = float(match[1])
            if not 0 <= pts <= duration:
                raise Failure("frame_pts_outside_audio")
            evidence.append(
                {
                    "id": f"f{i:02d}",
                    "kind": "frame",
                    "time_seconds": pts,
                    "sha256": digest(work / name),
                }
            )
    return {
        "duration": round(duration, 6),
        "measurements": rows,
        "evidence": evidence,
        "coverage": [
            {
                "method": "native PCM full decode + FFmpeg ebur128 true peak",
                "start_seconds": 0,
                "end_seconds": round(duration, 6),
                "scope": "full",
                "status": "complete",
            }
        ],
        "codec": info["codec"],
    }
