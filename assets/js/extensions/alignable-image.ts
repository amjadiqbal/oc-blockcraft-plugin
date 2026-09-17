import Image from '@tiptap/extension-image';

/**
 * AlignableImage extends TipTap's stock Image node (confirmed real API,
 * `@tiptap/extension-image`'s `ImageOptions`/`SetImageOptions` - see
 * PROJECT_PROGRESS.md Phase 3) with a persisted `align` attribute
 * ('left' | 'center' | 'right'), rendered as a CSS class rather than an
 * inline style so BlockCraft's own stylesheet controls the actual layout.
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
                parseHTML: (element) => element.getAttribute('data-align'),
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
