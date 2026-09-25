import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

// Keeps the browser URL in sync with the section/folder/document being browsed in the Document
// Tree, so reloading or hitting the back/forward button behaves as expected — and remembers the
// last section browsed (per centre, in this browser), so a plain click on "Árbol documental" in
// the menu returns there instead of always landing on the Root.
//
// SectionBrowserComponent dispatches a "document-tree:location" event (bubbles, on this same
// element) after each navigational action — opening a section, expanding a folder, opening a
// document's revision panel, opening a folder's settings panel — and this controller mirrors that
// into the URL via pushState, and the section into localStorage. Going back/forward re-reads the
// URL and calls the component's syncFromUrl action to restore that state, without pushing a
// further history entry (that action never re-dispatches the event).
export default class extends Controller {
    static values = { centreId: String };

    connect() {
        this.onLocation = (event) => this.pushLocation(event.detail);
        this.element.addEventListener('document-tree:location', this.onLocation);

        this.onPopState = () => this.applyFromUrl();
        window.addEventListener('popstate', this.onPopState);

        this.restoreRememberedSectionIfLandingFresh();
    }

    disconnect() {
        this.element.removeEventListener('document-tree:location', this.onLocation);
        window.removeEventListener('popstate', this.onPopState);
    }

    pushLocation({ section = '', folder = '', document: documentId = '', settings = '' }) {
        const url = new URL(window.location.href);
        this.setOrDelete(url.searchParams, 'section', section);
        this.setOrDelete(url.searchParams, 'folder', folder);
        this.setOrDelete(url.searchParams, 'document', documentId);
        this.setOrDelete(url.searchParams, 'settings', settings);

        this.rememberSection(section);

        if (url.href === window.location.href) {
            return;
        }
        window.history.pushState(null, '', url);
    }

    async applyFromUrl() {
        const url = new URL(window.location.href);
        await this.syncTo({
            section: url.searchParams.get('section') ?? '',
            folder: url.searchParams.get('folder') ?? '',
            document: url.searchParams.get('document') ?? '',
            settings: url.searchParams.get('settings') ?? '',
            // Not written by pushLocation() (the highlight is a one-shot "you just landed here"
            // flash, not a piece of state later navigation should keep re-asserting) — but still
            // read back here so landing on a search result's URL and then going back/forward to it
            // restores the same flash.
            highlight: url.searchParams.get('highlight') ?? '',
        });
    }

    // Only when the URL names no section at all — a plain click on "Árbol documental" in the
    // menu, not a deep link, a search result, or the "Editar árbol" tab's own "?tab=edit" (which
    // never touches "section" either, but never reaches this component to begin with).
    async restoreRememberedSectionIfLandingFresh() {
        if (new URL(window.location.href).searchParams.has('section')) {
            return;
        }
        const section = this.readRememberedSection();
        if (!section) {
            return;
        }
        await this.syncTo({ section, folder: '', document: '', settings: '', highlight: '' });
    }

    async syncTo(location) {
        const component = await getComponent(this.element);
        component.action('syncFromUrl', location, 0);
    }

    rememberSection(section) {
        try {
            if (section === '') {
                window.localStorage.removeItem(this.storageKey());
            } else {
                window.localStorage.setItem(this.storageKey(), section);
            }
        } catch {
            // localStorage not available (private mode, quota...): ignored — worst case, the next
            // fresh landing goes to the Root, exactly like before this existed.
        }
    }

    readRememberedSection() {
        try {
            return window.localStorage.getItem(this.storageKey());
        } catch {
            return null;
        }
    }

    storageKey() {
        return `aticacalidad:document-tree-section:${this.centreIdValue}`;
    }

    setOrDelete(params, key, value) {
        if (value) {
            params.set(key, value);
        } else {
            params.delete(key);
        }
    }
}
