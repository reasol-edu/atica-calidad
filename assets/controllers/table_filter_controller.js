import { Controller } from '@hotwired/stimulus';

/**
 * Filters the rows of a table by what is typed: a row stays if its text contains it (accents and case ignored).
 * Targets: `input` (the search box) and `row` (each <tr> to show or hide).
 */
export default class extends Controller {
    static targets = ['input', 'row'];

    filter() {
        const norm = (s) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
        const term = norm(this.inputTarget.value.trim());
        this.rowTargets.forEach((row) => {
            row.hidden = term !== '' && !norm(row.textContent).includes(term);
        });
    }
}
