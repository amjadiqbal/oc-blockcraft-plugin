# BlockCraft — build progress log

Local build/handoff record for `amjadiqbal/blockcraft` (October CMS plugin code
`AmjadIqbal.BlockCraft`). Not shipped in the public repo (see the workspace's
"internal build files don't belong in a public repo" lesson) — lives only here,
in `../research/PROJECT_PROGRESS.md`'s sibling copy at the package root, same
pattern as VueForge.

## Phase 0 — vendor/namespace check

Blueprint originally specified vendor `studio` (`Studio.BlockCraft`,
`studio/blockcraft`, `studio/oc-blockcraft`). Checked independently before
writing anything:

- **GitHub**: `github.com/studio` is a real organization, created 2017-01-06,
  7 public repos — **taken**, hard conflict, cannot create `studio/oc-blockcraft`
  there.
- **Packagist**: vendor `studio` already has an unrelated live package
  (`studio/laravel-totem`, 1.2M+ downloads) — not a hard block (Packagist
  vendor prefixes aren't exclusively reserved) but would misrepresent
  ownership on a public listing.
- **October CMS marketplace**: could not reliably confirm via unauthenticated
  HTTP (the site returns 200 for any path, SPA routing) — moot given the
  GitHub conflict.

Flagged to Amjad; he confirmed **`amjadiqbal`** as the replacement (same
personal account already used for VueForge, consistent with
`../../ACCOUNT-STRATEGY.md`). Confirmed free on both platforms before use:

- GitHub: `amjadiqbal/oc-blockcraft` — 404 (free)
- Packagist: `amjadiqbal/blockcraft` — package not found (free)

Final identifiers: plugin code `AmjadIqbal.BlockCraft`, Composer package
`amjadiqbal/blockcraft`, GitHub repo `amjadiqbal/oc-blockcraft`.

## Phase 1 — scaffold

In progress. Following the same layout decision VueForge landed on after its
own Phase 8 restructure: the **repo root is the plugin** (matches how official
October plugins ship on Packagist/`composer/installers`' `october-plugin`
type — no nested `plugins/<vendor>/<name>/` inside the source repo). Deciding
this up front this time instead of restructuring after the fact.

(Continued below as work proceeds.)
