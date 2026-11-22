/**
 * The expression language of the models (calculated fields and columns, consistency rules), the JavaScript twin of
 * App\Service\Form\FormExpression: same grammar, same results, run against the same cases (tests/Fixtures/form-expressions.json,
 * tests/js/form-expression.test.mjs). Change one and change the other. See the PHP class for the grammar.
 */

export const FUNCTIONS = ['sum', 'avg', 'min', 'max', 'count', 'count_if', 'pct_if', 'if', 'round', 'abs', 'empty'];

function tokens(s) {
    const out = [];
    let i = 0;
    const n = s.length;
    while (i < n) {
        const c = s[i];
        if (/\s/.test(c)) { i++; continue; }
        if (/\d/.test(c) || (c === '.' && i + 1 < n && /\d/.test(s[i + 1]))) {
            let j = i;
            while (j < n && /[\d.]/.test(s[j])) { j++; }
            out.push({ t: 'num', v: parseFloat(s.slice(i, j)) });
            i = j;
            continue;
        }
        if (c === '"') {
            const j = s.indexOf('"', i + 1);
            if (j < 0) { throw new Error('string'); }
            out.push({ t: 'str', v: s.slice(i + 1, j) });
            i = j + 1;
            continue;
        }
        if (c === '[') {
            const j = s.indexOf(']', i + 1);
            if (j < 0) { throw new Error('reference'); }
            out.push({ t: 'ref', v: s.slice(i + 1, j).trim() });
            i = j + 1;
            continue;
        }
        if (/[A-Za-z_]/.test(c)) {
            let j = i;
            while (j < n && /[A-Za-z0-9_]/.test(s[j])) { j++; }
            out.push({ t: 'name', v: s.slice(i, j).toLowerCase() });
            i = j;
            continue;
        }
        const two = s.slice(i, i + 2);
        if (['&&', '||', '!=', '<=', '>='].includes(two)) { out.push({ t: 'op', v: two }); i += 2; continue; }
        if ('+-*/()=<>!;'.includes(c)) { out.push({ t: 'op', v: c }); i++; continue; }
        throw new Error('character');
    }
    return out;
}

/** { ast, error }: the tree of an expression, or why it cannot be read. */
export function parse(source) {
    try {
        const toks = tokens(source);
        let pos = 0;
        const isOp = (op) => toks[pos] && toks[pos].t === 'op' && toks[pos].v === op;
        const parseOr = () => {
            let left = parseAnd();
            while (isOp('||')) { pos++; left = { k: 'or', a: left, b: parseAnd() }; }
            return left;
        };
        const parseAnd = () => {
            let left = parseCmp();
            while (isOp('&&')) { pos++; left = { k: 'and', a: left, b: parseCmp() }; }
            return left;
        };
        const parseCmp = () => {
            const left = parseAdd();
            for (const op of ['=', '!=', '<=', '>=', '<', '>']) {
                if (isOp(op)) { pos++; return { k: 'cmp', op, a: left, b: parseAdd() }; }
            }
            return left;
        };
        const parseAdd = () => {
            let left = parseMul();
            while (isOp('+') || isOp('-')) { const op = toks[pos].v; pos++; left = { k: 'bin', op, a: left, b: parseMul() }; }
            return left;
        };
        const parseMul = () => {
            let left = parseUnary();
            while (isOp('*') || isOp('/')) { const op = toks[pos].v; pos++; left = { k: 'bin', op, a: left, b: parseUnary() }; }
            return left;
        };
        const parseUnary = () => {
            if (isOp('-')) { pos++; return { k: 'neg', a: parseUnary() }; }
            if (isOp('!')) { pos++; return { k: 'not', a: parseUnary() }; }
            return parsePrimary();
        };
        const parsePrimary = () => {
            const tok = toks[pos];
            if (!tok) { throw new Error('end'); }
            pos++;
            if (tok.t === 'num') { return { k: 'num', v: tok.v }; }
            if (tok.t === 'str') { return { k: 'str', v: tok.v }; }
            if (tok.t === 'ref') { return { k: 'ref', v: tok.v }; }
            if (tok.t === 'name') {
                if (tok.v === 'true' || tok.v === 'false') { return { k: 'bool', v: tok.v === 'true' }; }
                if (!FUNCTIONS.includes(tok.v) || !isOp('(')) { throw new Error('function'); }
                pos++;
                const args = [];
                if (!isOp(')')) {
                    args.push(parseOr());
                    while (isOp(';')) { pos++; args.push(parseOr()); }
                }
                if (!isOp(')')) { throw new Error('parenthesis'); }
                pos++;
                return { k: 'call', name: tok.v, args };
            }
            if (tok.v === '(') {
                const inner = parseOr();
                if (!isOp(')')) { throw new Error('parenthesis'); }
                pos++;
                return inner;
            }
            throw new Error('unexpected');
        };
        const ast = parseOr();
        if (pos < toks.length) { throw new Error('unexpected'); }
        return { ast, error: null };
    } catch (e) {
        return { ast: null, error: e.message };
    }
}

