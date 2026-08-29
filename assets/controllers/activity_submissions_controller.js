import { Controller } from '@hotwired/stimulus';

const SIZE_UNITS = ['B', 'KiB', 'MiB', 'GiB'];

function formatFileSize(bytes) {
    if (bytes <= 0) {
        return `0 ${SIZE_UNITS[0]}`;
    }

    const exponent = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), SIZE_UNITS.length - 1);
    const value    = bytes / (1024 ** exponent);
    const decimals = exponent === 0 ? 0 : 1;

    return `${value.toFixed(decimals).replace('.', ',')} ${SIZE_UNITS[exponent]}`;
}

// Several independent single-file dropzones (one per empty entrega row) sharing one "Enviar
// entregas" submit button — unlike file_drop_controller (one dropzone per form), here each row's
// <input type="file" name="files[]"> already carries its own positionally-matched hidden
// items[N][slotKey] field (rendered server-side, see _activity_submission_row.html.twig), so this
// controller only needs to stage each row's own file and toggle the shared submit button once any
// row has one — no client-side renumbering, the form POSTs natively.
export default class extends Controller {
    static targets = ['dropzone', 'input', 'preview', 'submit'];

    #dragDepth = new WeakMap();

    dragEnter(event) {
        event.preventDefault();
        const el    = event.currentTarget;
        const depth = (this.#dragDepth.get(el) ?? 0) + 1;
        this.#dragDepth.set(el, depth);
        this.setActive(el, true);
    }

    dragOver(event) {
        event.preventDefault();
    }

    dragLeave(event) {
        event.preventDefault();
        const el    = event.currentTarget;
        const depth = Math.max(0, (this.#dragDepth.get(el) ?? 0) - 1);
        this.#dragDepth.set(el, depth);
        if (depth === 0) {
            this.setActive(el, false);
        }
    }

    drop(event) {
        event.preventDefault();
        const el = event.currentTarget;
        this.#dragDepth.set(el, 0);
        this.setActive(el, false);

        const [file] = event.dataTransfer.files;
        if (file) {
            this.stageFile(el, file);
        }
    }

    triggerBrowse(event) {
        this.inputFor(event.currentTarget).click();
    }

    change(event) {
        const input = event.target;
        const row   = input.closest('[data-role="submission-row"]');
        const [file] = input.files;
        if (row && file) {
            this.updatePreview(row, file);
        }
        this.updateSubmitVisibility();
    }

    stageFile(dropzone, file) {
        const input     = this.inputFor(dropzone);
        const transfer  = new DataTransfer();
        transfer.items.add(file);
        input.files = transfer.files;
        this.updatePreview(dropzone, file);
        this.updateSubmitVisibility();
    }

    inputFor(row) {
        return row.querySelector('input[type="file"]');
    }

    updatePreview(row, file) {
        const preview = row.querySelector('[data-activity-submissions-target="preview"]');
        if (preview) {
            preview.textContent = `${file.name} (${formatFileSize(file.size)})`;
        }
    }

    updateSubmitVisibility() {
        if (!this.hasSubmitTarget) {
            return;
        }

        const anyStaged = this.inputTargets.some((input) => input.files.length > 0);
        // Toggling only "hidden" is unreliable — Tailwind's generated stylesheet order (not HTML
        // class order) decides which of two conflicting `display` utilities wins, so `hidden` and
        // `inline-flex` sitting in the same class list can silently fight. Add/remove both together.
        this.submitTarget.classList.toggle('hidden', !anyStaged);
        this.submitTarget.classList.toggle('inline-flex', anyStaged);
    }

    setActive(dropzone, active) {
        dropzone.classList.toggle('border-forest-400', active);
        dropzone.classList.toggle('bg-forest-50/50', active);
        dropzone.classList.toggle('border-gray-200', !active);
    }
}
