/**
 * Typed wrapper around October CMS's real, global Media Manager popup -
 * `oc.mediaManager.popup` (`modules/media/widgets/mediamanager/assets/js/
 * mediamanager.popup.js`, class `MediaManagerPopup`), confirmed against the
 * actual installed source rather than assumed from the product brief.
 *
 * The brief originally called for a custom `onLoadMediaManager` AJAX
 * handler on the FormWidget - that turned out to be unnecessary. October
 * already bootstraps a single global MediaManager widget instance under the
 * alias `ocmediamanager` for every authenticated backend user with
 * `media.library` access (`Media\ServiceProvider::registerGlobalInstance()`),
 * and any frontend control - Vue or otherwise - can launch it with
 * `new oc.mediaManager.popup({...})`. See PROJECT_PROGRESS.md, Phase 3, for
 * the corrected assumption.
 */

export interface MediaManagerItem {
    itemType: string;
    path: string;
    title: string;
    documentType: string;
    folder: string;
    publicUrl: string;
    thumbUrl: string;
}

interface MediaManagerPopupOptions {
    alias: string;
    mediaFolder?: string | null;
    bottomToolbar?: boolean;
    cropAndInsertButton?: boolean;
    onInsert?: (items: MediaManagerItem[]) => void;
    onClose?: () => void;
}

declare global {
    interface Window {
        oc?: {
            mediaManager?: {
                popup: new (options: MediaManagerPopupOptions) => unknown;
            };
        };
    }
}

/** isMediaManagerAvailable checks whether October's global popup class is loaded. */
export function isMediaManagerAvailable(): boolean {
    return typeof window !== 'undefined' && typeof window.oc?.mediaManager?.popup === 'function';
}

/**
 * openMediaManager launches the Media Manager popup and resolves with the
 * single selected item, or `null` if the popup was closed without a
 * selection. BlockCraft only ever asks for a single image
 * (`cropAndInsertButton: true` also lets an editor crop before inserting).
 */
export function openMediaManager(): Promise<MediaManagerItem | null> {
    return new Promise((resolve) => {
        if (!isMediaManagerAvailable()) {
            resolve(null);
            return;
        }

        let inserted = false;

        // eslint-disable-next-line new-cap
        new window.oc!.mediaManager!.popup({
            alias: 'ocmediamanager',
            cropAndInsertButton: true,
            onInsert(items) {
                inserted = true;
                resolve(items[0] ?? null);
            },
            onClose() {
                if (!inserted) {
                    resolve(null);
                }
            },
        });
    });
}
