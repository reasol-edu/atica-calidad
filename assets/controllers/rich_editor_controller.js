import { Controller } from '@hotwired/stimulus';
import Quill from 'quill';
import 'quill/dist/quill.snow.css';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['editor', 'input'];
    static values  = { placeholder: String };

    connect() {
        this.quill = new Quill(this.editorTarget, {
            theme: 'snow',
            placeholder: this.placeholderValue,
            formats: ['header', 'bold', 'italic', 'underline', 'blockquote', 'list', 'link'],
            modules: {
                toolbar: [
                    [{ header: [2, false] }],
                    ['bold', 'italic', 'underline'],
                    ['blockquote'],
                    [{ list: 'ordered' }, { list: 'bullet' }],
                    ['link'],
                    ['clean'],
                ],
            },
        });

        const stored = this.inputTarget.value.trim();
        if (stored) {
            this.quill.clipboard.dangerouslyPasteHTML(stored);
        }

        this.lastDispatched = this.inputTarget.value;

        this.quill.on('text-change', (delta, oldDelta, source) => {
            const html = this.quill.root.innerHTML;
            this.inputTarget.value = html === '<p><br></p>' ? '' : html;
            // La normalización inicial de Quill (source 'api') no cuenta como
            // edición: solo los cambios del usuario deben disparar 'change'.
            if (source !== 'user') {
                this.lastDispatched = this.inputTarget.value;
            }
        });

        // Al perder el foco, el textarea oculto se comporta como un input
        // nativo: emite 'change' si el usuario modificó el contenido.
        this.quill.on('selection-change', (range) => {
            if (range === null && this.inputTarget.value !== this.lastDispatched) {
                this.lastDispatched = this.inputTarget.value;
                this.inputTarget.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    }

    disconnect() {
        if (this.quill) {
            this.quill = null;
        }
    }
}
