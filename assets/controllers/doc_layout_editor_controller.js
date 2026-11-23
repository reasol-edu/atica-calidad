import { Controller } from '@hotwired/stimulus';
import { Editor, Extension } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import TextAlign from '@tiptap/extension-text-align';
import { Color, FontSize, TextStyle } from '@tiptap/extension-text-style';
import { Table, TableCell, TableHeader, TableRow } from '@tiptap/extension-table';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';
import 'prosemirror-view/style/prosemirror.min.css';
import 'prosemirror-tables/style/tables.min.css';

const BORDER = '1px solid #555555';

// The attributes the output needs on each cell: its border (a table can be without lines), its background and its vertical alignment.
// They are written as inline style, which is what the PDF and the office files read (see FormLayout and DocLayoutStyleAttributeSanitizer).
const cellAttributes = (parent) => ({
    ...parent,
    background: {
        default: null,
        parseHTML: (el) => el.style.backgroundColor || null,
        renderHTML: (attrs) => (attrs.background ? { style: `background-color: ${attrs.background}` } : {}),
    },
    border: {
        default: BORDER,
        parseHTML: (el) => (/^none\b/.test(el.style.border) || el.style.borderStyle === 'none' ? 'none' : BORDER),
        renderHTML: (attrs) => ({ style: `border: ${attrs.border === 'none' ? 'none' : BORDER}; padding: 4px` }),
    },
    verticalAlign: {
        default: null,
        parseHTML: (el) => el.style.verticalAlign || null,
        renderHTML: (attrs) => (attrs.verticalAlign ? { style: `vertical-align: ${attrs.verticalAlign}` } : {}),
    },
});

const Cell = TableCell.extend({ addAttributes() { return cellAttributes(this.parent?.()); } });
const HeaderCell = TableHeader.extend({ addAttributes() { return cellAttributes(this.parent?.()); } });

// Shows the {tokens} (centre, page number, code…) as marked text, so it is clear which words the application will fill in.
const TokenMarks = Extension.create({
    name: 'tokenMarks',
    addProseMirrorPlugins() {
        return [new Plugin({
            key: new PluginKey('tokenMarks'),
            props: {
                decorations(state) {
                    const decorations = [];
                    state.doc.descendants((node, pos) => {
                        if (!node.isText) {
                            return;
                        }
                        for (const match of node.text.matchAll(/\{[a-z_]+\}/g)) {
                            decorations.push(Decoration.inline(pos + match.index, pos + match.index + match[0].length, { class: 'doc-token' }));
                        }
                    });

                    return DecorationSet.create(state.doc, decorations);
                },
            },
        })];
    },
});

const BTN = 'inline-flex h-8 min-w-8 items-center justify-center rounded-lg border border-gray-200 bg-white px-2 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40';
const SELECT = 'h-8 rounded-lg border border-gray-200 bg-white px-2 text-xs text-gray-700';

/**
 * The editor of the running header and footer of the documents: rich text with tables (merged cells, borders, backgrounds) and a
 * button to put in the fields the application fills in ({centro}, {pagina}…). It keeps the HTML in a hidden textarea and fires `change`
 * on it, so it works inside a settings row (setting-save) as well as in the model editor.
 *
 * Targets: `editor` (where it is drawn), `input` (the textarea with the HTML).
 * Values: `labels` (texts of the buttons, translated), `tokens` ({key: label}), `defaultHtml` (what "restore" goes back to).
 */
export default class extends Controller {
    static targets = ['editor', 'input', 'preview'];
    static values = { labels: Object, tokens: Object, defaultHtml: String, sample: Object };

    connect() {
        const L = this.labelsValue;
        this.timer = null;

        this.toolbar = document.createElement('div');
        this.toolbar.className = 'flex flex-wrap items-center gap-1.5 border-b border-gray-200 bg-gray-50 p-2';
        this.editorTarget.before(this.toolbar);
        this.buttons = [];

        this.editor = new Editor({
            element: this.editorTarget,
            extensions: [
                StarterKit.configure({
                    heading: false, blockquote: false, bulletList: false, orderedList: false, listItem: false, listKeymap: false,
                    code: false, codeBlock: false, horizontalRule: false, strike: false, link: false, trailingNode: false,
                }),
                TextStyle, Color, FontSize,
                TextAlign.configure({ types: ['paragraph'] }),
                Table.configure({ resizable: true }),
                TableRow, Cell, HeaderCell,
                TokenMarks,
            ],
            content: this.inputTarget.value || '',
            editorProps: { attributes: { class: 'doc-layout-prose min-h-24 px-3 py-2 text-sm focus:outline-none', 'aria-label': L.editor || '' } },
            onUpdate: () => {
                this.queueSave();
                this.renderPreview();
            },
            onBlur: () => this.save(),
            onTransaction: () => this.refresh(),
        });

        this.build(L);
        this.refresh();
        this.renderPreview();
    }

