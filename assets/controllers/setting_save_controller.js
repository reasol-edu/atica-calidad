import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

export default class extends Controller {
    async save() {
        const root = this.element.closest('[data-controller~="live"]');
        const component = await getComponent(root);

        // String() avoids Stimulus's JSON typecast: "true"/"false" would arrive
        // at the server as bool and PHP would coerce them to "1"/"".
        component.action('save', {
            key: this.element.dataset.settingKey,
            value: String(this.element.value),
        });
    }
}
