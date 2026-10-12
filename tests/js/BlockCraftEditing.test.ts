import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
import type { Editor } from '@tiptap/core';
import { NodeSelection } from '@tiptap/pm/state';
import BlockCraftEditor from '../../assets/vue/BlockCraftEditor.vue';
import { formatHtml } from '../../assets/js/formatHtml';

/**
 * Table, image and source-mode editing (0.2.0), against the real
 * TipTap/ProseMirror editor under jsdom - the same approach as
 * BlockCraftEditor.test.ts.
 *
 * @tiptap/vue-3's Editor publishes state changes to Vue through a ref that
 * triggers two animation frames after each transaction (`useDebouncedRef`
 * in its dist/index.js), so the contextual bars appear a couple of frames
 * after a selection change, not on the next tick. `settle()` waits for that.
 */
// jsdom does no layout and has no Range geometry; ProseMirror asks for it
// whenever a command scrolls the selection into view (`focus()` does).
// Zero-sized rects are what an unrendered page would report anyway.
const EMPTY_RECT = { x: 0, y: 0, top: 0, left: 0, bottom: 0, right: 0, width: 0, height: 0, toJSON: () => ({}) } as DOMRect;
if (!Range.prototype.getClientRects) {
    Range.prototype.getClientRects = () => [] as unknown as DOMRectList;
}
if (!Range.prototype.getBoundingClientRect) {
    Range.prototype.getBoundingClientRect = () => EMPTY_RECT;
}

async function settle(): Promise<void> {
    await new Promise((resolve) => setTimeout(resolve, 60));
    await nextTick();
}

async function waitForEditor(wrapper: VueWrapper): Promise<Editor> {
    for (let attempt = 0; attempt < 20; attempt++) {
        const instance = (wrapper.vm as unknown as { editor?: Editor }).editor;

        if (instance) {
            await nextTick();
            return instance;
        }

        await nextTick();
        await new Promise((resolve) => setTimeout(resolve, 0));
    }

    throw new Error('BlockCraftEditor: TipTap editor instance never became available');
}

function button(wrapper: VueWrapper, title: string) {
    const found = wrapper.findAll('button').find((btn) => btn.attributes('title') === title);
    expect(found, `button "${title}"`).toBeTruthy();
    return found!;
}

function lastEmitted(wrapper: VueWrapper): string {
    const emitted = wrapper.emitted('update:modelValue');
    expect(emitted).toBeTruthy();
    return String(emitted![emitted!.length - 1][0]);
}

function countCells(html: string, row = 0): number {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    return doc.querySelectorAll('tr')[row]?.querySelectorAll('td, th').length ?? 0;
}

/** Puts the cursor in the first cell of the given row of the first table. */
function cursorInRow(editor: Editor, row: number): void {
    let pos: number | null = null;
    let seenRows = -1;

    editor.state.doc.descendants((node, nodePos) => {
        if (pos !== null) {
            return false;
        }
        if (node.type.name === 'tableRow') {
            seenRows++;
        }
        if (seenRows === row && (node.type.name === 'tableCell' || node.type.name === 'tableHeader')) {
            pos = nodePos + 2; // inside the cell's first paragraph
            return false;
        }
        return true;
    });

    editor.chain().focus().setTextSelection(pos!).run();
}

function selectFirstImage(editor: Editor): void {
    let pos = -1;
    editor.state.doc.descendants((node, nodePos) => {
        if (pos === -1 && node.type.name === 'image') {
            pos = nodePos;
        }
        return pos === -1;
    });
    editor.view.dispatch(editor.state.tr.setSelection(NodeSelection.create(editor.state.doc, pos)));
}

