import { describe, it, expect, afterEach } from 'vitest';
import { hydrateAll } from '../../assets/js/blockcraft';

/**
 * Regression coverage for the repeater-add hydration bug (confirmed
 * 2026-09-22 against a real browser session): October's native repeater
 * "Add Item" action never fires `ajax:update-complete` or `page:updated` -
 * the only two events blockcraft.ts originally listened for - because its
 * response is handled entirely through Larajax's ops-based
 * `patchDom`/`loadAssets` path, which never calls the framework's
 * `notifyApplicationUpdateComplete()`. The new mount point landed in the
 * DOM but was never hydrated. `ajax:done` fires for every completed AJAX
 * request regardless of path and is the fix; these tests simulate that
 * exact sequence (a mount point appearing in the DOM outside of the
 * module's own initial `hydrateAll()` call, followed by an `ajax:done`
 * dispatch) rather than mocking October's AJAX framework itself.
 */

async function waitFor(predicate: () => boolean, attempts = 20): Promise<void> {
    for (let attempt = 0; attempt < attempts; attempt++) {
        if (predicate()) {
            return;
        }
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
    throw new Error('waitFor: condition never became true');
}

function appendMountPoint(id: string): HTMLElement {
    const container = document.createElement('div');
    container.innerHTML = `
        <div id="${id}" class="blockcraft-widget" data-control="blockcraft"
            data-blockcraft-config='{"outputFormat":"html","height":"200px","buttons":null,"readOnly":false,"useMediaManager":false}'
            data-blockcraft-value='""'>
        </div>
        <textarea id="${id}-input" data-blockcraft-hidden-input style="display:none"></textarea>
    `;
    document.body.appendChild(container);
    return container;
}

describe('blockcraft.ts hydrator', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('mounts a widget added to the DOM after an ajax:done event, not just on initial load', async () => {
        // Simulates a repeater row inserted by October's real "Add Item"
        // action, whose response never fires ajax:update-complete/page:updated.
        const container = appendMountPoint('BlockCraft-repeater-row0-content');

        expect(container.querySelector('.ProseMirror')).toBeNull();

        document.dispatchEvent(new Event('ajax:done'));

        await waitFor(() => container.querySelector('.ProseMirror') !== null);

        expect(container.querySelector('.ProseMirror')).not.toBeNull();
    });

    it('does not double-mount an already-hydrated widget on a second ajax:done', async () => {
        const container = appendMountPoint('BlockCraft-repeater-row1-content');

        document.dispatchEvent(new Event('ajax:done'));
        await waitFor(() => container.querySelector('.ProseMirror') !== null);

        const mountEl = container.querySelector('[data-control="blockcraft"]') as HTMLElement;
        const firstProseMirror = mountEl.querySelector('.ProseMirror');

        // A second ajax:done (e.g. an unrelated request elsewhere on the
        // page) must be a no-op for content that's already mounted -
        // mountWidget()'s own mountedInstances guard is what makes calling
        // hydrateAll() on every ajax:done safe to do at all.
        document.dispatchEvent(new Event('ajax:done'));
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(mountEl.querySelector('.ProseMirror')).toBe(firstProseMirror);
        expect(mountEl.children.length).toBe(1);
    });

    it('still mounts widgets present at initial load via hydrateAll()', async () => {
        const container = appendMountPoint('BlockCraft-initial-content');

        hydrateAll();

        await waitFor(() => container.querySelector('.ProseMirror') !== null);

        expect(container.querySelector('.ProseMirror')).not.toBeNull();
    });
});
