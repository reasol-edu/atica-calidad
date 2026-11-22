/**
 * The client side of App\Service\Form\FormWalker / FormDataNormalizer / FormResolver: the same rules for repeating sections,
 * conditions and references, so the form reacts at once while typing (a new row of the units table creates its section, a title
 * shows the unit's name). The server cleans and validates everything again when it saves; keep this file and those classes in step.
 *
 * Data is scoped: the root scope holds the blocks of the sections that do not repeat and each copy of a repeating section is a
 * scope of its own, in `scope.copies[sectionKey]`.
 */

import { parse, evaluate, round } from './form_expression.js';

export const MAX_COPIES = 100;
export const MAX_COUNTED_ROWS = 100;

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

/** How many rows a counted table has: what its number field says (never negative), up to the table's cap (the twin of the PHP one). */
export function countedCount(block, chain) {
    const n = Number(lookup(chain, 'fields', block.rowsField));
    const cap = Math.min(block.maxRows > 0 ? block.maxRows : MAX_COUNTED_ROWS, MAX_COUNTED_ROWS);

    return Math.min(cap, Math.max(0, Number.isFinite(n) ? Math.trunc(n) : 0));
}

/** The rows of a counted table: the first ones as they were typed, blank ones after them when the number grows, the last ones gone when it drops. */
function countedRows(block, rows, chain) {
    const out = rows.slice(0, countedCount(block, chain));
    while (out.length < countedCount(block, chain)) {
        out.push({ _key: newKey() });
    }

    return out;
}

/**
 * What depends on other tables of the chain: a row for each row of the table a double-entry table takes its rows from, and the
 * relation columns, which keep only rows that still exist (the twin of FormDataNormalizer::relatedData()).
 */
