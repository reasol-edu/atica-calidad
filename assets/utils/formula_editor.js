import { Schema } from '@tiptap/pm/model';
import { EditorState, Plugin, NodeSelection, TextSelection } from '@tiptap/pm/state';
import { EditorView, Decoration, DecorationSet } from '@tiptap/pm/view';
import { keymap } from '@tiptap/pm/keymap';
import { history, undo, redo } from '@tiptap/pm/history';
import { baseKeymap } from '@tiptap/pm/commands';
import { FUNCTIONS, tokens, parse, arityProblems, callAt } from './form_expression.js';
import { fold, matches, wordStarts, suggestFunction, templateText, templateToken, splitLine, joinLine } from './formula_text.js';
import 'prosemirror-view/style/prosemirror.min.css';

export { templateText, templateToken };

/**
 * The visual editors of the model editor for what is written with references (ProseMirror, the engine under the rich text editor):
 *
 * - **Formulas** (`mountFormulaEditor`): the references are pills with their name (not `[r:01a2…]`) and a chevron to change them; function
 *   names open a list with what each does; parentheses are coloured by depth and the pair at the cursor is marked; mistakes are underlined
 *   where they are, with an explanation (and a fix when there is an obvious one); while writing, a list suggests functions and data, and a
 *   line shows the values the function at the cursor takes. «Ver como texto» shows the plain formula.
 * - **Templates** (`mountTemplateEditor`): a single line of text (a section title) whose `{{label|ref}}` references are pills.
 *
 * What they write is the same text as ever (`[r:key] / [r:key] * 100`, `Seguimiento de {{Elemento de la lista|copy.label}}`): the server,
 * the saved models and the tests do not know about them. `pillElement()` and `openMenu()` are reused by the rich text editor for the
 * references of a text. The look is in app.css (`.fx-*`). See skills/forms.md.
 */

// ── The menu: a list to pick from, with a search box or driven from the editor ───────────────────────────────────────────────────

let openedMenu = null;
let menuSeq = 0;

/**
 * Opens a list of `items` ({value, label, hint?, desc?, group?, kind?, keywords?}) next to `anchor` (an element, or a function that gives
 * a DOMRect). With `search`, it has its own box and takes the focus; without it (the suggestions while writing), the editor keeps the
 * focus and drives it with `move()` / `pick()`. Only one is open at a time; it closes with Escape, a click outside or a scroll.
 *
 * @returns {{el: HTMLElement, move: (delta: number) => void, pick: () => boolean, filter: (q: string) => number, close: () => void, isOpen: () => boolean}}
 */
