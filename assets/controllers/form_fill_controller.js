import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';
import { mountRichText } from '../utils/rich_text_editor.js';
import { h } from '../utils/form_dom.js';
import { confirmDialog } from '../utils/confirm_dialog.js';
import { asMap, sync, walk, numbers, references, fieldSpecs, progress, newKey, MAX_COPIES, calculate, buildIndex, totals, rowsOf, format, countedCount } from '../utils/form_scopes.js';

const INPUT = 'w-full min-w-0 rounded-lg border border-gray-200 px-2.5 py-1.5 text-sm focus:border-forest-500 focus:ring-forest-500 disabled:bg-gray-50 disabled:text-gray-500';
// A native drop-down (its chevron and the room under it come from the global rule for `select` in app.css).
const SELECT = INPUT;
// What is shown wrong in a field (the look of a Tom Select box in error comes from `.has-error`, see app.css).
const INVALID = ['border-red-400', 'bg-red-50', 'focus:border-red-500', 'focus:ring-red-500'];
const BTN ='rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-forest-500 disabled:opacity-50';

/**
 * Filling in a document online. It draws the form from the model's definition and the data typed so far (App\Service\Form),
 * following the rules of repeating sections, conditions and references (assets/utils/form_scopes.js), and saves the data as it
 * changes (debounced) with the version of the draft it started from, so another tab or person cannot be overwritten unnoticed. The
 * server cleans and validates everything again; what it answers is the list of what is still missing before the document can be
 * delivered.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['canvas', 'status', 'problems', 'problemsBox', 'warnings', 'warningsBox', 'deliver'];
    static values = { config: Object, labels: Object };

    connect() {
        this.l = this.labelsValue;
        this.def = this.configValue.definition;
        this.refContext = this.configValue.context || {};
        const data = this.configValue.data || {};
        // PHP writes an empty map as [] and an array loses its string keys in JSON.stringify: always plain objects here.
        this.state = { fields: asMap(data.fields), texts: asMap(data.texts), tables: asMap(data.tables), copies: asMap(data.copies) };
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
        // What the server says is wrong, and which empty required fields have been left (or asked for) and so are shown in red.
        this.problemList = this.configValue.problems || [];
        this.revealed = new Set();
        this.showAll = false;
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
            this.statusTarget.className = 'mb-3 text-sm ' + tone;
        }
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
                    this.markInvalid();
                    (document.getElementById('blk-' + p.path + '-' + p.block) || document.getElementById('blk-' + p.block))?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                },
            })]));
    }

    showProblems(problems, warnings = []) {
        this.problemList = problems;
        this.markInvalid();
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
            entry.el.textContent = entry.prefix + references(entry.text, entry.inst, this.specs, this.refContext, this.l);
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
        const heading = section.title || canRemove
            ? h('div', { class: 'flex items-start justify-between gap-2' }, [
                this.liveText(level === 1 ? 'h2' : 'h3', { class: (level === 1 ? 'border-b border-gray-200 pb-1 text-lg' : 'text-base') + ' min-w-0 flex-1 font-semibold text-gray-900' }, section.title || '', inst, number ? number + '. ' : ''),
                ...(canRemove ? [h('button', {
                    type: 'button', class: BTN + ' text-red-700', title: this.l.remove_copy, 'aria-label': this.l.remove_copy, text: '✕',
                    onclick: () => this.removeCopy(inst),
                })] : []),
            ])
            : null;

        return h('section', { id: 'sec-' + inst.path, class: level === 1 ? 'mt-6 first:mt-0' : 'mt-5' }, [
            heading,
            ...(section.help ? [h('p', { class: 'mt-1 text-sm text-gray-500', text: section.help })] : []),
            h('div', { class: 'mt-3 space-y-4' }, inst.blocks.filter((b) => b.visible).map((b) => this.blockEl(b.block, inst))),
            ...inst.children.map((c) => this.instEl(c, level + 1)),
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
        const button = h('button', {
            type: 'button', class: 'mt-1 text-xs text-gray-400 underline decoration-dotted hover:text-forest-700', text: this.l.history,
            onclick: () => { panel.classList.toggle('hidden'); if (!panel.classList.contains('hidden')) { load(); } },
        });

        return h('div', {}, [button, panel]);
    }

    blockEl(block, inst) {
        const wrap = (content) => h('div', { id: 'blk-' + inst.path + '-' + block.key }, content);
        if (block.type === 'text') {
            const text = this.textEl(block, inst);

            return wrap(block.mode === 'fixed' ? text : [...text, this.historyEl(block, inst)]);
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
            return wrap([
                this.labelEl(block, inst, id),
                control,
                ...(block.help ? [h('p', { class: 'mt-1 text-xs text-gray-500', text: block.help })] : []),
                block.fieldType === 'calculated' ? null : this.historyEl(block, inst),
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
                const input = h('input', {
                    ...base, type: 'number', step: 'any', inputmode: 'decimal', value: value ?? '',
                    min: min ?? null, max: max ?? null,
                    oninput: (e) => {
                        onChange(e.target.value === '' ? null : Number(e.target.value));
                        // At once, without waiting for the save: a value outside the limits turns the box red as it is typed.
                        const n = e.target.value === '' ? null : Number(e.target.value);
                        const wrong = n !== null && ((min !== null && n < min) || (max !== null && n > max));
                        this.limitMark(e.target, wrong ? (min !== null && n < min ? this.l.err_min.replace('%min%', String(min)) : this.l.err_max.replace('%max%', String(max))) : null);
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
                const pill = (n) => {
                    const radio = h('input', {
                        type: 'radio', name, value: String(n), checked: value === n, disabled, class: 'peer sr-only',
                        onchange: () => { clear.hidden = !!spec.required; onChange(n); },
                    });
                    radios.push(radio);
                    return h('label', { class: 'cursor-pointer' }, [
                        radio,
                        h('span', {
                            class: 'flex h-9 min-w-9 items-center justify-center rounded-lg border border-gray-200 px-2 text-sm text-gray-700 hover:bg-gray-50 peer-checked:border-forest-600 peer-checked:bg-forest-600 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-forest-500 peer-disabled:cursor-default',
                            text: String(n),
                        }),
                    ]);
                };
                return h('fieldset', { id, class: 'min-w-0' }, [
                    h('legend', { class: 'sr-only', text: spec.label || '' }),
                    h('div', { class: 'flex flex-wrap items-center gap-1.5' }, [
                        spec.lowLabel ? h('span', { class: 'mr-1 text-xs text-gray-500', text: spec.lowLabel }) : null,
                        ...Array.from({ length: points }, (_, i) => pill(i + 1)),
                        spec.highLabel ? h('span', { class: 'ml-1 text-xs text-gray-500', text: spec.highLabel }) : null,
                        h('span', { class: 'ml-2' }, [clear]),
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
        const list = h('ul', { class: 'space-y-1' });
        const status = h('p', { class: 'text-xs text-gray-500', 'aria-live': 'polite' });
        const input = h('input', { type: 'file', multiple: true, class: 'sr-only', id: id ? id + '-files' : null, disabled: this.readOnly });
        const box = h('label', {
            for: input.id || null,
            class: 'flex cursor-pointer flex-col items-center gap-1 rounded-lg border border-dashed border-gray-300 px-3 py-3 text-center text-sm text-gray-600 hover:bg-gray-50 focus-within:ring-2 focus-within:ring-forest-500',
        }, [
            input,
            h('span', { class: 'font-medium text-forest-700', text: this.l.attachment_add }),
            h('span', { class: 'text-xs text-gray-500', text: this.l.attachment_drop }),
            h('span', { class: 'text-xs text-gray-400', text: this.l.attachment_hint.replace('%max%', size(config.fileMaxBytes || 10485760)) }),
        ]);

        const draw = () => {
            list.replaceChildren(...files.map((file) => h('li', { class: 'flex items-center gap-2 rounded-lg border border-gray-200 px-2.5 py-1.5 text-sm' }, [
                h('a', { href: urlOf(file), class: 'min-w-0 flex-1 truncate font-medium text-forest-700 underline decoration-dotted', text: file.name }),
                h('span', { class: 'shrink-0 text-xs text-gray-500', text: size(file.size || 0) }),
                this.readOnly ? null : h('button', {
                    type: 'button', class: 'shrink-0 rounded p-1 text-gray-500 hover:bg-gray-100 hover:text-red-700',
                    'aria-label': this.l.attachment_remove.replace('%name%', file.name), text: '✕',
                    onclick: () => { files = files.filter((f) => f.id !== file.id); draw(); onChange(files.length ? [...files] : null); },
                }),
            ])));
            box.hidden = this.readOnly || files.length >= max;
            if (!this.readOnly && files.length >= max) {
                status.textContent = this.l.attachment_full.replace('%max%', String(max));
            }
        };

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
                status.textContent = this.l.attachment_uploading.replace('%name%', file.name);
                const body = new FormData();
                body.append('field', spec.key);
                body.append('file', file);
                try {
                    const response = await fetch(config.attachUrl, { method: 'POST', headers: { 'X-CSRF-Token': config.csrf }, body });
                    const answer = await response.json().catch(() => ({}));
                    if (!response.ok || !answer.id) {
                        status.textContent = answer.message || this.l.attachment_error;
                        continue;
                    }
                    files.push({ id: answer.id, name: answer.name, size: answer.size, mime: answer.mime });
                    status.textContent = '';
                    draw();
                    onChange([...files]);
                } catch (e) {
                    status.textContent = this.l.attachment_offline;
                }
            }
        };

        input.addEventListener('change', () => { upload([...input.files]); input.value = ''; });
        box.addEventListener('dragover', (e) => { e.preventDefault(); box.classList.add('bg-forest-50'); });
        box.addEventListener('dragleave', () => box.classList.remove('bg-forest-50'));
        box.addEventListener('drop', (e) => { e.preventDefault(); box.classList.remove('bg-forest-50'); upload([...(e.dataTransfer?.files || [])]); });
        draw();

        return h('div', { id, class: 'space-y-2' }, [list, box, status]);
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
            const el = h('div', { class: 'prose prose-sm max-w-none text-gray-800' });
            el.innerHTML = block.html || '';
            return el;
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
                const content = h('div', { class: 'prose prose-sm max-w-none' });
                content.innerHTML = entry.html;
                box.append(content);
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
        const fixed = block.mode === 'fixed' || counted;
        const tables = inst.scope.tables;
        if (!Array.isArray(tables[block.key])) {
            tables[block.key] = [];
        }
        const rows = tables[block.key];
        const labels = new Map((block.rows || []).map((r) => [r.key, r.label]));
        const max = block.maxRows > 0 ? block.maxRows : 1000;

        const head = h('tr', {}, [
            ...(fixed ? [h('th', { scope: 'col', class: 'px-2 py-2 text-left font-medium', text: block.rowHeader || '' })] : []),
            ...block.columns.map((c) => h('th', { scope: 'col', class: 'px-2 py-2 text-left font-medium' }, [c.label, c.required ? h('span', { class: 'text-red-600', 'aria-hidden': 'true', text: ' *' }) : null])),
            ...(fixed ? [] : [h('th', { scope: 'col', class: 'w-10 px-2 py-2' }, [h('span', { class: 'sr-only', text: this.l.remove_row })])]),
        ]);

        const body = h('tbody', { class: 'divide-y divide-gray-100' }, rows.map((row, index) => h('tr', {}, [
            ...(fixed ? [h('th', { scope: 'row', class: 'px-2 py-2 text-left align-top font-normal text-gray-700', text: counted ? String(index + 1) : labels.get(row._key) ?? '' })] : []),
            ...block.columns.map((c) => h('td', { class: 'min-w-[9rem] px-2 py-1.5 align-top' }, [
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
            ...block.columns.map((c, i) => {
                const cell = h('td', { class: 'px-2 py-2', text: footValues()[i] });
                this.calcEls.push({ el: cell, read: () => (footValues() || [])[i] || '' });
                return cell;
            }),
            ...(fixed ? [] : [h('td')]),
        ])]);

        return [
            ...(block.label ? [h('p', { class: 'mb-1 text-sm font-medium text-gray-700' }, [this.liveText('span', {}, block.label, inst)])] : []),
            h('div', { class: 'overflow-x-auto rounded-xl border border-gray-200' }, [
                h('table', { class: 'min-w-full text-sm' }, [h('thead', { class: 'bg-gray-50 text-gray-600' }, [head]), body, ...(foot ? [foot] : [])]),
            ]),
            ...(counted && rows.length === 0 ? [h('p', { class: 'mt-1 text-xs text-gray-500', text: this.l.counted_empty })] : []),
            ...(fixed ? [] : [h('button', {
                type: 'button', class: BTN + ' mt-2', disabled: this.readOnly || rows.length >= max, text: '+ ' + this.l.add_row,
                onclick: () => { rows.push({ _key: newKey() }); this.refresh(); },
            })]),
            ...(block.help ? [h('p', { class: 'mt-1 text-xs text-gray-500', text: block.help })] : []),
        ];
    }
}
