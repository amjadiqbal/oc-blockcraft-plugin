import Image from '@tiptap/extension-image';

export type ImageAlign = 'left' | 'center' | 'right';

const ALIGN_VALUES: ImageAlign[] = ['left', 'center', 'right'];

/**
 * parseAlign reads the alignment from `data-align`, falling back to the
 * `blockcraft-image-align-*` class. Content saved by BlockCraft 0.1.x only
 * kept the class (HtmlSanitizer stripped `data-align` before 0.2.0), so the
 * class is what existing content has to be recovered from.
 */
export function parseAlign(element: HTMLElement): ImageAlign | null {
    const fromData = element.getAttribute('data-align');

    if (fromData && (ALIGN_VALUES as string[]).includes(fromData)) {
        return fromData as ImageAlign;
    }

    const match = (element.getAttribute('class') ?? '').match(/(?:^|\s)blockcraft-image-align-(left|center|right)(?:\s|$)/);

    return match ? match[1] as ImageAlign : null;
}

/**
 * AlignableImage extends TipTap's stock Image node (confirmed real API,
 * `@tiptap/extension-image`'s `ImageOptions`/`SetImageOptions` - see
 * PROJECT_PROGRESS.md Phase 3) with a persisted `align` attribute
 * ('left' | 'center' | 'right'), rendered as a CSS class rather than an
 * inline style so BlockCraft's own stylesheet controls the actual layout.
 * The stock node already carries `width`/`height` attributes (3.x), which
 * render as plain HTML attributes.
 *
 * Setting alignment reuses TipTap's built-in `updateAttributes` command
 * (part of `@tiptap/core`, not something BlockCraft invents) - no custom
 * command definition needed:
 *
 *     editor.chain().focus().updateAttributes('image', { align: 'left' }).run()
 */
export const AlignableImage = Image.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            align: {
                default: null,
                parseHTML: (element) => parseAlign(element),
                renderHTML: (attributes) => {
                    if (!attributes.align) {
                        return {};
                    }

                    return {
                        'data-align': attributes.align,
                        class: `blockcraft-image-align-${attributes.align}`,
                    };
                },
            },
        };
    },
});

export default AlignableImage;
