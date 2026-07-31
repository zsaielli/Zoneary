# Zoneary Website

Public source for the Zoneary marketing website.

**Production:** https://www.zoneary.com
**Review mirror:** https://zsaielli.github.io/Zoneary/

## Products

- **Watchtower** — local-first video management system (flagship)
- **PulseGrid** — infrastructure health monitoring
- **Sentinel** — home security control

## Branches

| Branch | What it is | Who writes to it |
|---|---|---|
| `main` | Full source and development branch. Auto-deploys to GitHub Pages (staging). | People |
| `production` | **Generated deployment artifact.** Its root is the built website; Hostinger deploys it to `public_html`. | Automation only — never edit it by hand |

`production` is rebuilt from `site/` by a manual GitHub Actions workflow. Any
commit made to it directly will be overwritten by the next publish. See
**[DEPLOYMENT.md](DEPLOYMENT.md)**.

## Structure

- `site/` — the deployable website (all HTML, CSS, JS, and image assets)
- `assets/` — brand and screenshot source material
- `tools/` — repository tooling (site checks, production publish script)

## Information architecture

The homepage introduces Zoneary and its flagship; each product owns a dedicated page.

```
/                 Zoneary — company + flagship introduction + ecosystem
/watchtower/      Watchtower (flagship) — the complete product story
/pulsegrid/       PulseGrid
/sentinel/        Sentinel
/about/           Meet Zoneary
```

Each product page shares the homepage design system (`site/css/styles.css`) and adds
its own sheet (`watchtower.css`, `pulsegrid.css`, `sentinel.css`) for page-specific
sections. Watchtower's deep content lives on `/watchtower/` only — the homepage
previews it and links across, and must not grow back into a full product manual.

The GitHub Pages workflow (`.github/workflows/pages.yml`) publishes **only the contents of `/site`**, so `site/index.html` is the site root. Production is hosted separately on Hostinger; GitHub Pages is a review environment.

## Deploying

Staging updates itself on every push to `main`. Production is deliberate: review
the Pages build, then run **Actions → “Publish production branch (Hostinger)”**
and type `publish` to confirm. Full process, rollback and Hostinger setup are in
**[DEPLOYMENT.md](DEPLOYMENT.md)**.

## Local preview

```
cd site
python -m http.server 8000
# then open http://127.0.0.1:8000/
```

Run the website checks before publishing:

```
python tools/check_site.py
```

Canonical URLs point to the production domain (`zoneary.com`); the Pages mirror is not listed as canonical.
