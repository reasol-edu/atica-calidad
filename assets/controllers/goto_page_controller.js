import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

/*
 * Campo «ir a página» de la paginación de los Live Components. Lee el número
 * introducido, lo acota a [1, max] y dispara la acción Live `setPage` sobre el
 * componente que envuelve este control.
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
