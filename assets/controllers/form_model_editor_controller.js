import { Controller } from '@hotwired/stimulus';
import Quill from 'quill';
import 'quill/dist/quill.snow.css';
import { h, uid, sectionNumbers } from '../utils/form_dom.js';
import { parse } from '../utils/form_expression.js';

// Same as the rich text editor of the rest of the application: align is written inline, which the sanitizer lets through.
Quill.register(Quill.import('attributors/style/align'), true);

const FIELD_TYPES = ['short', 'long', 'number', 'date', 'time', 'bool', 'choice', 'multichoice', 'calculated', 'relation'];
const CELL_TYPES = ['number', 'short', 'long', 'date', 'time', 'bool'];
const FUNCTION_SNIPPETS = ['sum(', 'avg(', 'min(', 'max(', 'count(', 'count_if(', 'pct_if(', 'if(', 'round(', 'abs(', 'empty('];
const BTN = 'rounded-lg border border-gray-200 bg-white px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-forest-500';
const INPUT = 'w-full min-w-0 rounded-lg border border-gray-200 px-2.5 py-1.5 text-sm focus:border-forest-500 focus:ring-forest-500';
const SELECT = 'min-w-0 rounded-lg border border-gray-200 px-2 py-1.5 text-sm focus:border-forest-500 focus:ring-forest-500';

