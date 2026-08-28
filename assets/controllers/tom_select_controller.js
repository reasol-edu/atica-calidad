import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';

// Convierte un <select> (simple o múltiple) en un desplegable con autocompletar: al hacer clic se
// despliegan todas las opciones sin necesidad de escribir nada, y escribir filtra la lista. Las
// opciones salen del propio <select> (sus <option>), no se piden por red — ver el tema "forest"
// para TomSelect en assets/styles/app.css.
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
