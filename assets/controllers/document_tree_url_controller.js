import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

// Keeps the browser URL in sync with the section/folder/document being browsed in Árbol
// documental, so reloading or hitting the back/forward button behaves as expected.
//
// SectionBrowserComponent dispatches a "document-tree:location" event (bubbles, on this same
// element) after each navigational action — opening a section, expanding a folder, opening a
// document's revision panel, opening a folder's settings panel — and this controller mirrors that
// into the URL via pushState. Going back/forward re-reads the URL and calls the component's
// syncFromUrl action to restore that state, without pushing a further history entry (that action
// never re-dispatches the event).
export default class extends Controller {
    connect() {
        this.onLocation = (event) => this.pushLocation(event.detail);
        this.element.addEventListener('document-tree:location', this.onLocation);

        this.onPopState = () => this.applyFromUrl();
        window.addEventListener('popstate', this.onPopState);
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

        if (url.href === window.location.href) {
            return;
        }
        window.history.pushState(null, '', url);
    }

    async applyFromUrl() {
        const url = new URL(window.location.href);
        const component = await getComponent(this.element);
        component.action('syncFromUrl', {
            section: url.searchParams.get('section') ?? '',
            folder: url.searchParams.get('folder') ?? '',
            document: url.searchParams.get('document') ?? '',
            settings: url.searchParams.get('settings') ?? '',
        }, 0);
    }

    setOrDelete(params, key, value) {
        if (value) {
            params.set(key, value);
        } else {
            params.delete(key);
        }
    }
}