export function openMenu({ anchor, items, search = false, selected = null, onPick, onClose = () => {}, labels = {}, title = '' }) {
    openedMenu?.close();
    const id = 'fx-menu-' + (++menuSeq);
    const el = document.createElement('div');
    el.className = 'fx-menu';
    el.setAttribute('data-fx-menu', '');
    const list = document.createElement('ul');
    list.className = 'fx-menu-list';
    list.id = id;
    list.setAttribute('role', 'listbox');
    if (title) { list.setAttribute('aria-label', title); }
    let input = null;
    if (search) {
        input = document.createElement('input');
        input.type = 'search';
        input.className = 'fx-menu-search';
        input.placeholder = labels.search || '';
        input.setAttribute('aria-label', labels.search || title);
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-expanded', 'true');
        input.setAttribute('aria-controls', id);
        input.setAttribute('autocomplete', 'off');
        el.append(input);
    }
    el.append(list);
    document.body.append(el);

    let shown = [];
    let active = 0;
    let closed = false;
    const paint = () => {
        [...list.querySelectorAll('[role="option"]')].forEach((li, i) => {
            const on = i === active;
            li.classList.toggle('is-active', on);
            li.setAttribute('aria-selected', on ? 'true' : 'false');
            if (on) {
                (input || list).setAttribute('aria-activedescendant', li.id);
                // Only the list scrolls (scrollIntoView would also move the page).
                if (li.offsetTop < list.scrollTop) {
                    list.scrollTop = li.offsetTop;
                } else if (li.offsetTop + li.offsetHeight > list.scrollTop + list.clientHeight) {
                    list.scrollTop = li.offsetTop + li.offsetHeight - list.clientHeight;
                }
            }
        });
    };
    const filter = (query) => {
        shown = items.filter((item) => matches(item, query));
        list.replaceChildren();
        let group = null;
        shown.forEach((item, i) => {
            if (item.group && item.group !== group) {
                group = item.group;
                const head = document.createElement('li');
                head.className = 'fx-menu-group';
                head.setAttribute('role', 'presentation');
                head.textContent = group;
                list.append(head);
            }
            const li = document.createElement('li');
            li.id = id + '-' + i;
            li.className = 'fx-menu-item';
            li.setAttribute('role', 'option');
            const line = document.createElement('div');
            line.className = 'fx-menu-line';
            if (item.kind) {
                const dot = document.createElement('span');
                dot.className = 'fx-kind-dot fx-kind-' + item.kind;
                dot.setAttribute('aria-hidden', 'true');
                line.append(dot);
            }
            const label = document.createElement('span');
            label.className = item.mono ? 'fx-menu-label fx-mono' : 'fx-menu-label';
            label.textContent = item.label;
            line.append(label);
            if (item.hint) {
                const hint = document.createElement('span');
                hint.className = 'fx-menu-hint';
                hint.textContent = item.hint;
                line.append(hint);
            }
            li.append(line);
            if (item.desc) {
                const desc = document.createElement('div');
                desc.className = 'fx-menu-desc';
                desc.textContent = item.desc;
                li.append(desc);
            }
            if (item.value === selected) { li.classList.add('is-current'); }
            li.addEventListener('mousedown', (e) => { e.preventDefault(); active = i; pick(); });
            li.addEventListener('mousemove', () => { if (active !== i) { active = i; paint(); } });
            list.append(li);
        });
        if (shown.length === 0) {
            const none = document.createElement('li');
            none.className = 'fx-menu-none';
            none.setAttribute('role', 'presentation');
            none.textContent = labels.no_results || '—';
            list.append(none);
        }
        const current = shown.findIndex((item) => item.value === selected);
        active = current >= 0 && !query ? current : 0;
        paint();
        return shown.length;
    };
    const place = () => {
        const r = typeof anchor === 'function' ? anchor() : anchor.getBoundingClientRect();
        const width = Math.min(352, window.innerWidth - 16);
        el.style.width = width + 'px';
        const left = Math.max(8, Math.min(r.left, window.innerWidth - width - 8));
        el.style.left = left + 'px';
        const below = window.innerHeight - r.bottom;
        el.style.maxHeight = '';
        if (below < 240 && r.top > below) {
            el.style.top = '';
            el.style.bottom = (window.innerHeight - r.top + 4) + 'px';
            el.style.maxHeight = Math.min(360, r.top - 12) + 'px';
        } else {
            el.style.bottom = '';
            el.style.top = (r.bottom + 4) + 'px';
            el.style.maxHeight = Math.min(360, below - 12) + 'px';
        }
    };
    const close = () => {
        if (closed) { return; }
        closed = true;
        el.remove();
        document.removeEventListener('mousedown', outside, true);
        window.removeEventListener('scroll', scrolled, true);
        window.removeEventListener('resize', close);
        if (openedMenu === api) { openedMenu = null; }
        onClose();
    };
    const pick = () => {
        const item = shown[active];
        if (!item) { return false; }
        close();
        onPick(item);
        return true;
    };
    const move = (delta) => {
        if (shown.length === 0) { return; }
        active = (active + delta + shown.length) % shown.length;
        paint();
    };
    const outside = (e) => {
        if (!el.contains(e.target) && !(typeof anchor !== 'function' && anchor.contains(e.target))) { close(); }
    };
    // A scroll moves it with its anchor (the editor scrolls the cursor into view as one types); it closes when the anchor leaves the screen.
    const scrolled = (e) => {
        if (el.contains(e.target)) { return; }
        const r = typeof anchor === 'function' ? anchor() : anchor.getBoundingClientRect();
        if (r.bottom < 0 || r.top > window.innerHeight) { close(); } else { place(); }
    };
    if (input) {
        input.addEventListener('input', () => filter(input.value));
        input.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
            else if (e.key === 'Enter') { e.preventDefault(); pick(); }
            else if (e.key === 'Escape' || e.key === 'Tab') { e.preventDefault(); close(); }
        });
    }
    document.addEventListener('mousedown', outside, true);
    window.addEventListener('scroll', scrolled, true);
    window.addEventListener('resize', close);

    const api = { el, move, pick, filter, close, isOpen: () => !closed, setItems: (next, query = '') => { items = next; return filter(query); }, place };
    openedMenu = api;
    place();
    filter('');
    place();
    input?.focus({ preventScroll: true });
    return api;
}

// ── Pills ───────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

const CHEVRON = '<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path fill="currentColor" fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/></svg>';

/**
 * A reference drawn as a pill: a coloured tag with its kind («Fila», «Campo»…), its name and a chevron. `item` is the reference as the
 * picker lists it, or null when it is not among them (deleted, or out of reach from here): then it is red and says so.
 */
export function pillElement(item, { id, fallback = '', labels = {} } = {}) {
    const pill = document.createElement('span');
    pill.className = 'fx-pill ' + (item ? 'fx-kind-' + (item.kind || 'field') : 'fx-pill-bad');
    pill.setAttribute('contenteditable', 'false');
    pill.setAttribute('data-fx-ref', id || '');
    const kind = document.createElement('span');
    kind.className = 'fx-pill-kind';
    kind.textContent = item ? (labels['kind_' + (item.kind || 'field')] || '') : '?';
    const label = document.createElement('span');
    label.className = 'fx-pill-label';
    label.textContent = item ? item.label : (fallback || labels.unknown_ref_short || id);
    const chev = document.createElement('span');
    chev.className = 'fx-pill-chev';
    chev.innerHTML = CHEVRON;
    pill.append(kind, label, chev);
    pill.title = item ? [item.label, item.hint].filter(Boolean).join(' · ') + (labels.change_hint ? ' — ' + labels.change_hint : '') : (labels.unknown_ref || '');
    pill.setAttribute('role', 'button');
    pill.setAttribute('aria-label', (item ? item.label : (labels.unknown_ref || '')) + (labels.change_hint ? '. ' + labels.change_hint : ''));
    return pill;
}

// ── Templates: text with {{label|ref}} ──────────────────────────────────────────────────────────────────────────────────────────

/**
 * The HTML of a text with its `{{label|ref}}` turned into elements (`<span data-ref-token>` for the rich text editor, or pills to show
 * it), and back. Through the DOM, so that what is escaped stays escaped.
 */
