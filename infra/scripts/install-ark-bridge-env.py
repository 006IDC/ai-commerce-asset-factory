#!/usr/bin/env python3
"""Install the root-only Ark Bridge environment without logging secrets."""

from __future__ import annotations

import hashlib
import os
from pathlib import Path
import secrets
import stat
import sys


TARGET = Path("/etc/ark-bridge.env")


def required(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value or "\n" in value or "\r" in value:
        raise RuntimeError(f"{name} is missing or invalid")
    return value


def main() -> int:
    if os.geteuid() != 0:
        raise RuntimeError("run as root")

    access_key = required("VOLC_ACCESSKEY")
    secret_key = required("VOLC_SECRETKEY")
    zhipu_key = required("ZHIPU_API_KEY")
    internal_key = "sk-" + secrets.token_hex(32)
    content = "\n".join(
        [
            "# Managed by install-ark-bridge-env.py; do not commit or expose.",
            f"VOLC_ACCESSKEY={access_key}",
            f"VOLC_SECRETKEY={secret_key}",
            f"ZHIPU_API_KEY={zhipu_key}",
            f"ARK_BRIDGE_API_KEY={internal_key}",
            "VOLC_REGION=cn-beijing",
            "VOLC_PROJECT=default",
            "ARK_BRIDGE_HOST=127.0.0.1",
            "ARK_BRIDGE_PORT=3010",
            "LOG_LEVEL=INFO",
            "",
        ]
    )
    temporary = TARGET.with_suffix(".env.new")
    temporary.write_text(content, encoding="utf-8")
    os.chmod(temporary, stat.S_IRUSR | stat.S_IWUSR)
    os.replace(temporary, TARGET)
    os.chown(TARGET, 0, 0)
    os.chmod(TARGET, stat.S_IRUSR | stat.S_IWUSR)

    fingerprint = hashlib.sha256(internal_key.encode("utf-8")).hexdigest()[:16]
    print(f"ark-bridge-env|installed|mode=0600|internal-key-sha256={fingerprint}")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exception:
        print(f"ERROR: {exception}", file=sys.stderr)
        raise SystemExit(1)
