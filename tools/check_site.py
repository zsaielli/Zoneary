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
 10. the contact form posts to its server endpoint and no longer uses mailto
 11. no credential material is present anywhere in the deployable site

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


def php_const_list(text, name):
    """The single-quoted string entries of a `const NAME = [...]` array.

    Used to read the endpoint's own allowlists rather than restating them here,
    so the form and the server cannot drift apart without this check noticing.

    Comments are stripped first. An apostrophe in a comment - "the site's single
    contact destination" - would otherwise pair with the opening quote of the
    next real entry and silently swallow it, which is exactly the kind of quiet
    wrong answer a checker must not give.
    """
    match = re.search(r"const\s+%s\s*=\s*\[(.*?)\];" % re.escape(name), text, re.S)
    if not match:
        return set()
    body = re.sub(r"/\*.*?\*/", "", match.group(1), flags=re.S)
    body = re.sub(r"//[^\n]*", "", body)
    return set(re.findall(r"'([^'\n]*)'", body))


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

    # ---- the contact form must be server-side, and stay server-side ----------
    # The form used to open the visitor's mail client. It now posts to a PHP
    # endpoint that authenticates to SMTP on the server. Both halves of that are
    # checked here: that the endpoint is actually shipping, and that no part of
    # the old mailto submission has crept back in.
    ea = os.path.join(root, "contact", "index.html")
    endpoint = os.path.join(root, "api", "contact.php")
    if os.path.isfile(ea):
        ea_txt = open(ea, encoding="utf-8", errors="replace").read()

        if not os.path.isfile(endpoint):
            problems.append("contact/index.html expects api/contact.php, which is not in the site tree")
        if 'action="../api/contact.php"' not in ea_txt:
            problems.append("contact/index.html: the form does not post to ../api/contact.php")
        if "fetch(" not in ea_txt:
            problems.append("contact/index.html: the form is not submitted asynchronously")

        # A plain "email us" link is fine. A mailto carrying the form's contents
        # is the old flow, whatever it is dressed up as.
        if re.search(r"mailto:[^\"']*[?&]body=", ea_txt, re.I):
            problems.append("contact/index.html: a mailto: link carries a prefilled body")
        if "window.location.href" in ea_txt:
            problems.append("contact/index.html: the page still navigates by assigning location.href")
        if "nothing is submitted or stored on this site" in ea_txt:
            problems.append("contact/index.html: the explanatory copy still describes the old mailto flow")

        # ---- the honeypot must hide itself -------------------------------
        # It was concealed only by a class in styles.css. A visitor holding a
        # cached stylesheet saw an ordinary "Company website" field, filled it
        # in, and had their submission silently discarded as bot traffic. A
        # control that changes behaviour cannot depend on a separate file
        # arriving, so the concealment is now inline - and asserted here.
        trap = re.search(r'<div[^>]*class="ea-trap"[^>]*>(.*?)</div>', ea_txt, re.S)
        if not trap:
            problems.append("contact/index.html: the honeypot wrapper (.ea-trap) is missing")
        else:
            wrapper = trap.group(0)[:trap.group(0).find(">") + 1]
            inline = re.search(r'style="([^"]*)"', wrapper)
            if not inline:
                problems.append("contact/index.html: the honeypot has no inline style - "
                                "it would be visible if styles.css were stale")
            else:
                css = inline.group(1).replace(" ", "").lower()
                if "position:absolute" not in css or "left:-" not in css:
                    problems.append("contact/index.html: the honeypot's inline style does not "
                                    "move it off-screen (want position:absolute + a negative left)")
            if 'tabindex="-1"' not in trap.group(1):
                problems.append("contact/index.html: the honeypot input is still keyboard-focusable")
            if 'aria-hidden="true"' not in wrapper:
                problems.append("contact/index.html: the honeypot wrapper is not aria-hidden")
            if 'type="hidden"' in trap.group(1):
                problems.append("contact/index.html: the honeypot uses type=\"hidden\", "
                                "which automated submitters skip - it would stop detecting anything")

        # The library directory is denied at the web-server level. Losing this
        # file would not break anything visibly, which is exactly why it is
        # checked rather than trusted.
        if os.path.isfile(endpoint):
            deny = os.path.join(root, "api", "lib", ".htaccess")
            if not os.path.isfile(deny):
                problems.append("api/lib/.htaccess is missing - the library directory would be web-readable")
            elif "Require all denied" not in open(deny, encoding="utf-8", errors="replace").read():
                problems.append("api/lib/.htaccess no longer denies access")

        # Every field the form posts must be one the endpoint accepts, and every
        # product option must survive its allowlist - a mismatch is a form that
        # looks fine and is rejected at the server.
        if os.path.isfile(endpoint):
            val = os.path.join(root, "api", "lib", "validate.php")
            val_txt = open(val, encoding="utf-8", errors="replace").read() if os.path.isfile(val) else ""
            known = php_const_list(val_txt, "KNOWN_FIELDS")
            products = php_const_list(val_txt, "PRODUCTS")
            if known:
                for field in set(re.findall(r'<(?:input|select|textarea)[^>]*\bname="([^"]+)"', ea_txt, re.I)):
                    if field not in known:
                        problems.append("contact/index.html: form field %r is not accepted by the endpoint" % field)
            if products:
                for value in re.findall(r'<option value="([^"]+)"', ea_txt):
                    if value not in products:
                        problems.append("contact/index.html: product option %r is not in the server allowlist" % value)

    # ---- Contact routes to the form, not to a mail client --------------------
    # The site has one contact destination. A link labelled "Contact" that opens
    # the visitor's mail client is the behaviour the form replaced, so it fails
    # the publish rather than quietly reappearing in a footer someone copied.
    # Informational "email us at ..." links in body copy are deliberately left
    # alone - only links whose visible label is Contact are covered here.
    CONTACT_MAILTO = re.compile(r'<a[^>]*href="mailto:[^"]*"[^>]*>\s*Contact\s*</a>', re.I)
    CONTACT_FORM = re.compile(r'<a[^>]*href="[^"]*contact/[^"]*"[^>]*>\s*Contact\s*</a>', re.I)
    routed = 0
    for f in html:
        text = open(f, encoding="utf-8", errors="replace").read()
        name = rel(f, root)
        if CONTACT_MAILTO.search(text):
            problems.append("%s: a link labelled Contact still opens a mail client" % name)
        if CONTACT_FORM.search(text):
            routed += 1
    if routed and routed < 9:
        problems.append("only %d page(s) route Contact to the form; expected every page with a footer" % routed)

    # ---- commercial CTAs must terminate at the form --------------------------
    # A CTA that says "Request early access" and scrolls to another section
    # containing a second "Request early access" button costs the visitor a hop
    # for nothing. PulseGrid shipped three of these. The previous contact audit
    # missed them because it searched for mailto: - a different failure mode -
    # so this checks the destination itself, whatever scheme it uses.
    #
    # Informational anchors (Features, What it is, See the live demo, See how it
    # works) are deliberately out of scope: they lead somewhere a reader wants to
    # go, not to another button.
    ANCHOR = re.compile(r"<a\b([^>]*)>(.*?)</a>", re.S | re.I)
    HREF_A = re.compile(r'href="([^"]*)"')
    INTENT = re.compile(
        r"(request early access|join[^<]*early[- ]access|early[- ]access|"
        r"contact sales|contact zoneary|contact|talk to us|get in touch|"
        r"send to zoneary|request access)", re.I)
    EARLY_ACCESS_LABEL = re.compile(r"early[- ]access|request access", re.I)
    PRODUCT_DIRS = {"watchtower": "Watchtower", "pulsegrid": "PulseGrid", "sentinel": "Sentinel"}

    ctas_checked = 0
    for f in html:
        text = open(f, encoding="utf-8", errors="replace").read()
        name = rel(f, root)
        product = PRODUCT_DIRS.get(name.split("/")[0]) if "/" in name else None

        for m in ANCHOR.finditer(text):
            attrs, inner = m.group(1), m.group(2)
            label = plain(inner)
            href_m = HREF_A.search(attrs)
            if not label or not href_m or not INTENT.search(label):
                continue
            href = href_m.group(1)

            # mailto is handled by the Contact-link check above; the remaining
            # informational addresses are labelled with the address itself.
            if href.startswith("mailto:"):
                continue

            ctas_checked += 1

            if href.startswith("#"):
                problems.append(
                    "%s: CTA %r points at the in-page fragment %s instead of the contact form"
                    % (name, label, href))
                continue

            if not re.search(r"(^|/)contact/$", urlparse(href).path):
                problems.append(
                    "%s: CTA %r goes to %s instead of terminating at /contact/"
                    % (name, label, href))
                continue

            # On a product page, an early-access CTA must carry that product.
            # A general "Contact" link may stay general.
            if product and EARLY_ACCESS_LABEL.search(label):
                want = "product=%s" % product
                if want not in href:
                    problems.append(
                        "%s: CTA %r loses product context (want %s, got %s)"
                        % (name, label, want, href))

    # ---- the old early-access URL is gone, not redirected --------------------
    # /contact/ is the only Contact destination. early-access.html was a
    # launch-period URL and was deliberately retired without a redirect, so a
    # surviving reference to it is a dead link rather than an extra hop.
    for f in files:
        text = open(f, encoding="utf-8", errors="replace").read()
        name = rel(f, root)
        if "early-access.html" in text:
            for line_no, line in enumerate(text.splitlines(), 1):
                if "early-access.html" in line:
                    problems.append(
                        "%s:%d: still references early-access.html, which no longer exists "
                        "(the contact page is /contact/)" % (name, line_no))
    if os.path.exists(os.path.join(root, "early-access.html")):
        problems.append("early-access.html still exists; /contact/ is the only contact page")
    if not os.path.isfile(os.path.join(root, "contact", "index.html")):
        problems.append("contact/index.html is missing")

    # ---- long-cached stylesheets must be cache-busted ------------------------
    # css/ is served with max-age=604800 while the HTML that references it is
    # not cached at all, so a deploy lands new markup against a week-old
    # stylesheet. Every reference carries a content-derived version; this fails
    # the publish if any of them has drifted from the file it points at.
    try:
        sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
        import stamp_assets
        stale, _ = stamp_assets.stamp(root, check_only=True)
        for s in stale:
            problems.append("stale asset version: %s (run python tools/stamp_assets.py)" % s)
        # and every page that links the stylesheet must carry a version at all
        for f in html:
            text = open(f, encoding="utf-8", errors="replace").read()
            name = rel(f, root)
            for ref in re.findall(r'href="([^"]*styles\.css[^"]*)"', text):
                if "?v=" not in ref:
                    problems.append("%s: %s has no cache-busting version" % (name, ref))
    except ImportError:
        problems.append("tools/stamp_assets.py is missing - asset versions cannot be verified")

    # ---- no credential material in anything we deploy ------------------------
    # The SMTP password lives in a file above the web root, created by hand on
    # the server. Nothing under site/ may ever contain one, so this fails the
    # publish rather than discovering it in production.
    SECRET_PATTERNS = [
        (re.compile(r"smtp_password\s*=>\s*['\"][^'\"]+['\"]"), "a literal smtp_password"),
        (re.compile(r"(?:password|passwd|pwd)\s*=\s*['\"][^'\"]{4,}['\"]", re.I), "a literal password assignment"),
        (re.compile(r"AUTH\s+LOGIN\s+[A-Za-z0-9+/]{12,}={0,2}"), "an inline SMTP credential"),
        (re.compile(r"-----BEGIN [A-Z ]*PRIVATE KEY-----"), "a private key"),
    ]
    scanned = 0
    for dirpath, _dirs, filenames in os.walk(root):
        for fn in filenames:
            if not fn.lower().endswith((".php", ".js", ".html", ".json", ".css", ".txt", ".env")):
                continue
            path = os.path.join(dirpath, fn)
            scanned += 1
            text = open(path, encoding="utf-8", errors="replace").read()
            for pattern, label in SECRET_PATTERNS:
                if pattern.search(text):
                    problems.append("%s: contains %s" % (rel(path, root), label))
            # the deployed tree must not carry a config file at all
            if fn in ("contact-config.php", ".env") or fn.startswith(".env."):
                problems.append("%s: a configuration/secrets file is inside the deployable site" % rel(path, root))

    if not args.quiet:
        print("checked %d files, %d internal references, %d commercial constants, "
              "%d FAQ page(s), %d file(s) scanned for credentials"
              % (len(files), checked_refs, priced, faq_checked, scanned))

    if problems:
        print("\nFAILED - %d problem(s):" % len(problems))
        for p in problems:
            print("  - %s" % p)
        return 1

    print("ALL SITE CHECKS PASSED")
    return 0


if __name__ == "__main__":
    sys.exit(main())