function related(blocks, scope, chain) {
    for (const block of blocks) {
        if (block.type !== 'table') {
            continue;
        }
        if (block.mode === 'matrix') {
            const byKey = new Map((scope.tables[block.key] || []).map((r) => [r._key, r]));
            scope.tables[block.key] = (lookup(chain, 'tables', block.rowTable) || []).map((src) => {
                const row = byKey.get(src._key) || {};
                row._key = src._key;
                return row;
            });
            continue;
        }
        if (block.mode === 'counted') {
            scope.tables[block.key] = countedRows(block, scope.tables[block.key] || [], chain);
        }
        for (const column of block.columns || []) {
            if (column.fieldType !== 'relation') {
                continue;
            }
            const valid = new Set((lookup(chain, 'tables', column.relTable) || []).map((r) => r._key));
            for (const row of scope.tables[block.key] || []) {
                const given = Array.isArray(row[column.key]) ? row[column.key] : [row[column.key]];
                const kept = given.filter((v) => valid.has(v));
                if (kept.length === 0) {
                    delete row[column.key];
                } else {
                    row[column.key] = column.multiple ? kept : kept[0];
                }
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
    related(blocks, scope, chain);

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
        blocks: (section.blocks || []).map((block) => ({ block, visible: visible && !block.foot && holds(block.visibleIf, chain) })),
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
        case 'calculated': {
            if (typeof value === 'boolean') {
                return value ? labels.yes : labels.no;
            }
            if (typeof value !== 'number') {
                return String(value);
            }
            const text = value.toFixed(Math.min(6, Math.max(0, spec.decimals ?? 2))).replace('.', ',');
            return spec.unit ? text + ' ' + spec.unit : text;
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

// ── Calculations (the twin of FormEvaluator / FormCalculator) ──────────────────────────────────────────────────────────

const trees = new Map();

function tree(source) {
    if (!trees.has(source)) {
        trees.set(source, parse(source).ast);
    }
    return trees.get(source);
}

function splitOnce(text, separator) {
    const at = text.indexOf(separator);
    return at < 0 ? [text, ''] : [text.slice(0, at), text.slice(at + 1)];
}

const columnOf = (rows, column) => rows.map((r) => (r[column] === undefined ? null : r[column]));

function copiesOf(chain, section) {
    for (const scope of chain) {
        if (scope.copies && Array.isArray(scope.copies[section])) {
            return scope.copies[section];
        }
    }
    return [];
}

/** The cells of a double-entry table, or (`.rows`, `.cols`) the sum of each row or column. */
function matrixValues(path, index, chain) {
    const [key, what] = splitOnce(path, '.');
    const table = index.tables.get(key);
    if (!table) {
        return [];
    }
    const columns = (lookup(chain, 'tables', table.colTable) || []).map((r) => r._key);
    const cells = [];
    const rowSums = [];
    const colSums = new Map(columns.map((c) => [c, 0]));
    for (const row of lookup(chain, 'tables', key) || []) {
        let sum = 0;
        for (const col of columns) {
            const value = row[col] === undefined ? null : row[col];
            cells.push(value);
            if (typeof value === 'number' || (typeof value === 'string' && value !== '' && Number.isFinite(Number(value)))) {
                sum += Number(value);
                colSums.set(col, colSums.get(col) + Number(value));
            }
        }
        rowSums.push(sum);
    }
    return what === 'rows' ? rowSums : what === 'cols' ? [...colSums.values()] : cells;
}

function refValue(ref, index, chain, row, origin) {
    const [kind, rest] = splitOnce(ref, ':');
    switch (kind) {
        case 'f': {
            const value = lookup(chain, 'fields', rest);
            return value === undefined ? null : value;
        }
        case 't': {
            const [table, column] = splitOnce(rest, '.');
            return columnOf(lookup(chain, 'tables', table) || [], column);
        }
        case 'r': return row && row[rest] !== undefined ? row[rest] : null;
        case 'o': return origin && origin.row[rest] !== undefined ? origin.row[rest] : null;
        case 'c': {
            const [section, tail] = splitOnce(rest, '.');
            const out = [];
            for (const copy of copiesOf(chain, section)) {
                if (tail.startsWith('f:')) {
                    const value = (copy.fields || {})[tail.slice(2)];
                    out.push(value === undefined ? null : value);
                } else if (tail.startsWith('t:')) {
                    const [table, column] = splitOnce(tail.slice(2), '.');
                    out.push(...columnOf((copy.tables || {})[table] || [], column));
                }
            }
            return out;
        }
        case 'm': return matrixValues(rest, index, chain);
        default: return null;
    }
}

/** The fields and tables of a definition by key. */
export function buildIndex(definition) {
    const fields = new Map();
    const tables = new Map();
    const visit = (sections) => {
        for (const s of sections || []) {
            for (const b of s.blocks || []) {
                (b.type === 'field' ? fields : b.type === 'table' ? tables : new Map()).set(b.key, b);
            }
            visit(s.children);
        }
    };
    visit(definition.sections);
    return { fields, tables };
}

function calcValue(formula, spec, index, chain, row, origin) {
    const ast = tree(formula || '');
    if (!ast) {
        return null;
    }
    const result = evaluate(ast, (ref) => refValue(ref, index, chain, row, origin));
    if (typeof result === 'number' && Number.isFinite(result)) {
        return round(result, Math.min(6, Math.max(0, spec.decimals ?? 2)));
    }
    return typeof result === 'string' || typeof result === 'boolean' ? result : null;
}

function setCalc(map, key, value) {
    if (value === null) {
        return key in map ? delete map[key] : false;
    }
    if (map[key] !== value) {
        map[key] = value;
        return true;
    }
    return false;
}

/** Fills the calculated fields and columns in place, in a few passes (a formula can read another calculated value). */
export function calculate(definition, data, index = buildIndex(definition)) {
    const hasFormulas = [...index.fields.values()].some((f) => f.fieldType === 'calculated')
        || [...index.tables.values()].some((t) => (t.columns || []).some((c) => c.fieldType === 'calculated'));
    if (!hasFormulas) {
        return;
    }
    for (let pass = 0; pass < 4; pass++) {
        let changed = false;
        const visit = (instances) => {
            for (const inst of instances) {
                if (inst.adder) {
                    continue;
                }
                for (const { block } of inst.blocks) {
                    if (block.type === 'field' && block.fieldType === 'calculated') {
                        changed = setCalc(inst.scope.fields, block.key, calcValue(block.formula, block, index, inst.chain, null, inst.origin)) || changed;
                    }
                    if (block.type === 'table') {
                        for (const column of block.columns || []) {
                            if (column.fieldType === 'calculated') {
                                for (const row of inst.scope.tables[block.key] || []) {
                                    changed = setCalc(row, column.key, calcValue(column.formula, column, index, inst.chain, row, inst.origin)) || changed;
                                }
                            }
                        }
                    }
                }
                visit(inst.children);
            }
        };
        visit(walk(definition, data));
        if (!changed) {
            break;
        }
    }
}

/** A number the way a column shows it (the twin of FormResolver::number()). */
function numberText(value, column) {
    const text = column.fieldType === 'calculated'
        ? value.toFixed(Math.min(6, Math.max(0, column.decimals ?? 2))).replace('.', ',')
        : String(round(value, 6)).replace('.', ',');
    return column.unit ? text + ' ' + column.unit : text;
}

/** The foot of a table: for each column that asks for it, its sum, average, minimum, maximum or count; null when none does. */
export function totals(columns, rows, fields = {}) {
    if (!columns.some((c) => c.total)) {
        return null;
    }
    return columns.map((column) => {
        if (!column.total) {
            return '';
        }
        // A custom total is the value of its field (calculated like any other).
        if (column.total === 'custom') {
            const value = fields[column.totalKey];
            return value !== undefined && value !== null && value !== '' && Number.isFinite(Number(value)) ? numberText(Number(value), column) : '';
        }
        const values = rows.map((r) => r[column.key]).filter((v) => v !== undefined && v !== null && v !== '');
        const numbers = values.filter((v) => Number.isFinite(Number(v))).map(Number);
        let result;
        switch (column.total) {
            case 'count': result = values.length; break;
            case 'sum': result = numbers.reduce((a, b) => a + b, 0); break;
            case 'avg': result = numbers.length ? numbers.reduce((a, b) => a + b, 0) / numbers.length : null; break;
            case 'min': result = numbers.length ? Math.min(...numbers) : null; break;
            default: result = numbers.length ? Math.max(...numbers) : null;
        }
        if (result === null) {
            return '';
        }
        return column.total === 'count' ? String(result) : numberText(result, column);
    });
}

/** The rows of a table, looked up from the innermost scope out. */
export function rowsOf(chain, table) {
    return lookup(chain, 'tables', table) || [];
}
