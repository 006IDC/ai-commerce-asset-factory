"""Synthetic-only checks for the encrypted Ark credential installer."""

import importlib.util
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile
import unittest
from unittest.mock import patch


SPEC = importlib.util.spec_from_file_location(
    "ark_installer", Path(__file__).with_name("install-ark-bridge-env.py")
)
installer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(installer)
VALUES = {name: "synthetic-not-a-live-value-" + name.lower() for name in (
    "VOLC_ACCESSKEY", "VOLC_SECRETKEY", "ZHIPU_API_KEY", "ARK_BRIDGE_API_KEY"
)}


class CredentialInstallerTest(unittest.TestCase):
    def test_only_ciphertext_is_persisted_with_private_mode(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / "store" / installer.CREDENTIAL_NAME

            def encrypt(content, output):
                self.assertEqual(json.loads(content), VALUES)
                self.assertEqual(stat.S_IMODE(output.parent.stat().st_mode), 0o700)
                self.assertFalse(output.exists())
                output.write_bytes(b"synthetic-ciphertext-not-a-secret")

            installer.install_encrypted(VALUES, target, encrypt)
            self.assertEqual(target.read_bytes(), b"synthetic-ciphertext-not-a-secret")
            self.assertEqual(stat.S_IMODE(target.stat().st_mode), 0o600)
            self.assertEqual(list(target.parent.iterdir()), [target])

    def test_encryption_failure_preserves_previous_credential(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / installer.CREDENTIAL_NAME
            target.write_bytes(b"previous-ciphertext")

            def fail(content, output):
                output.write_bytes(b"incomplete-ciphertext")
                raise RuntimeError("synthetic encryption failure")

            with self.assertRaisesRegex(RuntimeError, "synthetic"):
                installer.install_encrypted(VALUES, target, fail)
            self.assertEqual(target.read_bytes(), b"previous-ciphertext")
            self.assertEqual(list(target.parent.iterdir()), [target])

    def test_rejects_shared_writable_directory(self):
        with tempfile.TemporaryDirectory() as directory:
            os.chmod(directory, 0o777)
            with self.assertRaisesRegex(RuntimeError, "directory"):
                installer.install_encrypted(VALUES, Path(directory) / "credential")

    def test_rejects_directory_symlink(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)
            (path / "actual").mkdir()
            (path / "link").symlink_to(path / "actual", target_is_directory=True)
            with self.assertRaisesRegex(RuntimeError, "directory"):
                installer.install_encrypted(VALUES, path / "link" / "credential")

    def test_missing_native_tool_does_not_fall_back_to_plaintext(self):
        with patch.object(installer, "SYSTEMD_CREDS", Path("/nonexistent/synthetic-tool")):
            with self.assertRaisesRegex(RuntimeError, "no plaintext fallback"):
                installer.encrypt_payload(b"synthetic-input", Path("/unused-output"))

    def test_encryptor_uses_stdin_not_secret_arguments(self):
        with patch.object(installer, "SYSTEMD_CREDS", Path(__file__)), \
                patch.object(installer.subprocess, "run") as run:
            run.return_value.returncode = 0
            installer.encrypt_payload(b"synthetic-private-payload", Path("/synthetic-output"))
            args, kwargs = run.call_args
            self.assertNotIn("synthetic-private-payload", " ".join(args[0]))
            self.assertIn("--with-key=host", args[0])
            self.assertEqual(kwargs["input"], b"synthetic-private-payload")

    @unittest.skipUnless(os.environ.get("ARK_CREDENTIAL_NATIVE_TEST") == "1",
                         "native systemd round trip runs on the isolated Linux CI runner")
    def test_native_host_encryption_round_trip(self):
        self.assertEqual(os.geteuid(), 0, "native test must run on an isolated root CI process")
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / installer.CREDENTIAL_NAME
            installer.install_encrypted(VALUES, target)
            encrypted = target.read_bytes()
            for value in VALUES.values():
                self.assertNotIn(value.encode(), encrypted)
            result = subprocess.run(
                [str(installer.SYSTEMD_CREDS), "decrypt", "--name=" + installer.CREDENTIAL_NAME,
                 str(target), "-"], stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True,
            )
            self.assertEqual(json.loads(result.stdout), VALUES)


if __name__ == "__main__":
    unittest.main()
