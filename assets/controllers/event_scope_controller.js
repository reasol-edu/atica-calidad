import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['profilesBlock', 'radio'];

    connect() {
        this.#apply();
    }

    toggle() {
        this.#apply();
    }

    #apply() {
        const restricted = this.radioTargets.find(r => r.checked)?.value === 'restricted';
        this.profilesBlockTarget.classList.toggle('hidden', !restricted);
    }
}
