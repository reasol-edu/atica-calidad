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
// <input type="file" name="files[N]"> already carries its own explicitly-indexed hidden
// items[N][slotKey] field (rendered server-side, see _activity_submission_row.html.twig), so this
// controller only needs to stage each row's own file and toggle the shared submit button once any
// row has one — no client-side renumbering, the form POSTs natively. The explicit N in both names
// matters: with a plain files[]/items[] pair, PHP renumbers files[] to only the parts actually
// present in the request body, and some browsers omit untouched file inputs from it entirely —
// desyncing which file is "row N" from the hidden field that says what row N actually is.
//
// A bulk zone on top takes several files at once and hands each to the empty row whose name it
// matches ("Matemáticas.pdf" → the "Matemáticas" row), staging it exactly as dropping it on that
// row would — so the server side stays the one it always was. What can't be matched with confidence
// is listed to be placed by hand, in a select of the rows still empty.
export default class extends Controller {
    static targets = ['dropzone', 'input', 'preview', 'submit', 'bulk', 'bulkInput', 'bulkResult'];
    static values  = { matchedLabel: String, unassignedLabel: String, pickLabel: String, noRoomLabel: String };

    #dragDepth = new WeakMap();

    // ── Bulk drop ────────────────────────────────────────────────────────────

    bulkEnter(event) {
        event.preventDefault();
        this.setActive(this.bulkTarget, true);
    }

    bulkOver(event) {
        event.preventDefault();
    }

    bulkLeave(event) {
        event.preventDefault();
        // Leaving for a child of the zone is not leaving it.
        if (!this.bulkTarget.contains(event.relatedTarget)) {
            this.setActive(this.bulkTarget, false);
        }
    }

    bulkDrop(event) {
        event.preventDefault();
        this.setActive(this.bulkTarget, false);
        this.assignFiles([...event.dataTransfer.files]);
    }

    bulkBrowse(event) {
        event.preventDefault();
        this.bulkInputTarget.click();
    }

    bulkChange() {
        this.assignFiles([...this.bulkInputTarget.files]);
        this.bulkInputTarget.value = '';
    }

    /** Stages each file on the empty row it matches by name; lists the rest for choosing by hand. */
    assignFiles(files) {
        const emptyRows = () => this.dropzoneTargets.filter((row) => this.inputFor(row).files.length === 0);
        const matches   = new Map(); // row → file
        const taken     = new Set();

        // Best scores first, so "Lengua extranjera" doesn't go to "Lengua" just by being listed before it.
        const scored = [];
        for (const file of files) {
            for (const row of emptyRows()) {
                const score = this.matchScore(file.name, row.dataset.slotName ?? '');
                if (score > 0) {
                    scored.push({ file, row, score });
                }
            }
        }
        scored.sort((a, b) => b.score - a.score);
        for (const { file, row } of scored) {
            if (!matches.has(row) && !taken.has(file)) {
                matches.set(row, file);
                taken.add(file);
            }
        }

        for (const [row, file] of matches) {
            this.stageFile(row, file);
        }

        this.showResult(matches.size, files.filter((file) => !taken.has(file)));
    }

    /** 0 = no match; higher = better: exact name, then one containing the other, then shared words. */
    matchScore(fileName, slotName) {
        const file = this.normalise(fileName.replace(/\.[^.]+$/, ''));
        const slot = this.normalise(slotName);
        if (file === '' || slot === '') {
            return 0;
        }
        if (file === slot) {
            return 100;
        }
        if (slot.length >= 3 && file.includes(slot)) {
            return 50 + slot.length;
        }
        if (file.length >= 3 && slot.includes(file)) {
            return 40 + file.length;
        }

        const slotWords = slot.split(' ').filter((w) => w.length >= 3);
        const fileWords = new Set(file.split(' '));
        const shared    = slotWords.filter((w) => fileWords.has(w)).length;

        return slotWords.length > 0 && shared / slotWords.length >= 0.6 ? 10 + shared : 0;
    }

    normalise(text) {
        return text
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, ' ')
            .trim();
    }

    showResult(matched, unassigned) {
        const box = this.bulkResultTarget;
        box.replaceChildren();
        box.classList.toggle('hidden', matched === 0 && unassigned.length === 0);

        if (matched > 0) {
            const line = document.createElement('li');
            line.className = 'text-forest-700';
            line.textContent = this.matchedLabelValue.replace('%count%', String(matched));
            box.appendChild(line);
        }

        for (const file of unassigned) {
            box.appendChild(this.unassignedItem(file));
        }
    }

    unassignedItem(file) {
        const item  = document.createElement('li');
        item.className = 'flex flex-wrap items-center gap-2 text-gray-700';

        const name = document.createElement('span');
        name.className = 'min-w-0 truncate';
        name.textContent = `${this.unassignedLabelValue} ${file.name} (${formatFileSize(file.size)})`;
        item.appendChild(name);

        const rows = this.dropzoneTargets.filter((row) => this.inputFor(row).files.length === 0);
        if (rows.length === 0) {
            const none = document.createElement('span');
            none.className = 'text-xs italic text-gray-400';
            none.textContent = this.noRoomLabelValue;
            item.appendChild(none);

            return item;
        }

        const select = document.createElement('select');
        select.className = 'rounded-md border border-gray-200 bg-white py-1 pl-2 pr-6 text-xs';
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = this.pickLabelValue;
        select.appendChild(placeholder);
        rows.forEach((row, index) => {
            const option = document.createElement('option');
            option.value = String(index);
            option.textContent = row.dataset.slotName ?? '';
            select.appendChild(option);
        });
        select.addEventListener('change', () => {
            if (select.value === '') {
                return;
            }
            const row = rows[Number(select.value)];
            if (row && this.inputFor(row).files.length === 0) {
                this.stageFile(row, file);
                item.remove();
                this.refreshPickers();
            }
        });
        item.appendChild(select);

        return item;
    }

    /** A row taken by hand can't be offered again: drop it from the other pickers. */
    refreshPickers() {
        const taken = new Set(this.dropzoneTargets.filter((row) => this.inputFor(row).files.length > 0).map((row) => row.dataset.slotName));
        for (const option of this.bulkResultTarget.querySelectorAll('select option[value]:not([value=""])')) {
            option.hidden = taken.has(option.textContent);
        }
    }

    // ── One row at a time ────────────────────────────────────────────────────

    /** Enter / Space on a focused row opens its file chooser, like clicking it. */
    rowKey(event) {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            this.triggerBrowse(event);
        }
    }

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