export function templateHtmlToNodes(html, make) {
    const box = document.createElement('template');
    box.innerHTML = html || '';
    const walker = document.createTreeWalker(box.content, NodeFilter.SHOW_TEXT);
    const texts = [];
    while (walker.nextNode()) { texts.push(walker.currentNode); }
    texts.forEach((node) => {
        const pieces = splitLine(node.nodeValue, 'template');
        if (!pieces.some((p) => p.ref !== undefined)) { return; }
        node.replaceWith(...pieces.map((p) => (p.ref !== undefined ? make(p.label, p.ref) : document.createTextNode(p.text))));
    });
    return box.innerHTML;
}

export function templateNodesToHtml(html, selector, read) {
    const box = document.createElement('template');
    box.innerHTML = html || '';
    box.content.querySelectorAll(selector).forEach((el) => {
        const { label, ref } = read(el);
        el.replaceWith(document.createTextNode(templateToken(label, ref)));
    });
    return box.innerHTML;
}

// ── The document: one line of text and pills ───────────────────────────────────────────────────────────────────────────────────

const schema = new Schema({
    nodes: {
        doc: { content: 'line' },
        line: { content: 'inline*', toDOM: () => ['div', { class: 'fx-line' }, 0], parseDOM: [{ tag: 'div' }, { tag: 'p' }] },
        text: { group: 'inline' },
        ref: {
            group: 'inline', inline: true, atom: true, selectable: true, draggable: false,
            attrs: { id: { default: '' }, label: { default: '' } },
            toDOM: (node) => ['span', { 'data-fx-ref': node.attrs.id, 'data-label': node.attrs.label }, node.attrs.label || node.attrs.id],
            parseDOM: [{ tag: 'span[data-fx-ref]', getAttrs: (el) => ({ id: el.getAttribute('data-fx-ref') || '', label: el.getAttribute('data-label') || '' }) }],
        },
    },
});

/** The inline nodes of a text: in a formula, `[ref]` outside quotes; in a template, `{{label|ref}}`. */
function inlineNodes(text, mode) {
    return splitLine(text, mode).map((p) => (p.ref !== undefined
        ? schema.nodes.ref.create({ id: p.ref, label: p.label || '' })
        : schema.text(p.text)));
}

function docOf(text, mode) {
    return schema.node('doc', null, [schema.node('line', null, inlineNodes(String(text || '').replace(/[\r\n]+/g, ' '), mode))]);
}

/**
 * The text of the line and where each piece of it is in the document: `segs` ([{ref, s, e, pos, len}]) maps offsets of the text to
 * positions of ProseMirror and back.
 */
function serialize(doc, mode, labelOf) {
    const line = doc.firstChild;
    let text = '';
    const segs = [];
    line.forEach((node, offset) => {
        const pos = 1 + offset;
        const s = text.length;
        if (node.isText) {
            text += node.text;
            segs.push({ ref: false, s, e: text.length, pos, len: node.text.length });
        } else {
            text += joinLine([{ ref: node.attrs.id, label: node.attrs.label }], mode, labelOf);
            segs.push({ ref: true, s, e: text.length, pos, len: 1 });
        }
    });
    return { text, segs, end: 1 + line.content.size };
}

function toPos(map, offset) {
    for (const seg of map.segs) {
        if (!seg.ref && offset >= seg.s && offset <= seg.e) { return seg.pos + (offset - seg.s); }
        if (seg.ref && offset === seg.s) { return seg.pos; }
        if (seg.ref && offset === seg.e) { return seg.pos + 1; }
    }
    return map.end;
}

function toOffset(map, pos) {
    for (const seg of map.segs) {
        if (pos >= seg.pos && pos <= seg.pos + seg.len) {
            return seg.ref ? (pos === seg.pos ? seg.s : seg.e) : seg.s + (pos - seg.pos);
        }
    }
    return map.text.length;
}

// ── The diagnosis of a formula: colours, mistakes, what to say under it ─────────────────────────────────────────────────────────

/**
 * Everything the editor shows about a formula: the decorations of its text (kinds of token, depth of each parenthesis and the pair at
 * the cursor, the mistake), and the messages under it.
 */
