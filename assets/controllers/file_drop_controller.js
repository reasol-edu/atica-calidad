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

// Drag-and-drop zone for attaching files. Syncs dropped or selected files
// with the native <input type="file"> via DataTransfer (the input is kept
// as an accessible fallback: click or keyboard open the system's native
// picker) and shows a preview where each file can be removed before
// submitting the form.
export default class extends Controller {
    static targets = ['dropzone', 'input', 'list', 'itemTemplate', 'clientError', 'submit'];
    static values  = { maxSize: Number, tooLargeMessage: String, single: Boolean };

    connect() {
        this.dragDepth = 0;
        this.render();
    }

    dragEnter(event) {
        event.preventDefault();
        this.dragDepth++;
        this.setActive(true);
    }

    dragOver(event) {
        event.preventDefault();
    }

    dragLeave(event) {
        event.preventDefault();
        this.dragDepth = Math.max(0, this.dragDepth - 1);
        if (this.dragDepth === 0) {
            this.setActive(false);
        }
    }

    drop(event) {
        event.preventDefault();
        this.dragDepth = 0;
        this.setActive(false);
        this.addFiles(event.dataTransfer.files);
    }

    triggerBrowse() {
        this.inputTarget.click();
    }

    change() {
        this.render();
    }

    removeFile(event) {
        const index = Number(event.params.index);
        this.assignFiles(Array.from(this.inputTarget.files).filter((_, i) => i !== index));
        this.render();
    }

    addFiles(fileList) {
        if (this.singleValue) {
            // Single-file mode (e.g. PDF templates in settings): a newly
            // dropped or selected file replaces the previous one, it does not accumulate.
            const [file] = fileList;
            if (file) {
                this.assignFiles([file]);
            }
            this.render();
            return;
        }

        const existing = Array.from(this.inputTarget.files);
        const incoming = Array.from(fileList).filter((file) => !existing.some(
            (current) => current.name === file.name && current.size === file.size && current.lastModified === file.lastModified,
        ));

        this.assignFiles([...existing, ...incoming]);
        this.render();
    }

    assignFiles(files) {
        const transfer = new DataTransfer();
        files.forEach((file) => transfer.items.add(file));
        this.inputTarget.files = transfer.files;
    }

    setActive(active) {
        this.dropzoneTarget.classList.toggle('border-forest-400', active);
        this.dropzoneTarget.classList.toggle('bg-forest-50/50', active);
        this.dropzoneTarget.classList.toggle('border-gray-200', !active);
        this.dropzoneTarget.classList.toggle('bg-gray-50', !active);
    }

    render() {
        const files = Array.from(this.inputTarget.files);
        // Rows for the per-file version/profile fields (folder uploads) get torn down and rebuilt
        // below on every add/remove — capture whatever the user already picked, keyed by file
        // identity, so editing one file's fields doesn't get lost when another file is added or
        // removed.
        const previousValues = this.captureValues();

        this.listTarget.innerHTML = '';
        files.forEach((file, index) => this.listTarget.appendChild(this.buildItem(file, index, previousValues)));

        const oversized = files.find((file) => file.size > this.maxSizeValue);
        this.clientErrorTarget.textContent = oversized ? this.tooLargeMessageValue.replace('%filename%', oversized.name) : '';
        this.clientErrorTarget.classList.toggle('hidden', !oversized);

        if (this.hasSubmitTarget) {
            // Toggling only "hidden" is unreliable here: Tailwind's generated stylesheet order
            // (not class-attribute order) decides which of two conflicting `display` utilities
            // wins, so `hidden` and `inline-flex` sitting in the same class list can silently
            // fight each other. Add/remove both together instead.
            const show = files.length > 0;
            this.submitTarget.classList.toggle('hidden', !show);
            this.submitTarget.classList.toggle('inline-flex', show);
        }
    }

    fileKey(file) {
        return `${file.name}::${file.size}::${file.lastModified}`;
    }

    captureValues() {
        const map = new Map();
        this.listTarget.querySelectorAll('[data-file-key]').forEach((row) => {
            const versionInput  = row.querySelector('[data-role="version-input"]');
            const profileSelect = row.querySelector('[data-role="profile-select"]');
            map.set(row.dataset.fileKey, {
                version: versionInput?.value,
                profileKey: profileSelect?.value,
            });
        });

        return map;
    }

    buildItem(file, index, previousValues = new Map()) {
        const fragment = this.itemTemplateTarget.content.cloneNode(true);
        const key       = this.fileKey(file);
        const saved     = previousValues.get(key);
        const root      = fragment.firstElementChild;
        if (root) {
            root.dataset.fileKey = key;
        }

        const nameSpan = fragment.querySelector('[data-role="name"]');
        if (nameSpan) {
            nameSpan.textContent = file.name;
        }

        const versionInput = fragment.querySelector('[data-role="version-input"]');
        if (versionInput) {
            versionInput.name = `items[${index}][version]`;
            if (saved?.version) {
                versionInput.value = saved.version;
            }
        }

        const profileSelect = fragment.querySelector('[data-role="profile-select"]');
        if (profileSelect) {
            profileSelect.name = `items[${index}][profileKey]`;
            if (saved?.profileKey) {
                profileSelect.value = saved.profileKey;
            }
        }

        fragment.querySelector('[data-role="size"]').textContent = formatFileSize(file.size);
        fragment.querySelector('[data-action*="removeFile"]').dataset.fileDropIndexParam = String(index);

        return fragment;
    }
}
