/**
 * The client side of App\Service\Form\FormWalker / FormDataNormalizer / FormResolver: the same rules for repeating sections,
 * conditions and references, so the form reacts at once while typing (a new row of the units table creates its section, a title
 * shows the unit's name). The server cleans and validates everything again when it saves; keep this file and those classes in step.
 *
 * Data is scoped: the root scope holds the blocks of the sections that do not repeat and each copy of a repeating section is a
 * scope of its own, in `scope.copies[sectionKey]`.
 */

export const MAX_COPIES = 100;

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

export function newKey() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
        return window.crypto.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
}

export function asMap(value) {
    return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
}

/** The blocks that live in a scope, and the repeating sections that open a new one (a repeating section is a boundary). */
export function collect(sections) {
    const blocks = [];
    const repeating = [];
    for (const section of sections || []) {
        if (section.repeat) {
            repeating.push(section);
            continue;
        }
        blocks.push(...(section.blocks || []));
        const inner = collect(section.children);
        blocks.push(...inner.blocks);
        repeating.push(...inner.repeating);
    }
    return { blocks, repeating };
}

function lookup(chain, group, key) {
    for (const scope of chain) {
        const map = asMap(scope[group]);
        if (Object.prototype.hasOwnProperty.call(map, key)) {
            return map[key];
        }
    }
    return undefined;
}

/** Makes sure a scope has what its blocks need (the maps, the rows a fixed table owns, the starting texts) — never overwrites. */
function prepare(blocks, scope) {
    scope.fields = asMap(scope.fields);
    scope.texts = asMap(scope.texts);
    scope.tables = asMap(scope.tables);
    for (const block of blocks) {
        if (block.type === 'text' && block.mode === 'starter' && !scope.texts[block.key]) {
            scope.texts[block.key] = { html: block.html || '', modified: false };
        }
        if (block.type !== 'table') {
            continue;
        }
        const fresh = !Array.isArray(scope.tables[block.key]);
        if (fresh) {
            scope.tables[block.key] = [];
        }
        if (block.mode === 'fixed') {
            const byKey = new Map(scope.tables[block.key].map((r) => [r._key, r]));
            scope.tables[block.key] = (block.rows || []).map((r) => byKey.get(r.key) || { _key: r.key });
        } else if (fresh) {
            for (let i = 0; i < (block.minRows || 0); i++) {
                scope.tables[block.key].push({ _key: newKey() });
            }
        }
    }
}

/** Brings the copies of every repeating section in line with what decides them (rows, a number, the user's own list). */
export function sync(sections, scope, outer = []) {
    const { blocks, repeating } = collect(sections);
    prepare(blocks, scope);
    scope.copies = asMap(scope.copies);
    const chain = [scope, ...outer];

    for (const section of repeating) {
        const raw = scope.copies[section.key];
        const list = Array.isArray(raw) ? raw : [];
        const inner = { ...section, repeat: null };
        const repeat = section.repeat;
        const out = [];

        if (repeat.mode === 'table') {
            const rows = lookup(chain, 'tables', repeat.table) || [];
            const byKey = new Map(list.map((c) => [c._key, c]));
            for (const row of rows) {
                const copy = byKey.get(row._key) || {};
                copy._key = row._key;
                copy._source = row._key;
                sync([inner], copy, chain);
                out.push(copy);
            }
        } else {
            let total;
            if (repeat.mode === 'field') {
                const n = Number(lookup(chain, 'fields', repeat.field));
                total = Math.min(MAX_COPIES, Math.max(0, Number.isFinite(n) ? Math.trunc(n) : 0));
            } else {
                const max = Math.min(MAX_COPIES, repeat.max || MAX_COPIES);
                total = Array.isArray(raw) ? Math.min(max, list.length) : Math.min(max, repeat.min || 0);
            }
            for (let i = 0; i < total; i++) {
                const copy = list[i] || {};
                if (!UUID.test(copy._key || '') || out.some((c) => c._key === copy._key)) {
                    copy._key = newKey();
                }
                sync([inner], copy, chain);
                out.push(copy);
            }
        }
        scope.copies[section.key] = out;
    }
}

/** A condition { field, op, value } over the value of a field, looked up from the innermost scope out; a broken one holds. */
export function holds(condition, chain) {
    if (!condition || !condition.field || !['filled', 'empty', 'true', 'false', 'eq', 'neq'].includes(condition.op)) {
        return true;
    }
    const value = lookup(chain, 'fields', condition.field);
    const filled = value !== undefined && value !== null && value !== '' && !(Array.isArray(value) && value.length === 0);
    const same = Array.isArray(value) ? value.includes(condition.value) : value !== undefined && value !== null && String(value) === String(condition.value);
    switch (condition.op) {
        case 'filled': return filled;
        case 'empty': return !filled;
        case 'true': return value === true;
        case 'false': return value === false;
        case 'eq': return same;
        default: return !same;
    }
}

/**
 * The sections once per copy, with their scope and what they show: { section, path, own, copy, origin, scope, chain, visible,
 * blocks: [{ block, visible }], children }. A repeating section that the user fills by hand also yields an `adder` entry after its
 * copies (also when there are none), so the page has a place for the "add" button.
 */
