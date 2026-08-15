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
  9. FAQPage structured data matches the visible FAQ, question for question

Usage
  python tools/check_site.py [--site site] [--quiet]
"""

import argparse
import json
import os
import re
import sys
# not "import html": main() already binds `html` to the list of HTML files
from html import unescape
from urllib.parse import urlparse, unquote

ATTR = re.compile(r'(?:href|src)\s*=\s*"([^"]+)"', re.I)
CSSURL = re.compile(r"""url\(\s*['"]?([^)'"]+?)['"]?\s*\)""")
IDRE = re.compile(r'\bid\s*=\s*"([^"]+)"')
LDRE = re.compile(
    r'<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>',
    re.I | re.S)
CANON = re.compile(r'<link[^>]+rel=["\']canonical["\'][^>]*href=["\']([^"\']+)["\']', re.I)
LOC = re.compile(r"<loc>\s*([^<]+?)\s*</loc>", re.I)

# Visible FAQ entries: <details class="wt-q"><summary>Q</summary><div class="a"><p>A</p>…</div></details>
FAQ_ITEM = re.compile(
    r'<details class="wt-q">\s*<summary>(.*?)</summary>\s*<div class="a">(.*?)</div>\s*</details>',
    re.S)
FAQ_PARA = re.compile(r"<p>(.*?)</p>", re.S)

SKIP_SCHEMES = ("http://", "https://", "mailto:", "data:", "tel:", "//", "#")
ALLOWED_EXTERNAL_LINK_HOSTS = {
    # plain hyperlinks a visitor may click; these do not fire on page load
    "openweathermap.org",
}


def rel(path, root):
    return os.path.relpath(path, root).replace("\\", "/")


def plain(fragment):
    """Visible text of an HTML fragment, whitespace-normalised."""
    return re.sub(r"\s+", " ", unescape(re.sub(r"<[^>]+>", "", fragment))).strip()


def visible_faq(text):
    """[(question, answer)] as a reader sees them, in document order."""
    return [(plain(q), " ".join(plain(p) for p in FAQ_PARA.findall(a)))
            for q, a in FAQ_ITEM.findall(text)]


def schema_faq(text):
    """[(question, answer)] as declared by FAQPage JSON-LD, in document order."""
    out = []
    for block in LDRE.findall(text):
        try:
            doc = json.loads(block)
        except Exception:
            continue  # a broken block is already reported by the JSON-LD check
        if doc.get("@type") != "FAQPage":
            continue
        for q in doc.get("mainEntity", []):
            out.append((plain(str(q.get("name", ""))),
                        plain(str(q.get("acceptedAnswer", {}).get("text", "")))))
    return out


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

    # ---- FAQ structured data must describe the FAQ a reader actually sees ----
    # Schema that drifts from the page stops being a description and becomes a
    # claim about content that isn't there. Both directions are failures: markup
    # without schema is only a missed opportunity, but schema without matching
    # visible copy is misrepresentation.
    faq_checked = 0
    for f in html:
        text = open(f, encoding="utf-8", errors="replace").read()
        name = rel(f, root)
        seen, declared = visible_faq(text), schema_faq(text)
        if not seen and not declared:
            continue
        if declared and not seen:
            problems.append("%s: FAQPage schema declares questions but the page shows no FAQ" % name)
            continue
        if not declared:
            continue  # visible FAQ with no schema: allowed, nothing is misstated
        faq_checked += 1
        if len(seen) != len(declared):
            problems.append("%s: FAQPage declares %d question(s) but %d are visible"
                            % (name, len(declared), len(seen)))
        for i, (want, got) in enumerate(zip(seen, declared), 1):
            if want[0] != got[0]:
                problems.append("%s: FAQ #%d question text differs\n      visible: %s\n      schema:  %s"
                                % (name, i, want[0], got[0]))
            elif want[1] != got[1]:
                problems.append("%s: FAQ #%d (%s) answer text differs from the visible answer"
                                % (name, i, want[0]))

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

    # ---- commercial terms: pages must agree with tools/pricing.json ----
    pj = os.path.join(os.path.dirname(os.path.abspath(__file__)), "pricing.json")
    priced = 0
    if os.path.isfile(pj):
        cfg = json.load(open(pj, encoding="utf-8"))
        wt = os.path.join(root, "watchtower", "index.html")
        sn = os.path.join(root, "sentinel", "index.html")
        tm = os.path.join(root, "terms.html")
        wt_txt = open(wt, encoding="utf-8").read() if os.path.isfile(wt) else ""
        sn_txt = open(sn, encoding="utf-8").read() if os.path.isfile(sn) else ""
        tm_txt = open(tm, encoding="utf-8").read() if os.path.isfile(tm) else ""

        wtf, wtc = cfg["watchtower"]["founder"], cfg["watchtower"]["community"]
        snf = cfg["sentinel"]["founder"]
        refund = "%d-day refund" % cfg["refund_days"]

        must = [
            (wt_txt, "watchtower/index.html", wtf["launch_price"]),
            (wt_txt, "watchtower/index.html", wtf["regular_price"]),
            (wt_txt, "watchtower/index.html", cfg["promo_ends"]),
            # Community limits must be stated, not merely implied
            (wt_txt, "watchtower/index.html", wtc["price"]),
            (wt_txt, "watchtower/index.html", "%d cameras" % wtc["max_cameras"]),
            (wt_txt, "watchtower/index.html", "%d days" % wtc["max_retention_days"]),
            (sn_txt, "sentinel/index.html", snf["launch_price"]),
            (sn_txt, "sentinel/index.html", snf["regular_price"]),
            (sn_txt, "sentinel/index.html", cfg["promo_ends"]),
            # the refund policy: on both paid product pages and spelled out in Terms.
            # The condition is asserted alongside the offer on every page that makes
            # it - the refund is not unconditional and must never read as if it is.
            (wt_txt, "watchtower/index.html", refund),
            (wt_txt, "watchtower/index.html", cfg["refund_condition"]),
            (sn_txt, "sentinel/index.html", refund),
            (sn_txt, "sentinel/index.html", cfg["refund_condition"]),
            (tm_txt, "terms.html", refund),
            (tm_txt, "terms.html", cfg["refund_condition"]),
            (tm_txt, "terms.html", 'id="refunds"'),
        ]
        for txt, name, value in must:
            priced += 1
            if txt and value not in txt:
                problems.append("%s: pricing.json says %r but the page does not contain it"
                                % (name, value))

        # guardrails: claims we have explicitly ruled out, anywhere on the site
        for f in html:
            low = open(f, encoding="utf-8", errors="replace").read().lower()
            for bad in cfg.get("forbidden_claims", []):
                if bad.lower() in low:
                    problems.append("%s: forbidden claim %r appears" % (rel(f, root), bad))

    if not args.quiet:
        print("checked %d files, %d internal references, %d commercial constants, "
              "%d FAQ page(s)" % (len(files), checked_refs, priced, faq_checked))

    if problems:
        print("\nFAILED - %d problem(s):" % len(problems))
        for p in problems:
            print("  - %s" % p)
        return 1

    print("ALL SITE CHECKS PASSED")
    return 0


if __name__ == "__main__":
    sys.exit(main())
