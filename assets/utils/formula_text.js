import { FUNCTIONS } from './form_expression.js';

/**
 * The text side of the formula and reference editor (formula_editor.js), without the browser: how a formula or a template splits into
 * text and references, how templates read, the accent-free search and the «¿Querías decir…?» of an unknown function. Tested in
 * tests/js/formula-text.test.mjs.
 */

/** Lower case and without accents: «Matemáticas» is found writing «matematicas». */
export function fold(text) {
    return String(text || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

/** Every word of the query somewhere in the item's label, hint, keywords or value. */
export function matches(item, query) {
    const words = fold(query).split(/\s+/).filter(Boolean);
    if (words.length === 0) { return true; }
    const haystack = fold([item.label, item.hint, item.keywords, item.value].join(' '));
    return words.every((w) => haystack.includes(w));
}

/** Some word of the label starts with what is written («apro» → «Aprobados (%)»): the data offered while typing a formula. */
export function wordStarts(label, query) {
    const q = fold(query);
    return fold(label).split(/[^a-z0-9]+/).some((w) => w !== '' && w.startsWith(q));
}

function distance(a, b) {
    const d = Array.from({ length: a.length + 1 }, (_, i) => [i, ...Array(b.length).fill(0)]);
    for (let j = 1; j <= b.length; j++) { d[0][j] = j; }
    for (let i = 1; i <= a.length; i++) {
        for (let j = 1; j <= b.length; j++) {
            d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
        }
    }
    return d[a.length][b.length];
}

/** Spanish (and Excel) names people try, and the function they mean. */
const SPANISH_NAMES = {
    suma: 'sum', sumar: 'sum', total: 'sum', media: 'avg', promedio: 'avg', average: 'avg', minimo: 'min', maximo: 'max',
    contar: 'count', cuenta: 'count', cuantos: 'count', contar_si: 'count_if', contarsi: 'count_if', countif: 'count_if',
    porcentaje: 'pct_if', si: 'if', redondear: 'round', redondeo: 'round', absoluto: 'abs', abs_: 'abs',
    vacio: 'empty', vacia: 'empty', esvacio: 'empty', isempty: 'empty', isblank: 'empty', esblanco: 'empty',
};

/** The function someone probably meant writing `name` (a Spanish name, or one or two letters off); null when none is close. */
export function suggestFunction(name) {
    const folded = fold(name);
    if (SPANISH_NAMES[folded]) { return SPANISH_NAMES[folded]; }
    let best = null;
    let score = 3;
    FUNCTIONS.forEach((f) => { const d = distance(folded, f); if (d < score) { score = d; best = f; } });
    return best;
}

// ── Templates: text with {{label|ref}} ──────────────────────────────────────────────────────────────────────────────────────────

export const TEMPLATE_REF = /\{\{([^{}|]*)\|([^{}]*)\}\}/g;

/** A template as it reads: `Seguimiento de «Elemento de la lista»` (for the outline and the lists of sections). */
export function templateText(text) {
    return String(text || '').replace(TEMPLATE_REF, (all, label) => '«' + label.trim() + '»');
}

/** The token of a reference in a template: `{{label|ref}}`, the label without the characters that would break it. */
export function templateToken(label, ref) {
    return '{{' + String(label || '').replace(/[{}|]/g, '') + '|' + ref + '}}';
}

/**
 * The pieces of a line: [{text}] and [{ref, label?}]. In a formula a reference is `[ref]` outside a string in quotes (an unclosed `[`
 * stays text); in a template it is `{{label|ref}}`.
 */
export function splitLine(text, mode) {
    const out = [];
    const pushText = (t) => { if (t) { out.push({ text: t }); } };
    const source = String(text || '');
    if (mode === 'template') {
        const re = new RegExp(TEMPLATE_REF.source, 'g');
        let last = 0;
        let m;
        while ((m = re.exec(source)) !== null) {
            pushText(source.slice(last, m.index));
            out.push({ ref: m[2].trim(), label: m[1].trim() });
            last = m.index + m[0].length;
        }
        pushText(source.slice(last));
        return out;
    }
    let buffer = '';
    let quoted = false;
    for (let i = 0; i < source.length; i++) {
        const c = source[i];
        if (c === '"') { quoted = !quoted; }
        if (c === '[' && !quoted) {
            const j = source.indexOf(']', i + 1);
            if (j >= 0) {
                pushText(buffer);
                buffer = '';
                out.push({ ref: source.slice(i + 1, j).trim() });
                i = j;
                continue;
            }
        }
        buffer += c;
    }
    pushText(buffer);
    return out;
}

/** The pieces back into text (the reverse of splitLine). `tokenLabel(ref)` gives the readable part of a template's reference. */
export function joinLine(pieces, mode, tokenLabel = () => null) {
    return pieces.map((p) => {
        if (p.text !== undefined) { return p.text; }
        return mode === 'template' ? templateToken(tokenLabel(p.ref) ?? p.label ?? '', p.ref) : '[' + p.ref + ']';
    }).join('');
}
