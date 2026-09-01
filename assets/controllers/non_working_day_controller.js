import { Controller } from '@hotwired/stimulus';
import { isNonWorkingDate } from '../utils/non_working_days.js';

// Blocks selection of non-working dates (weekends or declared holidays) on
// one or more <input type="date">: reverts to the last valid value and shows
// a notice next to the field. The "input" and "warning" targets are paired
// by position in the DOM (the nth "warning" corresponds to the nth "input").
// Usage: data-controller="non-working-day" on a container with, for each
// date, an <input data-non-working-day-target="input"
// data-action="change->non-working-day#check"> followed by a
// <p data-non-working-day-target="warning">, and
// data-non-working-day-dates-value="[...]" (ISO dates of the academic year's holidays).
export default class extends Controller {
    static targets = ['input', 'warning'];
    static values  = { dates: Array, blockedMessage: String };

    connect() {
        this.lastValid = this.inputTargets.map((input) => input.value);
    }

    check(event) {
        const index = this.inputTargets.indexOf(event.target);
        const input   = this.inputTargets[index];
        const warning = this.warningTargets[index];

        if (!this.isInvalid(input.value)) {
            this.lastValid[index] = input.value;
            this.setWarning(input, warning, false);
            return;
        }

        input.value = this.lastValid[index] ?? '';
        this.setWarning(input, warning, true);
    }

    isInvalid(value) {
        return value !== '' && isNonWorkingDate(value, this.datesValue);
    }

    setWarning(input, warning, active) {
        warning.textContent = active ? this.blockedMessageValue : '';
        warning.classList.toggle('hidden', !active);
        input.classList.toggle('border-red-300', active);
    }
}
