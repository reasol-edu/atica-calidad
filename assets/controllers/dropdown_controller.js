import { Controller } from '@hotwired/stimulus';

// Show/hide a menu next to its button. With `data-dropdown-float-value="true"` the menu is
// positioned against the viewport (position: fixed) every time it opens, instead of being an
// `absolute` child of the button's container: row menus live inside cards with `overflow-hidden`,
// which would otherwise slice off the menu of the last rows. It opens upwards when there is no room
// below, and closes on scroll or resize because it would no longer be next to its button.
export default class extends Controller {
    static targets = ['menu'];
    static values = { float: Boolean };

    connect() {
        this.onOutside = (event) => {
            if (!this.element.contains(event.target)) {
                this.close();
            }
        };
        this.onViewportChange = () => this.close();
        document.addEventListener('click', this.onOutside);
        window.addEventListener('resize', this.onViewportChange);
        window.addEventListener('scroll', this.onViewportChange, true);
    }

    disconnect() {
        document.removeEventListener('click', this.onOutside);
        window.removeEventListener('resize', this.onViewportChange);
        window.removeEventListener('scroll', this.onViewportChange, true);
    }

    toggle() {
        this.menuTarget.classList.toggle('hidden');

        if (this.floatValue && !this.menuTarget.classList.contains('hidden')) {
            this.place();
        }
    }

    close() {
        this.menuTarget.classList.add('hidden');
    }

    place() {
        const menu   = this.menuTarget;
        const anchor = this.element.getBoundingClientRect();
        const margin = 8;

        Object.assign(menu.style, { position: 'fixed', right: 'auto', left: '0px', top: '0px' });
        const box = menu.getBoundingClientRect();

        const left = Math.min(Math.max(margin, anchor.right - box.width), window.innerWidth - box.width - margin);
        const fitsBelow = anchor.bottom + 4 + box.height <= window.innerHeight - margin;
        const top = fitsBelow || anchor.top - 4 - box.height < margin
            ? anchor.bottom + 4
            : anchor.top - 4 - box.height;

        menu.style.left = `${left}px`;
        menu.style.top  = `${top}px`;
    }
}
