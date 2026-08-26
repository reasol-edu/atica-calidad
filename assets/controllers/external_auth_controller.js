import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['passwordBlock', 'passwordInput', 'radio'];

    connect() {
        this.#apply();
    }

    toggle() {
        this.#apply();
    }

    #apply() {
        const external = this.radioTargets.find(r => r.checked)?.value === 'external';
        this.passwordBlockTarget.classList.toggle('hidden', external);
        this.passwordInputTarget.disabled = external;
        if (external) {
            this.passwordInputTarget.value = '';
        }
    }
}
