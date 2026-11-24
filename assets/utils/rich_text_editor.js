import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import TextAlign from '@tiptap/extension-text-align';
import { Placeholder } from '@tiptap/extensions';
import 'prosemirror-view/style/prosemirror.min.css';

/**
 * The rich text editor of the whole application (Tiptap): paragraphs, two heading levels, bold, italic, underline, quote, lists, links and
 * alignment. It writes the HTML the `app.rich_text` sanitizer allows (p, h2, h3, strong, em, u, blockquote, ul/ol/li, a, and a paragraph's
 * `text-align`), so what it stores is what the pages and the PDFs read.
 *
 * Used by the `rich-editor` Stimulus controller (settings, management review, folder description) and mounted straight from the model
 * editor and the fill form, which draw their texts on click. The look is in app.css (`.rich-*`).
 */

// The toolbar icons: the paths of the Heroicons imported in assets/icons/heroicons (the same files the Twig `ux_icon()` uses; a script
// cannot read them, so the drawing is repeated here). The first one is the heroicon's own name.
const ICONS = {
    bold: ['2', 'M6.75 3.744h-.753v8.25h7.125a4.125 4.125 0 0 0 0-8.25zm0 0v.38m0 16.122h6.747a4.5 4.5 0 0 0 0-9.001h-7.5v9zm0 0v-.37m0-15.751h6a3.75 3.75 0 1 1 0 7.5h-6m0-7.5v7.5m0 0v8.25m0-8.25h6.375a4.125 4.125 0 0 1 0 8.25H6.75m.747-15.38h4.875a3.375 3.375 0 0 1 0 6.75H7.497zm0 7.5h5.25a3.75 3.75 0 0 1 0 7.5h-5.25z'],
    italic: ['1.5', 'M5.248 20.246H9.05m0 0h3.696m-3.696 0l5.893-16.502m0 0h-3.697m3.697 0h3.803'],
    underline: ['1.5', 'M17.995 3.744v7.5a6 6 0 1 1-12 0v-7.5m-2.25 16.502h16.5'],
    'chat-bubble-bottom-center-text': ['1.5', 'M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227q1.694.25 3.423.379c.35.026.67.21.865.501L12 21l2.755-4.132a1.14 1.14 0 0 1 .865-.502a48 48 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.4 48.4 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741z'],
    'list-bullet': ['1.5', 'M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75zm.375 0a.375.375 0 1 1-.75 0a.375.375 0 0 1 .75 0M3.75 12h.007v.008H3.75zm.375 0a.375.375 0 1 1-.75 0a.375.375 0 0 1 .75 0m-.375 5.25h.007v.008H3.75zm.375 0a.375.375 0 1 1-.75 0a.375.375 0 0 1 .75 0'],
    'numbered-list': ['1.5', 'M8.242 5.992h12m-12 6.003H20.24m-12 5.999h12M4.117 7.495v-3.75H2.99m1.125 3.75H2.99m1.125 0H5.24m-1.92 2.577a1.125 1.125 0 1 1 1.591 1.59l-1.83 1.83h2.16M2.99 15.746h1.125a1.125 1.125 0 0 1 0 2.25H3.74m0-.002h.375a1.125 1.125 0 0 1 0 2.25H2.99'],
    'bars-3-bottom-left': ['1.5', 'M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12'],
    'bars-3': ['1.5', 'M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5'],
    'bars-3-bottom-right': ['1.5', 'M3.75 6.75h16.5M3.75 12h16.5M12 17.25h8.25'],
    'bars-4': ['1.5', 'M3.75 5.25h16.5m-16.5 4.5h16.5m-16.5 4.5h16.5m-16.5 4.5h16.5'],
    link: ['1.5', 'M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244'],
    'x-mark': ['1.5', 'M6 18L18 6M6 6l12 12'],
};

