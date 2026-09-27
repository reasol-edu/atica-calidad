import { Controller } from '@hotwired/stimulus';

// Highlights whichever radio's label is currently checked in a group of "radio cards" (see the
// calendar generator's page-orientation picker, templates/utilities/calendar_generator/
// form.html.twig) — purely visual. Combine with another controller on the same element via
// data-action for a choice that also drives something else (see the period's mode picker,
// printable_calendar_period_controller.js, which shows/hides fields based on the same radios).
const ACTIVE_CLASSES   = ['border-forest-500', 'bg-forest-50', 'text-forest-800'];
const INACTIVE_CLASSES = ['border-gray-200', 'text-gray-600', 'hover:bg-gray-50'];

export default class extends Controller {
    static targets = ['option'];

    connect() {
        this.update();
    }

    update() {
        for (const radio of this.optionTargets) {
            const label = radio.closest('label');
            label?.classList.remove(...(radio.checked ? INACTIVE_CLASSES : ACTIVE_CLASSES));
            label?.classList.add(...(radio.checked ? ACTIVE_CLASSES : INACTIVE_CLASSES));
        }
    }
}