/**
 * The editor of a document model. It works on the definition (a JSON tree of sections, see App\Service\Form\FormDefinition),
 * builds the whole page from it and saves the draft as it changes (debounced), with the version it started from so two editors
 * cannot overwrite each other. Every element has a stable `key` that the editor creates and never shows.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['canvas', 'outline', 'status', 'problems', 'code', 'send'];
    static values = { config: Object, labels: Object };

    connect() {
        this.l = this.labelsValue;
        this.def = this.configValue.definition && Array.isArray(this.configValue.definition.sections)
            ? this.configValue.definition
            : { version: 1, sections: [] };
        this.version = this.configValue.version;
        this.dirty = false;
        this.saving = false;
        this.stale = false;
        this.timer = null;
        this.focusKey = null;

        this.onCode = () => this.touch();
        this.codeTarget.addEventListener('input', this.onCode);
        this.onHide = () => { if (this.dirty && !this.stale) { this.save(true); } };
        window.addEventListener('pagehide', this.onHide);

        this.render();
    }

    disconnect() {
        clearTimeout(this.timer);
        this.codeTarget.removeEventListener('input', this.onCode);
        window.removeEventListener('pagehide', this.onHide);
    }

    // ── Saving ───────────────────────────────────────────────────────────────

    touch() {
        if (this.stale) {
            return;
        }
        this.dirty = true;
        this.setStatus(this.l.saving, 'text-gray-500');
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.save(false), 700);
    }

    async save(keepalive) {
        if (this.saving) {
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
                body: JSON.stringify({ version: this.version, code: this.codeTarget.value, definition: this.def }),
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
            this.version = body.version;
            this.showProblems(body.problems || []);
            if (this.dirty) {
                this.setStatus(this.l.saving, 'text-gray-500');
            } else {
                this.setStatus(this.l.saved, 'text-forest-700');
            }
        } catch (e) {
            this.dirty = true;
            this.setStatus(this.l.offline, 'text-amber-700');
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.save(false), 5000);
        } finally {
            this.saving = false;
        }
    }

    setStatus(text, tone) {
        this.statusTarget.textContent = text;
        this.statusTarget.className = 'mt-3 text-sm ' + tone;
    }

    showProblems(problems) {
        this.problemsTarget.replaceChildren(...problems.map((p) => h('li', { text: p })));
        this.sendTarget.disabled = false;
    }

    // ── Structure operations ────────────────────────────────────────────────

    /** The list a section is in, its index and its parent section (null at the top). */
    locate(key, list = this.def.sections, parent = null) {
        for (let i = 0; i < list.length; i++) {
            if (list[i].key === key) {
                return { list, index: i, parent };
            }
            const found = this.locate(key, list[i].children, list[i]);
            if (found) {
                return found;
            }
        }
        return null;
    }

    newSection() {
        return { key: uid(), title: '', numbered: true, help: '', blocks: [], children: [] };
    }

    changed(focusKey = null) {
        this.focusKey = focusKey;
        this.render();
        this.touch();
    }

    addSection(list) {
        const section = this.newSection();
        list.push(section);
        this.changed(section.key);
    }

    moveSection(key, delta) {
        const at = this.locate(key);
        const to = at.index + delta;
        if (to < 0 || to >= at.list.length) {
            return;
        }
        [at.list[at.index], at.list[to]] = [at.list[to], at.list[at.index]];
        this.changed(key);
    }

    indentSection(key) {
        const at = this.locate(key);
        if (at.index === 0) {
            return;
        }
        const [section] = at.list.splice(at.index, 1);
        at.list[at.index - 1].children.push(section);
        this.changed(key);
    }

    outdentSection(key) {
        const at = this.locate(key);
        if (at.parent === null) {
            return;
        }
        const [section] = at.list.splice(at.index, 1);
        const up = this.locate(at.parent.key);
        up.list.splice(up.index + 1, 0, section);
        this.changed(key);
    }

    removeSection(key) {
        if (!window.confirm(this.l.confirm_remove)) {
            return;
        }
        const at = this.locate(key);
        at.list.splice(at.index, 1);
        this.changed();
    }

    addBlock(section, type) {
        const block = { key: uid(), type };
        if (type === 'text') {
            Object.assign(block, { mode: 'fixed', label: '', html: '', required: false, help: '' });
        } else if (type === 'field') {
            Object.assign(block, { name: '', label: '', fieldType: 'short', required: false, options: [], min: null, max: null, unit: '', help: '' });
        } else {
            Object.assign(block, { name: '', label: '', mode: 'free', rowHeader: '', columns: [this.newColumn()], rows: [], minRows: 0, maxRows: 0, help: '' });
        }
        section.blocks.push(block);
        this.changed(block.key);
    }

    newColumn() {
        return { key: uid(), name: '', label: '', fieldType: 'short', required: false, options: [], min: null, max: null, unit: '' };
    }

    moveIn(list, index, delta) {
        const to = index + delta;
        if (to < 0 || to >= list.length) {
            return;
        }
        [list[index], list[to]] = [list[to], list[index]];
        this.changed(list[to].key);
    }

    removeFrom(list, index) {
        if (!window.confirm(this.l.confirm_remove)) {
            return;
        }
        list.splice(index, 1);
        this.changed();
    }

    // ── Rendering ────────────────────────────────────────────────────────────

    render() {
        const numbers = sectionNumbers(this.def.sections);
        this.canvasTarget.replaceChildren(
            ...this.def.sections.map((s) => this.sectionEl(s, numbers, 0)),
            ...(this.def.sections.length === 0 ? [h('p', { class: 'rounded-xl border border-dashed border-gray-300 px-4 py-6 text-center text-sm text-gray-500', text: this.l.no_sections })] : []),
            h('button', { type: 'button', class: BTN + ' py-2', text: '+ ' + this.l.add_section, onclick: () => this.addSection(this.def.sections) }),
            this.rulesEl(),
            this.approvalEl(),
        );
        this.outlineTarget.replaceChildren(...this.outlineItems(this.def.sections, numbers, 0));
        if (this.focusKey) {
            const target = this.canvasTarget.querySelector('[data-focus="' + this.focusKey + '"]');
            if (target) {
                target.focus();
                target.scrollIntoView({ block: 'nearest' });
            }
            this.focusKey = null;
        }
    }

    /** The approval chain of the documents filled with this model: ordered steps, each decided by whoever holds a profile of the centre. */
    approvalEl() {
        this.def.approval = Array.isArray(this.def.approval) ? this.def.approval : [];
        const steps = this.def.approval;
        const profiles = this.configValue.profiles || [];
        const rows = steps.map((step, i) => h('div', { class: 'flex flex-wrap items-end gap-2 rounded-xl border border-gray-200 bg-white p-3' }, [
            h('span', { class: 'w-6 pb-2 text-sm text-gray-400', text: (i + 1) + '.' }),
            this.textInput(step, 'name', this.l.step_name, 'flex-1 basis-48', 255),
            this.labelled(this.l.step_profile, this.select(step.profile || '', [['', this.l.no_profile], ...profiles.map((p) => [p.id, p.name])], (v) => { step.profile = v; this.touch(); }, this.l.step_profile), 'basis-48'),
            h('div', { class: 'flex items-center gap-1 pb-0.5' }, [
                h('button', { type: 'button', class: BTN, title: this.l.up, 'aria-label': this.l.up, text: '↑', disabled: i === 0, onclick: () => this.moveIn(steps, i, -1) }),
                h('button', { type: 'button', class: BTN, title: this.l.down, 'aria-label': this.l.down, text: '↓', disabled: i === steps.length - 1, onclick: () => this.moveIn(steps, i, 1) }),
                h('button', { type: 'button', class: BTN + ' text-red-700', title: this.l.remove, 'aria-label': this.l.remove, text: '✕', onclick: () => this.removeFrom(steps, i) }),
            ]),
        ]));

        return h('section', { class: 'rounded-2xl border border-gray-200 bg-white p-3 shadow-xs sm:p-4' }, [
            h('h2', { class: 'text-base font-semibold text-gray-900', text: this.l.approval }),
            h('p', { class: 'mt-1 text-xs text-gray-500', text: this.l.approval_help }),
            h('div', { class: 'mt-3 space-y-2' }, rows),
            h('button', { type: 'button', class: BTN + ' mt-3', text: '+ ' + this.l.add_step, onclick: () => { const s = { key: uid(), name: '', profile: '' }; steps.push(s); this.changed(s.key); } }),
        ]);
    }

    /** The consistency rules of the model: a condition that should hold, and what to say (and whether to stop the delivery) when not. */
    rulesEl() {
        this.def.rules = Array.isArray(this.def.rules) ? this.def.rules : [];
        const rules = this.def.rules;
        const cards = rules.map((rule, i) => h('div', { class: 'space-y-2 rounded-xl border border-gray-200 bg-white p-3' }, [
            h('div', { class: 'flex justify-end' }, [
                h('button', { type: 'button', class: BTN + ' text-red-700', title: this.l.remove, 'aria-label': this.l.remove, text: '✕', onclick: () => this.removeFrom(rules, i) }),
            ]),
            this.formulaEditor(rule, 'expr', null, this.l.rule_expr),
            h('div', { class: 'flex flex-wrap items-end gap-3' }, [
                this.textInput(rule, 'message', this.l.rule_message, 'flex-1 basis-64', 500),
                this.labelled(this.l.rule_severity, this.select(rule.severity || 'warning', [['warning', this.l.rule_warning], ['block', this.l.rule_block]], (v) => { rule.severity = v; this.touch(); }, this.l.rule_severity)),
            ]),
        ]));

        return h('section', { class: 'rounded-2xl border border-gray-200 bg-white p-3 shadow-xs sm:p-4' }, [
            h('h2', { class: 'text-base font-semibold text-gray-900', text: this.l.rules }),
            h('p', { class: 'mt-1 text-xs text-gray-500', text: this.l.rules_help }),
            h('div', { class: 'mt-3 space-y-3' }, cards),
            h('button', { type: 'button', class: BTN + ' mt-3', text: '+ ' + this.l.add_rule, onclick: () => { const r = { key: uid(), expr: '', message: '', severity: 'warning' }; rules.push(r); this.changed(); } }),
        ]);
    }

    outlineItems(sections, numbers, depth) {
        const items = [];
        for (const s of sections) {
            items.push(h('button', {
                type: 'button',
                class: 'block w-full truncate rounded-lg px-2 py-1 text-left text-sm text-gray-700 hover:bg-gray-50',
                style: 'padding-left:' + (0.5 + depth * 0.9) + 'rem',
                text: (numbers.get(s.key) ? numbers.get(s.key) + '. ' : '') + (s.title || this.l.section),
                onclick: () => document.getElementById('sec-' + s.key)?.scrollIntoView({ behavior: 'smooth', block: 'start' }),
            }));
            items.push(...this.outlineItems(s.children, numbers, depth + 1));
        }
        return items;
    }

    sectionEl(section, numbers, depth) {
        const at = this.locate(section.key);
        const number = numbers.get(section.key);
        const controls = h('div', { class: 'flex flex-wrap items-center gap-1', role: 'group', 'aria-label': this.l.block_actions }, [
            h('button', { type: 'button', class: BTN, title: this.l.up, 'aria-label': this.l.up, text: '↑', disabled: at.index === 0, onclick: () => this.moveSection(section.key, -1) }),
            h('button', { type: 'button', class: BTN, title: this.l.down, 'aria-label': this.l.down, text: '↓', disabled: at.index === at.list.length - 1, onclick: () => this.moveSection(section.key, 1) }),
            h('button', { type: 'button', class: BTN, title: this.l.indent, 'aria-label': this.l.indent, text: '→', disabled: at.index === 0, onclick: () => this.indentSection(section.key) }),
            h('button', { type: 'button', class: BTN, title: this.l.outdent, 'aria-label': this.l.outdent, text: '←', disabled: at.parent === null, onclick: () => this.outdentSection(section.key) }),
            h('button', { type: 'button', class: BTN + ' text-red-700', title: this.l.remove, 'aria-label': this.l.remove, text: '✕', onclick: () => this.removeSection(section.key) }),
        ]);

        const titleInput = h('input', {
            class: INPUT + ' font-semibold', value: section.title, placeholder: this.l.section_title, 'aria-label': this.l.section_title, maxlength: 255,
            'data-focus': section.key,
            oninput: (e) => { section.title = e.target.value; this.touch(); },
        });
        const numbered = h('label', { class: 'inline-flex items-center gap-1.5 text-xs text-gray-600' }, [
            h('input', { type: 'checkbox', checked: section.numbered !== false, onchange: (e) => { section.numbered = e.target.checked; this.changed(); } }),
            this.l.numbered,
        ]);

        return h('section', { id: 'sec-' + section.key, class: depth === 0 ? 'rounded-2xl border border-gray-200 bg-white p-3 shadow-xs sm:p-4' : 'mt-3 rounded-xl border border-gray-200 bg-gray-50/50 p-3' }, [
            h('div', { class: 'flex flex-wrap items-center gap-2' }, [
                h('span', { class: 'w-10 shrink-0 text-sm font-semibold text-forest-700', text: number ? number + '.' : '' }),
                h('div', { class: 'min-w-0 flex-1 basis-48' }, [titleInput, this.refPicker(section, (token) => this.insertIntoInput(titleInput, section, 'title', token))]),
                numbered,
                controls,
            ]),
            this.sectionOptions(section),
            h('div', { class: 'mt-3 space-y-3' }, section.blocks.map((b, i) => this.blockEl(section, b, i))),
            h('div', { class: 'mt-3 flex flex-wrap items-center gap-2' }, [
                h('button', { type: 'button', class: BTN, text: '+ ' + this.l.add_text, onclick: () => this.addBlock(section, 'text') }),
                h('button', { type: 'button', class: BTN, text: '+ ' + this.l.add_field, onclick: () => this.addBlock(section, 'field') }),
                h('button', { type: 'button', class: BTN, text: '+ ' + this.l.add_table, onclick: () => this.addBlock(section, 'table') }),
                h('span', { class: 'flex-1' }),
                h('button', { type: 'button', class: BTN, text: '+ ' + this.l.add_subsection, onclick: () => { const s = this.newSection(); section.children.push(s); this.changed(s.key); } }),
            ]),
            ...(section.children.length ? [h('div', { class: 'mt-3 ml-2 border-l-2 border-forest-100 pl-3 sm:ml-4' }, section.children.map((c) => this.sectionEl(c, numbers, depth + 1)))] : []),
        ]);
    }

    blockEl(section, block, index) {
        const kinds = { text: this.l.add_text, field: this.l.add_field, table: this.l.add_table };
        const controls = h('div', { class: 'flex items-center gap-1', role: 'group', 'aria-label': this.l.block_actions }, [
            h('button', { type: 'button', class: BTN, title: this.l.up, 'aria-label': this.l.up, text: '↑', disabled: index === 0, onclick: () => this.moveIn(section.blocks, index, -1) }),
            h('button', { type: 'button', class: BTN, title: this.l.down, 'aria-label': this.l.down, text: '↓', disabled: index === section.blocks.length - 1, onclick: () => this.moveIn(section.blocks, index, 1) }),
            h('button', { type: 'button', class: BTN + ' text-red-700', title: this.l.remove, 'aria-label': this.l.remove, text: '✕', onclick: () => this.removeFrom(section.blocks, index) }),
        ]);
        const body = block.type === 'text' ? this.textBody(block, section) : block.type === 'field' ? this.fieldBody(block) : this.tableBody(block);

        return h('div', { class: 'rounded-xl border border-gray-200 bg-white p-3' }, [
            h('div', { class: 'mb-2 flex items-center justify-between gap-2' }, [
                h('span', { class: 'rounded-full bg-forest-50 px-2.5 py-0.5 text-xs font-medium text-forest-700', text: kinds[block.type] }),
                controls,
            ]),
            body,
            this.conditionBox(block, new Set([block.key])),
        ]);
    }

    // ── Blocks ───────────────────────────────────────────────────────────────

    labelled(text, control, extra = '') {
        return h('label', { class: 'block min-w-0 ' + extra }, [h('span', { class: 'mb-1 block text-xs font-medium text-gray-600', text }), control]);
    }

    textInput(obj, prop, text, extra = '', maxlength = 255) {
        return this.labelled(text, h('input', { class: INPUT, value: obj[prop] ?? '', maxlength, 'data-focus': obj.key, oninput: (e) => { obj[prop] = e.target.value; this.touch(); } }), extra);
    }

    checkbox(obj, prop, text) {
        return h('label', { class: 'inline-flex items-center gap-1.5 text-sm text-gray-700' }, [
            h('input', { type: 'checkbox', checked: obj[prop] === true, onchange: (e) => { obj[prop] = e.target.checked; this.touch(); } }),
            text,
        ]);
    }

    select(value, options, onchange, ariaLabel) {
        return h('select', { class: SELECT, 'aria-label': ariaLabel, onchange: (e) => onchange(e.target.value) },
            options.map(([v, t]) => h('option', { value: v, text: t, selected: v === value })));
    }

    // ── Repetition, conditions and references ───────────────────────────────

    /** Every field of the document (optionally without some keys), in reading order. */
    fieldList(exclude = new Set()) {
        const out = [];
        const visit = (sections) => {
            for (const s of sections) {
                s.blocks.filter((b) => b.type === 'field' && !exclude.has(b.key)).forEach((b) => out.push(b));
                visit(s.children);
            }
        };
        visit(this.def.sections);
        return out;
    }

    tableList(exclude = new Set()) {
        const out = [];
        const visit = (sections) => {
            for (const s of sections) {
                s.blocks.filter((b) => b.type === 'table' && !exclude.has(b.key)).forEach((b) => out.push(b));
                visit(s.children);
            }
        };
        visit(this.def.sections);
        return out;
    }

    /** The keys of every block inside a section and its subsections. */
    subtreeKeys(section) {
        const keys = new Set();
        const visit = (s) => { s.blocks.forEach((b) => keys.add(b.key)); s.children.forEach(visit); };
        visit(section);
        return keys;
    }

    /** The sections from the top down to the one with that key (itself included). */
    trailTo(key, list = this.def.sections, trail = []) {
        for (const s of list) {
            if (s.key === key) {
                return [...trail, s];
            }
            const found = this.trailTo(key, s.children, [...trail, s]);
            if (found) {
                return found;
            }
        }
        return null;
    }

    /** What a reference can read where this section is: the copy, the row it comes from, any field, the context. */
    refOptions(section) {
        const options = [['copy.number', this.l.ref_copy_number], ['copy.total', this.l.ref_copy_total]];
        for (const s of this.trailTo(section.key) || []) {
            if (s.repeat && s.repeat.mode === 'table') {
                const table = this.tableList().find((t) => t.key === s.repeat.table);
                (table ? table.columns : []).forEach((c) => options.push(['row:' + c.key, this.l.ref_row + ': ' + (c.label || '…')]));
            }
        }
        this.fieldList().forEach((f) => options.push(['f:' + f.key, this.l.ref_field + ': ' + (f.label || '…')]));
        ['centre', 'year', 'activity', 'slot', 'date'].forEach((c) => options.push(['ctx:' + c, this.l['ref_ctx_' + c]]));
        return options;
    }

    /** A select that hands the chosen reference, written as {{label|ref}}, to $onPick. */
    refPicker(section, onPick) {
        const options = this.refOptions(section);
        const select = h('select', {
            class: SELECT + ' mt-1 text-xs text-gray-600', 'aria-label': this.l.insert_ref,
            onchange: (e) => {
                const chosen = options.find(([ref]) => ref === e.target.value);
                e.target.value = '';
                if (chosen) {
                    onPick('{{' + chosen[1].replace(/[{}|]/g, '') + '|' + chosen[0] + '}}');
                }
            },
        }, [h('option', { value: '', text: '{ } ' + this.l.insert_ref }), ...options.map(([ref, label]) => h('option', { value: ref, text: label }))]);

        return select;
    }

    insertIntoInput(input, owner, prop, token) {
        const start = input.selectionStart ?? input.value.length;
        const end = input.selectionEnd ?? input.value.length;
        input.value = input.value.slice(0, start) + token + input.value.slice(end);
        owner[prop] = input.value;
        input.focus();
        input.setSelectionRange(start + token.length, start + token.length);
        this.touch();
    }

    /** "Options of the section": how it repeats, whether its copies are numbered, and when it is shown. */
    sectionOptions(section) {
        const own = this.subtreeKeys(section);
        const repeat = section.repeat;
        const mode = repeat ? repeat.mode : '';
        const modes = [['', this.l.repeat_none], ['manual', this.l.repeat_manual], ['field', this.l.repeat_field], ['table', this.l.repeat_table]];
        const numbered = (obj, prop, min, max) => this.labelled(prop === 'min' ? this.l.repeat_min : this.l.repeat_max, h('input', {
            type: 'number', min: 0, max: 100, class: INPUT, value: obj[prop] ?? 0,
            oninput: (e) => { obj[prop] = Math.min(100, Math.max(0, Number(e.target.value) || 0)); this.touch(); },
        }), 'w-28');

        const parts = [
            h('div', { class: 'flex flex-wrap items-end gap-3' }, [
                this.labelled(this.l.repeat, this.select(mode, modes, (v) => {
                    if (v === '') { section.repeat = null; }
                    else if (v === 'manual') { section.repeat = { mode: 'manual', min: 0, max: 100 }; }
                    else if (v === 'field') { section.repeat = { mode: 'field', field: (this.fieldList(own).find((f) => f.fieldType === 'number') || {}).key || '' }; }
                    else { section.repeat = { mode: 'table', table: (this.tableList(own)[0] || {}).key || '' }; }
                    this.changed();
                }, this.l.repeat)),
            ]),
        ];
        if (repeat && repeat.mode === 'manual') {
            parts[0].append(numbered(repeat, 'min'), numbered(repeat, 'max'));
        }
        if (repeat && repeat.mode === 'field') {
            const fields = this.fieldList(own).filter((f) => f.fieldType === 'number').map((f) => [f.key, f.label || '…']);
            parts[0].append(this.labelled(this.l.repeat_source_field, this.select(repeat.field, [['', '—'], ...fields], (v) => { repeat.field = v; this.changed(); }, this.l.repeat_source_field)));
        }
        if (repeat && repeat.mode === 'table') {
            const tables = this.tableList(own).map((t) => [t.key, t.label || '…']);
            parts[0].append(this.labelled(this.l.repeat_source_table, this.select(repeat.table, [['', '—'], ...tables], (v) => { repeat.table = v; this.changed(); }, this.l.repeat_source_table)));
        }
        if (repeat) {
            parts.push(this.checkbox(section, 'numberCopies', this.l.number_copies));
        }
        parts.push(this.conditionEditor(section, own));

        return h('details', { class: 'mt-3 rounded-lg border border-gray-200 bg-gray-50/60 px-3 py-2', open: repeat || section.visibleIf ? true : null }, [
            h('summary', { class: 'cursor-pointer text-xs font-medium text-gray-600', text: this.l.options_summary }),
            h('div', { class: 'mt-2 space-y-3' }, parts),
        ]);
    }

    /** The condition of a block, in a collapsed box of its own (open when there is one). */
    conditionBox(block, exclude) {
        return h('details', { class: 'mt-3', open: block.visibleIf ? true : null }, [
            h('summary', { class: 'cursor-pointer text-xs font-medium text-gray-500', text: this.l.condition }),
            h('div', { class: 'mt-2' }, [this.conditionEditor(block, exclude)]),
        ]);
    }

    /** "Shown always / only if a field…": { field, op, value }. */
    conditionEditor(owner, exclude) {
        const cond = owner.visibleIf || null;
        const fields = this.fieldList(exclude);
        const ops = [['', this.l.cond_always], ['filled', this.l.cond_filled], ['empty', this.l.cond_empty], ['true', this.l.cond_true], ['false', this.l.cond_false], ['eq', this.l.cond_eq], ['neq', this.l.cond_neq]];
        const row = h('div', { class: 'flex flex-wrap items-end gap-3' }, [
            this.labelled(this.l.condition, this.select(cond ? cond.op : '', ops, (op) => {
                owner.visibleIf = op === '' ? null : { field: cond ? cond.field : (fields[0] || {}).key || '', op, value: cond ? cond.value : '' };
                this.changed();
            }, this.l.condition)),
        ]);
        if (cond) {
            row.append(this.labelled(this.l.cond_field, this.select(cond.field, [['', '—'], ...fields.map((f) => [f.key, f.label || '…'])], (v) => { cond.field = v; cond.value = ''; this.changed(); }, this.l.cond_field)));
            if (cond.op === 'eq' || cond.op === 'neq') {
                const spec = fields.find((f) => f.key === cond.field);
                row.append(spec && (spec.fieldType === 'choice' || spec.fieldType === 'multichoice')
                    ? this.labelled(this.l.cond_value, this.select(cond.value, [['', '—'], ...spec.options.map((o) => [o.key, o.label || '…'])], (v) => { cond.value = v; this.touch(); }, this.l.cond_value))
                    : this.textInput(cond, 'value', this.l.cond_value, 'w-48'));
            }
        }

        return row;
    }

    textBody(block, section) {
        const modes = [['fixed', this.l.mode_fixed], ['starter', this.l.mode_starter], ['fill', this.l.mode_fill]];
        const parts = [
            h('div', { class: 'flex flex-wrap items-end gap-3' }, [
                this.labelled(this.l.text_mode, this.select(block.mode, modes, (v) => { block.mode = v; if (v === 'fill') { block.html = ''; } this.changed(); }, this.l.text_mode)),
                ...(block.mode !== 'fixed' ? [this.textInput(block, 'label', this.l.label, 'flex-1 basis-48'), h('div', { class: 'pb-1.5' }, [this.checkbox(block, 'required', this.l.required)])] : []),
            ]),
        ];
        if (block.mode !== 'fill') {
            parts.push(this.richText(block, section));
        }
        if (block.mode !== 'fixed') {
            parts.push(this.textInput(block, 'help', this.l.help, '', 1000));
        }
        return h('div', { class: 'space-y-3' }, parts);
    }

    /** The text as a preview that turns into the editor when it is clicked: dozens of editors on a page would be slow. */
    richText(block, section) {
        const holder = h('div');
        const preview = () => {
            const box = h('button', {
                type: 'button',
                class: 'block w-full rounded-lg border border-dashed border-gray-300 px-3 py-2 text-left text-sm text-gray-800 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-forest-500',
                'aria-label': this.l.edit_text,
                onclick: mount,
            });
            if (block.html) {
                const content = h('div', { class: 'prose prose-sm max-w-none' });
                content.innerHTML = block.html;
                box.append(content);
            } else {
                box.append(h('span', { class: 'text-gray-400', text: this.l.empty_text }));
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
            if (block.html) {
                quill.clipboard.dangerouslyPasteHTML(block.html);
            }
            quill.on('text-change', (delta, old, source) => {
                if (source === 'user') {
                    const html = quill.root.innerHTML;
                    block.html = html === '<p><br></p>' ? '' : html;
                    this.touch();
                }
            });
            holder.append(this.refPicker(section, (token) => {
                const range = quill.getSelection(true);
                quill.insertText(range ? range.index : quill.getLength() - 1, token, 'user');
            }));
            quill.focus();
        };
        preview();
        return holder;
    }

    /** A relation only makes sense in a table column; the cells of a double-entry table are plain values. */
    fieldTypeOptions(context) {
        const allowed = context.cell ? CELL_TYPES : FIELD_TYPES.filter((t) => t !== 'relation' || context.table);
        return allowed.map((t) => [t, this.l['type_' + t]]);
    }

    /** The references a formula can use here, as [text between brackets, readable label]. */
    formulaOptions(table) {
        const options = [];
        const tables = this.tableList().filter((t) => t.mode !== 'matrix');
        this.fieldList().forEach((f) => options.push(['f:' + f.key, this.l.ref_field + ': ' + (f.label || '…')]));
        tables.forEach((t) => (t.columns || []).forEach((c) => options.push(['t:' + t.key + '.' + c.key, (t.label || '…') + ' · ' + (c.label || '…')])));
        if (table) {
            (table.columns || []).forEach((c) => options.push(['r:' + c.key, this.l.formula_this_row + ': ' + (c.label || '…')]));
        }
        // The same across the copies of a repeating section.
        const visit = (sections) => {
            for (const s of sections) {
                if (s.repeat) {
                    const sub = (list) => list.forEach((x) => {
                        x.blocks.filter((b) => b.type === 'field').forEach((f) => options.push(['c:' + s.key + '.f:' + f.key, this.l.formula_copies + ' «' + (s.title || '…') + '»: ' + (f.label || '…')]));
                        x.blocks.filter((b) => b.type === 'table' && b.mode !== 'matrix').forEach((t) => (t.columns || []).forEach((c) => options.push(['c:' + s.key + '.t:' + t.key + '.' + c.key, this.l.formula_copies + ' «' + (s.title || '…') + '»: ' + (t.label || '…') + ' · ' + (c.label || '…')])));
                        sub(x.children);
                    });
                    sub([s]);
                }
                visit(s.children);
            }
        };
        visit(this.def.sections);
        this.tableList().filter((t) => t.mode === 'matrix').forEach((t) => {
            options.push(['m:' + t.key, this.l.formula_matrix + ' «' + (t.label || '…') + '»']);
            options.push(['m:' + t.key + '.rows', this.l.formula_matrix_rows + ' «' + (t.label || '…') + '»']);
            options.push(['m:' + t.key + '.cols', this.l.formula_matrix_cols + ' «' + (t.label || '…') + '»']);
        });
        return options;
    }

    /** A formula input with a picker for references and functions, and a readable echo of what it says (or why it cannot be read). */
    formulaEditor(owner, prop, table, labelText) {
        const options = this.formulaOptions(table);
        const names = new Map(options);
        const preview = h('p', { class: 'mt-1 text-xs text-gray-500' });
        const input = h('input', {
            class: INPUT + ' font-mono', value: owner[prop] || '', maxlength: 1000, placeholder: 'sum([…]) / 2',
            oninput: (e) => { owner[prop] = e.target.value; echo(); this.touch(); },
        });
        const echo = () => {
            const source = owner[prop] || '';
            const { error } = parse(source);
            if (source === '') {
                preview.textContent = '';
            } else if (error) {
                preview.className = 'mt-1 text-xs text-red-700';
                preview.textContent = this.l.formula_invalid;
            } else {
                preview.className = 'mt-1 text-xs text-gray-500';
                preview.textContent = source.replace(/\[([^\]]+)\]/g, (all, ref) => '«' + (names.get(ref.trim()) || '?') + '»');
            }
        };
        const insert = (text) => {
            const start = input.selectionStart ?? input.value.length;
            const end = input.selectionEnd ?? input.value.length;
            input.value = input.value.slice(0, start) + text + input.value.slice(end);
            owner[prop] = input.value;
            input.focus();
            input.setSelectionRange(start + text.length, start + text.length);
            echo();
            this.touch();
        };
        const pick = (list, label, wrap) => h('select', {
            class: SELECT + ' text-xs text-gray-600', 'aria-label': label,
            onchange: (e) => { const v = e.target.value; e.target.value = ''; if (v) { insert(wrap(v)); } },
        }, [h('option', { value: '', text: label }), ...list.map(([v, t]) => h('option', { value: v, text: t }))]);
        echo();

        return h('div', { class: 'space-y-1' }, [
            this.labelled(labelText, input),
            h('div', { class: 'flex flex-wrap gap-2' }, [
                pick(options, '[ ] ' + this.l.formula_insert_ref, (v) => '[' + v + ']'),
                pick(FUNCTION_SNIPPETS.map((f) => [f, f + ')']), 'ƒ ' + this.l.formula_insert_function, (v) => v),
            ]),
            preview,
        ]);
    }

    /** What a field and a table column share: type, required, number limits, options, formula, relation and total. */
    fieldProps(spec, context = {}) {
        const parts = [
            h('div', { class: 'flex flex-wrap items-end gap-3' }, [
                this.labelled(this.l.field_type, this.select(spec.fieldType, this.fieldTypeOptions(context), (v) => {
                    spec.fieldType = v;
                    if (v === 'calculated' && spec.decimals === undefined) { spec.decimals = 2; }
                    this.changed();
                }, this.l.field_type)),
                ...(spec.fieldType === 'calculated' ? [] : [h('div', { class: 'pb-1.5' }, [this.checkbox(spec, 'required', this.l.required)])]),
            ]),
        ];
        if (spec.fieldType === 'calculated') {
            parts.push(this.formulaEditor(spec, 'formula', context.table || null, this.l.formula));
            parts.push(h('div', { class: 'flex flex-wrap items-end gap-3' }, [
                this.labelled(this.l.decimals, h('input', {
                    type: 'number', min: 0, max: 6, class: INPUT, value: spec.decimals ?? 2,
                    oninput: (e) => { spec.decimals = Math.min(6, Math.max(0, Number(e.target.value) || 0)); this.touch(); },
                }), 'w-24'),
                this.textInput(spec, 'unit', this.l.unit, 'w-28', 20),
            ]));
        }
        if (spec.fieldType === 'relation') {
            const tables = this.tableList().filter((t) => t.mode !== 'matrix' && (!context.table || t.key !== context.table.key));
            const source = tables.find((t) => t.key === spec.relTable);
            parts.push(h('div', { class: 'flex flex-wrap items-end gap-3' }, [
                this.labelled(this.l.rel_table, this.select(spec.relTable || '', [['', '—'], ...tables.map((t) => [t.key, t.label || '…'])], (v) => { spec.relTable = v; spec.relLabel = ''; this.changed(); }, this.l.rel_table)),
                this.labelled(this.l.rel_label, this.select(spec.relLabel || '', [['', '—'], ...((source ? source.columns : []).map((c) => [c.key, c.label || '…']))], (v) => { spec.relLabel = v; this.touch(); }, this.l.rel_label)),
                h('div', { class: 'pb-1.5' }, [this.checkbox(spec, 'multiple', this.l.rel_multiple)]),
            ]));
        }
        if (context.table && (spec.fieldType === 'number' || spec.fieldType === 'calculated')) {
            const totalsList = [['', this.l.total_none], ['sum', this.l.total_sum], ['avg', this.l.total_avg], ['min', this.l.total_min], ['max', this.l.total_max], ['count', this.l.total_count]];
            parts.push(this.labelled(this.l.total, this.select(spec.total || '', totalsList, (v) => { spec.total = v; this.touch(); }, this.l.total)));
        }
        if (spec.fieldType === 'number') {
            const num = (prop, text) => this.labelled(text, h('input', {
                type: 'number', step: 'any', class: INPUT, value: spec[prop] ?? '',
                oninput: (e) => { spec[prop] = e.target.value === '' ? null : Number(e.target.value); this.touch(); },
            }), 'w-28');
            parts.push(h('div', { class: 'flex flex-wrap items-end gap-3' }, [num('min', this.l.min), num('max', this.l.max), this.textInput(spec, 'unit', this.l.unit, 'w-28', 20)]));
        }
        if (spec.fieldType === 'choice' || spec.fieldType === 'multichoice') {
            parts.push(this.listEditor(spec.options, this.l.options, this.l.add_option, this.l.option));
        }
        return parts;
    }

    fieldBody(block) {
        return h('div', { class: 'space-y-3' }, [
            this.textInput(block, 'label', this.l.label),
            ...this.fieldProps(block, {}),
            this.textInput(block, 'help', this.l.help, '', 1000),
        ]);
    }

    /** A double-entry table: its rows are the rows of one table, its columns the rows of another, and every cell is of one kind. */
    matrixConfig(block) {
        const tables = this.tableList().filter((t) => t.mode !== 'matrix' && t.key !== block.key);
        const columnsOf = (key) => (tables.find((t) => t.key === key) || { columns: [] }).columns.map((c) => [c.key, c.label || '…']);
        const side = (tableProp, labelProp, tableLabel, columnLabel) => h('div', { class: 'flex flex-wrap items-end gap-3' }, [
            this.labelled(tableLabel, this.select(block[tableProp] || '', [['', '—'], ...tables.map((t) => [t.key, t.label || '…'])], (v) => { block[tableProp] = v; block[labelProp] = ''; this.changed(); }, tableLabel)),
            this.labelled(columnLabel, this.select(block[labelProp] || '', [['', '—'], ...columnsOf(block[tableProp])], (v) => { block[labelProp] = v; this.touch(); }, columnLabel)),
        ]);
        block.cell = block.cell || { fieldType: 'number', required: false, options: [], min: null, max: null, unit: '' };

        return h('div', { class: 'space-y-3 rounded-lg border border-gray-200 bg-gray-50/60 p-2.5' }, [
            side('rowTable', 'rowLabel', this.l.matrix_rows_table, this.l.matrix_rows_label),
            side('colTable', 'colLabel', this.l.matrix_cols_table, this.l.matrix_cols_label),
            h('p', { class: 'text-xs font-medium text-gray-600', text: this.l.matrix_cell }),
            ...this.fieldProps(block.cell, { cell: true }),
        ]);
    }

    tableBody(block) {
        const modes = [['free', this.l.table_free], ['fixed', this.l.table_fixed], ['matrix', this.l.table_matrix]];
        const columns = block.columns.map((c, i) => h('div', { class: 'rounded-lg border border-gray-200 bg-gray-50/60 p-2.5 space-y-2' }, [
            h('div', { class: 'flex items-end gap-2' }, [
                this.textInput(c, 'label', this.l.column + ' ' + (i + 1), 'flex-1'),
                h('button', { type: 'button', class: BTN, title: this.l.up, 'aria-label': this.l.up, text: '↑', disabled: i === 0, onclick: () => this.moveIn(block.columns, i, -1) }),
                h('button', { type: 'button', class: BTN, title: this.l.down, 'aria-label': this.l.down, text: '↓', disabled: i === block.columns.length - 1, onclick: () => this.moveIn(block.columns, i, 1) }),
                h('button', { type: 'button', class: BTN + ' text-red-700', title: this.l.remove, 'aria-label': this.l.remove, text: '✕', onclick: () => this.removeFrom(block.columns, i) }),
            ]),
            ...this.fieldProps(c, { table: block }),
        ]));

        const parts = [
            h('div', { class: 'flex flex-wrap items-end gap-3' }, [
                this.textInput(block, 'label', this.l.label, 'flex-1 basis-48'),
                this.labelled(this.l.table_mode, this.select(block.mode, modes, (v) => { block.mode = v; this.changed(); }, this.l.table_mode)),
            ]),
        ];
        if (block.mode === 'matrix') {
            parts.push(this.matrixConfig(block));
            parts.push(this.textInput(block, 'help', this.l.help, '', 1000));

            return h('div', { class: 'space-y-3' }, parts);
        }
        parts.push(h('div', { class: 'space-y-2' }, [
            h('p', { class: 'text-xs font-medium text-gray-600', text: this.l.columns }),
            ...columns,
            h('button', { type: 'button', class: BTN, text: '+ ' + this.l.add_column, onclick: () => { const c = this.newColumn(); block.columns.push(c); this.changed(c.key); } }),
        ]));
        if (block.mode === 'fixed') {
            parts.push(this.textInput(block, 'rowHeader', this.l.row_header));
            parts.push(this.listEditor(block.rows, this.l.rows, this.l.add_row, this.l.row));
        } else {
            const num = (prop, text) => this.labelled(text, h('input', {
                type: 'number', min: 0, class: INPUT, value: block[prop] ?? 0,
                oninput: (e) => { block[prop] = Math.max(0, Number(e.target.value) || 0); this.touch(); },
            }), 'w-40');
            parts.push(h('div', { class: 'flex flex-wrap items-end gap-3' }, [num('minRows', this.l.min_rows), num('maxRows', this.l.max_rows)]));
        }
        parts.push(this.textInput(block, 'help', this.l.help, '', 1000));

        return h('div', { class: 'space-y-3' }, parts);
    }

    /** A list of { key, label } (options of a choice, rows of a fixed table): Enter in a line adds the next one. */
    listEditor(items, title, addLabel, itemLabel) {
        const rows = items.map((item, i) => h('div', { class: 'flex items-center gap-2' }, [
            h('input', {
                class: INPUT, value: item.label ?? '', maxlength: 500, 'aria-label': itemLabel + ' ' + (i + 1), 'data-focus': item.key,
                oninput: (e) => { item.label = e.target.value; this.touch(); },
                onkeydown: (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        const next = { key: uid(), label: '' };
                        items.splice(i + 1, 0, next);
                        this.changed(next.key);
                    }
                },
            }),
            h('button', { type: 'button', class: BTN, title: this.l.up, 'aria-label': this.l.up, text: '↑', disabled: i === 0, onclick: () => this.moveIn(items, i, -1) }),
            h('button', { type: 'button', class: BTN, title: this.l.down, 'aria-label': this.l.down, text: '↓', disabled: i === items.length - 1, onclick: () => this.moveIn(items, i, 1) }),
            h('button', { type: 'button', class: BTN + ' text-red-700', title: this.l.remove, 'aria-label': this.l.remove, text: '✕', onclick: () => { items.splice(i, 1); this.changed(); } }),
        ]));

        return h('div', { class: 'space-y-2' }, [
            h('p', { class: 'text-xs font-medium text-gray-600', text: title }),
            ...rows,
            h('button', { type: 'button', class: BTN, text: '+ ' + addLabel, onclick: () => { const it = { key: uid(), label: '' }; items.push(it); this.changed(it.key); } }),
        ]);
    }
}
