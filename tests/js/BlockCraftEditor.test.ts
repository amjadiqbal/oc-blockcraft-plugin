import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
import type { Editor } from '@tiptap/core';
import BlockCraftEditor from '../../assets/vue/BlockCraftEditor.vue';

/**
 * Component-level smoke tests, the pragmatic equivalent of a real browser
 * session when no headless-browser tool is available in this environment
 * (same tradeoff documented in VueForge's own PROJECT_PROGRESS.md). These
 * mount the real TipTap/ProseMirror editor under jsdom via
 * @vue/test-utils - not mocks of TipTap itself - so toolbar commands,
 * content serialization, and the mount/unmount lifecycle are exercised for
 * real.
 */

/**
 * `useEditor()` (see @tiptap/vue-3's real source) constructs the `Editor`
 * inside the parent's `onMounted`, and `<EditorContent>` (a child, which
 * mounts *before* its parent in Vue's lifecycle order) only creates the
 * actual ProseMirror view once it reacts to that ref becoming non-null - one
 * `nextTick()` after `mount()` isn't enough to observe either. This polls
 * a few ticks/microtasks until the editor instance genuinely exists.
 */
async function waitForEditor(wrapper: VueWrapper): Promise<Editor> {
    for (let attempt = 0; attempt < 20; attempt++) {
        // Vue's component proxy auto-unwraps a top-level ref exposed via
        // defineExpose(), so `wrapper.vm.editor` is the Editor instance
        // itself here, not a `{ value: Editor }` ref wrapper.
        const instance = (wrapper.vm as unknown as { editor?: Editor }).editor;

        if (instance) {
            // One more tick so <EditorContent>'s own reaction to the now-set
            // ref has actually mounted the ProseMirror view into the DOM.
            await nextTick();
            return instance;
        }

        await nextTick();
        await new Promise((resolve) => setTimeout(resolve, 0));
    }

    throw new Error('BlockCraftEditor: TipTap editor instance never became available');
}

describe('BlockCraftEditor', () => {
    let consoleError: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
        consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    afterEach(() => {
        consoleError.mockRestore();
        document.body.innerHTML = '';
    });

    it('mounts with seeded HTML content and renders it', async () => {
        const wrapper = mount(BlockCraftEditor, {
            props: { modelValue: '<p>Hello <strong>world</strong></p>', outputFormat: 'html' },
            attachTo: document.body,
        });

        await waitForEditor(wrapper);

        expect(wrapper.find('.blockcraft-content').html()).toContain('Hello');
        expect(wrapper.find('.blockcraft-content').html()).toContain('<strong>world</strong>');

        wrapper.unmount();
        expect(consoleError).not.toHaveBeenCalled();
    });

    it('toggling bold via the toolbar emits updated HTML and marks the button active', async () => {
        const wrapper = mount(BlockCraftEditor, {
            props: { modelValue: '<p>Hello</p>', outputFormat: 'html' },
            attachTo: document.body,
        });

        const instance = await waitForEditor(wrapper);

        // Select the paragraph's text, then toggle bold - exercises a real
        // ProseMirror selection + command, not a stubbed handler.
        instance.commands.selectAll();

        const boldButton = wrapper.findAll('button').find((btn) => btn.attributes('title') === 'Bold');
        expect(boldButton).toBeTruthy();

        await boldButton!.trigger('click');
        await wrapper.vm.$nextTick();

        const emitted = wrapper.emitted('update:modelValue');
        expect(emitted).toBeTruthy();
        expect(String(emitted![emitted!.length - 1][0])).toContain('<strong>');

        wrapper.unmount();
        expect(consoleError).not.toHaveBeenCalled();
    });

    it('emits a TipTap JSON document when outputFormat is json', async () => {
        const wrapper = mount(BlockCraftEditor, {
            props: { modelValue: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'hi' }] }] }, outputFormat: 'json' },
            attachTo: document.body,
        });

        const instance = await waitForEditor(wrapper);

        instance.commands.insertContentAt(instance.state.doc.content.size, '!');

        const emitted = wrapper.emitted('update:modelValue');
        expect(emitted).toBeTruthy();
        const lastValue = emitted![emitted!.length - 1][0] as { type: string; content: unknown[] };
        expect(lastValue.type).toBe('doc');
        expect(Array.isArray(lastValue.content)).toBe(true);

        wrapper.unmount();
        expect(consoleError).not.toHaveBeenCalled();
    });

    it('destroys the TipTap instance on unmount without leaking listeners (mount/unmount x3)', async () => {
        for (let i = 0; i < 3; i++) {
            const wrapper = mount(BlockCraftEditor, {
                props: { modelValue: '<p>content</p>', outputFormat: 'html' },
                attachTo: document.body,
            });
            await waitForEditor(wrapper);
            wrapper.unmount();
        }

        expect(consoleError).not.toHaveBeenCalled();
    });

    it('falls back to a URL prompt for image insertion when the Media Manager is unavailable', async () => {
        const promptSpy = vi.spyOn(window, 'prompt').mockReturnValue('https://example.com/photo.png');

        const wrapper = mount(BlockCraftEditor, {
            props: { modelValue: '<p>Hello</p>', outputFormat: 'html', useMediaManager: false },
            attachTo: document.body,
        });

        await waitForEditor(wrapper);

        const imageButton = wrapper.findAll('button').find((btn) => btn.attributes('title') === 'Insert image');
        expect(imageButton).toBeTruthy();

        await imageButton!.trigger('click');
        await nextTick();
        await nextTick();

        const emitted = wrapper.emitted('update:modelValue');
        expect(emitted).toBeTruthy();
        expect(String(emitted![emitted!.length - 1][0])).toContain('example.com/photo.png');

        promptSpy.mockRestore();
        wrapper.unmount();
        expect(consoleError).not.toHaveBeenCalled();
    });

    it('respects readOnly: does not render a toolbar and the editor is not editable', async () => {
        const wrapper = mount(BlockCraftEditor, {
            props: { modelValue: '<p>Locked</p>', outputFormat: 'html', readOnly: true },
            attachTo: document.body,
        });

        const instance = await waitForEditor(wrapper);

        expect(wrapper.find('.blockcraft-toolbar').exists()).toBe(false);
        expect(instance.isEditable).toBe(false);

        wrapper.unmount();
        expect(consoleError).not.toHaveBeenCalled();
    });
});