/** The text inside the brackets of every reference of a tree. */
export function references(ast, out = []) {
    if (!ast) { return out; }
    if (ast.k === 'ref') { out.push(ast.v); }
    references(ast.a, out);
    references(ast.b, out);
    (ast.args || []).forEach((a) => references(a, out));
    return out;
}

const NUMERIC = /^\s*[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?\s*$/;

function num(v) {
    if (typeof v === 'boolean' || Array.isArray(v) || v === null || v === undefined || v === '') { return null; }
    if (typeof v === 'number') { return Number.isFinite(v) ? v : null; }
    return typeof v === 'string' && NUMERIC.test(v) ? Number(v) : null;
}

function truthy(v) {
    if (Array.isArray(v)) { return v.length > 0; }
    return v !== null && v !== undefined && v !== false && v !== '' && v !== 0;
}

function compare(op, a, b) {
    if (a === null || a === undefined || b === null || b === undefined || Array.isArray(a) || Array.isArray(b)) { return false; }
    const x = num(a);
    const y = num(b);
    let cmp;
    if (x !== null && y !== null) {
        cmp = x < y ? -1 : x > y ? 1 : 0;
    } else if (typeof a === 'boolean' || typeof b === 'boolean') {
        if (op !== '=' && op !== '!=') { return false; }
        cmp = a === b ? 0 : 1;
    } else {
        const sa = String(a);
        const sb = String(b);
        cmp = sa < sb ? -1 : sa > sb ? 1 : 0;
    }
    switch (op) {
        case '=': return cmp === 0;
        case '!=': return cmp !== 0;
        case '<': return cmp < 0;
        case '<=': return cmp <= 0;
        case '>': return cmp > 0;
        default: return cmp >= 0;
    }
}

const items = (v) => (Array.isArray(v) ? v : v === null || v === undefined ? [] : [v]);

/** PHP's round(): halves go away from zero. */
export function round(x, decimals) {
    const f = 10 ** Math.max(0, Math.min(8, Math.trunc(decimals)));
    return (Math.sign(x) * Math.round(Math.abs(x) * f + 1e-9)) / f;
}

/** The value of a tree; `resolve(text)` says what a reference is worth (a scalar, a list or null). */
export function evaluate(ast, resolve) {
    const ev = (n) => {
        switch (n.k) {
            case 'num': case 'str': case 'bool': return n.v;
            case 'ref': { const v = resolve(n.v); return v === undefined ? null : v; }
            case 'neg': { const a = num(ev(n.a)); return a === null ? null : -a; }
            case 'not': return !truthy(ev(n.a));
            case 'and': return truthy(ev(n.a)) && truthy(ev(n.b));
            case 'or': return truthy(ev(n.a)) || truthy(ev(n.b));
            case 'bin': {
                const a = num(ev(n.a));
                const b = num(ev(n.b));
                if (a === null || b === null) { return null; }
                if (n.op === '+') { return a + b; }
                if (n.op === '-') { return a - b; }
                if (n.op === '*') { return a * b; }
                return b === 0 ? null : a / b;
            }
            case 'cmp': return compare(n.op, ev(n.a), ev(n.b));
            case 'call': return call(n.name, n.args);
            default: return null;
        }
    };
    const call = (name, args) => {
        const arg = (i) => (args[i] ? ev(args[i]) : null);
        switch (name) {
            case 'if': return truthy(arg(0)) ? arg(1) : arg(2);
            case 'round': { const x = num(arg(0)); return x === null ? null : round(x, num(arg(1)) ?? 0); }
            case 'abs': { const x = num(arg(0)); return x === null ? null : Math.abs(x); }
            case 'empty': { const v = arg(0); return v === null || v === '' || (Array.isArray(v) && v.length === 0); }
            case 'count_if': case 'pct_if': {
                const list = items(arg(0));
                const op = String(arg(1));
                const wanted = arg(2);
                if (list.length === 0) { return name === 'count_if' ? 0 : null; }
                const hits = list.filter((item) => compare(op, item, wanted)).length;
                return name === 'count_if' ? hits : (hits / list.length) * 100;
            }
            default: break;
        }
        const values = args.flatMap((_, i) => items(arg(i))).filter((v) => v !== null && v !== undefined && v !== '');
        if (name === 'count') { return values.length; }
        const numbers = values.map(num).filter((v) => v !== null);
        if (numbers.length === 0) { return name === 'sum' ? 0 : null; }
        if (name === 'sum') { return numbers.reduce((a, b) => a + b, 0); }
        if (name === 'avg') { return numbers.reduce((a, b) => a + b, 0) / numbers.length; }
        return name === 'min' ? Math.min(...numbers) : Math.max(...numbers);
    };
    return ev(ast);
}