function diagnose(map, cursorOffset, known, L, focused) {
    const source = map.text;
    const decos = [];
    const mark = (s, e, cls, attrs = {}) => {
        const from = toPos(map, s);
        const to = toPos(map, e);
        if (to > from) { decos.push(Decoration.inline(from, to, { class: cls, ...attrs })); }
    };
    const toks = tokens(source, true);
    // Parentheses: depth by depth, the unmatched ones in red.
    const stack = [];
    const pairs = new Map();
    toks.forEach((t, i) => {
        if (t.t === 'op' && t.v === '(') { stack.push(i); }
        if (t.t === 'op' && t.v === ')') {
            if (stack.length) { const o = stack.pop(); pairs.set(o, i); pairs.set(i, o); } else { pairs.set(i, -1); }
        }
    });
    stack.forEach((i) => pairs.set(i, -1));
    let depth = 0;
    toks.forEach((t, i) => {
        if (t.t === 'num') { mark(t.s, t.e, 'fx-num'); }
        else if (t.t === 'str') { mark(t.s, t.e, 'fx-str'); }
        else if (t.t === 'bad') { mark(t.s, t.e, 'fx-bad'); }
        else if (t.t === 'name') {
            if (FUNCTIONS.includes(t.v)) { mark(t.s, t.e, 'fx-fn', { 'data-fn': t.v, title: L.fn_click || '' }); }
            else if (t.v === 'true' || t.v === 'false') { mark(t.s, t.e, 'fx-bool'); }
            else { mark(t.s, t.e, 'fx-name'); }
        } else if (t.t === 'op' && (t.v === '(' || t.v === ')')) {
            const unmatched = pairs.get(i) === -1;
            if (t.v === ')' && !unmatched) { depth--; }
            const level = Math.max(0, depth) % 4;
            const atCursor = cursorOffset !== null && (cursorOffset === t.s || cursorOffset === t.e);
            const partner = pairs.get(i);
            const partnerAtCursor = partner >= 0 && cursorOffset !== null && (cursorOffset === toks[partner].s || cursorOffset === toks[partner].e);
            mark(t.s, t.e, 'fx-paren ' + (unmatched ? 'fx-paren-bad' : 'fx-paren-' + level) + (!unmatched && (atCursor || partnerAtCursor) ? ' fx-paren-match' : ''));
            if (t.v === '(' && !unmatched) { depth++; }
        } else if (t.t === 'op' && t.v === ';') { mark(t.s, t.e, 'fx-sep'); }
        else if (t.t === 'op') { mark(t.s, t.e, 'fx-op'); }
    });

    const messages = [];
    const unknown = toks.filter((t) => t.t === 'ref' && !t.open && !known(t.v));
    const parsed = source.trim() === '' ? { error: null } : parse(source);
    if (parsed.error) {
        const { start, end } = parsed.at;
        const d = parsed.data || {};
        // Still being written: what is missing at the end is said in grey while the cursor is in the editor.
        // And so is a name still being typed at the cursor («medi» on the way to a function or a datum).
        const pending = focused && (parsed.error === 'end' || parsed.error === 'parenthesis' || parsed.error === 'string' || parsed.error === 'reference'
            || (parsed.error === 'function' && cursorOffset === end));
        let text;
        let fix = null;
        switch (parsed.error) {
            case 'string': text = L.err_string; break;
            case 'reference': text = L.err_reference; break;
            case 'character': text = (L.err_character || '').replace('%char%', d.char); break;
            case 'end': text = d.after ? (L.err_end || '').replace('%after%', d.after) : L.err_empty; break;
            case 'parenthesis': text = L.err_parenthesis; break;
            case 'function':
                if (d.call) {
                    text = (L.err_call || '').replaceAll('%name%', d.name);
                } else {
                    const guess = suggestFunction(d.name);
                    text = (L.err_function || '').replace('%name%', d.name) + (guess ? ' ' + (L.err_did_you_mean || '').replace('%name%', guess) : '');
                    if (guess) { fix = { label: (L.fix_to || '').replace('%name%', guess), s: start, e: end, text: guess }; }
                }
                break;
            default:
                text = d.token === ')' ? L.err_extra_paren : d.token === ';' ? L.err_semicolon : (L.err_unexpected || '').replace('%token%', d.token || '');
        }
        if (end > start) {
            mark(start, end, pending ? 'fx-pending' : 'fx-error');
        } else {
            const at = toPos(map, start);
            decos.push(Decoration.widget(at, () => {
                const caret = document.createElement('span');
                caret.className = pending ? 'fx-missing fx-missing-pending' : 'fx-missing';
                caret.setAttribute('aria-hidden', 'true');
                return caret;
            }, { side: 1, key: 'missing' + (pending ? 'p' : '') }));
        }
        messages.push({ tone: pending ? 'pending' : 'error', text, fix });
    } else if (parsed.ast) {
        arityProblems(parsed.ast).forEach((p) => {
            mark(p.s, p.e, 'fx-warn');
            const signature = p.name + '(' + ((L.fn && L.fn[p.name] && L.fn[p.name].args) || '…') + ')';
            messages.push({ tone: 'warn', text: (p.given < p.min ? L.warn_few : L.warn_many || '').replace('%name%', p.name).replace('%signature%', signature) });
        });
    }
    if (unknown.length) {
        messages.push({ tone: 'error', text: L.unknown_refs });
    }
    // The values of the function at the cursor, the one being written in bold.
    let signature = null;
    if (cursorOffset !== null && focused) {
        const call = callAt(source, cursorOffset);
        if (call && L.fn && L.fn[call.name]) {
            signature = { name: call.name, args: (L.fn[call.name].args || '').split(';').map((a) => a.trim()), arg: call.arg, desc: L.fn[call.name].desc };
        }
    }
    return { decos, messages, signature, invalid: Boolean(parsed.error) || unknown.length > 0 };
}

// ── The editor ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/**
 * The shared core of both editors. `refs` lists what can be referred to: [{id, label, kind, group, hint}].
 *
 * @returns {{dom: HTMLElement, view: EditorView, value: () => string, setValue: (t: string) => void, insertRef: (id: string) => void,
 *            insertText: (t: string) => void, focus: () => void, destroy: () => void, onState: (fn: Function) => void}}
 */