    // The header as the page will draw it, with sample data: the fields are filled in and the logos are the centre's (a placeholder
    // when it has none). What is drawn is the editor's own HTML (the schema only lets through what the document allows).
    renderPreview() {
        if (!this.hasPreviewTarget) {
            return;
        }
        const sample = this.sampleValue;
        const escape = (text) => text.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        this.previewTarget.innerHTML = this.editor.getHTML().replace(/\{([a-z0-9_]+)\}/g, (match, key) => {
            if (key === 'logo' || key === 'logo2') {
                return sample[key]
                    ? `<img data-logo src="${escape(sample[key])}" alt="" style="height:1.6cm;width:auto;max-width:100%;object-fit:contain;display:inline-block;vertical-align:middle">`
                    : `<span data-logo-missing class="doc-logo-missing">${escape(this.labelsValue.logoMissing || 'Logo')}</span>`;
            }

            return key in sample ? escape(String(sample[key])) : match;
        });
        this.previewTarget.querySelectorAll('img[data-logo]').forEach((img) => img.addEventListener('error', () => {
            const placeholder = document.createElement('span');
            placeholder.className = 'doc-logo-missing';
            placeholder.textContent = this.labelsValue.logoMissing || 'Logo';
            img.replaceWith(placeholder);
        }, { once: true }));
    }

    disconnect() {
        clearTimeout(this.timer);
        this.editor?.destroy();
        this.toolbar?.remove();
    }

    // ── Toolbar ────────────────────────────────────────────────────────────────

    build(L) {
        const chain = () => this.editor.chain().focus();
        const group = () => {
            const g = document.createElement('div');
            g.className = 'flex items-center gap-1';
            this.toolbar.appendChild(g);

            return g;
        };
        const button = (g, text, title, run, active, needsTable = false) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = BTN;
            b.innerHTML = text;
            b.title = title;
            b.setAttribute('aria-label', title);
            b.addEventListener('mousedown', (e) => e.preventDefault());
            b.addEventListener('click', () => run());
            g.appendChild(b);
            this.buttons.push({ b, active, needsTable });

            return b;
        };

        const text = group();
        button(text, '<strong>N</strong>', L.bold, () => chain().toggleBold().run(), () => this.editor.isActive('bold'));
        button(text, '<em>K</em>', L.italic, () => chain().toggleItalic().run(), () => this.editor.isActive('italic'));
        button(text, '<u>S</u>', L.underline, () => chain().toggleUnderline().run(), () => this.editor.isActive('underline'));
        for (const [value, glyph, label] of [['left', '⇤', L.left], ['center', '↔', L.center], ['right', '⇥', L.right]]) {
            button(text, glyph, label, () => chain().setTextAlign(value).run(), () => this.editor.isActive({ textAlign: value }));
        }

        const color = document.createElement('input');
        color.type = 'color';
        color.value = '#000000';
        color.title = L.color;
        color.setAttribute('aria-label', L.color);
        color.className = 'h-8 w-8 cursor-pointer rounded-lg border border-gray-200 bg-white p-1';
        color.addEventListener('input', () => chain().setColor(color.value).run());
        text.appendChild(color);

        const size = document.createElement('select');
        size.className = SELECT;
        size.title = L.size;
        size.setAttribute('aria-label', L.size);
        size.innerHTML = `<option value="">${L.sizeAuto}</option>` + [8, 9, 10, 11, 12, 14, 16, 18, 20].map((n) => `<option value="${n}pt">${n} pt</option>`).join('');
        size.addEventListener('change', () => (size.value ? chain().setFontSize(size.value).run() : chain().unsetFontSize().run()));
        text.appendChild(size);
        this.sizeSelect = size;

