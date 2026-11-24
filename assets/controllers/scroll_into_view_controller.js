import { Controller } from '@hotwired/stimulus';

// Scrolls this element into view once it connects — attached only to the document row a search
// result (tree-wide search, ⌘K) just landed on, so it's visible even if the pulse highlight
// (see .document-highlight-pulse in app.css) would otherwise land off-screen. Fires both on a
// normal page load and when a LiveComponent DOM morph adds this controller to an existing row
// (e.g. picking a different search result while already on the page) — Stimulus reconnects a
// controller whenever its name appears in a data-controller attribute, even via a mutation on an
// element that already existed.
//
// With data-scroll-into-view-focus-value="true" the element also takes keyboard focus (it must be
// focusable, tabindex="0"): the empty submission row an activity link lands on, so Enter opens the
// file chooser right away — a browser won't open it on its own, it needs a user gesture.
//
// data-scroll-into-view-block-value="start" puts its top at the top instead of centring it: an activity card a search landed on,
// which can be taller than the screen.
export default class extends Controller {
    static values = { focus: Boolean, block: { type: String, default: 'center' } };

    connect() {
        this.element.scrollIntoView({ behavior: 'smooth', block: this.blockValue });
        if (this.focusValue) {
            this.element.focus({ preventScroll: true });
        }
    }
}
