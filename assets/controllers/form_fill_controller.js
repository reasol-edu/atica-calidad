import { Controller } from '@hotwired/stimulus';
import Quill from 'quill';
import 'quill/dist/quill.snow.css';
import { h } from '../utils/form_dom.js';
import { asMap, sync, walk, numbers, references, fieldSpecs, progress, newKey, MAX_COPIES, calculate, buildIndex, totals, rowsOf, format } from '../utils/form_scopes.js';

Quill.register(Quill.import('attributors/style/align'), true);

const INPUT = 'w-full min-w-0 rounded-lg border border-gray-200 px-2.5 py-1.5 text-sm focus:border-forest-500 focus:ring-forest-500 disabled:bg-gray-50 disabled:text-gray-500';
const BTN = 'rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-forest-500 disabled:opacity-50';

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
        this.index = buildIndex(this.def);
        sync(this.def.sections || [], this.state);
        calculate(this.def, this.state, this.index);
        this.specs = fieldSpecs(this.def);
        this.structural = this.structuralFields();
        this.version = this.configValue.version;
        this.readOnly = this.configValue.readOnly === true;
        this.dirty = false;
        this.saving = false;
        this.stale = false;
        this.failed = false;
        this.timer = null;
        this.live = [];

        this.onHide = () => { if (this.dirty && !this.stale) { this.save(true); } };
        window.addEventListener('pagehide', this.onHide);
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
    }

    disconnect() {
        clearTimeout(this.timer);
        window.removeEventListener('pagehide', this.onHide);
        if (this.hasDeliverTarget && this.onSubmit) {
            this.deliverTarget.form.removeEventListener('submit', this.onSubmit);
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
                this.stale = true;
                this.setStatus(this.l.stale, 'text-red-700');
                return;
            }
            if (!response.ok) {
                throw new Error('save');
            }
            this.failed = false;
            this.version = body.version;
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
                onclick: () => (document.getElementById('blk-' + p.path + '-' + p.block) || document.getElementById('blk-' + p.block))?.scrollIntoView({ behavior: 'smooth', block: 'center' }),
            })]));
    }

    showProblems(problems, warnings = []) {
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
        this.live = [];
        this.calcEls = [];
        this.tree = walk(this.def, this.state);
        this.nums = numbers(this.tree);
        this.progressEl = h('div', { class: 'mb-5' });
        this.canvasTarget.replaceChildren(this.progressEl, ...this.tree.map((i) => this.instEl(i, 1)));
        this.updateProgress();
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

    removeCopy(inst) {
        if (!window.confirm(this.l.confirm_remove_copy)) {
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

    blockEl(block, inst) {
        const wrap = (content) => h('div', { id: 'blk-' + inst.path + '-' + block.key }, content);
        if (block.type === 'text') {
            return wrap(this.textEl(block, inst));
        }
        if (block.type === 'field') {
            const id = 'f-' + inst.path + '-' + block.key;
            const control = this.control(block, inst.scope.fields[block.key], (v) => this.setIn(inst.scope.fields, block.key, v), id, false, { chain: inst.chain, map: inst.scope.fields, key: block.key });
            if (this.structural.has(block.key)) {
                control.addEventListener('change', () => this.refresh());
            }
            return wrap([
                this.labelEl(block, inst, id),
                control,
                ...(block.help ? [h('p', { class: 'mt-1 text-xs text-gray-500', text: block.help })] : []),
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
                return h('textarea', { ...base, rows: 4, maxlength: 20000, value: value ?? '', oninput: (e) => onChange(e.target.value) });
            case 'number': {
                const input = h('input', { ...base, type: 'number', step: 'any', inputmode: 'decimal', value: value ?? '', oninput: (e) => onChange(e.target.value === '' ? null : Number(e.target.value)) });
                return spec.unit ? h('div', { class: 'flex items-center gap-2' }, [input, h('span', { class: 'shrink-0 text-sm text-gray-500', text: spec.unit })]) : input;
            }
            case 'date':
                return h('input', { ...base, type: 'date', value: value ?? '', oninput: (e) => onChange(e.target.value || null) });
            case 'time':
                return h('input', { ...base, type: 'time', value: value ?? '', oninput: (e) => onChange(e.target.value || null) });
            case 'bool':
                return h('select', { ...base, onchange: (e) => onChange(e.target.value === '' ? null : e.target.value === '1') }, [
                    h('option', { value: '', text: this.l.choose, selected: value === undefined || value === null }),
                    h('option', { value: '1', text: this.l.yes, selected: value === true }),
                    h('option', { value: '0', text: this.l.no, selected: value === false }),
                ]);
            case 'choice':
                return h('select', { ...base, onchange: (e) => onChange(e.target.value || null) }, [
                    h('option', { value: '', text: this.l.choose, selected: !value }),
                    ...(spec.options || []).map((o) => h('option', { value: o.key, text: o.label, selected: value === o.key })),
                ]);
            case 'multichoice': {
                const current = Array.isArray(value) ? [...value] : [];
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
            const quill = new Quill(el, {
                theme: 'snow',
                formats: ['header', 'bold', 'italic', 'underline', 'blockquote', 'list', 'link', 'align'],
                modules: { toolbar: [[{ header: [2, false] }], ['bold', 'italic', 'underline'], ['blockquote'], [{ list: 'ordered' }, { list: 'bullet' }], [{ align: [] }], ['link'], ['clean']] },
            });
            if (entry.html) {
                quill.clipboard.dangerouslyPasteHTML(entry.html);
            }
            quill.on('text-change', (delta, old, source) => {
                if (source !== 'user') {
                    return;
                }
                const html = quill.root.innerHTML;
                entry.html = html === '<p><br></p>' ? '' : html;
                entry.modified = block.mode === 'starter' && entry.html !== (block.html || '');
                refreshBadge();
                if (entry.html === '' && block.mode === 'fill') {
                    delete texts[block.key];
                } else {
                    texts[block.key] = entry;
                }
                this.touch();
            });
            quill.focus();
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
        const fixed = block.mode === 'fixed';
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
            ...(fixed ? [h('th', { scope: 'row', class: 'px-2 py-2 text-left align-top font-normal text-gray-700', text: labels.get(row._key) ?? '' })] : []),
            ...block.columns.map((c) => h('td', { class: 'min-w-[9rem] px-2 py-1.5 align-top' }, [
                this.control({ ...c, label: c.label + ' · ' + this.l.row + ' ' + (index + 1) }, row[c.key], (v) => this.setIn(row, c.key, v), null, true, { chain: inst.chain, map: row, key: c.key }),
            ])),
            ...(fixed ? [] : [h('td', { class: 'px-2 py-1.5 align-top' }, [
                h('button', {
                    type: 'button', class: BTN + ' text-red-700', disabled: this.readOnly, title: this.l.remove_row, 'aria-label': this.l.remove_row, text: '✕',
                    onclick: () => { rows.splice(index, 1); this.refresh(); },
                }),
            ])]),
        ])));

        // The foot: the totals the columns ask for, recalculated as the rows change.
        const foot = totals(block.columns, rows) === null ? null : h('tfoot', { class: 'border-t border-gray-200 bg-gray-50 font-medium text-gray-900' }, [h('tr', {}, [
            ...(fixed ? [h('th', { scope: 'row', class: 'px-2 py-2 text-left', text: this.l.total })] : []),
            ...block.columns.map((c, i) => {
                const cell = h('td', { class: 'px-2 py-2', text: totals(block.columns, rows)[i] });
                this.calcEls.push({ el: cell, read: () => (totals(block.columns, rows) || [])[i] || '' });
                return cell;
            }),
            ...(fixed ? [] : [h('td')]),
        ])]);

        return [
            ...(block.label ? [h('p', { class: 'mb-1 text-sm font-medium text-gray-700' }, [this.liveText('span', {}, block.label, inst)])] : []),
            h('div', { class: 'overflow-x-auto rounded-xl border border-gray-200' }, [
                h('table', { class: 'min-w-full text-sm' }, [h('thead', { class: 'bg-gray-50 text-gray-600' }, [head]), body, ...(foot ? [foot] : [])]),
            ]),
            ...(fixed ? [] : [h('button', {
                type: 'button', class: BTN + ' mt-2', disabled: this.readOnly || rows.length >= max, text: '+ ' + this.l.add_row,
                onclick: () => { rows.push({ _key: newKey() }); this.refresh(); },
            })]),
            ...(block.help ? [h('p', { class: 'mt-1 text-xs text-gray-500', text: block.help })] : []),
        ];
    }
}
