import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'eyeOpen', 'eyeClosed'];

    toggle(event) {
        const isPassword = this.inputTarget.type === 'password';
        this.inputTarget.type = isPassword ? 'text' : 'password';
        this.eyeOpenTarget.classList.toggle('hidden', isPassword);
        this.eyeClosedTarget.classList.toggle('hidden', !isPassword);
        // Mantener aria-pressed sincronizado con el estado visible.
        if (event && event.currentTarget && event.currentTarget.hasAttribute('aria-pressed')) {
            event.currentTarget.setAttribute('aria-pressed', isPassword ? 'true' : 'false');
        }
    }
}
