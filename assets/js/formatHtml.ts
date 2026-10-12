/**
 * formatHtml puts each block-level element of TipTap's single-line
 * `getHTML()` output on its own line, so the source view is readable.
 *
 * Only newlines are added, and only between tags: ProseMirror's HTML parser
 * collapses whitespace between block nodes, so the formatted source parses
 * back to exactly the same document. `<pre>` contents are never touched -
 * newlines are only inserted after a closing block tag or after the opening
 * tag of a container that holds other blocks, and neither occurs inside a
 * code block's text.
 */
const BLOCK_CLOSE = /(<\/(?:p|h[1-6]|li|blockquote|pre|table|thead|tbody|tfoot|tr|th|td|ul|ol)>)(?!\n)/g;
const CONTAINER_OPEN = /(<(?:ul|ol|table|thead|tbody|tfoot|tr|blockquote)(?:\s[^>]*)?>)(?!\n)/g;
const VOID_BLOCK = /(<(?:hr|img)(?:\s[^>]*)?>)(?!\n)/g;

export function formatHtml(html: string): string {
    return html
        .replace(BLOCK_CLOSE, '$1\n')
        .replace(CONTAINER_OPEN, '$1\n')
        .replace(VOID_BLOCK, '$1\n')
        .trim();
}
