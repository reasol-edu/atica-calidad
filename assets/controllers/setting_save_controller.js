import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

export default class extends Controller {
    async save() {
        const root = this.element.closest('[data-controller~="live"]');
        const component = await getComponent(root);

        // String() evita el typecast JSON de Stimulus: "true"/"false" llegarían
        // al servidor como bool y PHP los coercionaría a "1"/"".
        component.action('save', {
            key: this.element.dataset.settingKey,
            value: String(this.element.value),
        });
    }
}
