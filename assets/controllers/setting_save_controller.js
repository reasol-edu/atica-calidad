import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

export default class extends Controller {
    // Only a day/month setting uses these: a wrapper around two selects, rather than the
    // single input/select this controller is otherwise attached to directly.
    static targets = ['day', 'month'];
    static values = { defaultMonth: String };

    async save(event) {
        const root = this.element.closest('[data-controller~="live"]');
        const component = await getComponent(root);

        // String() avoids Stimulus's JSON typecast: "true"/"false" would arrive
        // at the server as bool and PHP would coerce them to "1"/"".
        component.action('save', {
            key: this.element.dataset.settingKey,
            value: String(this.hasDayTarget ? this.dayMonthValue(event?.target) : this.element.value),
        });
    }

    // "MM-DD", or "__default__" when the month select is back on its default option and the
    // day select wasn't the one changed. The server validates the combination (31/04 → error).
    dayMonthValue(changed) {
        let month = this.monthTarget.value;
        if (month === '__default__') {
            if (changed !== this.dayTarget) {
                return '__default__';
            }
            month = this.defaultMonthValue;
        }

        return `${month.padStart(2, '0')}-${this.dayTarget.value.padStart(2, '0')}`;
    }
}
