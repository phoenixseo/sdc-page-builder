#!/usr/bin/env python3
"""Build the wp.org distribution zip for sdc-page-builder.

Reproduces what `zip -r sdc-page-builder.zip plugin/ -x ...` would produce, but
reads the exclusion rules from `.distignore` so the package and the ignore file
cannot drift apart. Exit code is 0 only when the package is verifiably clean.

Usage:  python3 test-scf/build-zip.py [--out PATH]
"""

import argparse
import fnmatch
import os
import sys
import zipfile

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), os.pardir))
PLUGIN = os.path.join(ROOT, "plugin")


def load_rules():
    """Read .distignore into (anchored, unanchored) pattern lists."""
    anchored, bare = [], []
    path = os.path.join(ROOT, ".distignore")
    with open(path, encoding="utf-8") as handle:
        for line in handle:
            line = line.strip()
            if not line or line.startswith("#"):
                continue
            if line.startswith("/"):
                anchored.append(line[1:])
            else:
                bare.append(line)
    return anchored, bare


def excluded(rel, anchored, bare):
    """Whether a root-relative path is excluded.

    An anchored pattern excludes the path itself and everything below it. A
    bare pattern matches any path component (a directory name at any depth), or
    the path's own basename — so `composer.json` also catches
    `plugin/composer.json`. fnmatch's `*` does not cross `/`.
    """
    for pattern in anchored:
        if rel == pattern or rel.startswith(pattern + "/"):
            return True
    basename = rel.rsplit("/", 1)[-1]
    for pattern in bare:
        if fnmatch.fnmatch(basename, pattern):
            return True
        for part in rel.split("/"):
            if fnmatch.fnmatch(part, pattern):
                return True
    return False


def collect(anchored, bare):
    entries = []
    for dirpath, dirnames, filenames in os.walk(PLUGIN):
        rel_dir = os.path.relpath(dirpath, ROOT).replace(os.sep, "/")
        # Prune excluded directories so we never descend into node_modules.
        dirnames[:] = sorted(
            d for d in dirnames if not excluded(f"{rel_dir}/{d}", anchored, bare)
        )
        for name in sorted(filenames):
            rel = f"{rel_dir}/{name}"
            if excluded(rel, anchored, bare):
                continue
            entries.append(rel)
    return sorted(entries)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--out", default=os.path.join(ROOT, "sdc-page-builder.zip"))
    args = parser.parse_args()

    anchored, bare = load_rules()
    entries = collect(anchored, bare)

    if os.path.exists(args.out):
        os.remove(args.out)

    with zipfile.ZipFile(args.out, "w", zipfile.ZIP_DEFLATED) as archive:
        for rel in entries:
            archive.write(os.path.join(ROOT, rel), rel)

    print(f"out: {args.out}")
    print(f"files: {len(entries)}")
    for rel in entries:
        print(rel)

    # Verify the four prohibitions and the two required files from the card.
    forbidden = [("node_modules", "node_modules/"), ("vendor", "/vendor/"),
                 ("tests", "/tests/"), ("screenshot", "/screenshot-")]
    problems = []
    for label, needle in forbidden:
        if label == "tests":
            hits = [e for e in entries if e.startswith("plugin/tests/")]
        else:
            hits = [e for e in entries if needle in "/" + e]
        print(f"check {label}: {len(hits)} hit(s)")
        if hits:
            problems.append(f"{label}: {hits[:3]}")
    for required in ("plugin/LICENSE", "plugin/readme.txt"):
        present = required in entries
        print(f"check {required}: {'yes' if present else 'MISSING'}")
        if not present:
            problems.append(f"missing {required}")

    if problems:
        print("FAIL " + "; ".join(problems), file=sys.stderr)
        return 1
    print("ERGEBNIS: Paket sauber")
    return 0


if __name__ == "__main__":
    sys.exit(main())
