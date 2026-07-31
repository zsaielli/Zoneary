# Deployment

Two environments, one source of truth.

| | Branch | Purpose | Updated by |
|---|---|---|---|
| **Staging / review** | `main` | GitHub Pages — <https://zsaielli.github.io/Zoneary/> | Automatically, on every push to `main` |
| **Production** | `production` | Hostinger — <https://www.zoneary.com> → `public_html` | Manually, by running a GitHub Actions workflow |

- **`main` is the source branch.** All development happens here. It contains the
  full repository: `site/`, `assets/` source material, `tools/`, docs, workflows.
- **`production` is generated.** It is a deployment artifact produced by
  automation. Its root *is* the website — `index.html`, `css/`, `js/`,
  `assets/` — not the repository layout.

> ### ⚠️ Nobody edits `production` by hand
> Every commit on `production` is written by
> [`tools/publish-production.sh`](tools/publish-production.sh). Any manual commit
> there will be wiped by the next publish. Fix things on `main` and republish.

---

## The process

```
develop on main
      │
      ├─► push  ──►  GitHub Pages rebuilds  ──►  review the staging site
      │                                              │
      │                                        approve it
      │                                              │
      └─────────────►  run "Publish production branch (Hostinger)"  ◄──┘
                                   │
                       production branch replaced
                                   │
                       Hostinger pulls production  ──►  public_html
```

Nothing reaches production without someone deliberately running the workflow.

---

## Publishing to production

1. Push your work to `main` and let GitHub Pages rebuild.
2. Review the staging site: <https://zsaielli.github.io/Zoneary/>
3. When you are happy with it, go to
   **GitHub → Actions → “Publish production branch (Hostinger)” → Run workflow**.
4. Fill in the two inputs:
   - **ref** — leave as `main` to publish the current tip, or paste a specific
     commit SHA or tag to publish that exact revision.
   - **confirm** — type `publish`. The run aborts immediately if this does not
     match, so an accidental click cannot deploy.
5. Click **Run workflow** and wait for it to go green.

The workflow will:

1. refuse to run unless you typed the confirmation phrase
2. check out the revision you selected
3. verify `site/index.html` exists
4. run `tools/check_site.py` (links, anchors, CSS `url()`, JSON-LD, canonicals,
   sitemap coverage, third-party requests) — **a failure here stops the deploy**
5. build the branch in dry-run mode and verify the root
6. build it again and push
7. re-fetch `origin/production` and confirm `index.html` really is at the root
   and no nested `site/` directory exists

If any step fails, nothing is pushed — there is no partial deployment.

6. Hostinger then picks up the updated `production` branch (automatically if
   auto-deploy is on; otherwise press Deploy in hPanel).

---

## Hostinger setup (one time)

In hPanel → **Website → Git**:

1. **Repository**: `https://github.com/zsaielli/Zoneary.git`
2. **Branch**: `production` ← *not* `main`
3. **Directory**: `public_html`
4. Save, then run the first deployment.

Because `production`’s root is already the website, `public_html/index.html`
lands in the right place with no sub-path configuration.

**After the first deploy, confirm `https://www.zoneary.com/.git/` is not
readable.** Some git-based hosts leave the clone metadata inside the web root.
If it is reachable, block it in Hostinger (file manager or an `.htaccess` deny
rule) — this repository deliberately does not ship an `.htaccess`, so that
choice stays with you.

---

## Recovering or redeploying a known-good revision

Everything is recoverable because production commits record the exact source
commit they were built from.

**Roll back to an earlier site:**

1. Find the good revision. Either read the production history —

   ```bash
   git fetch origin production
   git log --oneline origin/production
   ```

   each message reads `Deploy site/ from main @ <short-sha>` and includes the
   full source commit — or pick a commit from `main` directly.
2. Run the workflow again with **ref** set to that source commit SHA.
3. The workflow rebuilds `production` from that revision and pushes it forward.

This rolls *forward* to old content rather than rewriting history, so the
deployment log stays intact and the push is still a fast-forward.

**If you need production restored right now and Actions is unavailable**, run
the same script locally from a clean checkout of the revision you want:

```bash
git checkout <known-good-sha>
python tools/check_site.py
bash tools/publish-production.sh
git checkout main
```

The script never modifies your current branch — it works in a temporary git
worktree and cleans up after itself.

---

## Why it is built this way

- **Manual trigger only.** `workflow_dispatch` with a typed confirmation. There
  is no path from a push to production.
- **Least privilege.** The job requests `contents: write` and nothing else.
- **No force-push.** Each publish is a normal commit on top of the existing
  `production` branch, so deployment history is preserved and the push
  fast-forwards.
- **Deletions propagate.** The tree is cleared before `site/` is copied in, so a
  file removed from `site/` is removed from production too, rather than lingering
  on the live server.
- **Only `site/` ships.** `tools/`, `assets/` source material, `.github/`, the
  zip archives and every note or report live outside `site/` and therefore
  cannot leak into production.
- **Checks gate the deploy.** `tools/check_site.py` runs before anything is
  pushed.
- **Staging is untouched.** `.github/workflows/pages.yml` still publishes `site/`
  from `main` to GitHub Pages exactly as before.

## Files involved

| File | Role |
|---|---|
| `.github/workflows/pages.yml` | Staging — publishes `site/` to GitHub Pages on push to `main` |
| `.github/workflows/publish-production.yml` | Production — manual workflow that generates the `production` branch |
| `tools/publish-production.sh` | The publish logic (used by CI and usable locally) |
| `tools/check_site.py` | Pre-publish website checks |
