"""Bounded child supervisor: parent death, timeout, output and disk limits kill the entire tool group."""

import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import time


def supervise(spec_path):
    spec = json.loads(Path(spec_path).read_text())
    parent = os.getppid()
    start = time.monotonic()
    code = "process_error"
    child = None
    try:
        with open(spec["stdout"], "wb") as out, open(spec["stderr"], "wb") as err:
            child = subprocess.Popen(
                spec["argv"],
                stdin=subprocess.DEVNULL,
                stdout=out,
                stderr=err,
                start_new_session=True,
                cwd=spec["cwd"],
            )
            while child.poll() is None:
                if os.getppid() != parent:
                    code = "parent_stopped"
                    break
                if time.monotonic() - start > spec["seconds"]:
                    code = "process_timeout"
                    break
                if (
                    sum(
                        Path(p).stat().st_size for p in [spec["stdout"], spec["stderr"]]
                    )
                    > spec["log_bytes"]
                ):
                    code = "process_output_limit"
                    break
                total = sum(
                    p.lstat().st_size
                    for p in Path(spec["cwd"]).rglob("*")
                    if p.is_file()
                )
                if total > spec["tree_bytes"]:
                    code = "process_disk_limit"
                    break
                time.sleep(0.05)
            else:
                code = "ok" if child.returncode == 0 else "process_error"
    finally:
        if child:
            try:
                os.killpg(child.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            child.wait()
        Path(spec["result"]).write_text(json.dumps({"status": code}))
    return 0 if code == "ok" else 1


if __name__ == "__main__":
    sys.exit(supervise(sys.argv[1]))
