import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

// Keeps the browser URL's "category" query param in sync with the category being browsed in
// Actividades → Ver, so the browser's back/forward buttons work — scoped to just category
// navigation, not the rest of the screen's transient state (which activity's dropzone is
// expanded, etc.), which the URL never tracks here. Mirrors document_tree_url_controller.js for
// the document tree, but for this single param instead of a shared multi-field location.
//
// ActivityBrowserComponent dispatches an "activity-category:location" event (bubbles, on this
// same element) after openLevel(), and this controller mirrors it into the URL via pushState.
// Going back/forward re-reads the URL and calls the component's syncCategoryFromUrl action,
// without pushing a further history entry (that action never re-dispatches the event).
export default class extends Controller {
    connect() {
        this.onLocation = (event) => this.pushLocation(event.detail);
        this.element.addEventListener('activity-category:location', this.onLocation);

        this.onPopState = () => this.applyFromUrl();
        window.addEventListener('popstate', this.onPopState);
    }

    disconnect() {
        this.element.removeEventListener('activity-category:location', this.onLocation);
        window.removeEventListener('popstate', this.onPopState);
    }

    pushLocation({ category = '' }) {
        const url = new URL(window.location.href);
        if (category) {
            url.searchParams.set('category', category);
        } else {
            url.searchParams.delete('category');
        }
        // Browsing categories supersedes a deep link to one specific activity (or a search
        // result's one-shot highlight) — both were only ever meant for the page's initial load.
        url.searchParams.delete('activity');
        url.searchParams.delete('highlight');

        if (url.href === window.location.href) {
            return;
        }
        window.history.pushState(null, '', url);
    }

    async applyFromUrl() {
        const url = new URL(window.location.href);
        const component = await getComponent(this.element);
        component.action('syncCategoryFromUrl', {
            category: url.searchParams.get('category') ?? '',
        }, 0);
    }
}
