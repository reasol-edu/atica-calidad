import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

/*
 * "Go to page" field for Live Components pagination. Reads the entered
 * number, clamps it to [1, max] and dispatches the Live `setPage` action on
 * the component wrapping this control.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['input'];
    static values = { max: Number };

    async go(event) {
        event.preventDefault();
        const raw = parseInt(this.inputTarget.value, 10);
        if (Number.isNaN(raw)) {
            return;
        }
        const page = Math.min(Math.max(raw, 1), this.maxValue);
        this.inputTarget.value = String(page);

        const root = this.element.closest('[data-controller~="live"]');
        if (!root) {
            return;
        }
        const component = await getComponent(root);
        component.action('setPage', { page });
    }
}
