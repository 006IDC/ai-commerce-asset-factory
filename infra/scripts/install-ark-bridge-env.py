#!/usr/bin/env python3
"""Install host-encrypted systemd credentials without a plaintext disk copy."""

from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import secrets
import stat
import subprocess
import sys
import tempfile


CREDENTIAL_NAME = "ark-bridge-secrets"
TARGET = Path("/etc/credstore.encrypted") / CREDENTIAL_NAME
SYSTEMD_CREDS = Path("/usr/bin/systemd-creds")


def required(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value or "\n" in value or "\r" in value:
        raise RuntimeError(f"{name} is missing or invalid")
    return value


def encrypt_payload(content: bytes, destination: Path) -> None:
    if not SYSTEMD_CREDS.is_file():
        raise RuntimeError("systemd-creds 250+ is required; no plaintext fallback")
    result = subprocess.run(
        [str(SYSTEMD_CREDS), "encrypt", "--with-key=host",
         "--name=" + CREDENTIAL_NAME, "-", str(destination)],
        input=content, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE,
        check=False,
    )
    if result.returncode:
        raise RuntimeError("credential encryption failed; existing credential preserved")


def install_encrypted(values: dict[str, str], target: Path = TARGET,
                      encryptor=encrypt_payload) -> None:
    """Only ciphertext is ever written; replacement is atomic and owner-only."""
    parent = target.parent
    parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    metadata = parent.lstat()
    if (not stat.S_ISDIR(metadata.st_mode)
            or metadata.st_uid != os.geteuid()
            or metadata.st_mode & 0o022):
        raise RuntimeError("credential directory must be owned and not externally writable")
    content = json.dumps(values, ensure_ascii=True).encode("utf-8")
    with tempfile.TemporaryDirectory(prefix=".ark-bridge-", dir=parent) as directory:
        temporary = Path(directory) / CREDENTIAL_NAME
        encryptor(content, temporary)
        descriptor = os.open(temporary, os.O_RDONLY | os.O_NOFOLLOW)
        try:
            metadata = os.fstat(descriptor)
            if (not stat.S_ISREG(metadata.st_mode)
                    or metadata.st_uid != os.geteuid() or metadata.st_size == 0):
                raise RuntimeError("encrypted credential output is invalid")
            os.fchmod(descriptor, stat.S_IRUSR | stat.S_IWUSR)
        finally:
            os.close(descriptor)
        os.replace(temporary, target)


def main() -> int:
    if os.geteuid() != 0:
        raise RuntimeError("run as root")

    access_key = required("VOLC_ACCESSKEY")
    secret_key = required("VOLC_SECRETKEY")
    zhipu_key = required("ZHIPU_API_KEY")
    internal_key = "sk-" + secrets.token_hex(32)
    install_encrypted({
        "VOLC_ACCESSKEY": access_key,
        "VOLC_SECRETKEY": secret_key,
        "ZHIPU_API_KEY": zhipu_key,
        "ARK_BRIDGE_API_KEY": internal_key,
    })

    fingerprint = hashlib.sha256(internal_key.encode("utf-8")).hexdigest()[:16]
    print(f"ark-bridge-credential|encrypted|mode=0600|internal-key-sha256={fingerprint}")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exception:
        print(f"ERROR: {exception}", file=sys.stderr)
        raise SystemExit(1)
