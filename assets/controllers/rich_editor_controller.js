import { Controller } from '@hotwired/stimulus';
import { mountRichText } from '../utils/rich_text_editor.js';

/**
 * A rich text field: the Tiptap editor (assets/utils/rich_text_editor.js) over a hidden textarea that keeps the HTML, so the form (or a
 * settings row with setting-save) sends it like any other input.
 *
 * Targets: `editor` (where it is drawn), `input` (the hidden textarea). Values: `placeholder`.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['editor', 'input'];
    static values  = { placeholder: String };

    connect() {
        this.lastDispatched = this.inputTarget.value;

        this.rich = mountRichText(this.editorTarget, {
            html: this.inputTarget.value.trim(),
            placeholder: this.placeholderValue,
            onChange: (html) => {
                this.inputTarget.value = html;
            },
            // On blur, the hidden textarea behaves like a native input: it emits 'change' if the content was modified.
            onBlur: () => {
                if (this.inputTarget.value !== this.lastDispatched) {
                    this.lastDispatched = this.inputTarget.value;
                    this.inputTarget.dispatchEvent(new Event('change', { bubbles: true }));
                }
            },
        });
    }

    disconnect() {
        this.rich?.destroy();
        this.rich = null;
    }
}
