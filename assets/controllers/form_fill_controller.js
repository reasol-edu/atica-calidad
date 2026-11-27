import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';
import { mountRichText } from '../utils/rich_text_editor.js';
import { h } from '../utils/form_dom.js';
import { confirmDialog } from '../utils/confirm_dialog.js';
import { fold } from '../utils/formula_text.js';
import { asMap, sync, walk, numbers, references, referencesHtml, fieldSpecs, progress, newKey, MAX_COPIES, calculate, buildIndex, totals, rowsOf, format, countedCount } from '../utils/form_scopes.js';

const INPUT = 'w-full min-w-0 rounded-lg border border-gray-200 px-2.5 py-1.5 text-sm focus:border-forest-500 focus:ring-forest-500 disabled:bg-gray-50 disabled:text-gray-500';
// A native drop-down (its chevron and the room under it come from the global rule for `select` in app.css).
const SELECT = INPUT;
// What is shown wrong in a field (the look of a Tom Select box in error comes from `.has-error`, see app.css).
const INVALID = ['border-red-400', 'bg-red-50', 'focus:border-red-500', 'focus:ring-red-500'];
const BTN ='rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-forest-500 disabled:opacity-50';

// The Heroicons the page draws by itself (the same files as assets/icons/heroicons, which Twig's ux_icon() reads: a script cannot).
const ICON_PATHS = {
    'arrow-up-tray': 'M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5',
    document: 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9',
    photo: 'm2.25 15.75l5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5m10.5-11.25h.008v.008h-.008zm.375 0a.375.375 0 1 1-.75 0a.375.375 0 0 1 .75 0',
    'x-mark': 'M6 18L18 6M6 6l12 12',
    clock: 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0a9 9 0 0 1 18 0',
    'chevron-down': 'm19.5 8.25l-7.5 7.5l-7.5-7.5',
    'check-circle': 'M9 12.75L11.25 15L15 9.75M21 12a9 9 0 1 1-18 0a9 9 0 0 1 18 0',
    'exclamation-circle': 'M12 9v3.75m9-.75a9 9 0 1 1-18 0a9 9 0 0 1 18 0m-9 3.75h.008v.008H12z',
    'arrow-path': 'M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99',
    'arrow-top-right-on-square': 'M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25',
};

