"""Verify the release image index is for the tagged commit and both platforms."""

import json
from pathlib import Path
import sys


def verify(index, revision):
    if index.get("annotations", {}).get("org.opencontainers.image.revision") != revision:
        raise ValueError("image index revision does not match the release commit")

    platforms = {
        (manifest.get("platform", {}).get("os"), manifest.get("platform", {}).get("architecture"))
        for manifest in index.get("manifests", [])
    }
    if not {("linux", "amd64"), ("linux", "arm64")} <= platforms:
        raise ValueError("release image is missing linux/amd64 or linux/arm64")


if __name__ == "__main__":
    try:
        verify(json.loads(Path(sys.argv[1]).read_text()), sys.argv[2])
    except (ValueError, OSError, IndexError) as error:
        sys.exit(f"Invalid release image: {error}")
