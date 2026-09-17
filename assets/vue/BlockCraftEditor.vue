<script setup lang="ts">
import { computed, onBeforeUnmount, watch } from 'vue';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import { TableKit } from '@tiptap/extension-table/kit';
import { AlignableImage } from '../js/extensions/alignable-image';
import { openMediaManager, isMediaManagerAvailable } from '../js/useMediaManagerPopup';
import type { JSONContent } from '@tiptap/core';

type OutputFormat = 'html' | 'json';
type ToolbarButton =
    | 'bold' | 'italic' | 'strike' | 'code'
    | 'heading1' | 'heading2' | 'heading3' | 'heading4' | 'heading5' | 'heading6'
    | 'bulletList' | 'orderedList' | 'blockquote' | 'codeBlock'
    | 'table' | 'link' | 'image' | 'horizontalRule' | 'undo' | 'redo';

const DEFAULT_BUTTONS: ToolbarButton[] = [
    'undo', 'redo',
    'heading1', 'heading2', 'heading3',
    'bold', 'italic', 'strike', 'code',
    'bulletList', 'orderedList', 'blockquote', 'codeBlock',
    'link', 'image', 'table', 'horizontalRule',
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

function setImageAlign(align: 'left' | 'center' | 'right'): void {
    editor.value?.chain().focus().updateAttributes('image', { align }).run();
}
</script>

<template>
    <div class="blockcraft-editor" :class="{ 'is-readonly': readOnly }">
        <div v-if="editor && !readOnly" class="blockcraft-toolbar" role="toolbar" aria-label="Formatting">
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

            <template v-if="editor?.isActive('image')">
                <span class="blockcraft-toolbar-sep"></span>
                <button type="button" title="Align left" @click="setImageAlign('left')">⇤</button>
                <button type="button" title="Align center" @click="setImageAlign('center')">↔</button>
                <button type="button" title="Align right" @click="setImageAlign('right')">⇥</button>
            </template>
        </div>

        <editor-content :editor="editor" class="blockcraft-content" :style="{ minHeight: height }" />
    </div>
</template>