        const field = group();
        const tokens = document.createElement('select');
        tokens.className = SELECT;
        tokens.title = L.field;
        tokens.setAttribute('aria-label', L.field);
        tokens.innerHTML = `<option value="">${L.fieldPlaceholder}</option>` + Object.entries(this.tokensValue).map(([key, label]) => `<option value="${key}">${label}</option>`).join('');
        tokens.addEventListener('change', () => {
            if (tokens.value) {
                chain().insertContent(`{${tokens.value}}`).run();
            }
            tokens.value = '';
        });
        field.appendChild(tokens);

        const table = group();
        button(table, '▦', L.insertTable, () => chain().insertTable({ rows: 2, cols: 3, withHeaderRow: false }).run(), () => false);
        button(table, '+▤', L.addRow, () => chain().addRowAfter().run(), () => false, true);
        button(table, '+▥', L.addCol, () => chain().addColumnAfter().run(), () => false, true);
        button(table, '−▤', L.delRow, () => chain().deleteRow().run(), () => false, true);
        button(table, '−▥', L.delCol, () => chain().deleteColumn().run(), () => false, true);
        button(table, '⊞', L.merge, () => chain().mergeCells().run(), () => false, true);
        button(table, '⊟', L.split, () => chain().splitCell().run(), () => false, true);
        button(table, '▢', L.bordersToggle, () => this.toggleBorders(), () => false, true);
        button(table, '✕', L.delTable, () => chain().deleteTable().run(), () => false, true);

        const bg = document.createElement('input');
        bg.type = 'color';
        bg.value = '#eef2ea';
        bg.title = L.cellBackground;
        bg.setAttribute('aria-label', L.cellBackground);
        bg.className = 'h-8 w-8 cursor-pointer rounded-lg border border-gray-200 bg-white p-1';
        bg.addEventListener('input', () => chain().setCellAttribute('background', bg.value).run());
        table.appendChild(bg);
        this.buttons.push({ b: bg, active: () => false, needsTable: true });

        const noBg = button(table, '∅', L.noBackground, () => chain().setCellAttribute('background', null).run(), () => false, true);
        noBg.classList.add('text-gray-500');

        if (this.defaultHtmlValue) {
            const rest = group();
            rest.classList.add('ml-auto');
            button(rest, L.restore, L.restore, () => {
                this.editor.commands.setContent(this.defaultHtmlValue);
                this.save();
            }, () => false);
        }
    }

    refresh() {
        const inTable = this.editor.isActive('table');
        for (const { b, active, needsTable } of this.buttons) {
            if (needsTable) {
                b.disabled = !inTable;
            }
            b.classList.toggle('bg-forest-100', Boolean(active()));
        }
        if (this.sizeSelect) {
            this.sizeSelect.value = this.editor.getAttributes('textStyle').fontSize || '';
        }
    }

    // Lines on every cell of the table the cursor is in, or none (the table is then only a layout grid).
    toggleBorders() {
        const { state, view } = this.editor;
        const { $from } = state.selection;
        for (let depth = $from.depth; depth > 0; depth -= 1) {
            if ($from.node(depth).type.name !== 'table') {
                continue;
            }
            const table = $from.node(depth);
            const start = $from.start(depth);
            const none = table.firstChild?.firstChild?.attrs.border !== 'none';
            const tr = state.tr;
            table.descendants((node, pos) => {
                if (node.type.name === 'tableCell' || node.type.name === 'tableHeader') {
                    tr.setNodeMarkup(start + pos, undefined, { ...node.attrs, border: none ? 'none' : BORDER });
                }
            });
            view.dispatch(tr);
            this.save();

            return;
        }
    }

    // ── Value ──────────────────────────────────────────────────────────────────

    queueSave() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.save(), 500);
    }

    save() {
        clearTimeout(this.timer);
        const html = this.editor.isEmpty ? '' : this.editor.getHTML();
        if (html === this.inputTarget.value) {
            return;
        }
        this.inputTarget.value = html;
        this.inputTarget.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // Replaces the content from outside (the model editor does it when the person goes back to the inherited header).
    setHtml(html) {
        this.editor.commands.setContent(html || '', { emitUpdate: false });
        this.inputTarget.value = html || '';
        this.renderPreview();
    }
}
