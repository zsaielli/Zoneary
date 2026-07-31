#!/usr/bin/env python3
"""
Zoneary website pre-publish checks.

Static, dependency-free validation of everything under site/. Run before any
deployment; a non-zero exit blocks publishing.

Checks
  1. every internal href/src resolves to a real file
  2. directory links (e.g. watchtower/) resolve to an index.html
  3. every in-page #anchor has a matching id
  4. every url() in CSS resolves to a real file
  5. every JSON-LD block parses
  6. every page declares a canonical URL
  7. sitemap.xml URLs map to real files, and every page is listed
  8. no page requests a third-party host (fonts, CDNs, trackers)

Usage
  python tools/check_site.py [--site site] [--quiet]
"""

import argparse
import json
import os
import re
import sys
from urllib.parse import urlparse, unquote

ATTR = re.compile(r'(?:href|src)\s*=\s*"([^"]+)"', re.I)
CSSURL = re.compile(r"""url\(\s*['"]?([^)'"]+?)['"]?\s*\)""")
IDRE = re.compile(r'\bid\s*=\s*"([^"]+)"')
LDRE = re.compile(
    r'<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>',
    re.I | re.S)
CANON = re.compile(r'<link[^>]+rel=["\']canonical["\'][^>]*href=["\']([^"\']+)["\']', re.I)
LOC = re.compile(r"<loc>\s*([^<]+?)\s*</loc>", re.I)

SKIP_SCHEMES = ("http://", "https://", "mailto:", "data:", "tel:", "//", "#")
ALLOWED_EXTERNAL_LINK_HOSTS = {
    # plain hyperlinks a visitor may click; these do not fire on page load
    "openweathermap.org",
}


def rel(path, root):
    return os.path.relpath(path, root).replace("\\", "/")


def collect(root):
    out = []
    for dirpath, _dirs, files in os.walk(root):
        for f in files:
            if f.lower().endswith((".html", ".css", ".xml")):
                out.append(os.path.join(dirpath, f))
    return sorted(out)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--site", default="site", help="path to the deployable site root")
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    root = os.path.abspath(args.site)
    if not os.path.isdir(root):
        print("FAIL: site directory not found: %s" % root)
        return 2
    if not os.path.isfile(os.path.join(root, "index.html")):
        print("FAIL: %s/index.html is missing - refusing to continue" % args.site)
        return 2

    files = collect(root)
    html = [f for f in files if f.lower().endswith(".html")]
    ids = {}
    for f in html:
        ids[os.path.normpath(f)] = set(
            IDRE.findall(open(f, encoding="utf-8", errors="replace").read()))

    problems = []
    checked_refs = 0

    for f in files:
        text = open(f, encoding="utf-8", errors="replace").read()
        base = os.path.dirname(f)
        name = rel(f, root)

        refs = ATTR.findall(text)
        if f.lower().endswith(".css"):
            refs += CSSURL.findall(text)
        if f.lower().endswith(".xml"):
            refs = []

        for raw in refs:
            u = raw.strip()
            if not u:
                continue
            if u.startswith("#"):
                frag = unquote(u[1:])
                if frag and frag not in ids.get(os.path.normpath(f), set()):
                    problems.append("%s: in-page anchor #%s has no matching id" % (name, frag))
                continue
            if u.startswith(SKIP_SCHEMES):
                if u.startswith(("http://", "https://")):
                    host = urlparse(u).netloc.lower()
                    zoneary = host.endswith("zoneary.com") or host.endswith("schema.org")
                    if not zoneary and host not in ALLOWED_EXTERNAL_LINK_HOSTS:
                        problems.append("%s: third-party request to %s (%s)" % (name, host, u))
                continue

            parsed = urlparse(u)
            path = unquote(parsed.path)
            if not path:
                continue
            target = os.path.normpath(os.path.join(base, path))
            checked_refs += 1

            if os.path.isdir(target):
                idx = os.path.join(target, "index.html")
                if not os.path.isfile(idx):
                    problems.append("%s: directory link %s has no index.html" % (name, u))
                    continue
                target = idx
            if not os.path.exists(target):
                problems.append("%s: missing target -> %s" % (name, u))
            elif parsed.fragment and target.endswith(".html"):
                if parsed.fragment not in ids.get(os.path.normpath(target), set()):
                    problems.append("%s: %s -> #%s not found in target page" % (name, u, parsed.fragment))

    # JSON-LD + canonical
    for f in html:
        text = open(f, encoding="utf-8", errors="replace").read()
        name = rel(f, root)
        for block in LDRE.findall(text):
            try:
                json.loads(block)
            except Exception as exc:
                problems.append("%s: JSON-LD does not parse (%s)" % (name, exc))
        if name != "sentinel/demo.html" and not CANON.search(text):
            problems.append("%s: no canonical URL declared" % name)

    # sitemap
    sm = os.path.join(root, "sitemap.xml")
    if not os.path.isfile(sm):
        problems.append("sitemap.xml is missing")
    else:
        listed = set()
        for loc in LOC.findall(open(sm, encoding="utf-8", errors="replace").read()):
            p = urlparse(loc).path
            target = os.path.normpath(os.path.join(root, p.lstrip("/")))
            if os.path.isdir(target):
                target = os.path.join(target, "index.html")
            if p in ("/", ""):
                target = os.path.join(root, "index.html")
            if not os.path.isfile(target):
                problems.append("sitemap.xml: %s does not resolve to a file" % loc)
            listed.add(rel(target, root))
        # every indexable page should be listed
        for f in html:
            n = rel(f, root)
            if n.startswith("sentinel/demo"):
                continue
            if n not in listed:
                problems.append("sitemap.xml: %s is not listed" % n)

    if not args.quiet:
        print("checked %d files, %d internal references" % (len(files), checked_refs))

    if problems:
        print("\nFAILED - %d problem(s):" % len(problems))
        for p in problems:
            print("  - %s" % p)
        return 1

    print("ALL SITE CHECKS PASSED")
    return 0


if __name__ == "__main__":
    sys.exit(main())
