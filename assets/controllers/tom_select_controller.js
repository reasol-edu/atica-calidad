import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';

// Turns a <select> (single or multiple) into a dropdown with autocomplete: clicking it opens
// all the options without needing to type anything, and typing filters the list. The options
// come from the <select> itself (its <option> elements), not fetched over the network — see the
// "forest" theme for TomSelect in assets/styles/app.css.
export default class extends Controller {
    static values = { placeholder: String, noResults: String };

    connect() {
        const noResultsText = this.noResultsValue;

        this.tomSelect = new TomSelect(this.element, {
            create: false,
            maxOptions: null,
            placeholder: this.placeholderValue || undefined,
            // Rendered onto <body> instead of nested under the control: this controller is used
            // inside cards with `overflow-hidden` (e.g. the folder settings panel), which would
            // otherwise clip the open dropdown.
            dropdownParent: 'body',
            plugins: this.element.multiple ? ['remove_button', 'checkbox_options'] : [],
            render: noResultsText
                ? { no_results: () => `<div class="no-results px-3 py-2 text-sm">${noResultsText}</div>` }
                : {},
        });
    }

    disconnect() {
        this.tomSelect?.destroy();
        this.tomSelect = null;
    }
}
