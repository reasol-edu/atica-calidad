import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';

// The condition of an indicator computed from documents ("count / percent of those that are …"): when the field it reads is a
// choice, the value is picked from a searchable list of its options (typing the option's internal key would be impossible); for any
// other field it stays a free text. The options come from the page ("activity-id|field-key" → [{ key, label }]).
export default class extends Controller {
    static targets = ['field', 'input'];
    static values = { options: Object, placeholder: String };

    connect() {
        this.select = null;
        this.tomSelect = null;
        this.sync();
    }

    disconnect() {
        this.clear();
    }

    sync() {
        const options = this.optionsValue[this.fieldTarget.value] || [];
        if (options.length === 0) {
            this.clear();
            return;
        }
        this.clear();
        const current = this.inputTarget.value;
        this.select = document.createElement('select');
        this.select.name = this.inputTarget.name;
        // The look comes from Tom Select's own control; copying the text box's classes would draw a second border around it.
        this.select.className = 'w-full';
        this.select.setAttribute('aria-label', this.inputTarget.getAttribute('aria-label') || '');
        this.select.dataset.testid = 'condition-choice';
        const blank = document.createElement('option');
        blank.value = '';
        blank.textContent = '—';
        this.select.append(blank);
        options.forEach((o) => {
            const el = document.createElement('option');
            el.value = o.key;
            el.textContent = o.label;
            el.selected = o.key === current;
            this.select.append(el);
        });
        this.inputTarget.after(this.select);
        // The text box stays in the page (hidden, not sent) so it can come back when another field is chosen.
        this.inputTarget.disabled = true;
        this.inputTarget.classList.add('hidden');
        this.tomSelect = new TomSelect(this.select, { create: false, maxOptions: null, dropdownParent: 'body' });
    }

    clear() {
        this.tomSelect?.destroy();
        this.tomSelect = null;
        this.select?.remove();
        this.select = null;
        if (this.hasInputTarget) {
            this.inputTarget.disabled = false;
            this.inputTarget.classList.remove('hidden');
        }
    }
}
