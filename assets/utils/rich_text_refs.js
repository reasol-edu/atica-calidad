import { Node } from '@tiptap/core';
import { openMenu, pillElement, templateHtmlToNodes, templateNodesToHtml } from './formula_editor.js';

/**
 * The `{{label|ref}}` references of a text of a model (the rich text editor of the model editor), shown as pills with their name and a
 * chevron to change them, as in the formulas. Stored as ever: `htmlIn()` turns the tokens of the saved HTML into `<span data-ref-token>`
 * the extension reads, and `htmlOut()` turns them back into `{{label|ref}}` text before the HTML is handed over, so neither the
 * sanitizer nor the server ever see a span. `refs`: [{id, label, kind, group, hint, token}] (`token`: the readable part written).
 *
 * @returns {{extensions: Array, htmlIn: (html: string) => string, htmlOut: (html: string) => string, insert: (editor: object, id: string) => void, items: () => Array}}
 */
export function richTextRefs(refs, labels) {
    const byId = new Map(refs.map((r) => [r.id, r]));
    const tokenLabel = (id, fallback) => (byId.has(id) ? (byId.get(id).token || byId.get(id).label) : fallback);
    const items = () => refs.map((r) => ({ value: r.id, label: r.label, hint: r.hint, group: r.group, kind: r.kind }));

    const RefToken = Node.create({
        name: 'refToken',
        group: 'inline',
        inline: true,
        atom: true,
        selectable: true,
        draggable: false,

        addAttributes() {
            return { ref: { default: '' }, label: { default: '' } };
        },

        parseHTML() {
            return [{ tag: 'span[data-ref-token]', getAttrs: (el) => ({ ref: el.getAttribute('data-ref-token') || '', label: el.getAttribute('data-label') || '' }) }];
        },

        renderHTML({ node }) {
            return ['span', { 'data-ref-token': node.attrs.ref, 'data-label': tokenLabel(node.attrs.ref, node.attrs.label) }, node.attrs.label];
        },

        addNodeView() {
            return ({ node, getPos, editor }) => {
                let current = node;
                const draw = () => {
                    const pill = pillElement(byId.get(current.attrs.ref) || null, { id: current.attrs.ref, fallback: current.attrs.label, labels });
                    pill.addEventListener('mousedown', (e) => {
                        e.preventDefault();
                        openMenu({
                            anchor: pill, items: items(), search: true, selected: current.attrs.ref, labels, title: labels.change_ref,
                            onPick: (item) => {
                                const pos = getPos();
                                if (typeof pos !== 'number') { return; }
                                editor.chain().focus().command(({ tr }) => {
                                    tr.setNodeMarkup(pos, null, { ref: item.value, label: tokenLabel(item.value, item.label) });
                                    return true;
                                }).run();
                            },
                            onClose: () => editor.commands.focus(),
                        });
                    });
                    return pill;
                };
                let dom = draw();
                const wrapper = document.createElement('span');
                wrapper.className = 'fx-pill-wrap';
                wrapper.append(dom);
                return {
                    dom: wrapper,
                    update: (next) => {
                        if (next.type !== current.type) { return false; }
                        const changed = next.attrs.ref !== current.attrs.ref;
                        current = next;
                        if (changed) { const fresh = draw(); dom.replaceWith(fresh); dom = fresh; }
                        return true;
                    },
                    selectNode: () => dom.classList.add('is-selected'),
                    deselectNode: () => dom.classList.remove('is-selected'),
                    stopEvent: (event) => event.type === 'mousedown',
                    ignoreMutation: () => true,
                };
            };
        },
    });

    const span = (label, ref) => {
        const el = document.createElement('span');
        el.setAttribute('data-ref-token', ref.trim());
        el.setAttribute('data-label', label.trim());
        el.textContent = label.trim();
        return el;
    };

    return {
        extensions: [RefToken],
        htmlIn: (html) => templateHtmlToNodes(html, span),
        htmlOut: (html) => templateNodesToHtml(html, 'span[data-ref-token]', (el) => {
            const ref = el.getAttribute('data-ref-token') || '';
            return { ref, label: tokenLabel(ref, el.getAttribute('data-label') || '') };
        }),
        insert: (editor, id) => editor.chain().focus().insertContent({ type: 'refToken', attrs: { ref: id, label: tokenLabel(id, '') } }).run(),
        items,
    };
}

/** A saved text with its references drawn as pills, to show it (the model editor's preview of a text before it is clicked). */
export function htmlWithPills(html, refs, labels) {
    const byId = new Map(refs.map((r) => [r.id, r]));
    return templateHtmlToNodes(html, (label, ref) => {
        // Inside the preview (itself a button): only to be seen.
        const pill = pillElement(byId.get(ref.trim()) || null, { id: ref.trim(), fallback: label.trim(), labels });
        pill.removeAttribute('role');
        pill.removeAttribute('aria-label');
        pill.querySelector('.fx-pill-chev')?.remove();
        return pill;
    });
}