const icon = (name) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="rich-icon" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="${ICONS[name][0]}" d="${ICONS[name][1]}"/></svg>`;

const LABELS = {
    heading2: 'Título (nivel 1)', heading3: 'Título (nivel 2)', bold: 'Negrita', italic: 'Cursiva', underline: 'Subrayado', quote: 'Cita', bullets: 'Lista con viñetas',
    numbers: 'Lista numerada', left: 'Alinear a la izquierda', center: 'Centrar', right: 'Alinear a la derecha', justify: 'Justificar',
    link: 'Enlace', clear: 'Quitar formato', url: 'Dirección del enlace', apply: 'Aplicar', unlink: 'Quitar enlace', editor: 'Texto',
};

/**
 * HTML written by the Quill editor this one replaced: its bullet lists are `<ol><li data-list="bullet">` (with a `.ql-ui` marker span
 * inside each item), which is neither a bullet list nor what the schema reads. Turns them into real `<ul>`/`<ol>` runs.
 */
export function legacyHtml(html) {
    if (typeof html !== 'string' || !/data-list|ql-ui/.test(html)) {
        return html;
    }
    const doc = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html');
    doc.querySelectorAll('.ql-ui').forEach((node) => node.remove());
    doc.querySelectorAll('ol, ul').forEach((list) => {
        if (!list.querySelector(':scope > li[data-list]')) {
            return;
        }
        const runs = [];
        let current = null;
        [...list.children].forEach((item) => {
            const tag = item.getAttribute('data-list') === 'ordered' ? 'ol' : 'ul';
            item.removeAttribute('data-list');
            if (current === null || current.tagName.toLowerCase() !== tag) {
                current = doc.createElement(tag);
                runs.push(current);
            }
            current.append(item);
        });
        list.replaceWith(...runs);
    });

    return doc.body.innerHTML;
}

/** The address of a link as typed: without scheme it is a web address (unless it is an e-mail). */
export function linkHref(value) {
    const text = value.trim();
    if (text === '' || /^(https?:|mailto:)/i.test(text)) {
        return text;
    }

    return (/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(text) ? 'mailto:' : 'https://') + text;
}

/**
 * Draws the editor (toolbar and writing area) inside `el`.
 *
 * @param {HTMLElement} el
 * @param {{html?: string, placeholder?: string, labels?: object, onChange?: (html: string) => void, onBlur?: () => void,
 *          extensions?: Array, htmlIn?: (html: string) => string, htmlOut?: (html: string) => string}} options
 *   `onChange` gets the HTML ('' when nothing is written) on every change the person makes; `onBlur` fires when the focus leaves it.
 *   `extensions` adds Tiptap extensions, and `htmlIn` / `htmlOut` translate what is stored to what they read and back (the model
 *   editor's references, see rich_text_refs.js): what is stored stays the sanitizer's HTML.
 * @returns {{editor: Editor, focus: () => void, insertText: (text: string) => void, html: () => string, destroy: () => void}}
 */
export function mountRichText(el, { html = '', placeholder = '', labels = {}, onChange = () => {}, onBlur = () => {}, extensions = [], htmlIn = (x) => x, htmlOut = (x) => x } = {}) {
    const L = { ...LABELS, ...labels };
    el.classList.add('rich-box');
    const toolbar = document.createElement('div');
    toolbar.className = 'rich-toolbar';
    toolbar.setAttribute('role', 'toolbar');
    const linkBar = document.createElement('div');
    linkBar.className = 'rich-linkbar';
    linkBar.hidden = true;
    const area = document.createElement('div');
    el.append(toolbar, linkBar, area);

    const editor = new Editor({
        element: area,
        extensions: [
            StarterKit.configure({
                heading: { levels: [2, 3] },
                code: false, codeBlock: false, horizontalRule: false, strike: false, trailingNode: false,
                link: { openOnClick: false, autolink: false, linkOnPaste: true, protocols: ['mailto'], HTMLAttributes: { target: null, rel: 'noopener noreferrer' } },
            }),
            TextAlign.configure({ types: ['heading', 'paragraph'] }),
            Placeholder.configure({ placeholder }),
            ...extensions,
        ],
        content: htmlIn(legacyHtml(html) || ''),
        editorProps: { attributes: { class: 'rich-content prose prose-sm max-w-none focus:outline-none', 'aria-label': L.editor, role: 'textbox', 'aria-multiline': 'true' } },
        onUpdate: ({ editor: e }) => onChange(e.isEmpty ? '' : htmlOut(e.getHTML())),
        onBlur: ({ event }) => {
            // Going to the toolbar or the link field is still being inside the editor.
            if (!(event?.relatedTarget instanceof Node && el.contains(event.relatedTarget))) {
                onBlur();
            }
        },
    });

    const buttons = [];
    const add = (text, title, run, active) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'rich-btn';
        b.innerHTML = text;
        b.title = title;
        b.setAttribute('aria-label', title);
        b.addEventListener('mousedown', (e) => e.preventDefault());
        b.addEventListener('click', run);
        toolbar.append(b);
        buttons.push({ b, active });

        return b;
    };
    const separator = () => {
        const s = document.createElement('span');
        s.className = 'rich-sep';
        s.setAttribute('aria-hidden', 'true');
        toolbar.append(s);
    };
    const chain = () => editor.chain().focus();

    // The two heading levels have no Heroicon: they are the text «T1» and «T2».
    add('<span class="rich-t">T1</span>', L.heading2, () => chain().toggleHeading({ level: 2 }).run(), () => editor.isActive('heading', { level: 2 }));
    add('<span class="rich-t">T2</span>', L.heading3, () => chain().toggleHeading({ level: 3 }).run(), () => editor.isActive('heading', { level: 3 }));
    separator();
    add(icon('bold'), L.bold, () => chain().toggleBold().run(), () => editor.isActive('bold'));
    add(icon('italic'), L.italic, () => chain().toggleItalic().run(), () => editor.isActive('italic'));
    add(icon('underline'), L.underline, () => chain().toggleUnderline().run(), () => editor.isActive('underline'));
    separator();
    add(icon('chat-bubble-bottom-center-text'), L.quote, () => chain().toggleBlockquote().run(), () => editor.isActive('blockquote'));
    add(icon('list-bullet'), L.bullets, () => chain().toggleBulletList().run(), () => editor.isActive('bulletList'));
    add(icon('numbered-list'), L.numbers, () => chain().toggleOrderedList().run(), () => editor.isActive('orderedList'));
    separator();
    for (const [value, name, label] of [['left', 'bars-3-bottom-left', L.left], ['center', 'bars-3', L.center], ['right', 'bars-3-bottom-right', L.right], ['justify', 'bars-4', L.justify]]) {
        add(icon(name), label, () => chain().setTextAlign(value).run(), () => editor.isActive({ textAlign: value }));
    }
    separator();

    // The address of a link is asked in a row of its own under the toolbar (the buttons keep the selection while it is typed).
    const url = document.createElement('input');
    url.type = 'text';
    url.className = 'rich-url';
    url.placeholder = 'https://…';
    url.setAttribute('aria-label', L.url);
    const closeLinkBar = () => {
        linkBar.hidden = true;
        editor.chain().focus().run();
    };
    const applyLink = () => {
        const href = linkHref(url.value);
        if (href === '') {
            editor.chain().focus().extendMarkRange('link').unsetLink().run();
        } else if (editor.state.selection.empty && !editor.isActive('link')) {
            editor.chain().focus().insertContent({ type: 'text', text: url.value.trim(), marks: [{ type: 'link', attrs: { href } }] }).run();
        } else {
            editor.chain().focus().extendMarkRange('link').setLink({ href }).run();
        }
        closeLinkBar();
    };
    const apply = document.createElement('button');
    apply.type = 'button';
    apply.className = 'rich-btn rich-btn-text';
    apply.textContent = L.apply;
    apply.addEventListener('click', applyLink);
    const unlink = document.createElement('button');
    unlink.type = 'button';
    unlink.className = 'rich-btn rich-btn-text';
    unlink.textContent = L.unlink;
    unlink.addEventListener('click', () => {
        editor.chain().focus().extendMarkRange('link').unsetLink().run();
        closeLinkBar();
    });
    url.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            applyLink();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            closeLinkBar();
        }
    });
    linkBar.append(url, apply, unlink);
    add(icon('link'), L.link, () => {
        url.value = editor.getAttributes('link').href || '';
        linkBar.hidden = !linkBar.hidden;
        if (!linkBar.hidden) {
            url.focus();
        }
    }, () => editor.isActive('link'));
    separator();
    add(icon('x-mark'), L.clear, () => chain().unsetAllMarks().clearNodes().unsetTextAlign().run(), () => false);

    const refresh = () => buttons.forEach(({ b, active }) => {
        const on = active();
        b.classList.toggle('is-active', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    editor.on('transaction', refresh);
    refresh();

    return {
        editor,
        focus: () => editor.commands.focus('end'),
        insertText: (text) => editor.chain().focus().insertContent(text).run(),
        html: () => (editor.isEmpty ? '' : htmlOut(editor.getHTML())),
        destroy: () => {
            editor.destroy();
            el.replaceChildren();
            el.classList.remove('rich-box');
        },
    };
}
