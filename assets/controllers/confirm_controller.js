import { Controller } from '@hotwired/stimulus';

/*
 * Accessible confirmation dialog for destructive actions (delete).
 * Replaces the JS-positioned panel: uses role="dialog", aria-modal,
 * traps focus, closes with Escape or a click on the backdrop, and returns
 * focus to the trigger. Listens via delegation for clicks on
 * ".js-confirm-trigger" inside a ".js-confirm-form" with "data-confirm".
 *
 * The texts are injected from the template via values.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        cancel: String,
        confirm: String,
        close: String,
    };

    connect() {
        this.onTriggerClick = (event) => this.handleTrigger(event);
        this.element.addEventListener('click', this.onTriggerClick);
    }

    disconnect() {
        this.element.removeEventListener('click', this.onTriggerClick);
        this.teardown();
    }

    handleTrigger(event) {
        const trigger = event.target.closest('.js-confirm-trigger');
        if (!trigger || !this.element.contains(trigger)) {
            return;
        }
        const form = trigger.closest('.js-confirm-form');
        if (!form) {
            return;
        }
        // Forms with a Live action are handled by the component's own script
        // (StayDetailComponent), which stops the click's propagation before it
        // reaches here. Here we only handle regular POST forms (including
        // Live listings such as centres or teachers, which have no script of
        // their own and used to be submitted without confirmation).
        if (form.dataset.liveAction) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        this.open(form);
    }

    open(form) {
        if (this.overlay) {
            return;
        }
        this.form = form;
        this.previousFocus = document.activeElement;
        const message = form.dataset.confirm || '';

        const titleId = 'confirm-dialog-title';

        const overlay = document.createElement('div');
        overlay.className = 'fixed inset-0 z-[60] flex items-center justify-center bg-black/50 px-4';

        const dialog = document.createElement('div');
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-labelledby', titleId);
        dialog.className = 'w-full max-w-sm rounded-2xl border border-red-200 bg-white p-5 shadow-xl';
        dialog.innerHTML =
            '<p id="' + titleId + '" class="text-sm leading-snug text-gray-700 mb-4"></p>' +
            '<div class="flex items-center justify-end gap-2">' +
            '  <button type="button" class="js-confirm-cancel rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50 transition-colors"></button>' +
            '  <button type="button" class="js-confirm-accept rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700 transition-colors"></button>' +
            '</div>';

        dialog.querySelector('#' + titleId).textContent = message;
        const cancelBtn = dialog.querySelector('.js-confirm-cancel');
        const acceptBtn = dialog.querySelector('.js-confirm-accept');
        cancelBtn.textContent = this.cancelValue;
        acceptBtn.textContent = form.dataset.confirmLabel || this.confirmValue;
        cancelBtn.setAttribute('aria-label', this.closeValue || this.cancelValue);

        overlay.appendChild(dialog);
        document.body.appendChild(overlay);
        this.overlay = overlay;
        this.dialog = dialog;

        cancelBtn.addEventListener('click', () => this.close());
        acceptBtn.addEventListener('click', () => this.submit());
        overlay.addEventListener('mousedown', (event) => {
            if (event.target === overlay) {
                this.close();
            }
        });

        this.onKeydown = (event) => this.handleKeydown(event);
        document.addEventListener('keydown', this.onKeydown);

        cancelBtn.focus();
    }

    handleKeydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            this.close();
            return;
        }
        if (event.key === 'Tab' && this.dialog) {
            const focusables = this.dialog.querySelectorAll('button');
            if (focusables.length === 0) {
                return;
            }
            const first = focusables[0];
            const last = focusables[focusables.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    }

    submit() {
        const form = this.form;
        this.teardown();
        if (form) {
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        }
    }

    close() {
        const previous = this.previousFocus;
        this.teardown();
        if (previous && typeof previous.focus === 'function') {
            previous.focus();
        }
    }

    teardown() {
        if (this.onKeydown) {
            document.removeEventListener('keydown', this.onKeydown);
            this.onKeydown = null;
        }
        if (this.overlay) {
            this.overlay.remove();
            this.overlay = null;
        }
        this.dialog = null;
        this.form = null;
        this.previousFocus = null;
    }
}
