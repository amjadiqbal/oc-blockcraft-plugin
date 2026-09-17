import { createApp, type App } from 'vue';
import BlockCraftEditor from '../vue/BlockCraftEditor.vue';
import '../css/blockcraft.css';
import type { JSONContent } from '@tiptap/core';

/**
 * blockcraft.ts is the ESM entry loaded by every BlockCraft FormWidget
 * instance. It scans the DOM for `[data-control="blockcraft"]` mount points
 * rendered by `formwidgets/blockcraft/partials/_blockcraft.php`, mounts a
 * BlockCraftEditor.vue instance on each, and keeps a hidden `<textarea>` in
 * sync so October's native (non-AJAX) form submission captures the current
 * value - the same "hidden input mirrors the Vue model" approach verified
 * in the VueForge plugin, adapted here to a single fixed component instead
 * of a dynamic per-field component registry.
 */

interface BlockCraftConfig {
    outputFormat: 'html' | 'json';
    height: string;
    buttons: string[] | null;
    readOnly: boolean;
    useMediaManager: boolean;
}

interface MountedInstance {
    app: App;
    el: Element;
}

const mountedInstances = new Map<Element, MountedInstance>();

function parseJsonAttr<T>(raw: string | null, fallback: T): T {
    if (!raw) {
        return fallback;
    }

    try {
        return JSON.parse(raw) as T;
    } catch {
        // Malformed data attributes must never crash the whole backend form.
        return fallback;
    }
}

function mountWidget(el: Element): void {
    if (mountedInstances.has(el)) {
        return;
    }

    const fieldId = el.getAttribute('id');
    const hiddenInput = fieldId ? document.getElementById(`${fieldId}-input`) as HTMLTextAreaElement | null : null;

    if (!hiddenInput) {
        // eslint-disable-next-line no-console
        console.error('BlockCraft: hidden input not found for', el);
        return;
    }

    const config = parseJsonAttr<BlockCraftConfig>(el.getAttribute('data-blockcraft-config'), {
        outputFormat: 'html',
        height: '400px',
        buttons: null,
        readOnly: false,
        useMediaManager: false,
    });

    const rawValue = el.getAttribute('data-blockcraft-value');
    const initialValue: string | JSONContent = config.outputFormat === 'json'
        ? parseJsonAttr<JSONContent>(rawValue, { type: 'doc', content: [] })
        : parseJsonAttr<string>(rawValue, '');

    // Seed the hidden input immediately so a submit before any edit still
    // carries a value in the exact shape getSaveValue() expects.
    hiddenInput.value = config.outputFormat === 'json' ? JSON.stringify(initialValue) : (initialValue as string);

    const app = createApp(BlockCraftEditor, {
        modelValue: initialValue,
        outputFormat: config.outputFormat,
        height: config.height,
        buttons: config.buttons,
        readOnly: config.readOnly || el.hasAttribute('data-blockcraft-readonly'),
        useMediaManager: config.useMediaManager,
        'onUpdate:modelValue': (value: string | JSONContent) => {
            hiddenInput.value = config.outputFormat === 'json' ? JSON.stringify(value) : (value as string);

            // October's own dirty-state tracking listens for native
            // input/change events - a programmatic .value assignment alone
            // doesn't fire them.
            hiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
            hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
        },
    });

    app.mount(el);
    mountedInstances.set(el, { app, el });

    // Tailor/FormController repeaters remove a row's DOM outright on delete
    // rather than firing a dedicated lifecycle event - watch for that so the
    // TipTap instance (destroyed inside BlockCraftEditor's onBeforeUnmount)
    // and this Vue app are cleaned up instead of leaking.
    const observer = new MutationObserver(() => {
        if (!document.body.contains(el)) {
            unmountWidget(el);
            observer.disconnect();
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });
}

function unmountWidget(el: Element): void {
    const mounted = mountedInstances.get(el);

    if (!mounted) {
        return;
    }

    mounted.app.unmount();
    mountedInstances.delete(el);
}

function hydrateAll(root: ParentNode = document): void {
    root.querySelectorAll('[data-control="blockcraft"]').forEach((el) => {
        mountWidget(el);
    });
}

function init(): void {
    hydrateAll();

    // October's Larajax framework (v4.2+) re-renders form partials over AJAX
    // (opening a Repeater group, a RecordFinder popup, an inline relation
    // record) without a full page navigation - `ajax:update-complete` fires
    // once the DOM has been patched. Confirmed in VueForge's own build
    // against Larajax's real type declarations.
    document.addEventListener('ajax:update-complete', () => hydrateAll());

    // Turbo-driven backend navigation (no hard reload) fires this once the
    // new body is in place.
    document.addEventListener('page:updated', () => hydrateAll());
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}

export { hydrateAll, mountWidget, unmountWidget };