function mountLine(holder, { mode, value = '', refs = [], labels = {}, onChange = () => {}, placeholder = '', ariaLabel = '', className = '' }) {
    const L = labels;
    const byId = new Map(refs.map((r) => [r.id, r]));
    // What a template writes as the readable part of `{{…|ref}}` (`token`, e.g. «Campo: Departamento»; the pill shows the short label).
    const labelOf = (id) => (byId.has(id) ? (byId.get(id).token || byId.get(id).label) : null);
    const known = (id) => byId.has(id);
    let listeners = [];
    let suggest = null;          // the open suggestions: {menu, from, to}
    let quiet = false;           // Escape closed the suggestions: none until the next character
    let focused = false;

    const refItems = () => refs.map((r) => ({ value: r.id, label: r.label, hint: r.hint, group: r.group, kind: r.kind, keywords: L['kind_' + r.kind] }));
    const fnItems = () => FUNCTIONS.map((f) => ({
        value: f, label: f + '(' + ((L.fn && L.fn[f] && L.fn[f].args) || '') + ')', mono: true, group: L.functions,
        desc: L.fn && L.fn[f] ? L.fn[f].desc : '', keywords: f + ' ' + (L.fn && L.fn[f] ? (L.fn[f].keywords || '') + ' ' + L.fn[f].desc : ''),
    }));

    const current = (state) => serialize(state.doc, mode, labelOf);

    class RefView {
        constructor(node, view, getPos) {
            this.node = node;
            this.view = view;
            this.getPos = getPos;
            this.dom = this.draw(node);
        }

        draw(node) {
            const item = byId.get(node.attrs.id) || null;
            const pill = pillElement(item, { id: node.attrs.id, fallback: node.attrs.label, labels: L });
            pill.addEventListener('mousedown', (e) => {
                e.preventDefault();
                this.open();
            });
            return pill;
        }

        open() {
            const pos = this.getPos();
            if (typeof pos !== 'number') { return; }
            this.view.dispatch(this.view.state.tr.setSelection(NodeSelection.create(this.view.state.doc, pos)));
            openMenu({
                anchor: this.dom, items: refItems(), search: true, selected: this.node.attrs.id, labels: L, title: L.change_ref,
                onPick: (item) => {
                    const at = this.getPos();
                    const tr = this.view.state.tr.setNodeMarkup(at, null, { id: item.value, label: item.label });
                    tr.setSelection(TextSelection.create(tr.doc, at + 1));
                    this.view.dispatch(tr);
                    this.view.focus();
                },
                onClose: () => this.view.focus(),
            });
        }

        update(node) {
            if (node.type !== this.node.type) { return false; }
            if (node.attrs.id !== this.node.attrs.id) {
                const next = this.draw(node);
                this.dom.replaceWith(next);
                this.dom = next;
            }
            this.node = node;
            return true;
        }

        selectNode() { this.dom.classList.add('is-selected'); }

        deselectNode() { this.dom.classList.remove('is-selected'); }

        stopEvent(event) { return event.type === 'mousedown'; }

        ignoreMutation() { return true; }
    }

    const refNode = (id) => schema.nodes.ref.create({ id, label: labelOf(id) || '' });

    const closeSuggest = () => {
        if (suggest) { const m = suggest.menu; suggest = null; m.close(); }
    };

    /** What is being written right before the cursor, and what to offer for it. */
    const updateSuggest = (view) => {
        const { state } = view;
        const sel = state.selection;
        if (!focused || quiet || !sel.empty) { closeSuggest(); return; }
        const before = sel.$from.nodeBefore;
        const text = before && before.isText ? before.text : '';
        let match = null;
        let items = [];
        if (mode === 'template') {
            match = /\{\{?([^{}|]*)$/.exec(text);
            if (match) { items = refItems().filter((i) => matches(i, match[1])); }
        } else {
            const map = current(state);
            const offset = toOffset(map, sel.from);
            const quotes = (map.text.slice(0, offset).match(/"/g) || []).length;
            if (quotes % 2 === 0) {
                match = /\[([^[\]"]*)$/.exec(text);
                if (match) {
                    items = refItems().filter((i) => matches(i, match[1]));
                } else {
                    match = /(?:^|[^A-Za-z0-9_.À-ɏ])([A-Za-z_À-ɏ][A-Za-z0-9_À-ɏ]*)$/.exec(text);
                    if (match) {
                        const word = match[1];
                        const q = fold(word);
                        const fns = fnItems().filter((i) => i.value.startsWith(q) || fold(i.keywords).split(/\s+/).some((k) => k.startsWith(q)));
                        // Data whose name has a word starting with what is written («apro» → «Aprobados (%)»).
                        const data = word.length >= 2 ? refItems().filter((i) => wordStarts(i.label, word)).slice(0, 8) : [];
                        items = [...fns, ...data];
                        // The whole word already is the name of a function: nothing to offer but itself.
                        if (items.length === 1 && items[0].value === q && FUNCTIONS.includes(q)) { items = []; }
                        match = [match[0], word];
                        match.wordOnly = true;
                    }
                }
            }
        }
        if (!match || items.length === 0) { closeSuggest(); return; }
        const typed = match.wordOnly ? match[1] : match[0];
        // What the chosen item replaces: the word (or `[…`, `{{…`) as it is now, kept up to date while the list stays open.
        if (suggest && suggest.menu.isOpen()) {
            suggest.range.from = sel.from - typed.length;
            suggest.range.to = sel.from;
            suggest.menu.setItems(items);
            suggest.menu.place();
            return;
        }
        const range = { from: sel.from - typed.length, to: sel.from };
        const anchor = () => {
            const c = view.coordsAtPos(Math.min(range.from, view.state.doc.content.size - 1));
            return { left: c.left, right: c.right, top: c.top, bottom: c.bottom };
        };
        const menu = openMenu({
            anchor, items, labels: L, title: L.suggestions,
            onPick: (item) => {
                const s = range;
                suggest = null;
                const tr = view.state.tr;
                if (FUNCTIONS.includes(item.value) && item.mono) {
                    tr.insertText(item.value + '()', s.from, s.to);
                    tr.setSelection(TextSelection.create(tr.doc, s.from + item.value.length + 1));
                } else {
                    tr.replaceWith(s.from, s.to, refNode(item.value));
                    tr.setSelection(TextSelection.create(tr.doc, s.from + 1));
                }
                view.dispatch(tr);
                view.focus();
            },
            onClose: () => { if (suggest && suggest.menu === menu) { suggest = null; } },
        });
        suggest = { menu, range };
    };

    const decorations = (state) => {
        if (mode !== 'formula') {
            if (state.doc.firstChild.content.size === 0 && placeholder) {
                return DecorationSet.create(state.doc, [Decoration.node(0, state.doc.firstChild.nodeSize, { class: 'fx-empty', 'data-placeholder': placeholder })]);
            }
            return DecorationSet.empty;
        }
        const map = current(state);
        const cursor = state.selection.empty ? toOffset(map, state.selection.from) : null;
        const result = diagnose(map, cursor, known, L, focused);
        const decos = result.decos;
        if (map.text === '' && placeholder) {
            decos.push(Decoration.node(0, state.doc.firstChild.nodeSize, { class: 'fx-empty', 'data-placeholder': placeholder }));
        }
        return DecorationSet.create(state.doc, decos);
    };

    const plugin = new Plugin({
        props: {
            decorations,
            handleKeyDown(view, event) {
                if (suggest && suggest.menu.isOpen()) {
                    if (event.key === 'ArrowDown') { suggest.menu.move(1); return true; }
                    if (event.key === 'ArrowUp') { suggest.menu.move(-1); return true; }
                    if (event.key === 'Enter' || event.key === 'Tab') { return suggest.menu.pick(); }
                    if (event.key === 'Escape') { quiet = true; closeSuggest(); return true; }
                }
                // A selected pill opens its list with Enter, Space or the down arrow.
                const sel = view.state.selection;
                if (sel instanceof NodeSelection && sel.node.type === schema.nodes.ref && ['Enter', ' ', 'ArrowDown'].includes(event.key)) {
                    const dom = view.nodeDOM(sel.from);
                    dom?.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));
                    return true;
                }
                if (event.key === 'Enter') { return true; }
                return false;
            },
            handleClick(view, pos, event) {
                const fn = event.target instanceof Element ? event.target.closest('.fx-fn') : null;
                if (!fn || mode !== 'formula') { return false; }
                const map = current(view.state);
                const offset = toOffset(map, pos);
                const tok = tokens(map.text, true).find((t) => t.t === 'name' && t.s <= offset && offset <= t.e && FUNCTIONS.includes(t.v));
                if (!tok) { return false; }
                const from = toPos(map, tok.s);
                const to = toPos(map, tok.e);
                openMenu({
                    anchor: fn, items: fnItems(), search: true, selected: tok.v, labels: L, title: L.change_function,
                    onPick: (item) => {
                        view.dispatch(view.state.tr.insertText(item.value, from, to));
                        view.focus();
                    },
                    onClose: () => view.focus(),
                });
                return true;
            },
            handlePaste(view, event) {
                const text = event.clipboardData?.getData('text/plain');
                if (text === undefined || text === null) { return false; }
                const { from, to } = view.state.selection;
                view.dispatch(view.state.tr.replaceWith(from, to, inlineNodes(text.replace(/[\r\n]+/g, ' '), mode)).scrollIntoView());
                return true;
            },
            handleDrop: () => true,
            // One line: it is enough that the editor is in sight. (ProseMirror's own scrolling to the cursor measured a wrong place
            // on the first key after a click next to pills and threw the page down a screen.)
            handleScrollToSelection(view) {
                const r = view.dom.getBoundingClientRect();
                if (r.top < 64 || r.bottom > window.innerHeight) { view.dom.scrollIntoView({ block: 'nearest' }); }
                return true;
            },
            clipboardTextSerializer: (slice) => {
                let text = '';
                slice.content.descendants((node) => {
                    if (node.isText) { text += node.text; }
                    if (node.type === schema.nodes.ref) {
                        text += joinLine([{ ref: node.attrs.id, label: node.attrs.label }], mode, labelOf);
                    }
                });
                return text;
            },
            nodeViews: { ref: (node, view, getPos) => new RefView(node, view, getPos) },
            handleDOMEvents: {
                // A press on the empty part of the line (right of the text, the padding): the cursor goes to the nearest place. Left
                // to the browser, it lands at the start of the line when there are pills in it.
                mousedown: (view, event) => {
                    if (event.button !== 0 || !(event.target === view.dom || (event.target instanceof Element && event.target.classList.contains('fx-line')))) {
                        return false;
                    }
                    event.preventDefault();
                    const lineEnd = 1 + view.state.doc.firstChild.content.size;
                    const at = view.posAtCoords({ left: event.clientX, top: event.clientY });
                    const lastRight = view.dom.querySelector('.fx-line')?.lastChild?.getBoundingClientRect?.().right ?? 0;
                    const target = !at || event.clientX >= lastRight ? lineEnd : Math.min(Math.max(at.pos, 1), lineEnd);
                    view.focus();
                    view.dispatch(view.state.tr.setSelection(TextSelection.create(view.state.doc, target)));
                    return true;
                },
                focus: (view) => {
                    focused = true;
                    // Redrawn (the grey «still being written» marks) once the browser has placed the cursor where the click was: a
                    // transaction right now would carry the old selection and move the cursor back.
                    setTimeout(() => { if (!view.isDestroyed) { view.dispatch(view.state.tr.setMeta('fx', 'focus')); } }, 0);
                    return false;
                },
                blur: (view, event) => {
                    // Going into one of its menus is still being in the editor.
                    if (event.relatedTarget instanceof Element && event.relatedTarget.closest('[data-fx-menu]')) { return false; }
                    focused = false;
                    closeSuggest();
                    view.dispatch(view.state.tr.setMeta('fx', 'blur'));
                    return false;
                },
            },
        },
    });

    // Backspace and Delete take a pill away whole, at once.
    const removeRef = (backward) => (state, dispatch) => {
        const sel = state.selection;
        if (!sel.empty) { return false; }
        const node = backward ? sel.$from.nodeBefore : sel.$from.nodeAfter;
        if (!node || node.type !== schema.nodes.ref) { return false; }
        if (dispatch) {
            const at = backward ? sel.from - 1 : sel.from;
            dispatch(state.tr.delete(at, at + 1));
        }
        return true;
    };

    // Home and End: the start and the end of the line. (Left to Chrome, End next to pills moved the caret somewhere odd and threw the
    // page down a screen.)
    const edge = (toEnd, extend) => (state, dispatch) => {
        const pos = toEnd ? 1 + state.doc.firstChild.content.size : 1;
        if (dispatch) {
            dispatch(state.tr.setSelection(extend ? TextSelection.create(state.doc, state.selection.anchor, pos) : TextSelection.create(state.doc, pos)));
        }
        return true;
    };

    const dom = document.createElement('div');
    dom.className = 'fx-editor ' + className;
    holder.append(dom);
    let lastText = null;
    const view = new EditorView(dom, {
        state: EditorState.create({
            doc: docOf(value, mode),
            plugins: [plugin, history(), keymap({
                'Mod-z': undo, 'Shift-Mod-z': redo, 'Mod-y': redo, Backspace: removeRef(true), Delete: removeRef(false),
                Home: edge(false, false), End: edge(true, false), 'Shift-Home': edge(false, true), 'Shift-End': edge(true, true),
            }), keymap(baseKeymap)],
        }),
        attributes: { role: 'textbox', 'aria-label': ariaLabel, 'aria-multiline': 'false', spellcheck: 'false', class: 'fx-content' },
        dispatchTransaction(tr) {
            const state = view.state.apply(tr);
            view.updateState(state);
            const text = current(state).text;
            if (tr.docChanged) {
                quiet = false;
                if (text !== lastText) {
                    lastText = text;
                    onChange(text);
                }
            }
            if (tr.docChanged || tr.selectionSet) {
                // Typing opens or updates the suggestions; moving the cursor only updates them if they are open (and closes them when
                // the cursor leaves what they were for), so arrowing along a formula does not pop lists up.
                if (tr.docChanged && !tr.getMeta('fx-set')) { updateSuggest(view); } else if (!tr.docChanged && suggest) { updateSuggest(view); }
            }
            listeners.forEach((fn) => fn(state));
        },
    });
    lastText = current(view.state).text;
    const api = {
        dom,
        view,
        known,
        labels: L,
        current: () => current(view.state),
        value: () => current(view.state).text,
        setValue: (text) => {
            const doc = docOf(text, mode);
            const tr = view.state.tr.replaceWith(0, view.state.doc.content.size, doc.content).setMeta('fx-set', true);
            view.dispatch(tr);
        },
        insertRef: (id) => {
            const { from, to } = view.state.selection;
            const tr = view.state.tr.replaceWith(from, to, refNode(id));
            tr.setSelection(TextSelection.create(tr.doc, from + 1));
            view.dispatch(tr);
            view.focus();
        },
        insertText: (text) => {
            const { from, to } = view.state.selection;
            view.dispatch(view.state.tr.insertText(text, from, to));
            view.focus();
        },
        focus: () => view.focus(),
        isFocused: () => focused,
        refItems,
        fnItems,
        onState: (fn) => { listeners.push(fn); },
        destroy: () => { closeSuggest(); listeners = []; view.destroy(); dom.remove(); },
    };
    return api;
}

/**
 * The formula editor: the line with its pills, the buttons to insert a datum or a function and to see it as text, and under it what is
 * wrong (or the values of the function being written).
 *
 * @param {HTMLElement} holder
 * @param {{value?: string, refs: Array<{id: string, label: string, kind?: string, group?: string, hint?: string}>, labels: object,
 *          onChange?: (text: string) => void, ariaLabel?: string, placeholder?: string}} options
 */
export function mountFormulaEditor(holder, { value = '', refs = [], labels = {}, onChange = () => {}, ariaLabel = '', placeholder = '' } = {}) {
    const L = labels;
    holder.classList.add('fx-formula');
    const line = mountLine(holder, { mode: 'formula', value, refs, labels, onChange: (t) => { onChange(t); }, placeholder, ariaLabel, className: 'fx-mono-line' });

    // Plain text, for whoever prefers to type it: the same value, both ways.
    const raw = document.createElement('input');
    raw.className = 'fx-raw';
    raw.hidden = true;
    raw.setAttribute('aria-label', ariaLabel);
    raw.maxLength = 1000;
    raw.addEventListener('input', () => { line.setValue(raw.value); onChange(raw.value); paint(line.view.state); });
    holder.append(raw);

    const status = document.createElement('div');
    status.className = 'fx-status';
    status.id = 'fx-status-' + (++menuSeq);
    status.setAttribute('aria-live', 'polite');
    line.view.dom.setAttribute('aria-describedby', status.id);

    const button = (text, onClick, extra = '') => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'fx-tool ' + extra;
        b.textContent = text;
        b.addEventListener('mousedown', (e) => e.preventDefault());
        b.addEventListener('click', onClick);
        return b;
    };
    const tools = document.createElement('div');
    tools.className = 'fx-tools';
    const insertRef = button('[ ] ' + (L.insert_ref || ''), (e) => openMenu({
        anchor: e.currentTarget, items: line.refItems(), search: true, labels: L, title: L.insert_ref,
        onPick: (item) => line.insertRef(item.value), onClose: () => line.focus(),
    }));
    const insertFn = button('ƒ ' + (L.insert_function || ''), (e) => openMenu({
        anchor: e.currentTarget, items: line.fnItems(), search: true, labels: L, title: L.insert_function,
        onPick: (item) => {
            const { from, to } = line.view.state.selection;
            const tr = line.view.state.tr.insertText(item.value + '()', from, to);
            tr.setSelection(TextSelection.create(tr.doc, from + item.value.length + 1));
            line.view.dispatch(tr);
            line.focus();
        },
        onClose: () => line.focus(),
    }));
    const spacer = document.createElement('span');
    spacer.className = 'fx-spacer';
    const asText = button(L.as_text || '', () => {
        const toText = raw.hidden;
        raw.hidden = !toText;
        line.dom.hidden = toText;
        insertRef.hidden = toText;
        insertFn.hidden = toText;
        asText.textContent = toText ? L.as_pills : L.as_text;
        asText.setAttribute('aria-pressed', toText ? 'true' : 'false');
        if (toText) { raw.value = line.value(); raw.focus(); } else { line.focus(); }
    }, 'fx-tool-ghost');
    asText.setAttribute('aria-pressed', 'false');
    tools.append(insertRef, insertFn, spacer, asText);
    holder.append(tools, status);

    const paint = (state) => {
        const map = line.current();
        const cursor = state.selection.empty ? toOffset(map, state.selection.from) : null;
        const result = diagnose(map, cursor, line.known, L, line.isFocused());
        line.view.dom.setAttribute('aria-invalid', result.invalid ? 'true' : 'false');
        holder.classList.toggle('is-invalid', result.messages.some((m) => m.tone === 'error'));
        status.replaceChildren();
        if (result.signature) {
            const sig = document.createElement('p');
            sig.className = 'fx-signature';
            const code = document.createElement('code');
            code.append(result.signature.name + '(');
            result.signature.args.forEach((a, i) => {
                if (i > 0) { code.append('; '); }
                const part = document.createElement(i === Math.min(result.signature.arg, result.signature.args.length - 1) ? 'strong' : 'span');
                part.textContent = a;
                code.append(part);
            });
            code.append(')');
            sig.append(code, ' — ' + result.signature.desc);
            status.append(sig);
        }
        result.messages.forEach((m) => {
            const p = document.createElement('p');
            p.className = 'fx-msg fx-msg-' + m.tone;
            p.textContent = m.text;
            if (m.fix) {
                const fix = button(m.fix.label, () => {
                    const current = line.current();
                    const tr = line.view.state.tr.insertText(m.fix.text, toPos(current, m.fix.s), toPos(current, m.fix.e));
                    line.view.dispatch(tr);
                    line.focus();
                }, 'fx-fix');
                p.append(' ', fix);
            }
            status.append(p);
        });
    };
    line.onState(paint);
    paint(line.view.state);

    return {
        value: line.value,
        setValue: (text) => { line.setValue(text); raw.value = text; },
        focus: line.focus,
        destroy: () => { line.destroy(); holder.replaceChildren(); },
    };
}

/**
 * A single line of text with `{{label|ref}}` references as pills (a section title), and its «{ } Insertar referencia» button.
 *
 * @param {HTMLElement} holder
 * @param {{value?: string, refs: Array<object>, labels: object, onChange?: (text: string) => void, ariaLabel?: string, placeholder?: string, focusKey?: string, className?: string}} options
 */
export function mountTemplateEditor(holder, { value = '', refs = [], labels = {}, onChange = () => {}, ariaLabel = '', placeholder = '', focusKey = '', className = '' } = {}) {
    const L = labels;
    holder.classList.add('fx-template');
    const line = mountLine(holder, { mode: 'template', value, refs, labels, onChange, placeholder, ariaLabel, className });
    if (focusKey) { line.view.dom.setAttribute('data-focus', focusKey); }
    const insert = document.createElement('button');
    insert.type = 'button';
    insert.className = 'fx-tool fx-tool-small';
    insert.textContent = '{ } ' + (L.insert_template_ref || '');
    insert.addEventListener('mousedown', (e) => e.preventDefault());
    insert.addEventListener('click', (e) => openMenu({
        anchor: e.currentTarget, items: line.refItems(), search: true, labels: L, title: L.insert_template_ref,
        onPick: (item) => line.insertRef(item.value), onClose: () => line.focus(),
    }));
    holder.append(insert);
    return { value: line.value, setValue: line.setValue, focus: line.focus, destroy: () => { line.destroy(); holder.replaceChildren(); } };
}