describe('BlockCraftEditor editing controls', () => {
    let consoleError: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
        consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    afterEach(() => {
        consoleError.mockRestore();
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    describe('table bar', () => {
        it('appears only while the cursor is inside a table', async () => {
            const wrapper = mount(BlockCraftEditor, {
                props: { modelValue: '<p>Intro</p>' },
                attachTo: document.body,
            });
            const editor = await waitForEditor(wrapper);

            expect(wrapper.find('[aria-label="Table"]').exists()).toBe(false);

            await button(wrapper, 'Insert table').trigger('click');
            await settle();

            const bar = wrapper.find('[aria-label="Table"]');
            expect(bar.exists()).toBe(true);
            const titles = bar.findAll('button').map((b) => b.attributes('title'));
            expect(titles).toEqual([
                'Add row above', 'Add row below', 'Delete row',
                'Add column left', 'Add column right', 'Delete column',
                'Toggle header row', 'Merge cells', 'Split cell', 'Delete table',
            ]);

            // Back into the "Intro" paragraph, wherever the table landed.
            let introPos = -1;
            editor.state.doc.descendants((node, pos) => {
                if (introPos === -1 && node.isText && node.text === 'Intro') {
                    introPos = pos;
                }
                return introPos === -1;
            });
            editor.chain().focus().setTextSelection(introPos + 1).run();
            await settle();
            expect(wrapper.find('[aria-label="Table"]').exists()).toBe(false);

            wrapper.unmount();
            expect(consoleError).not.toHaveBeenCalled();
        });

        it('adds and deletes rows and columns', async () => {
            const wrapper = mount(BlockCraftEditor, {
                props: { modelValue: '<p>Intro</p>' },
                attachTo: document.body,
            });
            const editor = await waitForEditor(wrapper);

            await button(wrapper, 'Insert table').trigger('click');
            await settle();
            expect(countCells(lastEmitted(wrapper))).toBe(3);
            expect((lastEmitted(wrapper).match(/<tr>/g) ?? []).length).toBe(3);

            cursorInRow(editor, 1);
            await settle();

            await button(wrapper, 'Add row below').trigger('click');
            await button(wrapper, 'Add row above').trigger('click');
            expect((lastEmitted(wrapper).match(/<tr>/g) ?? []).length).toBe(5);

            await button(wrapper, 'Delete row').trigger('click');
            expect((lastEmitted(wrapper).match(/<tr>/g) ?? []).length).toBe(4);

            await button(wrapper, 'Add column right').trigger('click');
            await button(wrapper, 'Add column left').trigger('click');
            expect(countCells(lastEmitted(wrapper))).toBe(5);

            await button(wrapper, 'Delete column').trigger('click');
            expect(countCells(lastEmitted(wrapper))).toBe(4);

            wrapper.unmount();
            expect(consoleError).not.toHaveBeenCalled();
        });

        it('toggles the header row and deletes the table', async () => {
            const wrapper = mount(BlockCraftEditor, {
                props: { modelValue: '<p>Intro</p>' },
                attachTo: document.body,
            });
            const editor = await waitForEditor(wrapper);

            await button(wrapper, 'Insert table').trigger('click');
            await settle();
            expect(lastEmitted(wrapper)).toContain('<th');

            cursorInRow(editor, 0);
            await settle();
            await button(wrapper, 'Toggle header row').trigger('click');
            expect(lastEmitted(wrapper)).not.toContain('<th');

            await button(wrapper, 'Delete table').trigger('click');
            await settle();
            expect(lastEmitted(wrapper)).not.toContain('<table');
            expect(wrapper.find('[aria-label="Table"]').exists()).toBe(false);

            wrapper.unmount();
            expect(consoleError).not.toHaveBeenCalled();
        });

        it('merges a cell selection and splits it again; both are disabled when not applicable', async () => {
            const wrapper = mount(BlockCraftEditor, {
                props: { modelValue: '<table><tbody><tr><td><p>A</p></td><td><p>B</p></td></tr><tr><td><p>C</p></td><td><p>D</p></td></tr></tbody></table>' },
                attachTo: document.body,
            });
            const editor = await waitForEditor(wrapper);

            cursorInRow(editor, 0);
            await settle();
            expect(button(wrapper, 'Merge cells').attributes('disabled')).toBeDefined();
            expect(button(wrapper, 'Split cell').attributes('disabled')).toBeDefined();

            // Select the first row's two cells (positions of the cells, from
            // the real document), as a mouse drag across them would.
            const cells: number[] = [];
            editor.state.doc.descendants((node, pos) => {
                if (node.type.name === 'tableCell') {
                    cells.push(pos);
                }
                return true;
            });
            editor.chain().focus().setCellSelection({ anchorCell: cells[0], headCell: cells[1] }).run();
            await settle();

            expect(button(wrapper, 'Merge cells').attributes('disabled')).toBeUndefined();
            await button(wrapper, 'Merge cells').trigger('click');
            expect(lastEmitted(wrapper)).toContain('colspan="2"');

            await settle();
            expect(button(wrapper, 'Split cell').attributes('disabled')).toBeUndefined();
            await button(wrapper, 'Split cell').trigger('click');
            expect(lastEmitted(wrapper)).not.toContain('colspan="2"');

            wrapper.unmount();
            expect(consoleError).not.toHaveBeenCalled();
        });
    });

    describe('image bar', () => {
        const IMAGE = '<p>Before</p><img src="/storage/app/media/old.jpg" alt="old.jpg"><p>After</p>';

        it('appears when an image is selected and edits alt text and width', async () => {
            const wrapper = mount(BlockCraftEditor, { props: { modelValue: IMAGE }, attachTo: document.body });
            const editor = await waitForEditor(wrapper);

            expect(wrapper.find('[aria-label="Image"]').exists()).toBe(false);

            selectFirstImage(editor);
            await settle();

            const bar = wrapper.find('[aria-label="Image"]');
            expect(bar.exists()).toBe(true);

            const [altInput, widthInput] = bar.findAll('input');
            expect((altInput.element as HTMLInputElement).value).toBe('old.jpg');

            await altInput.setValue('A red bicycle');
            await altInput.trigger('change');
            expect(lastEmitted(wrapper)).toContain('alt="A red bicycle"');

            await widthInput.setValue('320');
            await widthInput.trigger('change');
            expect(lastEmitted(wrapper)).toContain('width="320"');

            await widthInput.setValue('');
            await widthInput.trigger('change');
            expect(lastEmitted(wrapper)).not.toContain('width=');

            wrapper.unmount();
            expect(consoleError).not.toHaveBeenCalled();
        });

        it('sets and clears alignment, and keeps it after a reload of the saved HTML', async () => {
            const wrapper = mount(BlockCraftEditor, { props: { modelValue: IMAGE }, attachTo: document.body });
            const editor = await waitForEditor(wrapper);

            selectFirstImage(editor);
            await settle();

            await button(wrapper, 'Align right').trigger('click');
            expect(lastEmitted(wrapper)).toContain('data-align="right"');
            await settle();
            expect(button(wrapper, 'Align right').classes()).toContain('is-active');

            await button(wrapper, 'Align right').trigger('click');
            expect(lastEmitted(wrapper)).not.toContain('data-align');

            wrapper.unmount();

            // 0.1.x content: the sanitizer had stripped data-align and only
            // the class survived. It must still load as aligned.
            const reloaded = mount(BlockCraftEditor, {
                props: { modelValue: '<img src="/a.jpg" alt="a" class="blockcraft-image-align-center">' },
                attachTo: document.body,
            });
            const reloadedEditor = await waitForEditor(reloaded);
            expect(reloadedEditor.getHTML()).toContain('data-align="center"');

            reloaded.unmount();
            expect(consoleError).not.toHaveBeenCalled();
        });

        it('replaces the image through the Media Manager, keeping custom alt text', async () => {
            const hide = vi.fn();
            const popup = vi.fn().mockImplementation((options: { onInsert: (this: { hide: () => void }, items: unknown[]) => void }) => {
                // October calls onInsert with the popup as `this`.
                options.onInsert.call({ hide }, [{ documentType: 'image', publicUrl: '/storage/app/media/new.jpg', title: 'new.jpg' }]);
            });
            (window as unknown as { oc: unknown }).oc = { mediaManager: { popup } };

            const wrapper = mount(BlockCraftEditor, {
                props: { modelValue: '<img src="/storage/app/media/old.jpg" alt="A red bicycle">', useMediaManager: true },
                attachTo: document.body,
            });
            const editor = await waitForEditor(wrapper);

            selectFirstImage(editor);
            await settle();
            await button(wrapper, 'Replace image').trigger('click');
            await settle();

            expect(popup).toHaveBeenCalledTimes(1);
            expect(hide).toHaveBeenCalledTimes(1);
            expect(lastEmitted(wrapper)).toContain('src="/storage/app/media/new.jpg"');
            expect(lastEmitted(wrapper)).toContain('alt="A red bicycle"');

            delete (window as unknown as { oc?: unknown }).oc;
            wrapper.unmount();
            expect(consoleError).not.toHaveBeenCalled();
        });

        it('replaces a default (file name) alt text with the new file name', async () => {
            vi.spyOn(window, 'prompt').mockReturnValue('https://example.com/img/new-photo.png');

            const wrapper = mount(BlockCraftEditor, { props: { modelValue: IMAGE }, attachTo: document.body });
            const editor = await waitForEditor(wrapper);

            selectFirstImage(editor);
            await settle();
            await button(wrapper, 'Replace image').trigger('click');
            await settle();

            expect(lastEmitted(wrapper)).toContain('src="https://example.com/img/new-photo.png"');
            expect(lastEmitted(wrapper)).toContain('alt="new-photo.png"');

            wrapper.unmount();
            expect(consoleError).not.toHaveBeenCalled();
        });

        it('removes the image', async () => {
            const wrapper = mount(BlockCraftEditor, { props: { modelValue: IMAGE }, attachTo: document.body });
            const editor = await waitForEditor(wrapper);

            selectFirstImage(editor);
            await settle();
            await button(wrapper, 'Remove image').trigger('click');
            await settle();

            expect(lastEmitted(wrapper)).not.toContain('<img');
            expect(lastEmitted(wrapper)).toContain('Before');
            expect(wrapper.find('[aria-label="Image"]').exists()).toBe(false);

            wrapper.unmount();
            expect(consoleError).not.toHaveBeenCalled();
        });
    });

    describe('source (HTML) mode', () => {
        it('shows the current HTML, applies edits through the schema, and switches back', async () => {
            const wrapper = mount(BlockCraftEditor, {
                props: { modelValue: '<h2>Title</h2><p>Hello</p>' },
                attachTo: document.body,
            });
            await waitForEditor(wrapper);

            await button(wrapper, 'Edit HTML source').trigger('click');
            const textarea = wrapper.find('.blockcraft-source textarea');
            expect(textarea.exists()).toBe(true);
            expect((textarea.element as HTMLTextAreaElement).value).toBe('<h2>Title</h2>\n<p>Hello</p>');
            expect(wrapper.find('.blockcraft-content').isVisible()).toBe(false);

            // Formatting buttons are disabled while editing source.
            expect(button(wrapper, 'Bold').element.closest('fieldset')!.disabled).toBe(true);

            await textarea.setValue('<h3>New</h3><p onclick="alert(1)">Body <span style="color:red">text</span></p><script>alert(2)</script>');
            await button(wrapper, 'Edit HTML source').trigger('click');
            await settle();

            const html = lastEmitted(wrapper);
            expect(html).toBe('<h3>New</h3><p>Body text</p>');
            expect(wrapper.find('.blockcraft-source').exists()).toBe(false);
            expect(wrapper.find('.blockcraft-content').isVisible()).toBe(true);

            wrapper.unmount();
            expect(consoleError).not.toHaveBeenCalled();
        });

        it('keeps the form value current while typing, without leaving source mode', async () => {
            vi.useFakeTimers();
            const wrapper = mount(BlockCraftEditor, {
                props: { modelValue: '<p>Old</p>' },
                attachTo: document.body,
            });

            for (let i = 0; i < 20 && !(wrapper.vm as unknown as { editor?: Editor }).editor; i++) {
                await nextTick();
            }
            await nextTick();

            await button(wrapper, 'Edit HTML source').trigger('click');
            await wrapper.find('.blockcraft-source textarea').setValue('<p>Typed in source</p>');
            vi.advanceTimersByTime(350);

            expect(lastEmitted(wrapper)).toBe('<p>Typed in source</p>');
            vi.useRealTimers();
            wrapper.unmount();
        });

        it('emits a JSON document from source edits when outputFormat is json', async () => {
            const wrapper = mount(BlockCraftEditor, {
                props: { modelValue: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'hi' }] }] }, outputFormat: 'json' },
                attachTo: document.body,
            });
            await waitForEditor(wrapper);

            await button(wrapper, 'Edit HTML source').trigger('click');
            await wrapper.find('.blockcraft-source textarea').setValue('<p><strong>bold</strong></p>');
            await button(wrapper, 'Edit HTML source').trigger('click');

            const emitted = wrapper.emitted('update:modelValue')!;
            const doc = emitted[emitted.length - 1][0] as { type: string; content: { type: string; content: { marks: { type: string }[] }[] }[] };
            expect(doc.type).toBe('doc');
            expect(doc.content[0].type).toBe('paragraph');
            expect(doc.content[0].content[0].marks[0].type).toBe('bold');

            wrapper.unmount();
            expect(consoleError).not.toHaveBeenCalled();
        });

        it('can be left out of the toolbar with the buttons option', async () => {
            const wrapper = mount(BlockCraftEditor, {
                props: { modelValue: '<p>x</p>', buttons: ['bold', 'italic'] },
                attachTo: document.body,
            });
            await waitForEditor(wrapper);

            expect(wrapper.findAll('button').some((b) => b.attributes('title') === 'Edit HTML source')).toBe(false);

            wrapper.unmount();
        });
    });

    describe('formatHtml', () => {
        it('puts blocks on their own lines without changing the content', () => {
            expect(formatHtml('<h2>A</h2><ul><li><p>x</p></li></ul><pre><code>a\nb</code></pre>'))
                .toBe('<h2>A</h2>\n<ul>\n<li><p>x</p>\n</li>\n</ul>\n<pre><code>a\nb</code></pre>');
        });

        it('is idempotent', () => {
            const once = formatHtml('<table><tbody><tr><td><p>1</p></td></tr></tbody></table><hr>');
            expect(formatHtml(once)).toBe(once);
        });
    });
});
