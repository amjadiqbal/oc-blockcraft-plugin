# Changelog

All notable changes to this project are documented in this file. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [Semantic Versioning](https://semver.org/).

## [0.1.1] - 2026-09-22

### Fixed

- **A BlockCraft field added dynamically inside a repeater row never
  initialized.** The `assets/js/blockcraft.ts` hydrator only re-scanned the
  DOM for new mount points on two events, `ajax:update-complete` and
  `page:updated`. Neither fires for October's native repeater "Add Item"
  action - confirmed by instrumenting every Larajax lifecycle event on a
  live page before clicking "Add Item": only `ajax:request-complete`,
  `ajax:done`, and `ajax:always` fired. Traced the root cause in Larajax's
  own source (`vendor/larajax/larajax/resources/src/request/actions.js`):
  the repeater's successful response is handled entirely through Larajax's
  ops-based `patchDom`/`loadAssets` path (`handleUpdateOperations()`), which
  never calls the function that dispatches `ajax:update-complete`
  (`notifyApplicationUpdateComplete()`, only called from the separate
  `handleUpdateResponse()` path). The new mount point genuinely landed in
  the DOM - confirmed directly from the real AJAX response body - but was
  never hydrated. Manually dispatching `ajax:update-complete` on the broken
  page immediately mounted the pending editor, confirming the hydration
  logic itself was correct and only its trigger coverage was incomplete.

  Fixed by also listening for `ajax:done`, which fires for every completed
  AJAX request regardless of response shape. Safe to call `hydrateAll()`
  unconditionally on it: `mountWidget()` already guards against
  double-mounting via its own `mountedInstances` map, so a re-scan on every
  AJAX completion is a no-op for anything already mounted (confirmed
  negligible in practice - ~0.01ms per scan with 2 widgets on a form).
  Confirmed the fix does not affect the existing removal path (the
  `MutationObserver` cleanup, unrelated to this event and unaffected by it)
  by adding and removing repeater rows in the same session.

  This directly affects the "stable behavior inside nested Tailor/
  FormController repeaters" claim in `README.md` - true only for removal
  and for rows present at page load before this fix; now true for rows
  added dynamically as well, verified with a real backend login: added a
  repeater row, typed into its nested editor, saved, reloaded, and
  confirmed the content round-tripped correctly from the database.

  New Vitest coverage (`tests/js/blockcraft.test.ts`) exercises this exact
  scenario directly against the hydrator module - a mount point appearing
  in the DOM outside of the module's own initial `hydrateAll()` call,
  followed by an `ajax:done` dispatch - rather than mocking October's AJAX
  framework. No test previously covered this path, which is how the bug
  reached a public README claim in the first place.

## Marketplace pricing decision (2026-09-22, no version bump - no code changed)

**Price: Free.** Decided by Amjad, not a default. Per this channel's `ACCOUNT-STRATEGY.md`, this
is Amjad's personal identity, where the goal is reputation and portfolio reach rather than direct
revenue from a small developer-tooling plugin - the same reasoning already applied to VueForge.
BlockCraft is small enough that Marketplace/Packagist distribution itself is the value, and it
feeds the consulting funnel this account is built around, matching
`GITHUB-REPO-STANDARD.md` §6's "free when it feeds the consulting funnel" criterion directly.

## Marketplace eligibility resolved (2026-09-22, no version bump - no code changed)

The identity fork described immediately below is closed: Amjad changed the account's registered
October Marketplace author code to `AmjadIqbal`, which now matches this plugin's existing plugin
code (`AmjadIqbal.BlockCraft`) exactly. Nothing renamed - this plugin was already on the correct
side of that fork by virtue of the distribution-scope decision below. It is now genuinely
Marketplace-eligible; the "not eligible under this account" line in the decision below is
superseded but left in place as the historical record of why the fork existed at all.

## Distribution scope decision (2026-09-18, no version bump - no code changed)

VueForge (the other plugin in this channel) had its October CMS Marketplace submission rejected
under vendor `AmjadIqbal` - the registered Marketplace author code turned out to be `Amjad`. A
rename to `Amjad\BlockCraft` / `amjad/blockcraft-plugin` was tried here too, then reverted at
Amjad's explicit direction: **this plugin ships under `amjadiqbal` - the same identity as every
other package in this portfolio (GitHub, Packagist, WordPress.org)** - not the Marketplace-specific
`Amjad` author code.

The real, confirmed tradeoff (read directly from October's own `PluginManager.php`, not assumed):
the install directory's vendor segment and the plugin's PHP namespace have to match for October to
load the plugin at all, and the Marketplace validates the plugin's *resolved* code against the
registered author account. Keeping vendor `amjadiqbal` therefore means **this plugin is not
eligible for an October CMS Marketplace listing under this account** - that is an accepted,
deliberate scope decision, not an oversight.

**Packagist and GitHub distribution are unaffected, and genuinely straightforward here** -
`amjadiqbal` is this account's own already-established Packagist vendor (existing packages:
`alertify`, `laravel-tiptap`, etc.), so `amjadiqbal/blockcraft-plugin` has no naming conflict at
all. (For the record, since this was a real point of confusion during this decision: Packagist
*does* reserve vendor names once any package is published under them - confirmed from Packagist's
own docs, "Vendor names on packagist are protected once a package with that name has been
published... you can not publish packages with a vendor name that already exists on packagist
without permission." That's exactly why the `amjad` vendor - a real, unrelated, pre-existing
package `amjad/lableb` - would have been a genuine blocker had this plugin gone the other way and
renamed to `Amjad`. It simply doesn't apply here, since `amjadiqbal` was never in question.)

## [0.1.0] - 2026-09-18

Initial build. Versioned `0.1.0` rather than `1.0.0` deliberately: this
plugin has real, working PHPUnit + Vitest coverage against a live October CMS
install and passes it, but has **not yet** been exercised through a real
backend HTTP login/save round-trip in a browser, and has not been published
to Packagist or the October Marketplace. `1.0.0` is reserved for after those
close.

### Added

- `formwidgets/BlockCraft.php` - `FormWidgetBase` subclass hydrating a Vue 3
  + TipTap block editor, with dual `outputFormat` ('html' or 'json'),
  attribute-safe JSON serialization, and sanitizing `getSaveValue()`.
- `classes/HtmlSanitizer.php` - server-side allow-list HTML sanitizer (not
  reliant on the browser-side editor's own configuration) for `html` mode.
- `classes/JsonSanitizer.php` - recursive TipTap-document validator/cleaner
  for `json` mode.
- `classes/ViteResolver.php` - dev-server vs. production `manifest.json`
  asset resolution (same pattern verified in the VueForge plugin).
- `assets/vue/BlockCraftEditor.vue` - the editor itself: TipTap's
  `useEditor()` (Vue 3 Composition API), StarterKit (headings, paragraphs,
  lists, blockquote, code block, horizontal rule, bold/italic/strike/code,
  built-in link), a custom `AlignableImage` extension (left/center/right),
  and `TableKit` (resizable tables). Toolbar buttons are individually
  configurable via the `buttons` YAML option.
- `assets/js/blockcraft.ts` - ESM hydrator: mounts/unmounts the editor on
  `[data-control="blockcraft"]` elements, re-hydrating on
  `ajax:update-complete`/`page:updated` and syncing a hidden `<textarea>`
  for native (non-AJAX) form submission. A `MutationObserver` destroys the
  TipTap instance when its element is removed from the DOM - required for
  correct behavior inside Tailor/FormController repeaters.
- `assets/js/useMediaManagerPopup.ts` - typed wrapper around October's real,
  global `oc.mediaManager.popup` (see "Corrected assumptions" below).
- PHPUnit suite (`tests/HtmlSanitizerTest.php`, `tests/JsonSanitizerTest.php`,
  `tests/BlockCraftTest.php`, 34 tests) run against a real October CMS 4.4.5
  install.
- Vitest + `@vue/test-utils` suite (`tests/js/BlockCraftEditor.test.ts`, 6
  tests) mounting the real TipTap/ProseMirror editor under jsdom - toolbar
  commands, HTML/JSON serialization, readOnly, image insertion, and the
  mount/unmount lifecycle.
- `.github/workflows/tests.yml` running both suites on PRs, installing a
  throwaway October CMS app in CI the same way this was verified manually.

### Corrected assumptions (found during this build via real source inspection)

- **Media Manager integration does not need a custom AJAX handler.** The
  original design assumed a `FormWidget`-side `onLoadMediaManager` handler
  would be needed. Reading October's actual installed source
  (`modules/media/ServiceProvider.php`, `modules/media/widgets/mediamanager/
  assets/js/mediamanager.popup.js`) showed October already bootstraps a
  single global `MediaManager` widget instance (alias `ocmediamanager`) for
  every backend user with `media.library` access, and any frontend code can
  launch it directly with `new oc.mediaManager.popup({...})` - confirmed via
  a real, shipped Vue 3 backend control (`modules/backend/vuecomponents/
  inspector/.../control-mediafinder.js`) already using exactly this pattern.
  No custom backend endpoint was needed at all.
- **`RichEditor`'s own partial views are `.php`, not `.htm`** - the original
  blueprint's `render()`/`makePartial()` description implied a `.htm`
  partial extension; October's real `formwidgets/blockcraft/partials/
  _blockcraft.php` follows the actual convention confirmed from
  `modules/backend/formwidgets/richeditor/partials/_richeditor.php`.
- **October CMS does not sanitize `RichEditor` content server-side** - its
  safety comes entirely from the client-side Froala editor's configurable
  allow/deny lists (`Backend\Models\EditorSetting`). BlockCraft deliberately
  does its own server-side sanitization (`HtmlSanitizer`/`JsonSanitizer`) so
  a crafted POST that never touches the browser can't persist unsafe markup
  - this is a design choice, not a gap being filled.

### Fixed (found during this initial build, not pre-existing regressions)

- `HtmlSanitizer::clean()`: DOMDocument's HTML parser has no built-in notion
  of "void" elements when fed a fragment via `loadHTML()` - an unclosed
  `<embed src="x">` was parsed as an *opening* tag, so everything that
  followed it in the source (e.g. `<form>...</form><p>safe</p>`) was parsed
  as its *children* and deleted along with it when the dangerous `<embed>`
  element was removed. Fixed by explicitly self-closing known void elements
  (`img`, `br`, `hr`, `embed`, `input`, etc.) before parsing. Caught by
  `HtmlSanitizerTest::testIframeObjectEmbedAndFormAreRemovedEntirely`
  asserting on real output, not by inspection.
- Test suite: `PluginTestCase::setUp()` is declared `public` in October's own
  base class (not `protected`, PHPUnit's usual convention) - a `protected`
  override is a fatal "access level" error. Confirmed by reading October's
  actual `modules/system/tests/PluginTestCase.php`.

### Known gaps (same tradeoff documented in VueForge's build)

- No real browser HTTP login/save round-trip has been verified - no
  headless-browser tool was available in this environment. The PHPUnit
  suite exercises the real `FormWidgetBase`/`FormField` machinery directly,
  and the Vitest suite exercises the real TipTap editor under jsdom, which
  together cover the same PHP and Vue code paths a browser session would,
  just not the surrounding HTTP/session/CSRF plumbing.
- Custom block builder (mapping Tailor blueprints to custom Vue block
  components), real-time collaborative editing (Yjs/WebSockets), and AI text
  actions are explicitly out of scope for this MVP - they're the blueprint's
  own "Future Features," not the initial release.
