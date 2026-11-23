import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';
import { mountRichText } from '../utils/rich_text_editor.js';
import { h, uid, sectionNumbers } from '../utils/form_dom.js';
import { parse } from '../utils/form_expression.js';
import { confirmDialog } from '../utils/confirm_dialog.js';

const FIELD_TYPES = ['short', 'long', 'number', 'date', 'time', 'bool', 'choice', 'multichoice', 'calculated', 'relation'];
const CELL_TYPES = ['number', 'short', 'long', 'date', 'time', 'bool'];
const FUNCTION_SNIPPETS = ['sum(', 'avg(', 'min(', 'max(', 'count(', 'count_if(', 'pct_if(', 'if(', 'round(', 'abs(', 'empty('];
const BTN = 'rounded-lg border border-gray-200 bg-white px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-forest-500';
const INPUT = 'w-full min-w-0 rounded-lg border border-gray-200 px-2.5 py-1.5 text-sm focus:border-forest-500 focus:ring-forest-500';
// The chevron and the room under it come from the global rule for `select` in app.css.
const SELECT = 'min-w-0 rounded-lg border border-gray-200 py-1.5 pl-2 text-sm focus:border-forest-500 focus:ring-forest-500';

/**
 * The editor of a document model. It works on the definition (a JSON tree of sections, see App\Service\Form\FormDefinition),
 * builds the whole page from it and saves the draft as it changes (debounced), with the version it started from so two editors
 * cannot overwrite each other. Every element has a stable `key` that the editor creates and never shows.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['canvas', 'outline', 'status', 'problems', 'code', 'send', 'bar', 'summary', 'panel', 'openSend', 'tabs', 'rules', 'approval', 'scale', 'scaleOutput'];
    static values = { config: Object, labels: Object };

    connect() {
        this.l = this.labelsValue;
        this.def = this.configValue.definition && Array.isArray(this.configValue.definition.sections)
            ? this.configValue.definition
            : { version: 1, sections: [] };
        // The fields made from the named totals of the columns are not edited as blocks: the server makes them from the columns.
        const dropFoot = (sections) => sections.forEach((s) => { s.blocks = (s.blocks || []).filter((b) => !b.foot); dropFoot(s.children || []); });
        dropFoot(this.def.sections);
        // The page layout (header, footer, orientation, font scale) edited in the "Ajustes" tab; a header or footer null is the centre's.
        this.def.layout = Object.assign({ header: null, footer: null, firstHeader: null, orientation: 'portrait', fontScale: 100, margins: null }, this.def.layout || {});
        this.version = this.configValue.version;
        this.dirty = false;
        this.saving = false;
        this.stale = false;
        this.timer = null;
        this.pendingSelects = [];
        this.tomSelects = [];
        this.focusKey = null;

        this.onCode = () => this.touch();
        this.codeTarget.addEventListener('input', this.onCode);
        this.onHide = () => { if (this.dirty && !this.stale) { this.save(true); } };
        window.addEventListener('pagehide', this.onHide);

        // The tabs: the panels are in the page, the tab that shows is the one the address names (#rules, #approval, #indicators).
        this.panels = [...this.element.querySelectorAll('[data-panel]')].map((el) => el.dataset.panel);
        const wanted = location.hash.replace('#', '');
        this.tab = this.panels.includes(wanted) ? wanted : 'content';
        this.problems = this.configValue.problems || [];
        this.changes = this.configValue.changes || { content: false, rules: false, approval: false, layout: false, base: null, any: true };

        this.render();
        this.showTab(this.tab);
        this.applyState(this.problems, this.changes);
    }

    // ── Tabs, bar and the panel that sends ──────────────────────────────────

    /** Shows one tab (a panel of the page) and keeps it in the address, so a reload or a shared link comes back to it. */
    showTab(name) {
        this.tab = name;
        this.element.querySelectorAll('[data-panel]').forEach((el) => { el.hidden = el.dataset.panel !== name; });
        try { history.replaceState(null, '', name === 'content' ? location.pathname + location.search : '#' + name); } catch (e) { /* no address to update */ }
        this.paintTabs();
    }

    /** The tabs, each with its state: a red number when it has problems that stop the sending, an amber dot when it differs from the last revision sent. */
    paintTabs() {
        const had = this.tabsTarget.contains(document.activeElement) ? document.activeElement.dataset.tab : null;
        const names = { content: this.l.tab_content, rules: this.l.tab_rules, approval: this.l.tab_approval, indicators: this.l.tab_indicators, layout: this.l.tab_layout };
        const tabs = this.panels.map((name, i) => {
            const count = this.problems.filter((p) => p.part === name).length;
            const changed = this.changes[name] === true;
            const active = name === this.tab;
            const state = [];
            if (count > 0) {
                state.push(h('span', { class: 'rounded-full bg-red-100 px-1.5 text-xs font-semibold text-red-700', 'aria-hidden': 'true', text: String(count) }));
                state.push(h('span', { class: 'sr-only', text: this.l.tab_problems.replace('%count%', String(count)) }));
            } else if (changed) {
                state.push(h('span', { class: 'h-2 w-2 rounded-full bg-amber-500', 'aria-hidden': 'true', title: this.l.tab_changed }));
                state.push(h('span', { class: 'sr-only', text: this.l.tab_changed }));
            }

            return h('button', {
                type: 'button', role: 'tab', id: 'editor-tab-' + name, 'data-tab': name, 'aria-selected': active ? 'true' : 'false', 'aria-controls': 'editor-panel-' + name, tabindex: active ? '0' : '-1',
                class: 'inline-flex shrink-0 items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 '
                    + (active ? 'border-forest-600 text-forest-700' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'),
                onclick: () => this.showTab(name),
                onkeydown: (e) => {
                    const move = { ArrowRight: 1, ArrowLeft: -1 }[e.key];
                    if (move !== undefined) {
                        e.preventDefault();
                        const next = this.panels[(i + move + this.panels.length) % this.panels.length];
                        this.showTab(next);
                        this.tabsTarget.querySelector('[data-tab="' + next + '"]')?.focus();
                    }
                },
            }, [names[name] || name, ...state]);
        });
        this.tabsTarget.replaceChildren(...tabs);
        this.element.querySelectorAll('[data-panel]').forEach((el) => { el.id = 'editor-panel-' + el.dataset.panel; el.setAttribute('aria-labelledby', 'editor-tab-' + el.dataset.panel); });
        if (had) {
            this.tabsTarget.querySelector('[data-tab="' + had + '"]')?.focus();
        }
    }

    /** What the server says after each save: the problems (with the tab they belong to) and what differs from the last revision sent. */
    applyState(problems, changes) {
        this.problems = problems;
        this.changes = changes;
        const parts = { content: this.l.tab_content, rules: this.l.tab_rules, approval: this.l.tab_approval, layout: this.l.tab_layout };
        const changedNames = Object.keys(parts).filter((p) => changes[p]).map((p) => parts[p].toLowerCase());
        let summary;
        if (changes.base === null) {
            summary = this.l.bar_no_revision;
        } else if (changes.any) {
            summary = this.l.bar_changed.replace('%parts%', changedNames.join(', '));
        } else {
            summary = this.l.bar_unchanged.replace('%version%', String(changes.base));
        }
        this.summaryTarget.replaceChildren(summary, ...(problems.length ? [' ', h('button', {
            type: 'button', class: 'font-medium text-red-700 underline decoration-dotted', text: this.l.bar_problems.replace('%count%', String(problems.length)),
            onclick: () => this.togglePanel(true),
        })] : []));

        // Nothing to send: the button says so; with problems the panel opens to show them, but the sending waits.
        this.openSendTarget.disabled = !changes.any;
        this.openSendTarget.title = changes.any ? '' : this.l.send_unchanged;
        this.sendTarget.disabled = !changes.any || problems.length > 0;
        this.sendTarget.title = problems.length > 0 ? this.l.send_blocked : (changes.any ? '' : this.l.send_unchanged);
        this.problemsTarget.replaceChildren(...problems.map((p) => h('li', { class: 'flex items-start gap-2' }, [
            h('span', { class: 'mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-600', 'aria-hidden': 'true' }),
            h('span', { class: 'min-w-0 flex-1', text: p.text }),
            h('button', { type: 'button', class: 'shrink-0 text-xs font-medium text-forest-700 underline decoration-dotted', text: this.l.problem_go, onclick: () => { this.togglePanel(false); this.showTab(p.part); } }),
        ])));
        if (this.hasTabsTarget) {
            this.paintTabs();
        }
    }

    // ── The "Ajustes" tab: header, footer, orientation and font scale ──────────

    /** The checkbox "customize": with it the model has its own header (or footer), without it it follows the centre's. */
    layoutMode(event) {
        const part = event.target.dataset.part;
        const box = this.element.querySelector('[data-layout-part="' + part + '"]');
        const custom = event.target.checked;
        box.querySelector('[data-layout-inherited]')?.classList.toggle('hidden', custom);
        box.querySelector('[data-layout-custom]').classList.toggle('hidden', !custom);
        if (custom) {
            // Starts from what the model had (or from the inherited one), kept as it is until it is edited.
            const input = box.querySelector('[data-doc-layout-editor-target="input"]');
            this.def.layout[part] = input.value || null;
        } else {
            this.def.layout[part] = null;
        }
        this.touch();
    }

    layoutEdited(event) {
        this.def.layout[event.target.dataset.part] = event.target.value || null;
        this.touch();
    }

    /** The checkbox of the margins: with it the model has margins of its own (the four boxes), without it the centre's. */
    layoutMarginsMode(event) {
        const boxes = [...this.element.querySelectorAll('[data-layout-margins] input[data-side]')];
        boxes.forEach((input) => {
            input.disabled = !event.target.checked;
            if (!event.target.checked) {
                input.value = input.dataset.inherited;
            }
        });
        this.def.layout.margins = event.target.checked ? this.marginsFromBoxes(boxes) : null;
        this.touch();
    }

    layoutMargins() {
        this.def.layout.margins = this.marginsFromBoxes([...this.element.querySelectorAll('[data-layout-margins] input[data-side]')]);
        this.touch();
    }

    marginsFromBoxes(boxes) {
        const margins = {};
        boxes.forEach((input) => { margins[input.dataset.side] = Math.max(5, Math.min(40, Number(input.value) || 5)); });

        return margins;
    }

    layoutOrientation(event) {
        this.def.layout.orientation = event.target.value;
        this.touch();
    }

    layoutScale() {
        this.def.layout.fontScale = Number(this.scaleTarget.value);
        this.scaleOutputTarget.textContent = this.def.layout.fontScale + ' %';
        this.touch();
    }

    layoutScaleReset() {
        this.scaleTarget.value = '100';
        this.layoutScale();
    }

    /** Opens or closes the panel under the bar (the way to send the draft); `open` forces one or the other. */
    togglePanel(open) {
        const show = typeof open === 'boolean' ? open : this.panelTarget.hidden;
        this.panelTarget.hidden = !show;
        this.openSendTarget.setAttribute('aria-expanded', show ? 'true' : 'false');
    }

    disconnect() {
        this.destroySelects();
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
            this.applyState(body.problems || [], body.changes || this.changes);
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
        this.statusTarget.className = 'text-sm ' + tone;
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

    async removeSection(key) {
        if (!await confirmDialog(this.l.confirm_remove)) {
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

    async removeFrom(list, index) {
        // The list can change while the question is open: the element is the one to remove, wherever it is by then.
        const item = list[index];
        if (!await confirmDialog(this.l.confirm_remove)) {
            return;
        }
        const at = list.indexOf(item);
        if (at < 0) {
            return;
        }
        list.splice(at, 1);
        this.changed();
    }

    // ── Rendering ────────────────────────────────────────────────────────────

    render() {
        this.destroySelects();
        const numbers = sectionNumbers(this.def.sections);
        this.canvasTarget.replaceChildren(
            ...this.def.sections.map((s) => this.sectionEl(s, numbers, 0)),
            ...(this.def.sections.length === 0 ? [h('p', { class: 'rounded-xl border border-dashed border-gray-300 px-4 py-6 text-center text-sm text-gray-500', text: this.l.no_sections })] : []),
            h('button', { type: 'button', class: BTN + ' py-2', text: '+ ' + this.l.add_section, onclick: () => this.addSection(this.def.sections) }),
        );
        this.rulesTarget.replaceChildren(this.rulesEl());
        this.approvalTarget.replaceChildren(this.approvalEl());
        this.outlineTarget.replaceChildren(...(this.def.sections.length === 0
            ? [h('div', { class: 'flex flex-col items-center gap-1.5 px-2 py-6 text-center text-gray-400' }, [
                h('span', { class: 'text-2xl leading-none', 'aria-hidden': 'true', text: '☰' }),
                h('p', { class: 'text-sm font-medium text-gray-500', text: this.l.outline_empty }),
                h('p', { class: 'text-xs', text: this.l.outline_empty_help }),
            ])]
            : this.outlineItems(this.def.sections, numbers, 0)));
        this.enhanceSelects();
        if (this.focusKey) {
            const target = this.element.querySelector('[data-focus="' + this.focusKey + '"]');
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
        // Every drop-down is a box you can type in to filter (see enhanceSelects(), once the page is drawn).
        const select = h('select', { class: 'w-full min-w-[8rem]', 'aria-label': ariaLabel, onchange: (e) => onchange(e.target.value) },
            options.map(([v, t]) => h('option', { value: v, text: t, selected: v === value })));
        this.pendingSelects.push(select);

        return select;
    }

    /** Turns the drop-downs just drawn into boxes with a search (they are drawn on <body>: the cards of the page clip an open list). */
    enhanceSelects() {
        for (const el of this.pendingSelects) {
            if (el.isConnected) {
                this.tomSelects.push(new TomSelect(el, { create: false, maxOptions: null, allowEmptyOption: true, dropdownParent: 'body' }));
            }
        }
        this.pendingSelects = [];
    }

    destroySelects() {
        this.tomSelects.forEach((ts) => ts.destroy());
        this.tomSelects = [];
        this.pendingSelects = [];
    }

    // ── Repetition, conditions and references ───────────────────────────────

    /** Every field of the document (optionally without some keys), in reading order. */
    fieldList(exclude = new Set()) {
        const out = [];
        const visit = (sections) => {
            for (const s of sections) {
                s.blocks.forEach((b) => {
                    if (b.type === 'field' && !exclude.has(b.key)) { out.push(b); }
                    // The named totals at the foot of a table's columns are fields too (the server makes them, see FormDefinitionNormalizer).
                    if (b.type === 'table') {
                        (b.columns || []).filter((c) => c.total && c.totalKey && ((c.totalName || '').trim() || (c.total === 'custom' && (c.totalFormula || '').trim())) && !exclude.has(c.totalKey))
                            .forEach((c) => out.push({ key: c.totalKey, type: 'field', fieldType: 'calculated', label: (c.totalName || '').trim() || c.label, name: '' }));
                    }
                });
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
                    ? this.labelled(this.l.cond_value, this.select(cond.value, [['', '—'], ...this.optionsOf(spec).map((o) => [o.key, o.label || '…'])], (v) => { cond.value = v; this.touch(); }, this.l.cond_value))
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
            const rich = mountRichText(el, {
                html: block.html,
                onChange: (html) => {
                    block.html = html;
                    this.touch();
                },
            });
            holder.append(this.refPicker(section, (token) => rich.insertText(token)));
            rich.focus();
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
            const totalsList = [['', this.l.total_none], ['sum', this.l.total_sum], ['avg', this.l.total_avg], ['min', this.l.total_min], ['max', this.l.total_max], ['count', this.l.total_count], ['custom', this.l.total_custom]];
            // With a name, the total is also a field of the document (it is still shown only at the foot of the column).
            const named = (e) => {
                spec.totalName = e.target.value;
                if (spec.totalName.trim() !== '' && !spec.totalKey) { spec.totalKey = uid(); }
                this.touch();
            };
            parts.push(h('div', { class: 'flex flex-wrap items-end gap-3' }, [
                this.labelled(this.l.total, this.select(spec.total || '', totalsList, (v) => { spec.total = v; this.changed(); }, this.l.total), 'w-48'),
                ...(spec.total ? [this.labelled(this.l.total_name, h('input', { class: INPUT, value: spec.totalName ?? '', maxlength: 255, placeholder: this.l.total_name_hint, oninput: named }), 'flex-1 basis-64')] : []),
            ]));
            if (spec.total === 'custom') {
                // A formula over the whole table (no "this row"): e.g. sum([t:…]) / count([t:…]).
                if (!spec.totalKey) { spec.totalKey = uid(); }
                parts.push(this.formulaEditor(spec, 'totalFormula', null, this.l.total_formula));
            }
        }
        if (spec.fieldType === 'number') {
            const num = (prop, text) => this.labelled(text, h('input', {
                type: 'number', step: 'any', class: INPUT, value: spec[prop] ?? '',
                oninput: (e) => { spec[prop] = e.target.value === '' ? null : Number(e.target.value); this.touch(); },
            }), 'w-28');
            parts.push(h('div', { class: 'flex flex-wrap items-end gap-3' }, [num('min', this.l.min), num('max', this.l.max), this.textInput(spec, 'unit', this.l.unit, 'w-28', 20)]));
        }
        if (spec.fieldType === 'choice' || spec.fieldType === 'multichoice') {
            parts.push(...this.optionsEditor(spec));
        }
        return parts;
    }

    /** The lists of the centre a choice can take its options from: { id, label, options: [{ key, label }] }. */
    centreLists() {
        return this.configValue.lists || [];
    }

    /** The options a choice offers in the editor: its own, or the ones its list offers now. */
    optionsOf(spec) {
        if (spec.list) {
            const list = this.centreLists().find((l) => l.id === spec.list);
            return list ? list.options : [];
        }
        return spec.options || [];
    }

    /**
     * Where a choice gets its options: typed here, or an item of the centre's lists (then they are read from the list every time,
     * so a new item shows up and a renamed one is renamed in the models and the documents still being filled).
     */
    optionsEditor(spec) {
        const lists = this.centreLists();
        const fromList = !!spec.list;
        // Nothing to choose from when the centre has no list (and this field is not pointing at one that is gone).
        if (lists.length === 0 && !fromList) {
            return [this.listEditor(spec.options, this.l.options, this.l.add_option, this.l.option)];
        }
        const parts = [];
        // Switching does not clear what was typed, so a slip can be undone on the spot; once saved with a list, the server keeps none.
        parts.push(this.labelled(this.l.options_source, this.select(fromList ? 'list' : 'own', [['own', this.l.options_own], ['list', this.l.options_list]], (v) => {
            spec.list = v === 'list' ? ((lists[0] || {}).id || '') : '';
            this.changed();
        }, this.l.options_source)));
        if (!fromList) {
            parts.push(this.listEditor(spec.options, this.l.options, this.l.add_option, this.l.option));
            return parts;
        }
        const current = lists.find((l) => l.id === spec.list);
        parts.push(this.labelled(this.l.options_list_pick, this.select(spec.list, [...(current ? [] : [[spec.list, '—']]), ...lists.map((l) => [l.id, l.label])], (v) => { spec.list = v; this.changed(); }, this.l.options_list_pick)));
        parts.push(h('p', {
            class: 'text-xs ' + (current && current.options.length ? 'text-gray-500' : 'text-amber-700'),
            'data-testid': 'list-options-hint',
            text: current ? this.l.options_list_hint.replace('%count%', String(current.options.length)).replace('%sample%', current.options.slice(0, 3).map((o) => o.label).join(', ')) : this.l.options_list_missing,
        }));
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
        const modes = [['free', this.l.table_free], ['fixed', this.l.table_fixed], ['counted', this.l.table_counted], ['matrix', this.l.table_matrix]];
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
                this.labelled(this.l.table_mode, this.select(block.mode, modes, (v) => {
                    block.mode = v;
                    if (v === 'counted') {
                        // The first number field of the model is the likeliest source; the cap starts at the most a table gets.
                        block.rowsField = block.rowsField || (this.fieldList().find((f) => f.fieldType === 'number') || {}).key || '';
                        block.maxRows = block.maxRows > 0 ? block.maxRows : 100;
                    }
                    this.changed();
                }, this.l.table_mode)),
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
        } else if (block.mode === 'counted') {
            const numbers = this.fieldList().filter((f) => f.fieldType === 'number').map((f) => [f.key, f.label || '…']);
            parts.push(this.textInput(block, 'rowHeader', this.l.row_header));
            parts.push(h('div', { class: 'flex flex-wrap items-end gap-3' }, [
                this.labelled(this.l.rows_field, this.select(block.rowsField || '', [['', '—'], ...numbers], (v) => { block.rowsField = v; this.changed(); }, this.l.rows_field)),
                this.labelled(this.l.counted_max, h('input', {
                    type: 'number', min: 1, max: 100, class: INPUT, value: block.maxRows || 100,
                    oninput: (e) => { block.maxRows = Math.min(100, Math.max(1, Number(e.target.value) || 100)); this.touch(); },
                }), 'w-40'),
            ]));
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
            h('div', { class: 'flex flex-wrap items-center gap-2' }, [
                h('button', { type: 'button', class: BTN, text: '+ ' + addLabel, onclick: () => { const it = { key: uid(), label: '' }; items.push(it); this.changed(it.key); } }),
            ]),
        ]);
    }
}
