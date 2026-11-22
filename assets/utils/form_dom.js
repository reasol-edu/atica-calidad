/**
 * Small helpers shared by the document model editor and the form filler: building DOM without innerHTML (every text goes in as
 * text), unique keys, and the hierarchical numbering of sections.
 */

/** h('div', { class: 'x', onclick: fn, text: 'hi', 'data-k': 1 }, [children…]) */
export function h(tag, props = {}, children = []) {
    const el = document.createElement(tag);
    for (const [name, value] of Object.entries(props)) {
        if (value === undefined || value === null || value === false) {
            continue;
        }
        if (name === 'text') {
            el.textContent = value;
        } else if (name.startsWith('on') && typeof value === 'function') {
            el.addEventListener(name.slice(2), value);
        } else if (name === 'value' || name === 'checked' || name === 'selected' || name === 'disabled') {
            el[name] = value;
        } else if (value === true) {
            el.setAttribute(name, '');
        } else {
            el.setAttribute(name, String(value));
        }
    }
    for (const child of Array.isArray(children) ? children : [children]) {
        if (child === null || child === undefined || child === false) {
            continue;
        }
        el.append(child instanceof Node ? child : document.createTextNode(String(child)));
    }
    return el;
}

/** A UUID (the keys of the definition are UUIDs, see App\Service\Form\FormDefinition). */
export function uid() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
        return window.crypto.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
}

/**
 * Map of section key → number ("1", "1.2"); a section that is not numbered has none and nor does its subtree — the same rule as
 * App\Service\Form\FormResolver.
 */
export function sectionNumbers(sections, prefix = '', numbering = true, map = new Map()) {
    let counter = 0;
    for (const section of sections) {
        const numbered = numbering && section.numbered !== false;
        let number = '';
        if (numbered) {
            counter += 1;
            number = prefix === '' ? String(counter) : prefix + '.' + counter;
            map.set(section.key, number);
        }
        sectionNumbers(section.children || [], number, numbered, map);
    }
    return map;
}
