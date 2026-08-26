import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['menu'];

    connect() {
        this.onOutside = (event) => {
            if (!this.element.contains(event.target)) {
                this.close();
            }
        };
        document.addEventListener('click', this.onOutside);
    }

    disconnect() {
        document.removeEventListener('click', this.onOutside);
    }

    toggle() {
        this.menuTarget.classList.toggle('hidden');
    }

    close() {
        this.menuTarget.classList.add('hidden');
    }
}
