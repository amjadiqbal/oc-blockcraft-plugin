# Changelog

All notable changes to this project are documented in this file. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [Semantic Versioning](https://semver.org/).

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
