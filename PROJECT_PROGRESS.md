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

Final identifiers (revised again in the mid-build correction below): plugin
code `AmjadIqbal.BlockCraft`, Composer package `amjadiqbal/blockcraft-plugin`,
GitHub repo `amjadiqbal/oc-blockcraft-plugin`.

## Phase 1 — scaffold

Following the same layout decision VueForge landed on after its own Phase 8
restructure: the **repo root is the plugin** (matches how official October
plugins ship on Packagist/`composer/installers`' `october-plugin` type — no
nested `plugins/<vendor>/<name>/` inside the source repo). Decided this up
front this time instead of restructuring after the fact.

Wrote `composer.json`, `package.json`, `tsconfig.json`, `vite.config.ts`,
`vitest.config.ts`, `.gitignore`, `updates/version.yaml`, `Plugin.php`,
`formwidgets/BlockCraft.php` (+ its partial), `classes/ViteResolver.php`,
`classes/HtmlSanitizer.php`, `classes/JsonSanitizer.php` — `Plugin.php` and
the FormWidget class it registers were written and committed in the same
step, per the workspace's "never register a class before it exists" rule.
Then the Vue 3 + TipTap frontend: `assets/vue/BlockCraftEditor.vue`,
`assets/js/blockcraft.ts` (hydrator), `assets/js/extensions/alignable-image.ts`,
`assets/js/useMediaManagerPopup.ts`, `assets/css/blockcraft.css`.

## Mid-build correction — `marketplaces/PRE-BUILD-CHECKLIST.md` (new, found mid-session)

A new mandatory pre-build checklist appeared in the channel workspace
mid-session (added after a real VueForge marketplace rejection: October CMS
requires the Composer package name to end in `-plugin`). Applied its
findings retroactively rather than re-researching from scratch, since
`../../listing/MARKETPLACE_CHECKLIST.md` (VueForge's own checklist,
confirmed 2026-09-18 directly from October's official docs -
`docs.octobercms.com/4.x/extend/resources/publishing-packages.html`,
`octobercms.com/help/guidelines/developer`) already has the real, checkable
requirements for this exact platform:

- **Composer package name must end in `-plugin`.** Fixed:
  `amjadiqbal/blockcraft` → `amjadiqbal/blockcraft-plugin` in `composer.json`.
  The installed directory name is controlled separately via
  `extra.installer-name`, left as `"blockcraft"` so the plugin still installs
  at `plugins/amjadiqbal/blockcraft`, matching plugin code
  `AmjadIqbal.BlockCraft`.
- **GitHub repo naming**: "Plugins should be named with a `-plugin` suffix
  and optional `oc-` prefix" — renamed this repo's local directory from
  `oc-blockcraft` to `oc-blockcraft-plugin` *before* the GitHub repo was ever
  created (unlike VueForge, which had to rename after the fact once this
  rule was found). `Plugin.php`'s `homepage` updated to match.
- **Vendor/author code format** (uppercase, no underscores/dashes):
  `AmjadIqbal` already complies.
- **`october/rain` version constraint**: added `"october/rain": ">=4.2"` to
  `composer.json`'s `require` up front — BlockCraft's real Media Manager
  integration and Vue 3/ESM backend both require 4.2+ (see Phase 0 research
  above; VueForge only added this constraint after the fact).
- No live-listing check (checklist Step 3) or fresh docs re-read (Step 1) was
  redone specifically for BlockCraft — this reuses VueForge's same-platform,
  same-day research rather than duplicating it. Per the checklist's own
  Step 8, this should be re-verified against current docs before actual
  submission, not assumed still accurate indefinitely.

(Continued below as work proceeds.)
