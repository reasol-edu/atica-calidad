import { Controller } from '@hotwired/stimulus';

// Scrolls this element into view once it connects — attached only to the document row a search
// result (tree-wide search, ⌘K) just landed on, so it's visible even if the pulse highlight
// (see .document-highlight-pulse in app.css) would otherwise land off-screen. Fires both on a
// normal page load and when a LiveComponent DOM morph adds this controller to an existing row
// (e.g. picking a different search result while already on the page) — Stimulus reconnects a
// controller whenever its name appears in a data-controller attribute, even via a mutation on an
// element that already existed.
export default class extends Controller {
    connect() {
        this.element.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}
