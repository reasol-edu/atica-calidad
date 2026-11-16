import { Controller } from '@hotwired/stimulus';

// A tab bar that scrolls on its own on a narrow screen (the "Mejora continua" sections): brings the current tab
// (`aria-current="page"`) into view, so on a phone you do not land on a page whose tab is off-screen. It moves only this
// element's horizontal scroll — never the page — so it does not fight the browser's own scroll restoration.
export default class extends Controller {
    connect() {
        const current = this.element.querySelector('[aria-current="page"]');
        if (!current) {
            return;
        }

        const target = current.offsetLeft - (this.element.clientWidth - current.offsetWidth) / 2;
        this.element.scrollLeft = Math.max(0, target);
    }
}
