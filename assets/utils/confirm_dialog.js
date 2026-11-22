/**
 * The confirmation dialog of the application (the same look and behaviour as the `confirm` Stimulus controller: role="dialog",
 * focus trapped, Escape or a click on the backdrop cancels, focus goes back to where it was) for what is not a form: resolves to true
 * when the person accepts. The button texts are the layout's (`data-confirm-*-value` of <html>).
 *
 * @param {string} message
 * @returns {Promise<boolean>}
 */
export function confirmDialog(message) {
    const values = (document.querySelector('[data-controller~="confirm"]') || document.documentElement).dataset;
    const cancelText = values.confirmCancelValue || 'Cancelar';
    const acceptText = values.confirmConfirmValue || 'Aceptar';
    const closeText = values.confirmCloseValue || cancelText;

    return new Promise((resolve) => {
        const previousFocus = document.activeElement;
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
        cancelBtn.textContent = cancelText;
        cancelBtn.setAttribute('aria-label', closeText);
        acceptBtn.textContent = acceptText;

        overlay.appendChild(dialog);
        document.body.appendChild(overlay);

        const finish = (answer) => {
            document.removeEventListener('keydown', onKeydown, true);
            overlay.remove();
            if (previousFocus && typeof previousFocus.focus === 'function') {
                previousFocus.focus();
            }
            resolve(answer);
        };
        const onKeydown = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                finish(false);
            } else if (event.key === 'Tab') {
                const first = cancelBtn;
                const last = acceptBtn;
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
        };

        cancelBtn.addEventListener('click', () => finish(false));
        acceptBtn.addEventListener('click', () => finish(true));
        overlay.addEventListener('mousedown', (event) => {
            if (event.target === overlay) {
                finish(false);
            }
        });
        document.addEventListener('keydown', onKeydown, true);
        cancelBtn.focus();
    });
}
