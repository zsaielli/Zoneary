# Zoneary Website

Public source for the Zoneary marketing website.

**Production:** https://www.zoneary.com
**Review mirror:** https://zsaielli.github.io/Zoneary/

## Products

- **Watchtower** — local-first video management system (flagship)
- **PulseGrid** — infrastructure health monitoring
- **Sentinel** — home security control

## Structure

- `site/` — the deployable website (all HTML, CSS, JS, and image assets)
- `assets/` — brand and screenshot source material

The GitHub Pages workflow (`.github/workflows/pages.yml`) publishes **only the contents of `/site`**, so `site/index.html` is the site root. Production is hosted separately on Hostinger; GitHub Pages is a review environment.

## Local preview

```
cd site
python -m http.server 8000
# then open http://127.0.0.1:8000/
```

Canonical URLs point to the production domain (`zoneary.com`); the Pages mirror is not listed as canonical.
