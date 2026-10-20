# Legacy wallpaper archive

WAPI-12 removes all 321 tracked PNG files under `storage/bing-wallpaper/` from
the current tree. The metadata API does not use them. No Git history was rewritten;
the image objects remain in full clones, so this cleanup does not materially
reduce full-clone size.

Before removal, the annotated tag `archive/legacy-wallpapers` was published at
commit `5cb12d6182d710d385024dfabbeaee7279d23808`. Do not move this archival tag.
The [manifest](legacy-wallpapers.txt) records each path, Git blob ID and byte size
from that commit (321 PNGs, 203,182,682 bytes in total).

Browse the files on [GitHub](https://github.com/haoyuqi/wallpaper-api/tree/archive/legacy-wallpapers/storage/bing-wallpaper),
or extract them outside your checkout to a new temporary directory:

```bash
git fetch origin tag archive/legacy-wallpapers
archive_dir=$(mktemp -d)
git archive archive/legacy-wallpapers storage/bing-wallpaper | tar -x -C "$archive_dir"
```

The archive retains third-party content and attribution; the project's MIT code
license does not grant rights to the images. New files under the old image path
remain ignored, and the repository guard test rejects tracked PNGs there.