/** A Heroicon as an inline SVG (decorative: the control around it says what it is). */
function icon(name, cls) {
    const span = document.createElement('span');
    span.className = 'inline-flex shrink-0';
    span.setAttribute('aria-hidden', 'true');
    span.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="${cls}"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="${ICON_PATHS[name]}"/></svg>`;
    return span;
}

/**
 * Filling in a document online. It draws the form from the model's definition and the data typed so far (App\Service\Form),
 * following the rules of repeating sections, conditions and references (assets/utils/form_scopes.js), and saves the data as it
 * changes (debounced) with the version of the draft it started from, so another tab or person cannot be overwritten unnoticed. The
 * server cleans and validates everything again; what it answers is the list of what is still missing before the document can be
 * delivered.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['canvas', 'status', 'problems', 'problemsBox', 'warnings', 'warningsBox', 'deliver', 'deliverBox', 'pending', 'index', 'indexMenu', 'indexMobile'];
    static values = { config: Object, labels: Object };

    connect() {
        this.l = this.labelsValue;
        this.def = this.configValue.definition;
        this.refContext = this.configValue.context || {};
        const data = this.configValue.data || {};
        // PHP writes an empty map as [] and an array loses its string keys in JSON.stringify: always plain objects here.
        this.state = { fields: asMap(data.fields), texts: asMap(data.texts), tables: asMap(data.tables), copies: asMap(data.copies) };
        // Where the rows of the tables of deliveries came from (written by the server only; the saves send it back and it is ignored).
        this.sources = asMap(data.sources);
        // The cells «Actualizar» changed, to blink them once, and what it said, by table. Delivering also reads them again: when it
        // found changes, the page comes back with them (config `changed`).
        this.changedCells = new Set((this.configValue.changed || []).map((c) => c.table + '|' + c.row + '|' + c.column));
        this.deliveredNotes = new Map();
        this.version = this.configValue.version;
        this.readOnly = this.configValue.readOnly === true;
        this.recovered = this.readOnly ? false : this.recoverLocal();
        this.index = buildIndex(this.def);
        sync(this.def.sections || [], this.state);
        calculate(this.def, this.state, this.index);
        this.specs = fieldSpecs(this.def);
        this.structural = this.structuralFields();
        this.dirty = this.recovered;
        this.saving = false;
        this.stale = false;
        this.failed = false;
        this.timer = null;
        this.live = [];
        // What is typed in the filter of the first column of each table whose rows come from a source (kept while the page redraws).
        this.rowFilters = new Map();
        // What the server says is wrong, and which empty required fields have been left (or asked for) and so are shown in red.
        this.problemList = this.configValue.problems || [];
        this.revealed = new Set();
        this.showAll = false;
        // The copies of repeating sections folded away (by path): kept across redraws.
        this.collapsed = new Set();
        this.pendingSelects = [];
        this.tomSelects = [];

        this.onHide = () => { if (this.dirty && !this.stale) { this.save(true); } };
        window.addEventListener('pagehide', this.onHide);
        // Keeps the edit lock while the page stays open (it lasts a few minutes without activity).
        if (!this.readOnly && this.configValue.heartbeatUrl) {
            this.beat = setInterval(() => this.heartbeat(), 60000);
        }
        if (this.hasDeliverTarget) {
            this.onSubmit = async (event) => {
                if (this.dirty || this.saving) {
                    event.preventDefault();
                    await this.flush();
                    if (!this.stale && !this.failed) {
                        this.deliverTarget.form.submit();
                    }
                }
            };
            this.deliverTarget.form.addEventListener('submit', this.onSubmit);
            this.deliverTarget.disabled = this.problemsTarget.children.length > 0;
        }

        this.render();
        // Blinked once: a later redraw does not blink them again.
        this.changedCells = new Set();
        if (!this.readOnly) {
            this.registerWorker();
            this.onOnline = () => { if (this.dirty && !this.stale) { this.flush(); } };
            window.addEventListener('online', this.onOnline);
            if (this.recovered) {
                this.setStatus(this.l.recovered, 'text-amber-700');
                this.timer = setTimeout(() => this.save(false), 300);
            }
        }
    }

    // ── Offline ──────────────────────────────────────────────────────────────
    // The page and its scripts are kept by a service worker (public/formularios-sw.js) so that it opens without a connection, and what
    // is typed without one is kept in this browser until it can be saved. A local copy only counts if it started from the version of
    // the draft the server still has: otherwise somebody else saved in between and the server's version wins.

    storageKey() {
        return 'atica-form:' + this.configValue.saveUrl;
    }

    recoverLocal() {
        try {
            const raw = window.localStorage.getItem(this.storageKey());
            if (raw === null) {
                return false;
            }
            const saved = JSON.parse(raw);
            if (saved.version !== this.version || typeof saved.state !== 'object' || saved.state === null) {
                window.localStorage.removeItem(this.storageKey());
                return false;
            }
            this.state = { fields: asMap(saved.state.fields), texts: asMap(saved.state.texts), tables: asMap(saved.state.tables), copies: asMap(saved.state.copies) };
            return true;
        } catch (e) {
            return false;
        }
    }

    keepLocal() {
        try {
            window.localStorage.setItem(this.storageKey(), JSON.stringify({ version: this.version, state: this.state }));
        } catch (e) {
            // No storage (private window, full): the in-memory retry is all there is.
        }
    }

    dropLocal() {
        try {
            window.localStorage.removeItem(this.storageKey());
        } catch (e) {
            // Nothing to drop.
        }
    }

    registerWorker() {
        if ('serviceWorker' in navigator && window.isSecureContext) {
            navigator.serviceWorker.register('/formularios-sw.js', { scope: '/' }).catch(() => {});
        }
    }

    async heartbeat() {
        if (this.stale || document.hidden) {
            return;
        }
        try {
            const response = await fetch(this.configValue.heartbeatUrl, { method: 'POST', headers: { 'X-CSRF-Token': this.configValue.csrf } });
            if (response.status === 409) {
                const body = await response.json().catch(() => ({}));
                this.stale = true;
                this.readOnly = true;
                this.setStatus(body.message || this.l.stale, 'text-red-700');
                this.render();
            }
        } catch (e) {
            // Offline: the next beat or save tries again.
        }
    }

    disconnect() {
        this.destroySelects();
        clearInterval(this.beat);
        clearTimeout(this.timer);
        window.removeEventListener('pagehide', this.onHide);
        if (this.onOnline) {
            window.removeEventListener('online', this.onOnline);
        }
        if (this.hasDeliverTarget && this.onSubmit) {
            this.deliverTarget.form.removeEventListener('submit', this.onSubmit);
        }
    }

    /** "Previsualizar": what is typed is saved first, so the other tab shows it (the tab is opened at once, or the browser would block it). */
    async preview(event) {
        if (!this.dirty && !this.saving) {
            return;
        }
        event.preventDefault();
        const url = event.currentTarget.href;
        const tab = window.open('', '_blank');
        await this.flush();
        if (tab) {
            tab.location.href = url;
        }
    }

    /** The fields that decide something else (the copies of a section, what is shown): changing one redraws the form. */
    structuralFields() {
        const keys = new Set();
        const visit = (sections) => {
            for (const s of sections || []) {
                if (s.repeat && s.repeat.mode === 'field') { keys.add(s.repeat.field); }
                if (s.visibleIf && s.visibleIf.field) { keys.add(s.visibleIf.field); }
                for (const b of s.blocks || []) {
                    if (b.visibleIf && b.visibleIf.field) { keys.add(b.visibleIf.field); }
                    if (b.type === 'table' && b.mode === 'counted' && b.rowsField) { keys.add(b.rowsField); }
                }
                visit(s.children);
            }
        };
        visit(this.def.sections);
        return keys;
    }

    // ── Saving ───────────────────────────────────────────────────────────────

    touch() {
        this.recalc();
        this.updateLive();
        if (this.stale || this.readOnly) {
            return;
        }
        this.dirty = true;
        this.keepLocal();
        this.setStatus(this.l.saving, 'text-gray-500');
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.save(false), 700);
    }

    async flush() {
        clearTimeout(this.timer);
        this.failed = false;
        while ((this.dirty || this.saving) && !this.stale && !this.failed) {
            if (this.saving) {
                await new Promise((resolve) => setTimeout(resolve, 100));
            } else {
                await this.save(false);
            }
        }
    }

    async save(keepalive) {
        if (this.saving) {
            // A change typed while a save is in flight: try again right after, never drop it.
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.save(false), 400);
            return;
        }
        this.saving = true;
        this.dirty = false;
        try {
            const response = await fetch(this.configValue.saveUrl, {
                method: 'POST',
                keepalive,
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.configValue.csrf },
                body: JSON.stringify({ version: this.version, data: this.state }),
            });
            const body = await response.json().catch(() => ({}));
            if (response.status === 409) {
                // Another tab saved in between, or somebody took over the editing: either way, nothing more is saved from here.
                this.stale = true;
                this.setStatus(body.message || this.l.stale, 'text-red-700');
                return;
            }
            if (response.status === 413) {
                // Too big to save: retrying would fail the same way. What was typed stays in this browser (keepLocal) and the next
                // change tries again (smaller, if the person removed what made it grow).
                this.failed = true;
                this.dirty = true;
                this.keepLocal();
                // (A proxy in front may answer the 413 itself, in HTML: then there is no message in the body.)
                this.setStatus(body.message || this.l.too_large, 'text-red-700');
                return;
            }
            if (!response.ok) {
                throw new Error('save');
            }
            this.failed = false;
            this.version = body.version;
            if (!this.dirty) {
                this.dropLocal();
            } else {
                this.keepLocal();
            }
            this.showProblems(body.problems || [], body.warnings || []);
            this.setStatus(this.dirty ? this.l.saving : this.l.saved, this.dirty ? 'text-gray-500' : 'text-forest-700');
        } catch (e) {
            this.failed = true;
            this.dirty = true;
            this.setStatus(this.l.offline, 'text-amber-700');
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.save(false), 5000);
        } finally {
            this.saving = false;
        }
    }

    setStatus(text, tone) {
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = text;
            this.statusTarget.className = tone;
        }
    }

    /** The bar's «N pendientes»: down to the list of what is left before delivering. */
    goToProblems() {
        this.showAll = true;
        this.markInvalid();
        (this.hasDeliverBoxTarget ? this.deliverBoxTarget : this.problemsBoxTarget).scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    /**
     * The index of the document's sections (each copy of a repeating one too), with how many problems each holds now: at the side on a big
     * screen, in the bar's «Secciones» on a small one. A problem belongs to the section whose path is its own or starts it.
     */
    renderIndex() {
        if (!this.hasIndexTarget && !this.hasIndexMobileTarget) {
            return;
        }
        const entries = (this.tree || []).filter((inst) => !inst.adder && inst.visible);
        const count = (inst) => this.problemList.filter((p) => p.path && (p.path === inst.path || p.path.startsWith(inst.path + '/'))).length;
        const list = () => h('ul', { class: 'space-y-0.5' }, entries.map((inst) => {
            const problems = count(inst);
            const number = this.nums.get(inst.path);
            const title = references(inst.section.title || '', inst, this.specs, this.refContext, this.l) || '…';
            return h('li', {}, [h('a', {
                href: '#sec-' + inst.path,
                class: 'flex items-start gap-2 rounded-lg px-2 py-1.5 text-sm text-gray-700 hover:bg-gray-50',
                onclick: (e) => {
                    e.preventDefault();
                    if (this.collapsed.delete(inst.path)) {
                        this.render();
                    }
                    document.getElementById('sec-' + inst.path)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    if (this.hasIndexMenuTarget) { this.indexMenuTarget.open = false; }
                },
            }, [
                problems > 0
                    ? h('span', { class: 'mt-0.5 inline-flex h-4 min-w-4 shrink-0 items-center justify-center rounded-full bg-amber-100 px-1 text-[10px] font-semibold text-amber-800', title: this.l.index_pending.replace('%count%', String(problems)), text: String(problems) })
                    : h('span', { class: 'mt-0.5 shrink-0 text-forest-600', title: this.l.index_done }, [icon('check-circle', 'h-4 w-4')]),
                h('span', { class: 'min-w-0', text: (number ? number + '. ' : '') + title }),
            ])]);
        }));
        if (this.hasIndexTarget) { this.indexTarget.replaceChildren(list()); }
        if (this.hasIndexMobileTarget) { this.indexMobileTarget.replaceChildren(list()); }
    }

    /** «N pendientes» in the bar (hidden when nothing is). */
    renderPending() {
        if (!this.hasPendingTarget) {
            return;
        }
        const n = this.problemList.length;
        this.pendingTarget.classList.toggle('hidden', n === 0);
        this.pendingTarget.classList.toggle('inline-flex', n > 0);
        this.pendingTarget.replaceChildren(icon('exclamation-circle', 'h-3.5 w-3.5'), document.createTextNode(n === 1 ? this.l.pending_one : this.l.pending_many.replace('%count%', String(n))));
    }

    /** A list of what is wrong, each one a link to the place (a rule of the model has no place: plain text). */
    problemItems(problems) {
        return problems.map((p) => h('li', {}, [p.block === ''
            ? h('span', { text: p.text })
            : h('button', {
                type: 'button', class: 'text-left underline decoration-dotted', text: p.text,
                onclick: () => {
                    // Asking where it is judges every field: what is missing turns red, not only the one clicked.
                    this.showAll = true;
                    // A copy folded away that holds it is unfolded first.
                    const folded = [...this.collapsed].filter((path) => p.path === path || (p.path || '').startsWith(path + '/'));
                    // And a filtered table shows all its rows again: the one with the problem could be hidden by it.
                    const filtered = this.rowFilters.size > 0;
                    if (filtered) { this.rowFilters.clear(); }
                    if (folded.length > 0 || filtered) {
                        folded.forEach((path) => this.collapsed.delete(path));
                        this.render();
                    }
                    this.markInvalid();
                    (document.getElementById('blk-' + p.path + '-' + p.block) || document.getElementById('blk-' + p.block))?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                },
            })]));
    }

    showProblems(problems, warnings = []) {
        this.problemList = problems;
        this.markInvalid();
        this.renderIndex();
        this.renderPending();
        if (!this.hasProblemsTarget) {
            return;
        }
        this.problemsTarget.replaceChildren(...this.problemItems(problems));
        if (this.hasWarningsTarget) {
            this.warningsTarget.replaceChildren(...this.problemItems(warnings));
            this.warningsBoxTarget.classList.toggle('hidden', warnings.length === 0);
        }
        this.problemsBoxTarget.classList.toggle('hidden', problems.length === 0);
        if (this.hasDeliverTarget) {
            this.deliverTarget.disabled = problems.length > 0;
        }
    }

    // ── Values ───────────────────────────────────────────────────────────────

    isEmpty(value) {
        return value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0);
    }

    setIn(map, key, value) {
        if (this.isEmpty(value)) {
            delete map[key];
        } else {
            map[key] = value;
        }
        this.touch();
    }

    /**
     * How many rows with something typed in them the tables that have as many rows as the field $fieldKey says would lose with the
     * value it holds now (they are still there until the next redraw).
     */
    rowsLost(fieldKey) {
        const filled = (row) => Object.entries(row).some(([k, v]) => k !== '_key' && v !== null && v !== '' && !(Array.isArray(v) && v.length === 0));
        const count = (instances) => instances.reduce((lost, inst) => {
            if (inst.adder) {
                return lost;
            }
            const here = inst.blocks
                .map(({ block }) => block)
                .filter((block) => block.type === 'table' && block.mode === 'counted' && block.rowsField === fieldKey)
                .reduce((sum, block) => sum + (inst.scope.tables[block.key] || []).slice(countedCount(block, inst.chain)).filter(filled).length, 0);

            return lost + here + count(inst.children);
        }, 0);

        return count(walk(this.def, this.state));
    }

    /** What decides the copies or what is shown may have changed (a row added or removed, a number or a condition field): redraw. */
    refresh() {
        sync(this.def.sections || [], this.state);
        calculate(this.def, this.state, this.index);
        this.render();
        this.touch();
    }

    /** What is calculated follows what is typed: recalculates and writes the new values into the cells that show them. */
    recalc() {
        calculate(this.def, this.state, this.index);
        for (const entry of this.calcEls || []) {
            if (entry.update) {
                entry.update();
                continue;
            }
            const text = entry.read();
            if ('value' in entry.el) {
                entry.el.value = text;
            } else {
                entry.el.textContent = text;
            }
        }
    }

    // ── Rendering ────────────────────────────────────────────────────────────

    render() {
        this.destroySelects();
        this.live = [];
        this.calcEls = [];
        this.tree = walk(this.def, this.state);
        this.nums = numbers(this.tree);
        this.progressEl = h('div', { class: 'mb-5' });
        this.canvasTarget.replaceChildren(this.progressEl, ...this.tree.map((i) => this.instEl(i, 1)));
        this.enhanceSelects();
        this.markInvalid();
        this.updateProgress();
        this.renderIndex();
        this.renderPending();
        this.renumberAnnexes();
    }

    /** The files of the fields «Adjuntos» numbered as the document will number them («Anexo 1», «Anexo 2»…), in the page's order. */
    renumberAnnexes() {
        this.canvasTarget.querySelectorAll('[data-annex]').forEach((el, i) => { el.textContent = this.l.annex.replace('%n%', String(i + 1)); });
    }

    /** The drop-downs of the choices (not the ones that follow another table) become boxes you can type in to filter, with pills when several are chosen. */
    enhanceSelects() {
        for (const el of this.pendingSelects) {
            if (!el.isConnected) {
                continue;
            }
            const multiple = el.multiple;
            const ts = new TomSelect(el, {
                create: false,
                maxOptions: null,
                // The empty option ("— elige —") is kept, so a choice can be taken back.
                allowEmptyOption: !multiple,
                placeholder: this.l.choose,
                // On <body>: the cards of the form clip an open list otherwise.
                dropdownParent: 'body',
                closeAfterSelect: !multiple,
                hideSelected: multiple,
                plugins: multiple ? ['remove_button'] : [],
                render: { no_results: () => '<div class="no-results px-3 py-2 text-sm">' + this.l.no_results + '</div>' },
            });
            ts.on('blur', () => this.reveal(el.dataset.pk));
            this.tomSelects.push(ts);
        }
        this.pendingSelects = [];
    }

    destroySelects() {
        this.tomSelects.forEach((ts) => ts.destroy());
        this.tomSelects = [];
        this.pendingSelects = [];
    }

    // ── What is wrong, shown on the field ─────────────────────────────────────

    /** A field the person has left is judged: its error (a required one included) is shown from then on. */
    reveal(pk) {
        if (pk && !this.revealed.has(pk)) {
            this.revealed.add(pk);
            this.markInvalid();
        }
    }

    /** Tags a field with the place the server's problems name (copy, block, row, column), and judges it when the person leaves it. */
    tag(control, pk) {
        // The editor of a rich text has selects of its own in its toolbar: the box itself is what is tagged and painted.
        const el = control.matches('input, select, textarea') || control.hasAttribute('data-rich') ? control : control.querySelector('input, select, textarea') || control;
        el.dataset.pk = pk;
        if (el.matches('input, textarea')) {
            el.addEventListener('blur', () => this.reveal(pk));
        }

        return control;
    }

    /** Red box and a line of text under every field the server found wrong (a required one only once it has been left or asked for). */
    markInvalid() {
        this.canvasTarget.querySelectorAll('[data-field-error]').forEach((e) => e.remove());
        this.canvasTarget.querySelectorAll('[data-pk]').forEach((el) => this.paint(el, null));
        const shown = new Map();
        for (const p of this.problemList) {
            if (!p.block) {
                continue;
            }
            const pk = [p.path, p.block, p.row ?? '', p.column ?? ''].join('|');
            if ((p.code === 'required' || p.code === 'option_gone') && !this.showAll && !this.revealed.has(pk)) {
                continue;
            }
            if (!shown.has(pk)) {
                shown.set(pk, p.short || p.text);
            }
        }
        for (const [pk, text] of shown) {
            const el = this.canvasTarget.querySelector('[data-pk="' + CSS.escape(pk) + '"]');
            if (el) {
                this.paint(el, text);
            }
        }
    }

    /** The error of a number outside its limits, drawn as it is typed (the server confirms it on the next save). */
    limitMark(el, message) {
        const holder = el.parentElement?.matches('div.flex.items-center') ? el.parentElement : el;
        if (holder.nextElementSibling?.hasAttribute('data-field-error')) {
            holder.nextElementSibling.remove();
        }
        this.paint(el, message);
    }

    /** Draws (message given) or clears the error state of one field. */
    paint(el, message) {
        const ts = el.tomselect;
        const box = ts ? ts.wrapper : el;
        if (message === null) {
            el.removeAttribute('aria-invalid');
            ts ? ts.wrapper.classList.remove('has-error') : el.classList.remove(...INVALID);

            return;
        }
        el.setAttribute('aria-invalid', 'true');
        ts ? ts.wrapper.classList.add('has-error') : el.classList.add(...INVALID);
        const holder = box.parentElement?.matches('div.flex.items-center') ? box.parentElement : box;
        holder.after(h('p', { class: 'mt-1 text-xs font-medium text-red-700', role: 'alert', 'data-field-error': '', text: message }));
    }

    /** Titles and labels that contain references follow what is typed without redrawing the form (that would lose the focus). */
    updateLive() {
        for (const entry of this.live) {
            if (entry.html) {
                entry.el.innerHTML = referencesHtml(entry.text, entry.inst, this.specs, this.refContext, this.l);
            } else {
                entry.el.textContent = entry.prefix + references(entry.text, entry.inst, this.specs, this.refContext, this.l);
            }
        }
        clearTimeout(this.progressTimer);
        this.progressTimer = setTimeout(() => this.updateProgress(), 150);
    }

    updateProgress() {
        if (!this.progressEl) {
            return;
        }
        const { done, total } = progress(walk(this.def, this.state));
        if (total === 0) {
            this.progressEl.replaceChildren();
            return;
        }
        const label = this.l.progress.replace('%done%', String(done)).replace('%total%', String(total));
        this.progressEl.replaceChildren(
            h('div', { class: 'flex items-center justify-between text-xs text-gray-500' }, [h('span', { text: label })]),
            h('div', { class: 'mt-1 h-1.5 overflow-hidden rounded-full bg-gray-100', role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': total, 'aria-valuenow': done, 'aria-label': label }, [
                h('div', { class: 'h-full rounded-full bg-forest-500 transition-all', style: 'width:' + Math.round((done / total) * 100) + '%' }),
            ]),
        );
    }

    /** An element whose text is a template with references, kept up to date by updateLive(). */
    liveText(tag, props, text, inst, prefix = '') {
        const el = h(tag, props);
        el.textContent = prefix + references(text, inst, this.specs, this.refContext, this.l);
        if (text.includes('{{') || prefix !== '') {
            this.live.push({ el, text, inst, prefix });
        }
        return el;
    }

    /** The same for a text's HTML (a fixed text, the preview of a starter one): its references resolved, and kept up to date. */
    liveHtml(el, html, inst) {
        el.innerHTML = referencesHtml(html, inst, this.specs, this.refContext, this.l);
        if (html && html.includes('{{')) {
            this.live.push({ el, text: html, inst, html: true });
        }
        return el;
    }

    instEl(inst, level) {
        if (inst.adder) {
            return this.adderEl(inst);
        }
        if (!inst.visible) {
            return null;
        }
        const section = inst.section;
        const number = this.nums.get(inst.path);
        const canRemove = inst.own && section.repeat.mode === 'manual' && !this.readOnly;
        // A copy of a repeating section can be folded away (a long list of them reads better), and stays folded across redraws.
        const foldable = inst.own === true;
        const folded = foldable && this.collapsed.has(inst.path);
        const body = h('div', { class: folded ? 'hidden' : null });
        const titleText = () => references(section.title || '', inst, this.specs, this.refContext, this.l);
        const fold = foldable ? h('button', {
            type: 'button', class: 'mt-0.5 shrink-0 rounded p-0.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700',
            'aria-expanded': folded ? 'false' : 'true', 'aria-label': (folded ? this.l.expand : this.l.collapse).replace('%title%', titleText()),
            onclick: (e) => {
                const button = e.currentTarget;
                const now = !this.collapsed.has(inst.path);
                if (now) { this.collapsed.add(inst.path); } else { this.collapsed.delete(inst.path); }
                body.classList.toggle('hidden', now);
                button.setAttribute('aria-expanded', now ? 'false' : 'true');
                button.setAttribute('aria-label', (now ? this.l.expand : this.l.collapse).replace('%title%', titleText()));
                button.firstChild.firstChild.classList.toggle('-rotate-90', now);
            },
        }, [icon('chevron-down', 'h-5 w-5 transition-transform' + (folded ? ' -rotate-90' : ''))]) : null;
        const heading = section.title || canRemove
            ? h('div', { class: 'flex items-start justify-between gap-2' }, [
                fold,
                this.liveText(level === 1 ? 'h2' : 'h3', { class: (level === 1 ? 'border-b border-gray-200 pb-1 text-lg' : 'text-base') + ' min-w-0 flex-1 font-semibold text-gray-900' }, section.title || '', inst, number ? number + '. ' : ''),
                ...(canRemove ? [h('button', {
                    type: 'button', class: BTN + ' text-red-700', title: this.l.remove_copy, 'aria-label': this.l.remove_copy, text: '✕',
                    onclick: () => this.removeCopy(inst),
                })] : []),
            ])
            : null;

        // The copy of an item of a list that no longer belongs here (kept because something is typed in it): it says so and can be emptied
        // in one go, which is what lets the document be delivered.
        const goneItem = inst.own && section.repeat && section.repeat.mode === 'list'
            ? (section.repeat.items || []).find((i) => i.gone && i.key === inst.copy.key)
            : null;
        const goneBox = goneItem ? h('div', { class: 'mt-2 flex flex-wrap items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900' }, [
            h('span', { class: 'flex-1', text: this.l.copy_gone.replace('%item%', goneItem.label) }),
            h('button', {
                type: 'button', class: BTN, disabled: this.readOnly, text: this.l.copy_gone_clear,
                onclick: () => {
                    for (const part of ['fields', 'texts', 'tables', 'copies']) { inst.scope[part] = {}; }
                    this.touch();
                    this.refresh();
                },
            }),
        ]) : null;

        body.append(
            ...(section.help ? [h('p', { class: 'mt-1 text-sm text-gray-500', text: section.help })] : []),
            h('div', { class: 'mt-3 space-y-4' }, inst.blocks.filter((b) => b.visible).map((b) => this.blockEl(b.block, inst))),
            ...inst.children.map((c) => this.instEl(c, level + 1)).filter((c) => c !== null),
        );

        // scroll-mt: jumping to it (the index, a problem) leaves it below the page's header and the bar.
        return h('section', { id: 'sec-' + inst.path, class: 'scroll-mt-32 ' + (level === 1 ? 'mt-6 first:mt-0' : 'mt-5') + (goneItem ? ' opacity-80' : '') }, [
            heading,
            goneBox,
            body,
        ]);
    }

    adderEl(inst) {
        const list = inst.scope.copies[inst.section.key] || [];
        const max = Math.min(MAX_COPIES, inst.section.repeat.max || MAX_COPIES);
        return h('div', { class: 'mt-4' }, [
            h('button', {
                type: 'button', class: BTN, disabled: this.readOnly || list.length >= max, text: '+ ' + this.l.add_copy.replace('%title%', (inst.section.title || '').replace(/\{\{[^{}]*\}\}/g, '').trim()),
                onclick: () => { list.push({ _key: newKey() }); inst.scope.copies[inst.section.key] = list; this.refresh(); },
            }),
        ]);
    }

    async removeCopy(inst) {
        if (!await confirmDialog(this.l.confirm_remove_copy)) {
            return;
        }
        const list = inst.chain[1].copies[inst.section.key];
        list.splice(list.findIndex((c) => c._key === inst.copy.key), 1);
        this.refresh();
    }

    labelEl(block, inst, forId) {
        return h('label', { for: forId, class: 'mb-1 block text-sm font-medium text-gray-700' }, [
            this.liveText('span', {}, block.label || '', inst),
            block.required ? h('span', { class: 'text-red-600', 'aria-hidden': 'true', text: ' *' }) : null,
        ]);
    }

    /**
     * "Historial" under a field or a text: who changed it, when, from what, with a button to put an earlier value back. The panel
     * loads when it is opened.
     */
    historyEl(block, inst) {
        if (!this.configValue.historyUrl) {
            return null;
        }
        const panel = h('div', { class: 'mt-1 hidden rounded-lg border border-gray-200 bg-gray-50 p-2 text-xs text-gray-700' });
        const show = (value) => {
            if (value === null || value === undefined) {
                return '—';
            }
            const text = block.type === 'text' ? String(value).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim() : format(block, value, this.l);
            return text.length > 120 ? text.slice(0, 120) + '…' : (text || '—');
        };
        const restore = (value) => {
            if (block.type === 'text') {
                const html = value || '';
                inst.scope.texts[block.key] = { html, modified: block.mode === 'starter' && html !== (block.html || '') };
                if (html === '' && block.mode === 'fill') { delete inst.scope.texts[block.key]; }
            } else {
                this.setIn(inst.scope.fields, block.key, value);
            }
            this.refresh();
        };
        const load = async () => {
            panel.textContent = this.l.history_loading;
            try {
                const response = await fetch(this.configValue.historyUrl + '?block=' + encodeURIComponent(block.key) + '&path=' + encodeURIComponent(inst.path));
                const body = await response.json();
                const list = body.changes || [];
                panel.replaceChildren(...(list.length === 0
                    ? [h('p', { text: this.l.history_empty })]
                    : list.map((c) => h('div', { class: 'flex flex-wrap items-baseline gap-x-2 py-0.5' }, [
                        h('span', { class: 'text-gray-500', text: new Date(c.at).toLocaleString('es-ES', { dateStyle: 'short', timeStyle: 'short' }) + ' · ' + (c.by || '') }),
                        h('span', { class: 'min-w-0 break-words', text: '«' + show(c.old) + '» → «' + show(c.new) + '»' }),
                        ...(this.readOnly ? [] : [h('button', { type: 'button', class: 'underline decoration-dotted hover:text-forest-700', text: this.l.history_restore, onclick: () => restore(c.old) })]),
                    ]))));
            } catch (e) {
                panel.textContent = '—';
            }
        };
        // A small clock next to the label (a link under every field made the form noisy); the panel opens under the field.
        const button = h('button', {
            type: 'button', class: 'shrink-0 rounded p-0.5 text-gray-400 hover:bg-gray-100 hover:text-forest-700 focus:outline-none focus:ring-2 focus:ring-forest-500',
            title: this.l.history, 'aria-label': this.l.history, 'aria-expanded': 'false',
            onclick: () => {
                panel.classList.toggle('hidden');
                const open = !panel.classList.contains('hidden');
                button.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (open) { load(); }
            },
        }, [icon('clock', 'h-4 w-4')]);

        return { button, panel };
    }

    /** The label of a field or text with, at its right, the button of its history. */
    headRow(head, history) {
        return history ? h('div', { class: 'flex items-start justify-between gap-2' }, [head, history.button]) : head;
    }

    blockEl(block, inst) {
        const wrap = (content) => h('div', { id: 'blk-' + inst.path + '-' + block.key }, content);
        if (block.type === 'text') {
            const text = this.textEl(block, inst);
            if (block.mode === 'fixed') {
                return wrap(text);
            }
            const history = this.historyEl(block, inst);
            const [head, ...rest] = text;

            return wrap([this.headRow(head, history), ...rest, history ? history.panel : null]);
        }
        if (block.type === 'field') {
            const id = 'f-' + inst.path + '-' + block.key;
            const control = this.tag(
                this.control(block, inst.scope.fields[block.key], (v) => this.setIn(inst.scope.fields, block.key, v), id, false, { chain: inst.chain, map: inst.scope.fields, key: block.key }),
                [inst.path, block.key, '', ''].join('|'),
            );
            if (this.structural.has(block.key)) {
                // What this field held before the change: lowering a number that sets the rows of a table asks first, and puts it back on "no".
                let previous = inst.scope.fields[block.key];
                control.addEventListener('change', async () => {
                    const lost = this.rowsLost(block.key);
                    if (lost > 0 && !await confirmDialog(this.l.confirm_remove_rows.replace('%count%', String(lost)))) {
                        this.setIn(inst.scope.fields, block.key, previous);
                    }
                    previous = inst.scope.fields[block.key];
                    this.refresh();
                });
            }
            const history = block.fieldType === 'calculated' ? null : this.historyEl(block, inst);

            return wrap([
                this.headRow(this.labelEl(block, inst, id), history),
                control,
                ...(block.help ? [h('p', { class: 'mt-1 text-xs text-gray-500', text: block.help })] : []),
                history ? history.panel : null,
            ]);
        }
        return wrap(this.tableEl(block, inst));
    }

    /** The input of a field or a table column by type; $onChange receives the typed value (null when empty). */
    control(spec, value, onChange, id, compact = false, ctx = null) {
        const disabled = this.readOnly;
        const base = { id, class: INPUT, disabled, 'aria-label': compact ? spec.label : null };
        switch (spec.fieldType) {
            case 'calculated': {
                // Never typed: it shows what the formula gives and follows the other values (recalc()).
                const input = h('input', { ...base, type: 'text', readonly: true, tabindex: -1, class: INPUT + ' bg-gray-50 text-gray-700', value: format(spec, value, this.l) });
                if (ctx) {
                    this.calcEls.push({ el: input, read: () => format(spec, ctx.map[ctx.key], this.l) });
                }
                return input;
            }
            case 'relation': {
                // The rows of another table of the document, shown by the column the model chose.
                const labelSpec = (this.index.tables.get(spec.relTable)?.columns || []).find((c) => c.key === spec.relLabel) || {};
                const rows = () => rowsOf(ctx ? ctx.chain : [], spec.relTable);
                const labelOf = (r) => format(labelSpec, r[spec.relLabel], this.l) || '—';
                const control = this.control({ ...spec, fieldType: spec.multiple ? 'multichoice' : 'choice', options: rows().map((r) => ({ key: r._key, label: labelOf(r) })) }, value, onChange, id, compact, ctx);
                // The names of the rows follow what is typed in them, without redrawing (a redraw on blur would eat the next click).
                this.calcEls.push({ update: () => {
                    const items = control.tagName === 'SELECT' ? [...control.querySelectorAll('option')].slice(1) : [...control.querySelectorAll('label')];
                    items.forEach((el, i) => {
                        const row = rows()[i];
                        if (row) {
                            (el.tagName === 'OPTION' ? el : el.lastChild).textContent = labelOf(row);
                        }
                    });
                } });
                return control;
            }
            case 'long':
                // A «texto largo» field has rich text; the cells of a table keep a plain box.
                if (spec.type === 'field' && !compact) {
                    return this.richEl(value, onChange, id);
                }
                return h('textarea', { ...base, rows: 4, maxlength: 20000, value: value ?? '', oninput: (e) => onChange(e.target.value) });
            case 'number': {
                const limit = (n) => (n === null || n === undefined || n === '' || !Number.isFinite(Number(n)) ? null : Number(n));
                const [min, max] = [limit(spec.min), limit(spec.max)];
                // Whole numbers only (0), at most N decimals, or any (null): the step of the box and the phone's keyboard follow it.
                const places = Number.isInteger(spec.maxDecimals) ? spec.maxDecimals : null;
                const decimalsOf = (n) => { const t = String(Number(n.toFixed(10))); return t.includes('.') ? t.length - t.indexOf('.') - 1 : 0; };
                const input = h('input', {
                    ...base, type: 'number', step: places === null ? 'any' : String(10 ** -places), inputmode: places === 0 ? 'numeric' : 'decimal', value: value ?? '',
                    min: min ?? null, max: max ?? null,
                    oninput: (e) => {
                        onChange(e.target.value === '' ? null : Number(e.target.value));
                        // At once, without waiting for the save: a value outside the limits (or with too many decimals) turns the box red
                        // as it is typed.
                        const n = e.target.value === '' ? null : Number(e.target.value);
                        let error = null;
                        if (n !== null && min !== null && n < min) { error = this.l.err_min.replace('%min%', String(min)); }
                        else if (n !== null && max !== null && n > max) { error = this.l.err_max.replace('%max%', String(max)); }
                        else if (n !== null && places !== null && decimalsOf(n) > places) {
                            error = places === 0 ? this.l.err_integer : this.l.err_decimals.replace('%decimals%', String(places));
                        }
                        this.limitMark(e.target, error);
                    },
                });
                return spec.unit ? h('div', { class: 'flex items-center gap-2' }, [input, h('span', { class: 'shrink-0 text-sm text-gray-500', text: spec.unit })]) : input;
            }
            case 'date':
                return h('input', { ...base, type: 'date', value: value ?? '', oninput: (e) => onChange(e.target.value || null) });
            case 'time':
                return h('input', { ...base, type: 'time', value: value ?? '', oninput: (e) => onChange(e.target.value || null) });
            case 'attachment':
                return this.attachmentEl(spec, value, onChange, id);
            case 'scale': {
                // A rating from 1 to N. In a table cell, a drop-down (a row of buttons does not fit); elsewhere, the points as
                // radio buttons in a row, with the words of the ends, and a way to take an optional answer back.
                const points = spec.points || 5;
                const end = (n) => (n === 1 ? spec.lowLabel : n === points ? spec.highLabel : '') || '';
                if (compact) {
                    return h('select', { ...base, class: SELECT, onchange: (e) => onChange(e.target.value === '' ? null : Number(e.target.value)) }, [
                        h('option', { value: '', text: this.l.choose, selected: value === undefined || value === null }),
                        ...Array.from({ length: points }, (_, i) => i + 1).map((n) => h('option', { value: String(n), text: end(n) ? n + ' · ' + end(n) : String(n), selected: value === n })),
                    ]);
                }
                const name = (id || 'scale') + '-' + Math.random().toString(36).slice(2, 8);
                const radios = [];
                const clear = h('button', {
                    type: 'button', disabled, hidden: value === undefined || value === null || spec.required,
                    class: 'text-xs text-gray-500 underline decoration-dotted hover:text-gray-700',
                    text: this.l.clear_answer,
                    onclick: () => { radios.forEach((r) => { r.checked = false; }); clear.hidden = true; onChange(null); },
                });
                // The usual look of a rating: a row of radio buttons, the number under each, the words of the ends at the sides.
                const point = (n) => {
                    const radio = h('input', {
                        type: 'radio', name, value: String(n), checked: value === n, disabled,
                        class: 'h-5 w-5 cursor-pointer accent-forest-600 disabled:cursor-default',
                        'aria-label': end(n) ? n + ' · ' + end(n) : String(n),
                        onchange: () => { clear.hidden = !!spec.required; onChange(n); },
                    });
                    radios.push(radio);
                    return h('label', { class: 'flex min-w-8 cursor-pointer flex-col items-center gap-1 px-1 py-1' }, [
                        radio,
                        h('span', { class: 'text-xs text-gray-600', 'aria-hidden': 'true', text: String(n) }),
                    ]);
                };
                // Everything is aligned on the row of the buttons, not on the buttons with their numbers (measured in the browser:
                // the native button has a margin of its own, so the words need 6 px above and the link 2 px).
                // On a narrow screen the words go under the first and the last point instead (at the sides they push the row apart).
                const side = 'hidden pt-1.5 text-sm leading-5 text-gray-600 sm:block';
                const ends = spec.lowLabel || spec.highLabel
                    ? h('div', { class: 'flex justify-between gap-3 text-xs text-gray-600 sm:hidden', 'aria-hidden': 'true' }, [
                        h('span', { text: spec.lowLabel || '' }),
                        h('span', { class: 'text-right', text: spec.highLabel || '' }),
                    ])
                    : null;
                return h('fieldset', { id, class: 'min-w-0' }, [
                    h('legend', { class: 'sr-only', text: spec.label || '' }),
                    h('div', { class: 'flex flex-wrap items-start gap-x-2 gap-y-1' }, [
                        spec.lowLabel ? h('span', { class: side, 'aria-hidden': 'true', text: spec.lowLabel }) : null,
                        h('div', { class: 'flex flex-col' }, [
                            h('div', { class: 'flex items-start' }, Array.from({ length: points }, (_, i) => point(i + 1))),
                            ends,
                        ]),
                        spec.highLabel ? h('span', { class: side, 'aria-hidden': 'true', text: spec.highLabel }) : null,
                        h('span', { class: 'ml-2 pt-0.5 leading-5' }, [clear]),
                    ]),
                ]);
            }
            case 'bool':
                return h('select', { ...base, class: SELECT, onchange: (e) => onChange(e.target.value === '' ? null : e.target.value === '1') }, [
                    h('option', { value: '', text: this.l.choose, selected: value === undefined || value === null }),
                    h('option', { value: '1', text: this.l.yes, selected: value === true }),
                    h('option', { value: '0', text: this.l.no, selected: value === false }),
                ]);
            case 'choice': {
                // A relation is a choice among rows that are renamed as they are typed: it stays a plain drop-down. The rest are searchable.
                const searchable = !spec.relTable;
                const select = h('select', { ...base, class: searchable ? 'w-full' : SELECT, onchange: (e) => onChange(e.target.value || null) }, [
                    h('option', { value: '', text: this.l.choose, selected: !value }),
                    ...(spec.options || []).map((o) => h('option', { value: o.key, text: o.label, selected: value === o.key })),
                ]);
                if (searchable) {
                    this.pendingSelects.push(select);
                }

                return select;
            }
            case 'multichoice': {
                const current = Array.isArray(value) ? [...value] : [];
                // Many options: a box that opens a list to pick from and shows what is chosen as pills (a relation keeps its checkboxes).
                if (!spec.relTable) {
                    const select = h('select', {
                        ...base, class: 'w-full', multiple: true,
                        onchange: (e) => { const chosen = [...e.target.selectedOptions].map((o) => o.value); onChange(chosen.length ? chosen : null); },
                    }, (spec.options || []).map((o) => h('option', { value: o.key, text: o.label, selected: current.includes(o.key) })));
                    this.pendingSelects.push(select);

                    return select;
                }

                return h('div', { id, class: 'flex flex-col gap-1' }, (spec.options || []).map((o) => h('label', { class: 'inline-flex items-center gap-2 text-sm text-gray-800' }, [
                    h('input', {
                        type: 'checkbox', checked: current.includes(o.key), disabled,
                        onchange: (e) => {
                            const at = current.indexOf(o.key);
                            if (e.target.checked && at < 0) { current.push(o.key); }
                            if (!e.target.checked && at >= 0) { current.splice(at, 1); }
                            onChange(current.length ? [...current] : null);
                        },
                    }),
                    o.label,
                ])));
            }
            default:
                return h('input', { ...base, type: 'text', maxlength: 255, value: value ?? '', oninput: (e) => onChange(e.target.value) });
        }
    }

    /**
     * A field «Adjuntos»: the files it has (to download, or to take out) and a box to add more, by picking them or dropping them on it.
     * Each file is uploaded on its own (it is stored at once, see FormAttachmentService) and then added to the value, which is saved
     * as any other. Needs the connection: there is no offline copy of a file.
     */
    attachmentEl(spec, value, onChange, id) {
        const config = this.configValue;
        const max = spec.maxFiles || 5;
        let files = Array.isArray(value) ? [...value] : [];
        const urlOf = (file) => (config.fileUrl || '').replace('__ID__', encodeURIComponent(file.id));
        const size = (bytes) => (bytes < 1024 ? bytes + ' B' : bytes < 1048576 ? Math.max(1, Math.round(bytes / 1024)) + ' KB' : (Math.round(bytes / 104857.6) / 10).toString().replace('.', ',') + ' MB');
        const list = h('ul', { class: 'space-y-1.5' });
        const status = h('p', { class: 'text-xs text-gray-500', 'aria-live': 'polite' });
        const input = h('input', { type: 'file', multiple: true, class: 'sr-only', id: id ? id + '-files' : null, disabled: this.readOnly });
        // The same box as the other uploads of the application (Form:PdfDropzone): dashed, the upload icon at the left.
        const box = h('label', {
            for: input.id || null,
            class: 'flex cursor-pointer items-center gap-3 rounded-xl border-2 border-dashed border-gray-200 bg-gray-50 px-4 py-3 transition-colors hover:border-forest-300 hover:bg-forest-50/50 focus-within:ring-2 focus-within:ring-forest-500',
        }, [
            input,
            icon('arrow-up-tray', 'h-5 w-5 shrink-0 text-gray-400'),
            h('span', { class: 'min-w-0' }, [
                h('span', { class: 'block text-sm text-gray-600' }, [
                    h('span', { class: 'font-medium text-forest-700', text: this.l.attachment_add }), ' ' + this.l.attachment_drop,
                ]),
                h('span', { class: 'block text-xs text-gray-400', text: this.l.attachment_hint.replace('%max%', size(config.fileMaxBytes || 10485760)) }),
            ]),
        ]);

        const draw = () => {
            // Each file with its number of annex (as the document will call it: renumberAnnexes()), a thumbnail when it is a picture.
            list.replaceChildren(...files.map((file) => h('li', { class: 'flex items-center gap-3 rounded-lg border border-gray-200 bg-white px-3 py-2' }, [
                (file.mime || '').startsWith('image/')
                    ? h('img', { src: urlOf(file) + '?ver=1', alt: '', loading: 'lazy', class: 'h-10 w-14 shrink-0 rounded border border-gray-200 bg-gray-50 object-cover' })
                    : icon('document', 'h-4 w-4 shrink-0 text-gray-400'),
                h('span', { class: 'shrink-0 text-xs font-medium text-gray-500', 'data-annex': '' }),
                h('a', { href: urlOf(file), class: 'min-w-0 flex-1 truncate text-sm text-gray-700 hover:text-forest-700 hover:underline', text: file.name }),
                h('span', { class: 'shrink-0 text-xs text-gray-400', text: size(file.size || 0) }),
                this.readOnly ? null : h('button', {
                    type: 'button', class: 'shrink-0 text-gray-400 transition-colors hover:text-red-600',
                    'aria-label': this.l.attachment_remove.replace('%name%', file.name),
                    onclick: () => { files = files.filter((f) => f.id !== file.id); draw(); onChange(files.length ? [...files] : null); },
                }, [icon('x-mark', 'h-4 w-4')]),
            ])));
            box.hidden = this.readOnly || files.length >= max;
            if (!this.readOnly && files.length >= max) {
                status.textContent = this.l.attachment_full.replace('%max%', String(max));
            }
            if (list.isConnected) {
                this.renumberAnnexes();
            }
        };

        // The file being sent: its name and a bar that fills as it goes up (a big PDF takes a while).
        const bar = h('div', { class: 'h-full w-0 rounded-full bg-forest-500 transition-[width]' });
        const sending = h('div', { class: 'hidden rounded-lg border border-gray-200 bg-white px-3 py-2' }, [
            h('p', { class: 'truncate text-xs text-gray-600', 'aria-live': 'polite' }),
            h('div', { class: 'mt-1 h-1.5 overflow-hidden rounded-full bg-gray-100' }, [bar]),
        ]);
        const send = (body, name) => new Promise((resolve) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', config.attachUrl);
            xhr.setRequestHeader('X-CSRF-Token', config.csrf);
            xhr.responseType = 'json';
            sending.classList.remove('hidden');
            sending.firstChild.textContent = this.l.attachment_uploading.replace('%name%', name);
            bar.style.width = '0%';
            xhr.upload.onprogress = (e) => { if (e.lengthComputable) { bar.style.width = Math.round((e.loaded / e.total) * 100) + '%'; } };
            xhr.onload = () => { sending.classList.add('hidden'); resolve({ ok: xhr.status >= 200 && xhr.status < 300, body: xhr.response || {} }); };
            xhr.onerror = () => { sending.classList.add('hidden'); resolve(null); };
            xhr.send(body);
        });

        const upload = async (picked) => {
            if (!navigator.onLine) {
                status.textContent = this.l.attachment_offline;
                return;
            }
            for (const file of picked) {
                if (files.length >= max) {
                    status.textContent = this.l.attachment_full.replace('%max%', String(max));
                    break;
                }
                const body = new FormData();
                body.append('field', spec.key);
                body.append('file', file);
                const result = await send(body, file.name);
                if (result === null) {
                    status.textContent = this.l.attachment_offline;
                    continue;
                }
                const answer = result.body;
                if (!result.ok || !answer.id) {
                    status.textContent = answer.message || this.l.attachment_error;
                    continue;
                }
                files.push({ id: answer.id, name: answer.name, size: answer.size, mime: answer.mime });
                status.textContent = '';
                draw();
                onChange([...files]);
            }
        };

        input.addEventListener('change', () => { upload([...input.files]); input.value = ''; });
        // While a file is dragged over it, the box lights up like the other dropzones.
        const over = ['border-forest-400', 'bg-forest-50'];
        box.addEventListener('dragover', (e) => { e.preventDefault(); box.classList.add(...over); });
        box.addEventListener('dragleave', () => box.classList.remove(...over));
        box.addEventListener('drop', (e) => { e.preventDefault(); box.classList.remove(...over); upload([...(e.dataTransfer?.files || [])]); });
        draw();

        return h('div', { id, class: 'space-y-2' }, [list, sending, box, status]);
    }

    /**
     * The box of a «texto largo»: an editor with the toolbar of the document's texts. What was saved as plain text (before the field was
     * rich) shows as paragraphs. The value is the HTML, or null when nothing is written. Read only, it is just the text.
     */
    richEl(value, onChange, id) {
        const asHtml = (v) => {
            const text = typeof v === 'string' ? v.trim() : '';
            if (text === '' || /<[a-z][\s\S]*>/i.test(text)) {
                return text;
            }
            const escape = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

            return text.split(/\n{2,}/).map((p) => '<p>' + p.trim().split('\n').map(escape).join('<br>') + '</p>').join('');
        };
        if (this.readOnly) {
            const box = h('div', { id, class: 'prose prose-sm max-w-none rounded-lg border border-gray-200 bg-gray-50 px-3 py-2' });
            box.innerHTML = asHtml(value);
            return box;
        }
        const wrapper = h('div', { id, class: 'rounded-lg', 'data-rich': '' });
        const el = h('div');
        wrapper.append(el);
        mountRichText(el, {
            html: asHtml(value),
            onChange: (now) => onChange(now === '' ? null : now),
            // Leaving the editor is when a required one that stays empty turns red (see tag()).
            onBlur: () => { if (wrapper.dataset.pk) { this.reveal(wrapper.dataset.pk); } },
        });

        return wrapper;
    }

    textEl(block, inst) {
        if (block.mode === 'fixed') {
            // Its {{…}} read as in the delivered document (they were shown as written).
            return this.liveHtml(h('div', { class: 'prose prose-sm max-w-none text-gray-800' }), block.html || '', inst);
        }
        const texts = inst.scope.texts;
        const entry = texts[block.key] || { html: '', modified: false };
        const holder = h('div');
        const modifiedBadge = h('span', { class: 'ml-2 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800', text: this.l.starter_modified });
        const refreshBadge = () => { modifiedBadge.hidden = !(block.mode === 'starter' && entry.modified); };

        const preview = () => {
            const box = h('button', {
                type: 'button', disabled: this.readOnly,
                class: 'block w-full rounded-lg border border-dashed border-gray-300 px-3 py-2 text-left text-sm text-gray-800 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-forest-500 disabled:cursor-default',
                'aria-label': this.l.edit_text, onclick: mount,
            });
            if (entry.html) {
                box.append(this.liveHtml(h('div', { class: 'prose prose-sm max-w-none' }), entry.html, inst));
            } else {
                box.append(h('span', { class: 'text-gray-400', text: this.l.write_text }));
            }
            holder.replaceChildren(box);
        };
        const mount = () => {
            const el = h('div');
            holder.replaceChildren(el);
            const rich = mountRichText(el, {
                html: entry.html,
                onChange: (html) => {
                    entry.html = html;
                    entry.modified = block.mode === 'starter' && entry.html !== (block.html || '');
                    refreshBadge();
                    if (entry.html === '' && block.mode === 'fill') {
                        delete texts[block.key];
                    } else {
                        texts[block.key] = entry;
                    }
                    this.touch();
                },
            });
            rich.focus();
        };
        preview();
        refreshBadge();

        return [
            h('p', { class: 'mb-1 text-sm font-medium text-gray-700' }, [
                this.liveText('span', {}, block.label || '', inst),
                block.required ? h('span', { class: 'text-red-600', 'aria-hidden': 'true', text: ' *' }) : null,
                block.mode === 'starter' ? modifiedBadge : null,
            ]),
            holder,
            ...(block.help ? [h('p', { class: 'mt-1 text-xs text-gray-500', text: block.help })] : []),
        ];
    }

    /** A double-entry table: a row for each row of one table and a column for each row of another, one kind of cell. */
    matrixEl(block, inst) {
        const cell = block.cell || { fieldType: 'number' };
        const colSource = rowsOf(inst.chain, block.colTable);
        const rowSource = new Map(rowsOf(inst.chain, block.rowTable).map((r) => [r._key, r]));
        const labelOf = (table, column, row) => format((this.index.tables.get(table)?.columns || []).find((c) => c.key === column) || {}, row && row[column], this.l);
        const rows = inst.scope.tables[block.key] || [];

        // The names of the rows and columns follow what is typed in the tables they come from.
        const named = (props, read) => {
            const th = h('th', props);
            th.textContent = read();
            this.calcEls.push({ el: th, read });

            return th;
        };
        const head = h('tr', {}, [
            h('th', { scope: 'col', class: 'px-2 py-2' }, [h('span', { class: 'sr-only', text: block.label || '' })]),
            ...colSource.map((col) => named({ scope: 'col', class: 'px-2 py-2 text-left font-medium' }, () => labelOf(block.colTable, block.colLabel, col))),
        ]);
        const body = h('tbody', { class: 'divide-y divide-gray-100' }, rows.map((row) => h('tr', {}, [
            named({ scope: 'row', class: 'whitespace-nowrap px-2 py-2 text-left align-top font-normal text-gray-700' }, () => labelOf(block.rowTable, block.rowLabel, rowSource.get(row._key))),
            ...colSource.map((col) => h('td', { class: 'min-w-[6rem] px-2 py-1.5 align-top' }, [
                this.control({ ...cell, label: labelOf(block.colTable, block.colLabel, col) }, row[col._key], (v) => this.setIn(row, col._key, v), null, true),
            ])),
        ])));

        return [
            ...(block.label ? [h('p', { class: 'mb-1 text-sm font-medium text-gray-700' }, [this.liveText('span', {}, block.label, inst)])] : []),
            rows.length === 0 || colSource.length === 0
                ? h('p', { class: 'rounded-xl border border-dashed border-gray-300 px-3 py-3 text-sm text-gray-400', text: this.l.matrix_empty })
                : h('div', { class: 'overflow-x-auto rounded-xl border border-gray-200' }, [h('table', { class: 'min-w-full text-sm' }, [h('thead', { class: 'bg-gray-50 text-gray-600' }, [head]), body])]),
            ...(block.help ? [h('p', { class: 'mt-1 text-xs text-gray-500', text: block.help })] : []),
        ];
    }

    tableEl(block, inst) {
        if (block.mode === 'matrix') {
            return this.matrixEl(block, inst);
        }
        // A counted table has as many rows as a number says: like a fixed one nobody adds or removes them, and they are told by number.
        const counted = block.mode === 'counted';
        // A table of deliveries has a row per delivery of another activity: nobody adds them either (see deliveredHeader()).
        const delivered = block.mode === 'delivered';
        const fixed = block.mode === 'fixed' || counted || delivered;
        const source = delivered ? (this.sources[block.key] || null) : null;
        if (delivered && (!source || !source.activity)) {
            return [
                ...(block.label ? [h('p', { class: 'mb-1 text-sm font-medium text-gray-700' }, [this.liveText('span', {}, block.label, inst)])] : []),
                h('p', { class: 'rounded-xl border border-dashed border-gray-300 px-4 py-3 text-sm text-gray-500', text: this.l.delivered_no_source }),
            ];
        }
        const rowInfo = new Map((block.rows || []).map((r) => [r.key, r]));
        const showStatus = delivered && block.showStatus !== false;
        const tables = inst.scope.tables;
        if (!Array.isArray(tables[block.key])) {
            tables[block.key] = [];
        }
        const rows = tables[block.key];
        const labels = new Map((block.rows || []).map((r) => [r.key, r.label]));
        const max = block.maxRows > 0 ? block.maxRows : 1000;
        // A number, a date, a rating… needs little room: its column stays narrow and the texts take the rest.
        const narrow = (c) => ['number', 'scale', 'date', 'time', 'bool', 'calculated'].includes(c.fieldType);
        const cellClass = (c) => (narrow(c) ? 'w-36 ' : 'min-w-[9rem] ') + 'px-2 py-1.5 align-top';

        // Rows that come from a source (a list of the catalogue, a number) are many and not written by anyone: their first column, the
        // one that names each row, carries a filter to find one while typing.
        const automatic = counted || Boolean(block.rowsList) || delivered;
        const filterKey = inst.path + '|' + block.key;
        const rowEls = [];
        let filterInput = null;
        let applyFilter = () => {};
        const firstHeader = () => {
            if (!automatic || rows.length === 0) {
                return h('th', { scope: 'col', class: 'px-2 py-2 text-left font-medium', text: block.rowHeader || '' });
            }
            const name = block.rowHeader || this.l.row;
            filterInput = h('input', {
                type: 'search', value: this.rowFilters.get(filterKey) || '', autocomplete: 'off', placeholder: this.l.filter_rows,
                'aria-label': this.l.filter_rows_by.replace('%column%', name),
                class: 'mt-1 block w-full min-w-[8rem] rounded-md border border-gray-200 bg-white px-2 py-1 text-xs font-normal text-gray-800 placeholder:text-gray-400 focus:border-forest-500 focus:outline-none focus:ring-1 focus:ring-forest-500/30',
                oninput: (e) => { this.rowFilters.set(filterKey, e.target.value); applyFilter(); },
                onkeydown: (e) => { if (e.key === 'Escape' && e.target.value !== '') { e.preventDefault(); e.target.value = ''; this.rowFilters.delete(filterKey); applyFilter(); } },
            });

            return h('th', { scope: 'col', class: 'px-2 py-2 text-left font-medium' }, [h('span', { class: 'block', text: block.rowHeader || '' }), filterInput]);
        };
        // A column that reads each delivery is not typed here: it says where to correct it.
        const read = (c) => delivered && Boolean(c.sourceField);
        const head = h('tr', {}, [
            ...(fixed ? [firstHeader()] : []),
            ...(showStatus ? [h('th', { scope: 'col', class: 'w-32 px-2 py-2 text-left font-medium', text: this.l.delivered_status })] : []),
            ...block.columns.map((c) => h('th', { scope: 'col', class: (narrow(c) ? 'w-36 ' : '') + 'px-2 py-2 text-left font-medium', title: read(c) ? this.l.delivered_cell_title : null }, [c.label, c.required ? h('span', { class: 'text-red-600', 'aria-hidden': 'true', text: ' *' }) : null])),
            ...(fixed ? [] : [h('th', { scope: 'col', class: 'w-10 px-2 py-2' }, [h('span', { class: 'sr-only', text: this.l.remove_row })])]),
        ]);

        // A row taken from a list that no longer belongs here (the item was deactivated or moved, or it is no longer of who delivers it)
        // stays only while it holds something: it is marked and can be emptied in one go, which is what lets the document be delivered.
        const gone = new Set((block.rows || []).filter((r) => r.gone).map((r) => r.key));
        const rowHead = (row, index) => {
            const text = counted ? String(index + 1) : labels.get(row._key) ?? '';
            if (delivered) {
                return this.deliveredRowHead(block, rowInfo.get(row._key) || {}, text, source);
            }
            if (!gone.has(row._key)) {
                return h('th', { scope: 'row', class: 'px-2 py-2 text-left align-top font-normal text-gray-700', text });
            }
            return h('th', { scope: 'row', class: 'px-2 py-2 text-left align-top font-normal text-amber-800' }, [
                h('span', { class: 'block line-through', text }),
                h('button', {
                    type: 'button', class: 'mt-1 text-xs underline decoration-dotted', disabled: this.readOnly, text: this.l.row_gone_clear,
                    onclick: () => {
                        for (const c of block.columns) { delete row[c.key]; }
                        this.touch();
                        this.refresh();
                    },
                }),
            ]);
        };
        const body = h('tbody', { class: 'divide-y divide-gray-100' }, rows.map((row, index) => rowEls[index] = h('tr', { class: gone.has(row._key) ? 'bg-amber-50' : null }, [
            ...(fixed ? [rowHead(row, index)] : []),
            ...(showStatus ? [this.deliveredStatusCell(rowInfo.get(row._key) || {})] : []),
            ...block.columns.map((c) => read(c) ? this.deliveredCell(block, row, c, cellClass(c)) : h('td', { class: cellClass(c) }, [
                this.tag(
                    this.control({ ...c, label: c.label + ' · ' + this.l.row + ' ' + (index + 1) }, row[c.key], (v) => this.setIn(row, c.key, v), null, true, { chain: inst.chain, map: row, key: c.key }),
                    [inst.path, block.key, row._key, c.key].join('|'),
                ),
            ])),
            ...(fixed ? [] : [h('td', { class: 'px-2 py-1.5 align-top' }, [
                h('button', {
                    type: 'button', class: BTN + ' text-red-700', disabled: this.readOnly, title: this.l.remove_row, 'aria-label': this.l.remove_row, text: '✕',
                    onclick: () => { rows.splice(index, 1); this.refresh(); },
                }),
            ])]),
        ])));

        // The foot: the totals the columns ask for, recalculated as the rows change.
        const footValues = () => totals(block.columns, rows, inst.scope.fields);
        const foot = footValues() === null ? null : h('tfoot', { class: 'border-t border-gray-200 bg-gray-50 font-medium text-gray-900' }, [h('tr', {}, [
            ...(fixed ? [h('th', { scope: 'row', class: 'px-2 py-2 text-left', text: this.l.total })] : []),
            ...(showStatus ? [h('td')] : []),
            ...block.columns.map((c, i) => {
                const cell = h('td', { class: 'px-2 py-2', text: footValues()[i] });
                this.calcEls.push({ el: cell, read: () => (footValues() || [])[i] || '' });
                return cell;
            }),
            ...(fixed ? [] : [h('td')]),
        ])]);

        // A fixed (or counted) table says how many of its rows have something: «Rellenas 2 de 14», kept up to date as they are typed.
        const typed = (c) => c.fieldType !== 'calculated';
        const filledRows = () => rows.filter((r) => block.columns.some((c) => typed(c) && !this.isEmpty(r[c.key]))).length;
        let counter = null;
        let shownRows = rows.length;
        if (fixed && rows.length > 0) {
            counter = h('span', { class: 'shrink-0 text-xs text-gray-500' });
            // A table of deliveries counts what is delivered, not what is typed here.
            const done = () => (delivered ? rows.filter((r) => (rowInfo.get(r._key) || {}).status === 'delivered').length : filledRows());
            const show = () => {
                counter.textContent = (delivered ? this.l.delivered_count : this.l.rows_filled).replace('%done%', String(done())).replace('%total%', String(rows.length))
                    + (shownRows < rows.length ? ' · ' + this.l.rows_shown.replace('%shown%', String(shownRows)).replace('%total%', String(rows.length)) : '');
            };
            show();
            this.calcEls.push({ update: show });
            const countAgain = show;
            // The filter: hides the rows whose first column does not hold what is typed (without accents or capitals), and says how many are left.
            applyFilter = () => {
                const query = fold(this.rowFilters.get(filterKey) || '').trim();
                shownRows = 0;
                rows.forEach((row, index) => {
                    const text = counted ? String(index + 1) : labels.get(row._key) ?? '';
                    const visible = query === '' || fold(text).includes(query);
                    rowEls[index].hidden = !visible;
                    if (visible) { shownRows++; }
                });
                countAgain();
                noMatch.hidden = shownRows > 0;
            };
        }
        // No row has what is typed: said under the table, with the way out.
        const noMatch = h('p', { class: 'mt-1 text-xs text-gray-500', hidden: true, role: 'status' }, [
            this.l.filter_rows_none + ' ',
            h('button', { type: 'button', class: 'underline decoration-dotted', text: this.l.filter_rows_clear, onclick: () => { this.rowFilters.delete(filterKey); if (filterInput) { filterInput.value = ''; } applyFilter(); filterInput?.focus(); } }),
        ]);
        if (automatic && rows.length > 0 && (this.rowFilters.get(filterKey) || '') !== '') {
            applyFilter();
        }
        // A long table keeps its header in sight: it scrolls inside its box, with the header stuck at the top.
        const long = rows.length > 10;
        if (long && foot) {
            foot.classList.add('sticky', 'bottom-0');   // and the totals at the bottom
        }

        if (delivered && rows.length === 0) {
            const none = (block.sourceScope || 'own') === 'own' && !source.assumedAll ? this.l.delivered_none_own : this.l.delivered_none;
            return [
                ...this.deliveredHeader(block, inst, source, null),
                h('p', { class: 'rounded-xl border border-dashed border-gray-300 px-4 py-3 text-sm text-gray-500', text: none.replace('%title%', source.title || '') }),
            ];
        }

        return [
            ...(delivered ? this.deliveredHeader(block, inst, source, counter) : [h('div', { class: 'mb-1 flex items-end justify-between gap-2' }, [
                block.label ? h('p', { class: 'min-w-0 text-sm font-medium text-gray-700' }, [this.liveText('span', {}, block.label, inst)]) : h('span'),
                counter,
            ])]),
            h('div', { class: 'overflow-x-auto rounded-xl border border-gray-200' + (long ? ' max-h-[32rem] overflow-y-auto' : '') }, [
                h('table', { class: 'min-w-full text-sm' }, [h('thead', { class: 'bg-gray-50 text-gray-600' + (long ? ' sticky top-0 z-10 shadow-[0_1px_0_0_rgb(229_231_235)]' : '') }, [head]), body, ...(foot ? [foot] : [])]),
            ]),
            ...(automatic && rows.length > 0 ? [noMatch] : []),
            ...(counted && rows.length === 0 ? [h('p', { class: 'mt-1 text-xs text-gray-500', text: this.l.counted_empty })] : []),
            ...(fixed ? [] : [h('button', {
                type: 'button', class: BTN + ' mt-2', disabled: this.readOnly || rows.length >= max, text: '+ ' + this.l.add_row,
                onclick: () => { rows.push({ _key: newKey() }); this.refresh(); },
            })]),
            ...(block.help ? [h('p', { class: 'mt-1 text-xs text-gray-500', text: block.help })] : []),
        ];
    }

    // ── Tables of deliveries ────────────────────────────────────────────────
    // A row per delivery of another activity: the server reads them (when the page opens, and with «Actualizar») and the page only
    // draws them; their cells are read-only and the own columns are typed as in any table.

    /** Above the table: where the rows come from and when they were read, how many are delivered, and «Actualizar». */
    deliveredHeader(block, inst, source, counter) {
        const at = source.at ? new Date(source.at) : null;
        const time = at && !Number.isNaN(at.getTime()) ? at.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' }) : '';
        const today = at && at.toDateString() === new Date().toDateString();
        const when = time === '' ? '' : (today ? this.l.delivered_at_today : this.l.delivered_at_day)
            .replace('%time%', time).replace('%date%', at.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit' }));
        const url = (this.configValue.delivered?.activities || {})[block.key];
        const title = url
            ? h('a', { href: url, class: 'font-medium text-forest-700 hover:underline', text: source.title || '' })
            : h('span', { class: 'font-medium', text: source.title || '' });
        const busy = this.refreshing === block.key;
        const offline = typeof navigator !== 'undefined' && navigator.onLine === false;
        const button = h('button', {
            type: 'button', class: BTN + ' inline-flex items-center gap-1.5', 'data-refresh-delivered': block.key,
            disabled: this.readOnly || busy || offline || !this.configValue.refreshUrl,
            title: offline ? this.l.delivered_offline : null,
            onclick: () => this.refreshDelivered(block.key),
        }, [icon('arrow-path', 'h-3.5 w-3.5' + (busy ? ' animate-spin' : '')), busy ? this.l.delivered_refreshing : this.l.delivered_refresh]);
        const note = this.deliveredNotes.get(block.key);

        return [
            ...(block.label ? [h('p', { class: 'mb-1 text-sm font-medium text-gray-700' }, [this.liveText('span', {}, block.label, inst)])] : []),
            // On a phone: where it comes from on one line, the count and the button on the next.
            h('div', { class: 'mb-1 flex flex-wrap items-center justify-between gap-x-3 gap-y-1' }, [
                h('p', { class: 'min-w-0 text-xs text-gray-500' }, [
                    this.l.delivered_from.split('%title%')[0], title, this.l.delivered_from.split('%title%')[1] || '',
                    // The values both activities share: only the deliveries of this document's («· de la 1ª»).
                    Array.isArray(source.within) && source.within.length > 0 ? ' · ' + this.l.delivered_within.replace('%values%', source.within.join(', ')) : '',
                    when ? ' · ' + when : '',
                ]),
                h('div', { class: 'flex shrink-0 items-center gap-2' }, [counter, button]),
            ]),
            ...(source.assumedAll ? [h('p', { class: 'mb-1 text-xs text-gray-500', text: this.l.delivered_assumed_all })] : []),
            ...(note ? [h('p', { class: 'mb-1 text-xs font-medium text-amber-800', role: 'status', text: note })] : []),
        ];
    }

    /** The first column of a row: the name of the delivery (a link to it when it is delivered and may be opened) and who teaches it. */
    deliveredRowHead(block, info, text, source) {
        const links = this.configValue.delivered?.links || {};
        const canOpen = info.status === 'delivered' && info.revision && links[block.key] === true && this.configValue.delivered?.revisionUrl;
        const teachers = (info.teachers || []).join(', ');

        return h('th', { scope: 'row', class: 'px-2 py-2 text-left align-top font-normal text-gray-700' }, [
            h('span', { class: 'inline-flex items-start gap-1' }, [
                h('span', { text }),
                ...(canOpen ? [h('a', {
                    href: this.configValue.delivered.revisionUrl.replace('__ID__', info.revision), target: '_blank', rel: 'noopener',
                    class: 'text-gray-400 hover:text-forest-700', title: this.l.delivered_open, 'aria-label': this.l.delivered_open + ': ' + text,
                }, [icon('arrow-top-right-on-square', 'h-3.5 w-3.5')])] : []),
            ]),
            ...(teachers !== ''
                ? [h('span', { class: 'block text-xs text-gray-500', text: teachers })]
                : (source.teachersAsked ? [h('span', { class: 'block text-xs text-gray-400', text: this.l.delivered_no_teacher })] : [])),
            ...this.remindButton(block, info),
        ]);
    }

    /**
     * «Recordar» under a delivery not delivered yet: an e-mail to whoever owes it (the server checks the row, who owes it and that it
     * was not reminded in the last hour). What it answered stays in place of the button until the page is reloaded.
     */
    remindButton(block, info) {
        if (this.readOnly || !this.configValue.remindUrl || !info.key || (info.status || 'none') === 'delivered') {
            return [];
        }
        const id = block.key + '|' + info.key;
        this.reminded ??= new Map();
        const done = this.reminded.get(id);
        if (done) {
            return [h('span', { class: 'mt-0.5 block text-xs ' + (done.ok ? 'text-forest-700' : 'text-amber-700'), role: 'status', text: done.text, 'data-testid': 'remind-result' })];
        }

        return [h('button', {
            type: 'button', class: 'mt-0.5 inline-flex items-center gap-1 text-xs font-medium text-gray-500 underline decoration-dotted hover:text-forest-700',
            title: this.l.remind_title, 'data-testid': 'remind-delivery', text: this.l.remind_button,
            onclick: async (e) => {
                e.currentTarget.disabled = true;
                e.currentTarget.textContent = this.l.remind_sending;
                let result;
                try {
                    const response = await fetch(this.configValue.remindUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.configValue.csrf },
                        body: JSON.stringify({ table: block.key, row: info.key }),
                    });
                    const body = await response.json().catch(() => ({}));
                    result = { ok: response.ok && (body.sent || 0) > 0, text: body.message || this.l.offline };
                } catch (err) {
                    result = { ok: false, text: this.l.offline };
                }
                this.reminded.set(id, result);
                this.render();
            },
        })];
    }

    /** The state of a delivery: delivered (and how its review goes), a draft, or not started. */
    deliveredStatusCell(info) {
        const status = info.status || 'none';
        const tone = { delivered: 'bg-forest-50 text-forest-700', draft: 'bg-gray-100 text-gray-700', none: 'bg-gray-50 text-gray-400' }[status] || 'bg-gray-50 text-gray-400';

        return h('td', { class: 'px-2 py-2 align-top' }, [
            h('span', { class: 'inline-block rounded-full px-2 py-0.5 text-xs font-medium ' + tone, text: this.l['delivered_status_' + status] || status }),
            ...(status === 'delivered' && info.approval ? [h('span', { class: 'mt-0.5 block text-xs ' + (info.approval === 'rejected' ? 'text-red-700' : 'text-gray-500'), text: this.l['delivered_approval_' + info.approval] || '' })] : []),
        ]);
    }

    /** A cell read from a delivery: its value as it shows (unit, decimals), or a grey dash; it blinks once if «Actualizar» changed it. */
    deliveredCell(block, row, column, cls) {
        const value = row[column.key];
        // A «texto largo» keeps its formatting: the HTML the server read and sanitized (the browser cannot change these cells).
        const rich = column.fieldType === 'long' && typeof value === 'string' && /<[a-z][\s\S]*>/i.test(value);
        const text = rich ? '' : format(column, value, this.l);
        let content;
        if (rich) {
            content = h('div', { class: 'prose prose-sm max-w-none' });
            content.innerHTML = value;
        } else {
            content = text === '' ? h('span', { class: 'text-gray-300', text: '—' }) : text;
        }
        const cell = h('td', { class: cls + ' bg-gray-50 text-gray-800', 'data-cell': row._key + '|' + column.key }, [content]);
        if (this.changedCells.has(block.key + '|' + row._key + '|' + column.key)) {
            cell.classList.add('delivered-changed');
        }

        return cell;
    }

    /**
     * «Actualizar»: what is pending is saved first; the server reads the deliveries again and stores the draft; the tables of deliveries
     * take the new rows and cells (what is typed in the own columns stays: the server kept it), the formulas and totals follow, and
     * the cells that changed blink once.
     */
    async refreshDelivered(key) {
        if (this.readOnly || this.refreshing) {
            return;
        }
        this.refreshing = key;
        this.render();
        try {
            await this.flush();
            if (this.stale || this.failed) {
                return;
            }
            const response = await fetch(this.configValue.refreshUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.configValue.csrf },
                body: JSON.stringify({ version: this.version }),
            });
            const body = await response.json().catch(() => ({}));
            if (response.status === 409) {
                this.stale = true;
                this.setStatus(body.message || this.l.stale, 'text-red-700');
                return;
            }
            if (!response.ok) {
                throw new Error('refresh');
            }
            this.version = body.version;
            this.sources = asMap(body.sources);
            const rowsByTable = asMap(body.rows);
            const tables = asMap(body.tables);
            const visit = (sections) => (sections || []).forEach((s) => {
                (s.blocks || []).forEach((b) => {
                    if (b.type === 'table' && b.mode === 'delivered' && rowsByTable[b.key]) {
                        b.rows = rowsByTable[b.key];
                    }
                });
                visit(s.children);
            });
            visit(this.def.sections);
            for (const [table, rows] of Object.entries(tables)) {
                this.state.tables[table] = rows;
            }
            this.index = buildIndex(this.def);
            this.changedCells = new Set((body.changed?.cells || []).map((c) => c.table + '|' + c.row + '|' + c.column));
            const count = body.changed?.count || 0;
            this.deliveredNotes.set(key, count === 0 ? this.l.delivered_unchanged : (count === 1 ? this.l.delivered_changed_one : this.l.delivered_changed_many.replace('%count%', String(count))));
            sync(this.def.sections || [], this.state);
            calculate(this.def, this.state, this.index);
            this.showProblems(body.problems || [], body.warnings || []);
            this.setStatus(this.l.saved, 'text-forest-700');
        } catch (e) {
            this.setStatus(this.l.offline, 'text-amber-700');
        } finally {
            this.refreshing = null;
            this.render();
            // Blinked once: a later redraw does not blink them again.
            this.changedCells = new Set();
        }
    }
}
