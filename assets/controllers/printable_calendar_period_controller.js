import { Controller } from '@hotwired/stimulus';

// One period card of the calendar generator's form (templates/utilities/calendar_generator/
// form.html.twig): shows only the fields for the mode radio currently checked (a plain date
// range, or one end plus a Monday-Friday hours pattern). Purely presentational — the server
// re-validates everything regardless of what the client showed or hid. The radios' own active/
// inactive card highlighting is a separate, reusable concern — see radio_card_controller.js,
// combined on the same fieldset via a second data-action on each radio.
export default class extends Controller {
    static targets = ['mode', 'fields'];

    connect() {
        this.update();
    }

    update() {
        const selected = this.modeTargets.find((radio) => radio.checked)?.value;
        for (const block of this.fieldsTargets) {
            const active = block.dataset.printableCalendarPeriodMode === selected;
            block.hidden = !active;
            // Several blocks share field names (startDate, totalHours...) so only one set reaches
            // the server — a hidden block's fields would otherwise still submit their stale value.
            for (const field of block.querySelectorAll('input, select, textarea')) {
                field.disabled = !active;
            }
        }
    }
}