export function walk(definition, data) {
    const tables = new Map();
    const collectTables = (sections) => {
        for (const s of sections || []) {
            for (const b of s.blocks || []) {
                if (b.type === 'table') {
                    tables.set(b.key, b);
                }
            }
            collectTables(s.children);
        }
    };
    collectTables(definition.sections);

    const sections = (list, scope, chain, path, parentVisible, inheritedCopy, inheritedOrigin) => {
        const out = [];
        for (const section of list || []) {
            const visible = parentVisible && holds(section.visibleIf, chain);
            if (!section.repeat) {
                out.push(instance(section, path + section.key, false, inheritedCopy, inheritedOrigin, scope, chain, visible));
                continue;
            }
            const copies = Array.isArray(scope.copies && scope.copies[section.key]) ? scope.copies[section.key] : [];
            const table = section.repeat.mode === 'table' ? tables.get(section.repeat.table) : null;
            copies.forEach((copy, index) => {
                let origin = null;
                if (table) {
                    const row = (lookup(chain, 'tables', table.key) || []).find((r) => r._key === copy._source);
                    origin = row ? { table, row } : null;
                }
                const info = { index: index + 1, total: copies.length, key: copy._key, source: copy._source || null };
                out.push(instance(section, path + section.key + ':' + copy._key, true, info, origin, copy, [copy, ...chain], visible));
            });
            if (section.repeat.mode === 'manual') {
                out.push({ adder: true, section, scope, chain, visible, count: copies.length, path: path + section.key });
            }
        }
        return out;
    };

    const instance = (section, path, own, copy, origin, scope, chain, visible) => ({
        section, path, own, copy, origin, scope, chain, visible,
        blocks: (section.blocks || []).map((block) => ({ block, visible: visible && holds(block.visibleIf, chain) })),
        children: sections(section.children, scope, chain, path + '/', visible, copy, origin),
    });

    return sections(definition.sections, data, [data], '', true, null, null);
}

/** Section numbers by instance path; unnumbered sections (and the copies a model chooses not to number) have none, nor their subtree. */
export function numbers(instances, prefix = '', numbering = true, map = new Map()) {
    let counter = 0;
    for (const inst of instances) {
        if (inst.adder || !inst.visible) {
            continue;
        }
        const numbered = numbering && inst.section.numbered !== false && (!inst.own || inst.section.numberCopies !== false);
        let number = '';
        if (numbered) {
            counter += 1;
            number = prefix === '' ? String(counter) : prefix + '.' + counter;
            map.set(inst.path, number);
        }
        numbers(inst.children, number, numbered, map);
    }
    return map;
}

/** The text of a value as people read it (the same as FormResolver::format()). */
export function format(spec, value, labels) {
    if (value === undefined || value === null || value === '' || (Array.isArray(value) && value.length === 0)) {
        return '';
    }
    switch (spec.fieldType) {
        case 'number': {
            const text = String(value).replace('.', ',');
            return spec.unit ? text + ' ' + spec.unit : text;
        }
        case 'date': {
            const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value));
            return m ? m[3] + '/' + m[2] + '/' + m[1] : '';
        }
        case 'bool':
            return value === true ? labels.yes : labels.no;
        case 'choice':
        case 'multichoice': {
            const keys = Array.isArray(value) ? value : [value];
            return (spec.options || []).filter((o) => keys.includes(o.key)).map((o) => o.label).join(', ');
        }
        default:
            return String(value);
    }
}

/** `{{ … }}` replaced (what follows the last `|` counts); `specs` is a Map of every field by key; `context` what `ctx:` reads. */
export function references(text, inst, specs, context, labels) {
    if (!text || !text.includes('{{')) {
        return text || '';
    }
    return text.replace(/\{\{\s*([^{}]*?)\s*\}\}/g, (all, body) => {
        const ref = body.split('|').pop().trim();
        if (ref === 'copy.number') { return inst.copy ? String(inst.copy.index) : ''; }
        if (ref === 'copy.total') { return inst.copy ? String(inst.copy.total) : ''; }
        if (ref.startsWith('ctx:')) { return context[ref.slice(4)] || ''; }
        if (ref.startsWith('row:')) {
            const column = ref.slice(4);
            const spec = inst.origin && (inst.origin.table.columns || []).find((c) => c.key === column);
            return spec ? format(spec, inst.origin.row[column], labels) : '';
        }
        if (ref.startsWith('f:')) {
            const key = ref.slice(2);
            const spec = specs.get(key);
            const value = lookup(inst.chain, 'fields', key);
            return spec && value !== undefined ? format(spec, value, labels) : '';
        }
        return '';
    });
}

/** Every field block of the definition by key. */
export function fieldSpecs(definition) {
    const map = new Map();
    const visit = (sections) => {
        for (const s of sections || []) {
            for (const b of s.blocks || []) {
                if (b.type === 'field') {
                    map.set(b.key, b);
                }
            }
            visit(s.children);
        }
    };
    visit(definition.sections);
    return map;
}

/** How much of what is required is already answered, over the shown part only: { done, total }. */
export function progress(instances) {
    let done = 0;
    let total = 0;
    const filled = (v) => v !== undefined && v !== null && v !== '' && !(Array.isArray(v) && v.length === 0);
    const visit = (list) => {
        for (const inst of list) {
            if (inst.adder || !inst.visible) {
                continue;
            }
            for (const { block, visible } of inst.blocks) {
                if (!visible) {
                    continue;
                }
                if (block.type === 'field' && block.required) {
                    total += 1;
                    done += filled(inst.scope.fields[block.key]) ? 1 : 0;
                } else if (block.type === 'text' && block.required) {
                    total += 1;
                    done += inst.scope.texts[block.key] && (inst.scope.texts[block.key].html || '').replace(/<[^>]*>/g, '').trim() !== '' ? 1 : 0;
                } else if (block.type === 'table') {
                    for (const row of inst.scope.tables[block.key] || []) {
                        for (const column of block.columns || []) {
                            if (column.required) {
                                total += 1;
                                done += filled(row[column.key]) ? 1 : 0;
                            }
                        }
                    }
                }
            }
            visit(inst.children);
        }
    };
    visit(instances);
    return { done, total };
}
