// Accessible-name safety net for markup that is generated in hundreds of places (and re-rendered by Live Components):
//  · icon-only buttons/links that only carry a `title` (not shown on touch screens, unreliable in screen readers) get the
//    same text as `aria-label`;
//  · visible fields without a label get their `title` or `placeholder` as `aria-label`;
//  · `<th>` in a header row/column gets `scope`.
// It never overrides an explicit aria-label/aria-labelledby or an associated <label>. Templates should still prefer a real
// label where the design allows it.

const FIELD_SELECTOR = 'input:not([type=hidden]):not([type=submit]):not([type=button]):not([type=checkbox]):not([type=radio]), select, textarea';

function fixIconButtons(root) {
    root.querySelectorAll('a[title], button[title]').forEach((el) => {
        if (el.hasAttribute('aria-label') || el.hasAttribute('aria-labelledby')) { return; }
        if ((el.textContent ?? '').trim() !== '') { return; }
        el.setAttribute('aria-label', el.getAttribute('title'));
    });
}

function fixFields(root) {
    root.querySelectorAll(FIELD_SELECTOR).forEach((el) => {
        if (el.hasAttribute('aria-label') || el.hasAttribute('aria-labelledby') || el.labels?.length) { return; }
        const text = el.getAttribute('title') || el.getAttribute('placeholder');
        if (text) { el.setAttribute('aria-label', text); }
    });
}

function fixTables(root) {
    root.querySelectorAll('th:not([scope])').forEach((th) => {
        if (th.closest('thead') || th.closest('tr')?.parentElement?.tagName === 'THEAD') {
            th.setAttribute('scope', 'col');
        } else if (th.parentElement?.firstElementChild === th && th.closest('tbody')) {
            th.setAttribute('scope', 'row');
        } else if (th.closest('table') && !th.closest('table').querySelector('thead')) {
            th.setAttribute('scope', 'col');
        }
    });
}

function run(root) {
    fixIconButtons(root);
    fixFields(root);
    fixTables(root);
}

export function initA11y() {
    run(document);
    let queued = false;
    new MutationObserver(() => {
        if (queued) { return; }
        queued = true;
        requestAnimationFrame(() => { queued = false; run(document); });
    }).observe(document.body, { childList: true, subtree: true });
}
