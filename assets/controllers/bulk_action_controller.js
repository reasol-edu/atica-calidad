import { Controller } from '@hotwired/stimulus';

/*
 * Shows only the fields of the chosen bulk change and keeps the others disabled, so the form sends
 * just what the chosen action needs.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['action', 'panel'];

    connect() {
        this.show();
    }

    show() {
        const chosen = this.actionTarget.value;
        this.panelTargets.forEach((panel) => {
            const active = panel.dataset.panel === chosen;
            panel.classList.toggle('hidden', !active);
            panel.querySelectorAll('input, select').forEach((field) => {
                field.disabled = !active;
            });
        });
    }
}
