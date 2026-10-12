<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import { TableKit } from '@tiptap/extension-table/kit';
import { AlignableImage, type ImageAlign } from '../js/extensions/alignable-image';
import { openMediaManager, isMediaManagerAvailable } from '../js/useMediaManagerPopup';
import { formatHtml } from '../js/formatHtml';
import type { JSONContent } from '@tiptap/core';

type OutputFormat = 'html' | 'json';
type ToolbarButton =
    | 'bold' | 'italic' | 'strike' | 'code'
    | 'heading1' | 'heading2' | 'heading3' | 'heading4' | 'heading5' | 'heading6'
    | 'bulletList' | 'orderedList' | 'blockquote' | 'codeBlock'
    | 'table' | 'link' | 'image' | 'horizontalRule' | 'undo' | 'redo' | 'source';

const DEFAULT_BUTTONS: ToolbarButton[] = [
    'undo', 'redo',
    'heading1', 'heading2', 'heading3',
    'bold', 'italic', 'strike', 'code',
    'bulletList', 'orderedList', 'blockquote', 'codeBlock',
    'link', 'image', 'table', 'horizontalRule',
    'source',
];

const props = withDefaults(defineProps<{
    modelValue: string | JSONContent;
    outputFormat?: OutputFormat;
    height?: string;
    buttons?: ToolbarButton[] | null;
    readOnly?: boolean;
    useMediaManager?: boolean;
}>(), {
    outputFormat: 'html',
    height: '400px',
    buttons: null,
    readOnly: false,
    useMediaManager: false,
});

const emit = defineEmits<{
    (event: 'update:modelValue', value: string | JSONContent): void;
}>();

const visibleButtons = computed(() => props.buttons && props.buttons.length ? props.buttons : DEFAULT_BUTTONS);

// Source (HTML) mode state - see the "Source (HTML) mode" section below.
const sourceMode = ref(false);
const sourceHtml = ref('');
let sourceTimer: ReturnType<typeof setTimeout> | null = null;

function hasButton(name: ToolbarButton): boolean {
    return visibleButtons.value.includes(name);
}

const editor = useEditor({
    content: props.modelValue as JSONContent | string,
    editable: !props.readOnly,
    extensions: [
        StarterKit.configure({
            link: {
                openOnClick: false,
                autolink: true,
            },
        }),
        AlignableImage.configure({
            inline: false,
            allowBase64: false,
        }),
        TableKit.configure({
            table: { resizable: true },
        }),
    ],
    onUpdate({ editor: instance }) {
        emit('update:modelValue', props.outputFormat === 'json' ? instance.getJSON() : instance.getHTML());
    },
});

// A field-level readOnly change (e.g. a form re-render) should update the
// live editor instance rather than requiring a remount.
watch(() => props.readOnly, (readOnly) => {
    editor.value?.setEditable(!readOnly);
});

// Support the field being reset from outside (e.g. a Repeater row's "reset"
// action) without tearing down and recreating the editor.
watch(() => props.modelValue, (value) => {
    if (!editor.value) {
        return;
    }

    const current = props.outputFormat === 'json' ? editor.value.getJSON() : editor.value.getHTML();

    if (JSON.stringify(current) !== JSON.stringify(value)) {
        editor.value.commands.setContent(value as JSONContent | string, { emitUpdate: false });
    }
});

onBeforeUnmount(() => {
    if (sourceTimer) {
        clearTimeout(sourceTimer);
    }

    // Required inside Tailor/FormController repeaters: a row removed from
    // the DOM must destroy its TipTap instance, or the ProseMirror view and
    // its event listeners leak - see README.md's "Repeater lifecycle" section.
    editor.value?.destroy();
});

function toggleHeading(level: 1 | 2 | 3 | 4 | 5 | 6): void {
    editor.value?.chain().focus().toggleHeading({ level }).run();
}

function setLink(): void {
    if (!editor.value) {
        return;
    }

    const previousUrl = editor.value.getAttributes('link').href as string | undefined;
    // eslint-disable-next-line no-alert
    const url = window.prompt('Link URL', previousUrl ?? 'https://');

    if (url === null) {
        return;
    }

    if (url === '') {
        editor.value.chain().focus().extendMarkRange('link').unsetLink().run();
        return;
    }

    editor.value.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
}

