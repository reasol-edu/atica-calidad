import { Controller } from '@hotwired/stimulus';

const PAGE = 50;
const STATUS_RANK = { pending: 0, draft: 1, unapproved: 2, inforce: 3 };
const STORAGE_KEY = 'models-list-view';

const norm = (s) => (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/**
 * The list of document models: search, filter by status and section, sort, group (collapsible groups) and "show more", all in the
 * browser (the server renders every row once, with what is needed in `data-*`). It also opens and closes the dialogs of "new model"
 * and "import". The sort and the grouping chosen are remembered in this browser.
 *
 * Targets: `row` (each <li> of the list), `list`, `search`, `status`, `section`, `sort`, `group`, `empty`, `count`, `moreButton`,
 * `attentionChip`, `dialog` (with `data-name`), `menu` (the <details> of a row's actions).
 */
export default class extends Controller {
    static targets = ['row', 'list', 'search', 'status', 'section', 'sort', 'group', 'empty', 'count', 'moreButton', 'attentionChip', 'dialog', 'menu'];
    static values = { showing: String, more: String };

    connect() {
        this.limit = PAGE;
        this.collapsed = new Set();
        this.opener = null;

        this.restore();
        this.onDocumentClick = (event) => {
            this.menuTargets.forEach((menu) => {
                if (menu.open && !menu.contains(event.target)) {
                    menu.open = false;
                }
            });
        };
        document.addEventListener('click', this.onDocumentClick);

        if (this.hasListTarget) {
            this.draw();
        }
    }

    disconnect() {
        document.removeEventListener('click', this.onDocumentClick);
    }

    // ── Dialogs ────────────────────────────────────────────────────────────────

    open(event) {
        const dialog = this.dialogTargets.find((d) => d.dataset.name === event.params.name);
        if (!dialog) {
            return;
        }
        this.opener = event.currentTarget;
        dialog.classList.remove('hidden');
        dialog.classList.add('flex');
        const first = dialog.querySelector('input:not([type=hidden]):not([type=file]), select, button');
        // Wait a tick: Tom Select hides its <select> and a focus() before that would go nowhere.
        setTimeout(() => (dialog.querySelector('input[name=name]') || first)?.focus(), 0);
    }

    close() {
        this.dialogTargets.forEach((dialog) => {
            dialog.classList.add('hidden');
            dialog.classList.remove('flex');
        });
        if (this.opener && typeof this.opener.focus === 'function') {
            this.opener.focus();
        }
        this.opener = null;
    }

    // A click on the dark backdrop (not inside the dialog) closes it.
    backdrop(event) {
        if (event.target === event.currentTarget) {
            this.close();
        }
    }

    keydown(event) {
        if (event.key === 'Escape') {
            // Tom Select's own open dropdown takes the first Escape.
            if (document.querySelector('.ts-dropdown') && getComputedStyle(document.querySelector('.ts-dropdown')).display !== 'none' && event.target.closest('.ts-wrapper')) {
                return;
            }
            event.preventDefault();
            this.close();
        } else if (event.key === 'Tab') {
            const focusable = [...event.currentTarget.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea, [tabindex]:not([tabindex="-1"])')]
                .filter((el) => !el.disabled && el.offsetParent !== null);
            if (focusable.length === 0) {
                return;
            }
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    }

    // ── The list ───────────────────────────────────────────────────────────────

    // Any change of a control: back to the first page and draw again.
    render() {
        this.limit = PAGE;
        this.save();
        this.draw();
    }

    more() {
        this.limit += PAGE;
        this.draw();
    }

    showAttention() {
        this.statusTarget.value = this.statusTarget.value === 'attention' ? '' : 'attention';
        this.render();
    }

    reset() {
        this.searchTarget.value = '';
        this.statusTarget.value = '';
        this.sectionTarget.value = '';
        this.render();
    }

    toggleGroup(key) {
        if (this.collapsed.has(key)) {
            this.collapsed.delete(key);
        } else {
            this.collapsed.add(key);
        }
        this.draw();
    }

    draw() {
        const term = norm(this.searchTarget.value.trim());
        const status = this.statusTarget.value;
        const section = this.sectionTarget.value;
        const sortBy = this.sortTarget.value;
        const groupBy = this.groupTarget.value;

        const matches = this.rowTargets.filter((row) => {
            const d = row.dataset;

            return (term === '' || norm(d.search).includes(term))
                && (status === '' || d.states.split(' ').includes(status))
                && (section === '' || d.section === section);
        });

        const collator = new Intl.Collator('es', { numeric: true, sensitivity: 'base' });
        const byName = (a, b) => collator.compare(a.dataset.name, b.dataset.name);
        const compare = {
            name: byName,
            // Models without a code go last; ties fall back to the name.
            code: (a, b) => {
                const ca = a.dataset.code;
                const cb = b.dataset.code;
                if (ca === '' && cb !== '') return 1;
                if (cb === '' && ca !== '') return -1;

                return collator.compare(ca, cb) || byName(a, b);
            },
            updated: (a, b) => Number(b.dataset.updated) - Number(a.dataset.updated) || byName(a, b),
            status: (a, b) => STATUS_RANK[a.dataset.primary] - STATUS_RANK[b.dataset.primary] || byName(a, b),
        }[sortBy] || byName;
        matches.sort(compare);

        // Groups in order of appearance when sorting by name/code/date; by section name or by status rank when grouping by those.
        const groupOf = (row) => groupBy === 'section' ? { key: row.dataset.section, label: row.dataset.section }
            : groupBy === 'status' ? { key: row.dataset.primary, label: row.dataset.primaryLabel }
            : { key: '', label: '' };
        const groups = new Map();
        matches.forEach((row) => {
            const g = groupOf(row);
            if (!groups.has(g.key)) {
                groups.set(g.key, { ...g, rows: [] });
            }
            groups.get(g.key).rows.push(row);
        });
        let ordered = [...groups.values()];
        if (groupBy === 'section') {
            ordered.sort((a, b) => collator.compare(a.label, b.label));
        } else if (groupBy === 'status') {
            ordered.sort((a, b) => STATUS_RANK[a.key] - STATUS_RANK[b.key]);
        }

        // Rebuild the list: headers out, rows hidden, then rows (and their headers) back in order up to the limit.
        this.listTarget.querySelectorAll('.js-group').forEach((el) => el.remove());
        this.rowTargets.forEach((row) => (row.hidden = true));

        let shown = 0;
        for (const group of ordered) {
            if (shown >= this.limit) {
                break;
            }
            const collapsed = groupBy !== '' && this.collapsed.has(group.key);
            if (groupBy !== '') {
                this.listTarget.appendChild(this.header(group, collapsed));
            }
            for (const row of group.rows) {
                if (shown >= this.limit) {
                    break;
                }
                this.listTarget.appendChild(row);
                row.hidden = collapsed;
                shown += 1;
            }
        }

        const total = matches.length;
        this.emptyTarget.classList.toggle('hidden', total > 0);
        this.listTarget.classList.toggle('hidden', total === 0);
        this.countTarget.textContent = this.showingValue.replace('%shown%', String(shown)).replace('%total%', String(total));
        this.moreButtonTarget.classList.toggle('hidden', shown >= total);
        this.moreButtonTarget.classList.toggle('inline-flex', shown < total);

        if (this.hasAttentionChipTarget) {
            const active = status === 'attention';
            this.attentionChipTarget.classList.toggle('ring-2', active);
            this.attentionChipTarget.classList.toggle('ring-amber-400', active);
            this.attentionChipTarget.setAttribute('aria-pressed', active ? 'true' : 'false');
        }
    }

    header(group, collapsed) {
        const li = document.createElement('li');
        li.className = 'js-group bg-gray-50/70 px-4 py-2 sm:px-5';
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'flex w-full items-center gap-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 hover:text-gray-700';
        button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        const chevron = document.createElement('span');
        chevron.setAttribute('aria-hidden', 'true');
        chevron.textContent = collapsed ? '▸' : '▾';
        const label = document.createElement('span');
        label.className = 'min-w-0 flex-1 truncate';
        label.textContent = group.label;
        const count = document.createElement('span');
        count.className = 'rounded-full bg-white px-2 py-0.5 text-[11px] font-medium normal-case text-gray-500 ring-1 ring-gray-200';
        count.textContent = String(group.rows.length);
        button.append(chevron, label, count);
        button.addEventListener('click', () => this.toggleGroup(group.key));
        li.appendChild(button);

        return li;
    }

    // ── What is remembered of the view ─────────────────────────────────────────

    save() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({ sort: this.sortTarget.value, group: this.groupTarget.value }));
        } catch (e) { /* storage not available: the view is just not remembered */ }
    }

    restore() {
        if (!this.hasSortTarget) {
            return;
        }
        try {
            const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
            if (saved && [...this.sortTarget.options].some((o) => o.value === saved.sort)) {
                this.sortTarget.value = saved.sort;
            }
            if (saved && [...this.groupTarget.options].some((o) => o.value === saved.group)) {
                this.groupTarget.value = saved.group;
            }
        } catch (e) { /* nothing saved, or not readable */ }
    }
}
