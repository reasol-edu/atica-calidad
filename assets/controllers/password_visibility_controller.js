import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'eyeOpen', 'eyeClosed'];

    toggle(event) {
        const isPassword = this.inputTarget.type === 'password';
        this.inputTarget.type = isPassword ? 'text' : 'password';
        this.eyeOpenTarget.classList.toggle('hidden', isPassword);
        this.eyeClosedTarget.classList.toggle('hidden', !isPassword);
        // Keep aria-pressed in sync with the visible state.
        if (event && event.currentTarget && event.currentTarget.hasAttribute('aria-pressed')) {
            event.currentTarget.setAttribute('aria-pressed', isPassword ? 'true' : 'false');
        }
    }
}