async function insertImage(): Promise<void> {
    if (!editor.value) {
        return;
    }

    if (props.useMediaManager && isMediaManagerAvailable()) {
        const item = await openMediaManager();

        if (item && item.documentType === 'image') {
            editor.value.chain().focus().setImage({
                src: item.publicUrl,
                alt: item.title,
            }).run();
        }

        return;
    }

    // eslint-disable-next-line no-alert
    const url = window.prompt('Image URL');

    if (url) {
        editor.value.chain().focus().setImage({ src: url }).run();
    }
}

// ---- Image editing (shown while an image node is selected) ----

const imageSelected = computed(() => !sourceMode.value && (editor.value?.isActive('image') ?? false));
const imageAttrs = computed(() => imageSelected.value ? editor.value!.getAttributes('image') : {});

function setImageAlign(align: ImageAlign): void {
    // Clicking the active alignment again clears it.
    const next = imageAttrs.value.align === align ? null : align;

    editor.value?.chain().focus().updateAttributes('image', { align: next }).run();
}

// The alt/width inputs take focus away from the editor, so these update the
// still-selected image without calling focus() (which would steal it back).
function setImageAlt(event: Event): void {
    const alt = (event.target as HTMLInputElement).value.trim();

    editor.value?.chain().updateAttributes('image', { alt: alt === '' ? null : alt }).run();
}

function setImageWidth(event: Event): void {
    const raw = (event.target as HTMLInputElement).value.trim();
    const width = /^\d{1,5}$/.test(raw) && Number(raw) > 0 ? Number(raw) : null;

    editor.value?.chain().updateAttributes('image', { width, height: null }).run();
}

