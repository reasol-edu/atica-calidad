import { Controller } from '@hotwired/stimulus';

// A "select/deselect all" toggle for a group of checkboxes, scoped to whichever element carries
// this controller — several independent groups on the same page (e.g. this year's pending
// reviews and an earlier year's) each get their own instance and their own toggle button.
export default class extends Controller {
    static targets = ['checkbox'];

    toggle() {
        const shouldCheck = !this.checkboxTargets.every((checkbox) => checkbox.checked);
        this.checkboxTargets.forEach((checkbox) => {
            checkbox.checked = shouldCheck;
        });
    }
}
