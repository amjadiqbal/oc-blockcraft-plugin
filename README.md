<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="art/banner-dark.png">
    <img alt="BlockCraft — the modern TipTap block editor for October CMS" src="art/banner-light.png" width="100%">
  </picture>
</p>

# BlockCraft

A `FormWidget` (`blockcraft`) that replaces October's native Froala
`richeditor` with a Vue 3 + [TipTap](https://tiptap.dev) block editor -
clean HTML or structured JSON output, native Media Manager integration, and
stable behavior inside nested Tailor/FormController repeaters.

[![Latest Version](https://img.shields.io/packagist/v/amjadiqbal/blockcraft-plugin.svg?style=flat-square)](https://packagist.org/packages/amjadiqbal/blockcraft-plugin)
[![Tests](https://github.com/amjadiqbal/oc-blockcraft-plugin/actions/workflows/tests.yml/badge.svg)](https://github.com/amjadiqbal/oc-blockcraft-plugin/actions/workflows/tests.yml)
[![PHP Version](https://img.shields.io/packagist/php-v/amjadiqbal/blockcraft-plugin?style=flat-square)](https://packagist.org/packages/amjadiqbal/blockcraft-plugin)
[![License](https://img.shields.io/packagist/l/amjadiqbal/blockcraft-plugin.svg?style=flat-square)](LICENSE.md)

<p align="center">
  <img src="art/screenshot.png" alt="BlockCraft's TipTap editor showing a heading, paragraph, bulleted feature list, and blockquote, live in a real October CMS backend form" width="500">
</p>

## Why

October's built-in rich text editor ships a pinned, several-major-versions-
old Froala build, has limited toolbar customization, and has documented
breakage when combined with Tailor repeater fields. BlockCraft is a
drop-in-shaped alternative: a real Vue 3 SFC built on TipTap's ProseMirror
foundation, with server-side sanitization independent of anything configured
in the browser.

## Features

- Headings, bold/italic/strike, inline code, lists, blockquotes, code blocks,
  links and horizontal rules.
- **Tables you can edit:** add and delete rows and columns, toggle the header
  row, merge and split cells, delete the table, and resize columns. The
  controls appear while the cursor is in a table.
- **Images from October's Media Manager**, editable after inserting: alt text,
  width, alignment, replace and remove.
- **HTML source mode** for editing the raw markup, still sanitized on save.
- Clean HTML or structured TipTap JSON output, sanitized server-side.
- Works inside Tailor/FormController repeaters, including rows added after the
  page loaded.

## Requirements

- October CMS **4.2+** (needs the Vue 3 / native ESM backend)
- PHP 8.2+

## Installation

```
composer require amjadiqbal/blockcraft-plugin
```

(Composer package name ends in `-plugin` per October's own naming
convention; it still installs at `plugins/amjadiqbal/blockcraft`.)

No build step is needed - the compiled editor ships in `assets/dist/`. If you
upload the plugin manually (FTP, zip), make sure that folder comes with it.

## Usage

```yaml
# fields.yaml
body:
    label: Body
    type: blockcraft
    outputFormat: html   # or 'json' for a structured TipTap document
    height: 400px
```

### Options

| Option | Type | Default | Description |
|---|---|---|---|
| `outputFormat` | `html` \| `json` | `html` | Persisted shape of the field's value. |
| `height` | string | `400px` | CSS height of the editor's content area. |
| `buttons` | array \| null | `null` (full default toolbar) | Restrict visible toolbar buttons: `bold`, `italic`, `strike`, `code`, `heading1`..`heading6`, `bulletList`, `orderedList`, `blockquote`, `codeBlock`, `link`, `image`, `table`, `horizontalRule`, `undo`, `redo`, `source`. The table and image bars appear on their own and need no entry. |
| `readOnly` | bool | `false` | Renders the content non-editable. |

`outputFormat: json` stores TipTap's own document schema
(`{type: 'doc', content: [...]}`) rather than an HTML string - useful when
your frontend wants to render content itself instead of trusting stored
markup, or when you want a stable structure for programmatic transforms.

### Media Manager

Clicking the toolbar's Image button launches October's real, built-in Media
Manager (`oc.mediaManager.popup`) when the current backend user has
`media.library` access - no extra setup needed, no custom AJAX handler on
BlockCraft's side. Without that access, the Image button asks for a URL.

### Editing tables and images

Put the cursor in a table and a **Table** bar appears under the toolbar: add
a row above/below, delete a row, add a column left/right, delete a column,
toggle the header row, merge cells (drag across cells to select them first),
split a merged cell, and delete the table. Drag a column border to resize it.

<p align="center">
  <img src="art/editing-table.png" alt="BlockCraft's Table bar under the toolbar, with two cells of a pricing table selected and Merge enabled" width="650">
</p>

Click an image and an **Image** bar appears: alt text, width in pixels, align
left/center/right, replace it (through the Media Manager), and remove it.

<p align="center">
  <img src="art/editing-image.png" alt="BlockCraft's Image bar for a selected, centered image: alt text, a 240 px width, alignment buttons, Replace and Remove" width="650">
</p>

### HTML source mode

The **HTML** toolbar button shows the field's content as HTML, one block per
line, for editing by hand. Changes apply as you type and when you switch
back. Only HTML the editor supports is kept: unknown tags are unwrapped, and
scripts, styles, event attributes, iframes and `javascript:` links are
removed - in the browser, and again on the server when the form is saved
(see Sanitization). To hide the button, leave `source` out of `buttons`.

<p align="center">
  <img src="art/editing-source.png" alt="BlockCraft in HTML source mode: the table and image markup, one block per line, with the formatting buttons disabled" width="650">
</p>

### Sanitization

Both output modes are sanitized **server-side**, independent of anything the
browser sent - including HTML typed in source mode:

- `html` mode: `classes/HtmlSanitizer.php` strips any tag/attribute outside
  an explicit allow-list (matching BlockCraft's own extension set - nothing
  the editor can't already produce), strips `javascript:`/`data:` URIs, and
  forces `rel="noopener noreferrer"` on `target="_blank"` links.
- `json` mode: `classes/JsonSanitizer.php` validates the decoded value is a
  well-formed TipTap document and recursively drops any node/mark type or
  attribute key outside that same allow-list.

Malformed input in either mode degrades to a safe empty value - it never
throws and never reaches the database unsanitized.

## Repeater lifecycle

BlockCraft's ESM hydrator (`assets/js/blockcraft.ts`) re-scans the DOM for
new mount points on every completed backend AJAX request (`ajax:done`), so
a BlockCraft field added dynamically inside a repeater row - via October's
native "Add Item" action - gets its editor initialized correctly, not just
one present when the page first loaded.

It also watches for its mount elements being removed from the DOM (a
Tailor/FormController repeater row deleted) and destroys the corresponding
TipTap/Vue instance via a `MutationObserver` - this prevents the memory
leaks and detached event listeners a naive integration would accumulate as
rows are added and removed.

## Development

```
npm install
npm run dev      # Vite dev server with HMR
npm run build    # production bundle -> assets/dist/
npm run typecheck
npm test         # Vitest, mounts the real TipTap editor under jsdom
```

### Testing

The PHPUnit suite (`tests/`) needs October's own base classes
(`PluginTestCase`, `Backend\Classes\FormField`/`FormWidgetBase`) to mean
anything, so it must run from inside a real October CMS application with
this plugin installed under `plugins/amjadiqbal/blockcraft`:

```
composer create-project october/october octoberapp
rsync -a --exclude=node_modules --exclude=assets/dist --exclude=.git \
  ./ octoberapp/plugins/amjadiqbal/blockcraft/
cd octoberapp
php artisan october:migrate
vendor/bin/phpunit --configuration path/to/phpunit.blockcraft.xml
```

`scripts/ci-local.sh` does all of this for you, the same way
`.github/workflows/tests.yml` does: a fresh October install, the plugin
installed from `git archive HEAD` (exactly what Packagist ships), PHPUnit,
plus the TypeScript/Vite job and a check that the committed `assets/dist/`
matches a fresh build. It tests the last commit, so commit first.

```
scripts/ci-local.sh                 # both jobs
JOB=phpunit PHP=php8.2 scripts/ci-local.sh
```

## License

MIT - see [LICENSE.md](LICENSE.md).

## Support

- 🐛 **Bug or feature request** — [open an issue](https://github.com/amjadiqbal/oc-blockcraft-plugin/issues)
- 💬 **Questions** — [contact me on Discord](https://discord.com/users/412244229520359434)
- 🔒 **Security issue** — please report privately via GitHub's security tab

## Need this customised, or something built?

I'm available for custom development, package integration, and technical consulting.

[![Hire me on Upwork](https://img.shields.io/badge/Hire%20me-Upwork-6FDA44?style=flat-square&logo=upwork&logoColor=white)](https://www.upwork.com/freelancers/amjadkhatri)
[![Discord](https://img.shields.io/badge/Discord-Message%20me-5865F2?style=flat-square&logo=discord&logoColor=white)](https://discord.com/users/412244229520359434)

## More packages

Part of a family of open-source packages — see [all of them](https://github.com/amjadiqbal?tab=repositories).

| | |
|---|---|
| [oc-vueforge-plugin](https://github.com/amjadiqbal/oc-vueforge-plugin) | The rapid Vue 3 component & widget engine for October CMS |
| [laravel-tiptap](https://github.com/amjadiqbal/laravel-tiptap) | Tiptap editor for Laravel, backend-driven config and secure uploads |
| [kiln](https://github.com/amjadiqbal/kiln) | Deploy-time OPcache control for Laravel |
| [laravel-logpulse](https://github.com/amjadiqbal/laravel-logpulse) | Log health monitoring & intelligent alerting for Laravel |
