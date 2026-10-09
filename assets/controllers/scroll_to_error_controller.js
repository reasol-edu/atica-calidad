import { Controller } from '@hotwired/stimulus';

// The activity form is long: when a save fails, scroll to its first error message. The server
// bumps `token` on every save attempt (and only then — typing re-renders the form without
// changing it), so this fires once per attempt, after the LiveComponent has morphed the errors in.
// A successful save closes the form, so there is nothing left to scroll to.
export default class extends Controller {
    static values = { token: Number };

    tokenValueChanged(value, previous) {
        if (previous === undefined || value === previous) {
            return;
        }
        // Wait for the morph that carries the new errors to finish (a timer, not requestAnimationFrame,
        // which a background tab never runs).
        setTimeout(() => {
            const error = this.element.querySelector('p.text-red-600');
            if (error) {
                error.scrollIntoView({ block: 'center' });
            }
        }, 50);
    }
}
