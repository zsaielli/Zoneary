# Contact / early-access form

The early-access form submits to a Zoneary endpoint and is delivered by
authenticated SMTP. It does not open the visitor's mail client, and there is no
database — the email in `info@zoneary.com` is the record.

```
browser
  │  HTTPS POST  (form fields, honeypot, timestamp)
  ▼
site/api/contact.php            → public_html/api/contact.php on Hostinger
  │  validation · rate limiting · header-injection defence
  ▼
smtp.hostinger.com:465          authenticated SSL/TLS, credentials server-side only
  ▼
info@zoneary.com
```

| | |
|---|---|
| From | `Zoneary Website <website@zoneary.com>` — always, never the visitor |
| To | `info@zoneary.com` — always, not derived from any input |
| Reply-To | the visitor's name and address |
| Subject | `Zoneary early access — <product>` (product comes from a server allowlist) |

---

## Setting the SMTP password

**This is the only manual step, and it is done once, on the server.**

Hostinger's shared hosting has no secret manager, so the credential lives in a
PHP file **above the web root**. That location is not reachable over HTTP, is not
touched by the git deployment, and is not in this repository.

Do it through **hPanel → File Manager**, not over SSH — a password typed into a
shell survives in that shell's history.

1. hPanel → **Files → File Manager**.
2. Navigate to the domain directory that *contains* `public_html`:
   `/home/uXXXXXXXX/domains/zoneary.com/`
   You should see `public_html` beside you. **Do not enter it.**
3. Create a folder named `zoneary-private`.
4. Inside it, create a file named `contact-config.php` with exactly this
   content, replacing the placeholder with the real `website@zoneary.com`
   password:

   ```php
   <?php
   return [
       'smtp_password' => 'PASTE-THE-WEBSITE-MAILBOX-PASSWORD-HERE',
   ];
   ```

5. Save, then set its permissions to **600** (File Manager → right-click →
   Permissions → owner read+write only).

Nothing else is required. Host, port, username, From and To are already fixed in
[`site/api/lib/config.php`](../site/api/lib/config.php); the file above supplies
only the secret. It may also override any of those keys (`smtp_host`,
`smtp_port`, `smtp_user`, `from_email`, `from_name`, `to_email`, `state_dir`) if
something ever changes.

The final layout:

```
/home/uXXXXXXXX/domains/zoneary.com/
├── zoneary-private/
│   ├── contact-config.php     ← the password. 600. never in git.
│   └── rate/                  ← created automatically; rate-limit counters
└── public_html/               ← the deployed production branch
    ├── index.html
    └── api/contact.php
```

If the file is missing or unreadable, the endpoint returns a generic failure and
logs the reason server-side. It never falls back to sending unauthenticated, and
it never discloses why to the visitor.

### Checking it worked

Submit the form on <https://zoneary.com/early-access.html>. A success message
appears in the page and the mail arrives at `info@zoneary.com` with the visitor
in `Reply-To`. If it fails, the reason is in hPanel → **Advanced → PHP
error log**, prefixed `[contact]`.

---

## What protects the endpoint

Deliberately no CAPTCHA. A public form of this volume does not need one, and it
would cost every legitimate visitor something real.

| Control | Where |
|---|---|
| Honeypot field, answered with a fake success so bots learn nothing | `lib/validate.php` |
| Minimum fill time (3s), skipped when absent so no-JS still works | `lib/validate.php` |
| 16 KB request body cap | `contact.php` |
| Per-field length limits, character-counted | `lib/validate.php` |
| Unknown fields rejected outright | `lib/validate.php` |
| Product choice matched against an allowlist | `lib/validate.php` |
| 3 submissions / 10 min and 10 / day per IP; 60 / hour globally | `lib/rate_limit.php` |
| CR/LF and control characters stripped before anything reaches a header | `lib/validate.php`, `lib/message.php` |
| Cross-origin POSTs refused when the browser sends `Origin` | `contact.php` |

Rate-limit state is a few small files under `zoneary-private/rate/`, keyed by an
HMAC of the IP address rather than the address itself, and pruned after a day.
If that directory cannot be written, the limiter fails **open** — a form that
cannot rate limit is better than a form that refuses everyone — and every other
control stays in force.

---

## The review mirror cannot run this

GitHub Pages is static. `site/api/` is stripped from the Pages artifact by
[`.github/workflows/pages.yml`](../.github/workflows/pages.yml), and the page
detects a `*.github.io` host and says the form only works on zoneary.com rather
than failing mysteriously. **End-to-end verification happens on production, after
deployment** — there is nowhere else the endpoint can actually run.

---

## Tests

```
php tools/test_contact.php
```

No test opens a socket or authenticates anywhere: the transport is an interface
and the tests inject a recording fake. The suite covers acceptance, required
fields, malformed addresses, length limits, honeypot and timing rejection, rate
limiting, SMTP failure handling, the fixed From/To, Reply-To, header injection
across every field, and that no credential can reach the browser.

`python tools/check_site.py` additionally refuses to publish if the form stops
posting to the endpoint, if a mailto submission reappears, if the form's fields
drift from what the server accepts, or if anything credential-shaped appears
under `site/`. Both run in the production publish workflow.

---

## The library directory is denied at the web-server level

[`site/api/lib/.htaccess`](../site/api/lib/.htaccess) contains `Require all
denied`, so `public_html/api/lib/` is refused by the web server before PHP is
invoked. This is the only `.htaccess` in the repository and it is scoped to that
one directory.

It cannot interfere with the endpoint. `contact.php` loads its libraries with
`require __DIR__ . '/lib/...'` — a filesystem read — and `.htaccess` governs
HTTP requests only. The two never meet. It is defence in depth on top of the
application guard: each library file already refuses to execute unless
`contact.php` has defined `ZONEARY_CONTACT`, and answers a direct request with
404 and an empty body.

The directive is deliberately unconditional rather than wrapped in `<IfModule
mod_authz_core.c>`. LiteSpeed does not report every Apache module, and a guarded
block that matches nothing would silently permit access — the failure mode of an
unrecognised directive (an error) is safer than the failure mode of a skipped
one (access).

**Verify after deployment** — the enforcement is the web server's, so it can only
be observed on Hostinger:

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://zoneary.com/api/lib/config.php   # expect 403
curl -s -o /dev/null -w "%{http_code}\n" https://zoneary.com/api/lib/.htaccess    # expect 403
curl -s -o /dev/null -w "%{http_code}\n" -X POST \
     -H "Content-Type: application/x-www-form-urlencoded" \
     https://zoneary.com/api/contact.php                                           # expect 400, not 500
```

A 403 on the first two and a 400 on the third means the deny is active and the
endpoint still loads its libraries. If the first two return 404 instead, the
`.htaccess` is not being read but the application guard is still holding — worth
investigating, not an exposure.
