<?php

namespace AmjadIqbal\BlockCraft\Classes;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * HtmlSanitizer strips any tag/attribute not on BlockCraft's explicit
 * allow-list before HTML-mode content is persisted to the database. This is
 * deliberately independent of October's own RichEditor sanitization, which
 * happens client-side via Froala's configurable allow/deny lists
 * (Backend\Models\EditorSetting - see modules/backend/formwidgets/RichEditor.php)
 * and is not applied server-side. BlockCraft sanitizes server-side so a
 * request that bypasses the browser entirely (a crafted POST, an API import)
 * can never persist unsafe markup.
 *
 * The allow-list mirrors BlockCraft's own TipTap extension set (StarterKit's
 * nodes/marks, plus Image and Table) - nothing the editor itself can't
 * already produce.
 */
class HtmlSanitizer
{
    /**
     * @var array<string, string[]> tag => allowed attribute names
     */
    protected array $allowedTags = [
        'p' => [],
        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'strong' => [], 'b' => [],
        'em' => [], 'i' => [],
        's' => [], 'strike' => [], 'del' => [],
        'u' => [],
        'code' => [],
        'pre' => [],
        'blockquote' => [],
        'ul' => [], 'ol' => ['start'],
        'li' => [],
        'a' => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'title', 'class', 'width', 'height'],
        'hr' => [],
        'br' => [],
        'table' => [],
        'thead' => [], 'tbody' => [], 'tfoot' => [],
        'tr' => [],
        'th' => ['colspan', 'rowspan'],
        'td' => ['colspan', 'rowspan'],
    ];

    /**
     * URI schemes allowed in href/src attributes. Deliberately excludes
     * `data:` - the Image extension's `allowBase64` option defaults to
     * false, so a base64 data URI should never have been produced by the
     * editor in the first place, and permitting it here would just open a
     * bypass for `javascript:`-adjacent data URI XSS vectors.
     */
    protected array $allowedUriSchemes = ['http', 'https', 'mailto', 'tel'];

    /**
     * clean sanitizes an HTML fragment down to the allow-listed tag/attribute
     * set, dropping anything else (including its tag but keeping safe
     * descendant content, except for genuinely dangerous containers like
     * <script>/<style>, whose content is dropped entirely).
     */
    public function clean(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument();

        // Wrap in a root element and force UTF-8 interpretation; suppress
        // warnings from libxml for intentionally-malformed input (a crafted
        // payload should degrade gracefully, not throw).
        $wrapped = '<?xml encoding="utf-8"?><root>' . $this->selfCloseVoidElements($html) . '</root>';

        $internalErrors = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML($wrapped, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD);
        libxml_use_internal_errors($internalErrors);

        if (!$loaded) {
            return '';
        }

        $xpath = new DOMXPath($dom);
        $root = $xpath->query('//root')->item(0);

        if (!$root) {
            return '';
        }

        $this->sanitizeNode($root);

        $output = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim($output);
    }

    /**
     * selfCloseVoidElements rewrites known HTML void elements (<img>, <br>,
     * <embed>, etc.) to explicit self-closing form before parsing.
     *
     * DOMDocument's HTML parser (libxml) has no built-in notion of "void"
     * elements when fed a fragment this way - an unclosed `<embed src="x">`
     * is treated as an *opening* tag, so every sibling that follows it in
     * the source (including a subsequent `<form>...</form><p>safe</p>`)
     * gets parsed as its *children*. Since `sanitizeNode()` removes a
     * dangerous element (`embed` is on that list) along with its entire
     * subtree, that swallowed-but-actually-safe content was being deleted
     * too - caught by HtmlSanitizerTest::testIframeObjectEmbedAndFormAreRemovedEntirely
     * asserting "safe" on an empty string instead of finding it preserved.
     */
    protected function selfCloseVoidElements(string $html): string
    {
        static $voidTags = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

        $pattern = '#<(' . implode('|', $voidTags) . ')((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>#i';

        return preg_replace_callback($pattern, function (array $matches): string {
            $inner = rtrim($matches[2]);

            if (str_ends_with($inner, '/')) {
                return $matches[0];
            }

            return '<' . $matches[1] . $inner . ' />';
        }, $html);
    }

    /**
     * sanitizeNode walks the tree depth-first, removing disallowed elements
     * (script/style content is dropped entirely; anything else not
     * allow-listed is unwrapped, keeping its safe children in its place) and
     * stripping disallowed attributes from what remains.
     */
    protected function sanitizeNode(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);

                // Always dangerous - remove the element AND its content.
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form'], true)) {
                    $node->removeChild($child);
                    continue;
                }

                // Recurse before deciding whether to unwrap, so children are
                // already clean either way.
                $this->sanitizeNode($child);

                if (!array_key_exists($tag, $this->allowedTags)) {
                    // Not an allowed element - unwrap it, keeping its
                    // (already-sanitized) children in its place.
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }

                $this->sanitizeAttributes($child, $this->allowedTags[$tag]);
            }
            // Text/comment nodes: comments are dropped by DOMDocument's own
            // serialization choices here; text nodes are left as-is.
            elseif ($child->nodeType === XML_COMMENT_NODE) {
                $node->removeChild($child);
            }
        }
    }

    /**
     * sanitizeAttributes removes any attribute not in $allowed, and
     * additionally validates href/src URI schemes and strips any `on*`
     * event-handler attribute regardless of tag (defense in depth - none of
     * these ever appear in $allowedTags, but a second check costs nothing).
     */
    protected function sanitizeAttributes(DOMElement $element, array $allowed): void
    {
        foreach (iterator_to_array($element->attributes) as $attr) {
            $name = strtolower($attr->name);

            if (str_starts_with($name, 'on') || $name === 'style') {
                $element->removeAttribute($attr->name);
                continue;
            }

            if (!in_array($name, $allowed, true)) {
                $element->removeAttribute($attr->name);
                continue;
            }

            if (in_array($name, ['href', 'src'], true) && !$this->isSafeUri($attr->value)) {
                $element->removeAttribute($attr->name);
            }
        }

        // rel="noopener noreferrer" whenever target="_blank" is present, to
        // avoid the classic reverse-tabnabbing gap left by user-supplied links.
        if ($element->tagName === 'a' && $element->getAttribute('target') === '_blank') {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    protected function isSafeUri(string $value): bool
    {
        $value = trim($value);

        if ($value === '') {
            return true;
        }

        // Relative/root-relative URLs (no scheme) are safe - most Media
        // Manager-inserted image paths look like this.
        if (!preg_match('#^([a-zA-Z][a-zA-Z0-9+.-]*):#', $value, $matches)) {
            return true;
        }

        return in_array(strtolower($matches[1]), $this->allowedUriSchemes, true);
    }
}
