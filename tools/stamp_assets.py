#!/usr/bin/env python3
"""
Stamp cache-busting versions onto long-cached stylesheet references.

Why this exists
---------------
Hostinger serves css/ with `Cache-Control: public, max-age=604800`, and the
HTML that references it is not cached at all. A deploy therefore lands new
markup against a visitor's week-old stylesheet. That is not merely a cosmetic
risk: on 2026-09-06 it rendered the early-access honeypot as a visible field,
and real submissions were silently discarded as bot traffic.

The version is a hash of the stylesheet's own contents, not the source commit.
A commit SHA would change the URL on every deploy and throw away a valid cache
for visitors even when the CSS did not move. A content hash changes exactly
when the bytes change, which is the whole point.

Usage
  python tools/stamp_assets.py            # rewrite references to match
  python tools/stamp_assets.py --check    # exit 1 if any is stale (CI/pre-publish)
"""

import argparse
import hashlib
import os
import re
import sys

# Stylesheets that are long-cached and whose staleness can change behaviour.
# fonts.css is deliberately not stamped: it is referenced by the same pages but
# carries only @font-face declarations, so a stale copy degrades typography
# rather than function. Add it here if that ever stops being true.
STAMPED = ("styles.css",)

VERSION_LEN = 8


def content_version(path):
    """Short, stable version derived from the file's bytes."""
    with open(path, "rb") as fh:
        # Normalise line endings so a Windows checkout and a Linux one agree.
        data = fh.read().replace(b"\r\n", b"\n")
    return hashlib.sha256(data).hexdigest()[:VERSION_LEN]


def reference_re(name):
    """Matches href="…/<name>" with or without an existing ?v=… stamp."""
    return re.compile(
        r'(href="[^"]*?%s)(\?v=[A-Za-z0-9]+)?(")' % re.escape(name)
    )


def stamp(site_root, check_only=False):
    problems = []
    changed = []

    for name in STAMPED:
        css_path = None
        for dirpath, _dirs, files in os.walk(site_root):
            if name in files:
                css_path = os.path.join(dirpath, name)
                break
        if css_path is None:
            problems.append("%s not found under %s" % (name, site_root))
            continue

        want = content_version(css_path)
        pattern = reference_re(name)

        for dirpath, _dirs, files in os.walk(site_root):
            for fn in files:
                if not fn.lower().endswith(".html"):
                    continue
                path = os.path.join(dirpath, fn)
                with open(path, encoding="utf-8", errors="replace") as fh:
                    text = fh.read()
                if not pattern.search(text):
                    continue

                rel = os.path.relpath(path, site_root).replace("\\", "/")
                new_text = pattern.sub(r"\g<1>?v=%s\g<3>" % want, text)

                if new_text == text:
                    continue
                if check_only:
                    for match in pattern.finditer(text):
                        got = (match.group(2) or "")[3:] or "(none)"
                        if got != want:
                            problems.append(
                                "%s: %s is stamped %s but the file hashes to %s"
                                % (rel, name, got, want))
                else:
                    with open(path, "w", encoding="utf-8", newline="") as fh:
                        fh.write(new_text)
                    changed.append(rel)

    return problems, changed


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--site", default="site")
    ap.add_argument("--check", action="store_true",
                    help="report stale stamps instead of rewriting them")
    args = ap.parse_args()

    root = os.path.abspath(args.site)
    if not os.path.isdir(root):
        print("FAIL: site directory not found: %s" % root)
        return 2

    problems, changed = stamp(root, check_only=args.check)

    if problems:
        print("FAILED - %d stale asset reference(s):" % len(problems))
        for p in problems:
            print("  - %s" % p)
        return 1

    if args.check:
        print("all stamped asset references are current")
        return 0

    if changed:
        print("stamped %d file(s):" % len(changed))
        for c in sorted(set(changed)):
            print("  - %s" % c)
    else:
        print("nothing to do - every reference is already current")
    return 0


if __name__ == "__main__":
    sys.exit(main())
