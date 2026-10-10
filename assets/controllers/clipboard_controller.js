import { Controller } from '@hotwired/stimulus';

/*
 * Copies the text of a field to the clipboard and flips the button's label for a moment.
 * Falls back to selecting the field when the Clipboard API isn't available (plain http).
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['source', 'label'];
    static values = { done: String };

    async copy() {
        const text = this.sourceTarget.value;
        try {
            await navigator.clipboard.writeText(text);
        } catch {
            this.sourceTarget.select();
            document.execCommand('copy');
        }

        if (this.hasLabelTarget && this.doneValue) {
            const original = this.labelTarget.textContent;
            this.labelTarget.textContent = this.doneValue;
            setTimeout(() => { this.labelTarget.textContent = original; }, 2000);
        }
    }
}
