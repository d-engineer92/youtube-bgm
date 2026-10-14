"""Strict JSON, durable writes and path primitives; no shell or database access."""

import hashlib
import json
import math
import os
from pathlib import Path
import stat
import uuid

REPO = Path(__file__).resolve().parents[2]


class Failure(Exception):
    pass


def canonical_id(value):
    try:
        parsed = uuid.UUID(value)
    except (ValueError, TypeError, AttributeError):
        raise Failure("invalid_uuid") from None
    if str(parsed) != value:
        raise Failure("noncanonical_uuid")
    return value


def check_path(path):
    path = Path(os.path.abspath(path))
    for component in [*reversed(path.parents), path]:
        if component.is_symlink():
            raise Failure("symlink_rejected")
    return path


def regular(path):
    check_path(path)
    info = Path(path).lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
        raise Failure("nonregular_or_hardlink_rejected")
    return info


def digest(path):
    regular(path)
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for block in iter(lambda: f.read(1048576), b""):
            h.update(block)
    return h.hexdigest()


def encoded(data):
    return (
        json.dumps(data, ensure_ascii=False, sort_keys=True, indent=2, allow_nan=False)
        + "\n"
    ).encode()


def object_pairs(pairs):
    obj = {}
    for key, value in pairs:
        if key in obj:
            raise Failure("duplicate_json_key")
        obj[key] = value
    return obj


def read_json(path, limit=8 * 1024 * 1024):
    if regular(path).st_size > limit:
        raise Failure("json_size_limit")
    try:
        obj = json.loads(
            Path(path).read_text(),
            object_pairs_hook=object_pairs,
            parse_constant=lambda _: (_ for _ in ()).throw(Failure("nonfinite_number")),
        )
    except (ValueError, UnicodeError, RecursionError):
        raise Failure("invalid_json") from None

    def visit(value, depth=0):
        if depth > 32:
            raise Failure("json_depth_limit")
        if isinstance(value, float) and not math.isfinite(value):
            raise Failure("nonfinite_number")
        if isinstance(value, dict):
            for v in value.values():
                visit(v, depth + 1)
        elif isinstance(value, list):
            for v in value:
                visit(v, depth + 1)

    visit(obj)
    return obj


def fsync_dir(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def write_bytes(path, data):
    path = check_path(path)
    if path.exists():
        regular(path)
    temp = path.with_name(path.name + ".writing-" + str(uuid.uuid4()))
    fd = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        with os.fdopen(fd, "wb") as f:
            f.write(data)
            f.flush()
            os.fsync(f.fileno())
        os.replace(temp, path)
        fsync_dir(path.parent)
    finally:
        if temp.exists():
            temp.unlink()


def write_json(path, obj):
    write_bytes(path, encoded(obj))
