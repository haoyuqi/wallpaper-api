"""Test release index validation independently of a registry."""

import importlib.util
from pathlib import Path
import unittest


spec = importlib.util.spec_from_file_location("verify_image", Path(__file__).with_name("verify-image.py"))
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class VerifyImageTests(unittest.TestCase):
    @staticmethod
    def index():
        return {
            "annotations": {"org.opencontainers.image.revision": "a" * 40},
            "manifests": [
                {"platform": {"os": "linux", "architecture": architecture}}
                for architecture in ("amd64", "arm64")
            ],
        }

    def test_accepts_release_commit_and_both_platforms(self):
        module.verify(self.index(), "a" * 40)

    def test_rejects_different_commit(self):
        with self.assertRaisesRegex(ValueError, "revision"):
            module.verify(self.index(), "b" * 40)

    def test_rejects_missing_architecture(self):
        index = self.index()
        index["manifests"].pop()
        with self.assertRaisesRegex(ValueError, "arm64"):
            module.verify(index, "a" * 40)