function fileTitle(src: string | null | undefined): string {
    return (src ?? '').split(/[?#]/)[0].split('/').pop() ?? '';
}

async function replaceImage(): Promise<void> {
    if (!editor.value) {
        return;
    }

    const current = editor.value.getAttributes('image');
    let src: string | null = null;
    let title = '';

    if (props.useMediaManager && isMediaManagerAvailable()) {
        const item = await openMediaManager();

        if (item && item.documentType === 'image') {
            src = item.publicUrl;
            title = item.title;
        }
    } else {
        // eslint-disable-next-line no-alert
        src = window.prompt('Image URL', current.src ?? '') || null;
        title = fileTitle(src);
    }

    if (!src) {
        return;
    }

    // Keep alt text the editor wrote; only replace the default one, which
    // is the old file's name.
    const altWasDefault = !current.alt || current.alt === fileTitle(current.src);

    editor.value.chain().focus().updateAttributes('image', {
        src,
        alt: altWasDefault ? (title || null) : current.alt,
        height: null,
    }).run();
}

function removeImage(): void {
    editor.value?.chain().focus().deleteSelection().run();
}

// ---- Table editing (shown while the selection is inside a table) ----

const inTable = computed(() => !sourceMode.value && (editor.value?.isActive('table') ?? false));

type TableCommand =
    | 'addRowBefore' | 'addRowAfter' | 'deleteRow'
    | 'addColumnBefore' | 'addColumnAfter' | 'deleteColumn'
    | 'toggleHeaderRow' | 'mergeCells' | 'splitCell' | 'deleteTable';

const TABLE_ACTIONS: { command: TableCommand; label: string; title: string }[] = [
    { command: 'addRowBefore', label: '+ Row above', title: 'Add row above' },
    { command: 'addRowAfter', label: '+ Row below', title: 'Add row below' },
    { command: 'deleteRow', label: '− Row', title: 'Delete row' },
    { command: 'addColumnBefore', label: '+ Col left', title: 'Add column left' },
    { command: 'addColumnAfter', label: '+ Col right', title: 'Add column right' },
    { command: 'deleteColumn', label: '− Col', title: 'Delete column' },
    { command: 'toggleHeaderRow', label: 'Header row', title: 'Toggle header row' },
    { command: 'mergeCells', label: 'Merge', title: 'Merge cells' },
    { command: 'splitCell', label: 'Split', title: 'Split cell' },
    { command: 'deleteTable', label: 'Delete table', title: 'Delete table' },
];

function canRunTable(command: TableCommand): boolean {
    return editor.value ? editor.value.can()[command]() : false;
}

function runTable(command: TableCommand): void {
    editor.value?.chain().focus()[command]().run();
}

// ---- Source (HTML) mode ----
//
// Editors can view and edit the document as HTML. What they type is parsed
// back through the editor's own schema (anything BlockCraft can't represent
// is dropped there), and whatever is posted is still run through the
// server-side HtmlSanitizer/JsonSanitizer in getSaveValue(), so source mode
// can't be used to get markup past either.

function applySource(): void {
    if (sourceTimer) {
        clearTimeout(sourceTimer);
        sourceTimer = null;
    }

    editor.value?.commands.setContent(sourceHtml.value, { emitUpdate: true });
}

function onSourceInput(event: Event): void {
    sourceHtml.value = (event.target as HTMLTextAreaElement).value;

    // Keep the form's hidden input current while typing, so saving without
    // leaving source mode still saves what's in the box.
    if (sourceTimer) {
        clearTimeout(sourceTimer);
    }
    sourceTimer = setTimeout(applySource, 300);
}

function toggleSource(): void {
    if (!editor.value) {
        return;
    }

    if (sourceMode.value) {
        applySource();
        sourceMode.value = false;
        editor.value.commands.focus();
        return;
    }

    sourceHtml.value = formatHtml(editor.value.getHTML());
    sourceMode.value = true;
}

// Exposed for the component-level test suite (tests/js/BlockCraftEditor.test.ts)
// to drive real ProseMirror selections/commands directly, the same way a
// consuming plugin's own extension code might reach into the editor via a
// template ref - not part of the widget's documented public API.
defineExpose({ editor, sourceMode });
</script>

<template>
    <div class="blockcraft-editor" :class="{ 'is-readonly': readOnly, 'is-source-mode': sourceMode }">
        <div v-if="editor && !readOnly" class="blockcraft-toolbar" role="toolbar" aria-label="Formatting">
            <!-- display: contents; disabling the fieldset disables every formatting button while source mode is on -->
            <fieldset class="blockcraft-toolbar-group" :disabled="sourceMode">
                <button type="button" title="Undo" v-if="hasButton('undo')" @click="editor.chain().focus().undo().run()">↶</button>
                <button type="button" title="Redo" v-if="hasButton('redo')" @click="editor.chain().focus().redo().run()">↷</button>

                <span class="blockcraft-toolbar-sep" v-if="hasButton('heading1') || hasButton('heading2') || hasButton('heading3')"></span>
                <button type="button" v-for="level in ([1,2,3,4,5,6] as const)" :key="level"
                    v-show="hasButton(`heading${level}` as ToolbarButton)"
                    :class="{ 'is-active': editor?.isActive('heading', { level }) }"
                    @click="toggleHeading(level)">H{{ level }}</button>

                <span class="blockcraft-toolbar-sep"></span>
                <button type="button" title="Bold" v-if="hasButton('bold')" :class="{ 'is-active': editor?.isActive('bold') }" @click="editor.chain().focus().toggleBold().run()"><strong>B</strong></button>
                <button type="button" title="Italic" v-if="hasButton('italic')" :class="{ 'is-active': editor?.isActive('italic') }" @click="editor.chain().focus().toggleItalic().run()"><em>I</em></button>
                <button type="button" title="Strikethrough" v-if="hasButton('strike')" :class="{ 'is-active': editor?.isActive('strike') }" @click="editor.chain().focus().toggleStrike().run()"><s>S</s></button>
                <button type="button" title="Inline code" v-if="hasButton('code')" :class="{ 'is-active': editor?.isActive('code') }" @click="editor.chain().focus().toggleCode().run()">&lt;/&gt;</button>

                <span class="blockcraft-toolbar-sep" v-if="hasButton('bulletList') || hasButton('orderedList') || hasButton('blockquote') || hasButton('codeBlock')"></span>
                <button type="button" title="Bullet list" v-if="hasButton('bulletList')" :class="{ 'is-active': editor?.isActive('bulletList') }" @click="editor.chain().focus().toggleBulletList().run()">• List</button>
                <button type="button" title="Numbered list" v-if="hasButton('orderedList')" :class="{ 'is-active': editor?.isActive('orderedList') }" @click="editor.chain().focus().toggleOrderedList().run()">1. List</button>
                <button type="button" title="Blockquote" v-if="hasButton('blockquote')" :class="{ 'is-active': editor?.isActive('blockquote') }" @click="editor.chain().focus().toggleBlockquote().run()">" Quote</button>
                <button type="button" title="Code block" v-if="hasButton('codeBlock')" :class="{ 'is-active': editor?.isActive('codeBlock') }" @click="editor.chain().focus().toggleCodeBlock().run()">{ } Code</button>

                <span class="blockcraft-toolbar-sep" v-if="hasButton('link') || hasButton('image') || hasButton('table') || hasButton('horizontalRule')"></span>
                <button type="button" title="Link" v-if="hasButton('link')" :class="{ 'is-active': editor?.isActive('link') }" @click="setLink">Link</button>
                <button type="button" title="Insert image" v-if="hasButton('image')" @click="insertImage">Image</button>
                <button type="button" title="Insert table" v-if="hasButton('table')" @click="editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()">Table</button>
                <button type="button" title="Horizontal rule" v-if="hasButton('horizontalRule')" @click="editor.chain().focus().setHorizontalRule().run()">―</button>
            </fieldset>

            <template v-if="hasButton('source')">
                <span class="blockcraft-toolbar-sep"></span>
                <button type="button" title="Edit HTML source" :class="{ 'is-active': sourceMode }" :aria-pressed="sourceMode" @click="toggleSource">HTML</button>
            </template>
        </div>

        <div v-if="editor && !readOnly && inTable" class="blockcraft-contextbar" role="toolbar" aria-label="Table">
            <span class="blockcraft-contextbar-label">Table</span>
            <button type="button" v-for="action in TABLE_ACTIONS" :key="action.command"
                :title="action.title"
                :class="{ 'is-danger': action.command === 'deleteTable' }"
                :disabled="!canRunTable(action.command)"
                @click="runTable(action.command)">{{ action.label }}</button>
        </div>

        <div v-if="editor && !readOnly && imageSelected" class="blockcraft-contextbar" role="toolbar" aria-label="Image">
            <span class="blockcraft-contextbar-label">Image</span>
            <label class="blockcraft-contextbar-field">
                Alt text
                <input type="text" :value="imageAttrs.alt ?? ''" placeholder="Describe the image" @change="setImageAlt">
            </label>
            <label class="blockcraft-contextbar-field">
                Width
                <input type="number" min="1" step="1" class="is-narrow" :value="imageAttrs.width ?? ''" placeholder="auto" @change="setImageWidth">
                px
            </label>
            <span class="blockcraft-toolbar-sep"></span>
            <button type="button" title="Align left" :class="{ 'is-active': imageAttrs.align === 'left' }" @click="setImageAlign('left')">⇤</button>
            <button type="button" title="Align center" :class="{ 'is-active': imageAttrs.align === 'center' }" @click="setImageAlign('center')">↔</button>
            <button type="button" title="Align right" :class="{ 'is-active': imageAttrs.align === 'right' }" @click="setImageAlign('right')">⇥</button>
            <span class="blockcraft-toolbar-sep"></span>
            <button type="button" title="Replace image" @click="replaceImage">Replace…</button>
            <button type="button" title="Remove image" class="is-danger" @click="removeImage">Remove</button>
        </div>

        <editor-content v-show="!sourceMode" :editor="editor" class="blockcraft-content" :style="{ minHeight: height }" />

        <div v-if="sourceMode" class="blockcraft-source">
            <textarea
                :value="sourceHtml"
                :style="{ minHeight: height }"
                spellcheck="false"
                aria-label="HTML source"
                @input="onSourceInput"
            ></textarea>
            <p class="blockcraft-source-hint">
                Only HTML the editor supports is kept. Other tags are unwrapped, and scripts, styles and event attributes are removed when you switch back or save.
            </p>
        </div>
    </div>
</template>
