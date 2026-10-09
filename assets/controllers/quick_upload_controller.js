import { Controller } from '@hotwired/stimulus';

/*
 * A one-file upload from a list row: the button opens the file chooser and choosing a file sends the
 * form right away (the server revalidates everything and flashes the outcome).
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['input'];

    choose() {
        this.inputTarget.click();
    }

    send() {
        if (this.inputTarget.files.length > 0) {
            this.element.requestSubmit();
        }
    }
}
