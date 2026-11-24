/**
 * The expression language of the models (calculated fields and columns, consistency rules), the JavaScript twin of
 * App\Service\Form\FormExpression: same grammar, same results, run against the same cases (tests/Fixtures/form-expressions.json,
 * tests/js/form-expression.test.mjs). Change one and change the other. See the PHP class for the grammar.
 */

export const FUNCTIONS = ['sum', 'avg', 'min', 'max', 'count', 'count_if', 'pct_if', 'if', 'round', 'abs', 'empty'];

/**
 * Why an expression cannot be read, and where: `code` (string, reference, character, end, function, parenthesis, unexpected — the
 * messages `parse()` has always returned), the characters it points at (`start`–`end`, an empty range at the end of the text when
 * something is missing there) and what the editor needs to explain it (`data`: the name, the character, the token before…).
 */
class ParseError extends Error {
    constructor(code, start, end, data = {}) {
        super(code);
        this.at = { start, end };
        this.data = data;
    }
}

/**
 * The tokens of an expression, each with where it is (`s`–`e`, offsets in the text). Strict (`lenient` false, what `parse()` uses): it
 * stops at the first thing it cannot read. Lenient (what the formula editor colours with): that thing becomes a `bad` token, an
 * unclosed string or reference runs to the end with `open: true`, and it goes on.
 */
export function tokens(s, lenient = false) {
    const out = [];
    let i = 0;
    const n = s.length;
    while (i < n) {
        const c = s[i];
        if (/\s/.test(c)) { i++; continue; }
        if (/\d/.test(c) || (c === '.' && i + 1 < n && /\d/.test(s[i + 1]))) {
            let j = i;
            while (j < n && /[\d.]/.test(s[j])) { j++; }
            out.push({ t: 'num', v: parseFloat(s.slice(i, j)), s: i, e: j });
            i = j;
            continue;
        }
        if (c === '"' || c === '[') {
            const close = c === '"' ? '"' : ']';
            const j = s.indexOf(close, i + 1);
            if (j < 0) {
                if (!lenient) { throw new ParseError(c === '"' ? 'string' : 'reference', i, n); }
                out.push({ t: c === '"' ? 'str' : 'ref', v: s.slice(i + 1), s: i, e: n, open: true });
                break;
            }
            const inner = s.slice(i + 1, j);
            out.push({ t: c === '"' ? 'str' : 'ref', v: c === '"' ? inner : inner.trim(), s: i, e: j + 1 });
            i = j + 1;
            continue;
        }
        if (/[A-Za-z_]/.test(c)) {
            let j = i;
            while (j < n && /[A-Za-z0-9_]/.test(s[j])) { j++; }
            out.push({ t: 'name', v: s.slice(i, j).toLowerCase(), s: i, e: j });
            i = j;
            continue;
        }
        const two = s.slice(i, i + 2);
        if (['&&', '||', '!=', '<=', '>='].includes(two)) { out.push({ t: 'op', v: two, s: i, e: i + 2 }); i += 2; continue; }
        if ('+-*/()=<>!;'.includes(c)) { out.push({ t: 'op', v: c, s: i, e: i + 1 }); i++; continue; }
        if (!lenient) { throw new ParseError('character', i, i + 1, { char: c }); }
        out.push({ t: 'bad', v: c, s: i, e: i + 1 });
        i++;
    }
    return out;
}

/**
 * { ast, error, at, data }: the tree of an expression, or why it cannot be read (`error`, the code; `at` and `data`, see ParseError).
 * Calls in the tree carry where their name is (`s`–`e`), for the editor.
 */
export function parse(source) {
    let toks = [];
    try {
        toks = tokens(source);
        let pos = 0;
        const end = () => {
            const prev = toks[toks.length - 1];
            return new ParseError('end', source.length, source.length, { after: prev ? source.slice(prev.s, prev.e) : '' });
        };
        // What stands where a ")" was due: the token that should not be there, or nothing left (the "(" is then the one unclosed).
        const closeAt = (open) => (toks[pos]
            ? new ParseError('unexpected', toks[pos].s, toks[pos].e, { token: source.slice(toks[pos].s, toks[pos].e) })
            : new ParseError('parenthesis', open.s, open.e));
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
            if (!tok) { throw end(); }
            pos++;
            if (tok.t === 'num') { return { k: 'num', v: tok.v }; }
            if (tok.t === 'str') { return { k: 'str', v: tok.v }; }
            if (tok.t === 'ref') { return { k: 'ref', v: tok.v, s: tok.s, e: tok.e }; }
            if (tok.t === 'name') {
                if (tok.v === 'true' || tok.v === 'false') { return { k: 'bool', v: tok.v === 'true' }; }
                if (!FUNCTIONS.includes(tok.v)) { throw new ParseError('function', tok.s, tok.e, { name: source.slice(tok.s, tok.e) }); }
                if (!isOp('(')) { throw new ParseError('function', tok.s, tok.e, { name: tok.v, call: true }); }
                const open = toks[pos];
                pos++;
                const args = [];
                if (!isOp(')')) {
                    args.push(parseOr());
                    while (isOp(';')) { pos++; args.push(parseOr()); }
                }
                if (!isOp(')')) { throw closeAt(open); }
                pos++;
                return { k: 'call', name: tok.v, args, s: tok.s, e: tok.e };
            }
            if (tok.v === '(') {
                const inner = parseOr();
                if (!isOp(')')) { throw closeAt(tok); }
                pos++;
                return inner;
            }
            throw new ParseError('unexpected', tok.s, tok.e, { token: source.slice(tok.s, tok.e) });
        };
        const ast = parseOr();
        if (pos < toks.length) {
            const tok = toks[pos];
            throw new ParseError('unexpected', tok.s, tok.e, { token: source.slice(tok.s, tok.e) });
        }
        return { ast, error: null, at: null, data: {} };
    } catch (e) {
        if (!(e instanceof ParseError)) { throw e; }
        return { ast: null, error: e.message, at: e.at, data: e.data };
    }
}

/** How many values each function takes: [at least, at most] (the language reads whatever it is given; the editor warns). */
export const ARITY = {
    sum: [1, Infinity], avg: [1, Infinity], min: [1, Infinity], max: [1, Infinity], count: [1, Infinity],
    count_if: [3, 3], pct_if: [3, 3], if: [2, 3], round: [1, 2], abs: [1, 1], empty: [1, 1],
};

/** The calls of a tree that take too few or too many values: [{name, s, e, given, min, max}]. */
export function arityProblems(ast, out = []) {
    if (!ast) { return out; }
    if (ast.k === 'call' && ARITY[ast.name]) {
        const [min, max] = ARITY[ast.name];
        if (ast.args.length < min || ast.args.length > max) {
            out.push({ name: ast.name, s: ast.s, e: ast.e, given: ast.args.length, min, max });
        }
    }
    arityProblems(ast.a, out);
    arityProblems(ast.b, out);
    (ast.args || []).forEach((a) => arityProblems(a, out));
    return out;
}

/**
 * Where the cursor is inside calls: the innermost function whose parentheses hold `offset`, and which of its values (0-based) the
 * cursor is in. Null outside any function. From the lenient tokens, so it answers while the formula is half written.
 */
export function callAt(source, offset) {
    const stack = [];
    const toks = tokens(source, true);
    for (let i = 0; i < toks.length; i++) {
        const tok = toks[i];
        if (tok.s >= offset) { break; }
        if (tok.t === 'op' && tok.v === '(') {
            const prev = toks[i - 1];
            stack.push({ name: prev && prev.t === 'name' && FUNCTIONS.includes(prev.v) ? prev.v : null, arg: 0, s: tok.s });
        } else if (tok.t === 'op' && tok.v === ')') {
            stack.pop();
        } else if (tok.t === 'op' && tok.v === ';' && stack.length) {
            stack[stack.length - 1].arg++;
        }
    }
    for (let i = stack.length - 1; i >= 0; i--) {
        if (stack[i].name) { return stack[i]; }
    }
    return null;
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

/**
 * The functions the «summary of the row» assistant of the editor offers: one result out of several cells of the same row. `pct` is
 * not a function of the language: it is the percentage of one column over another, `[r:a] / [r:b] * 100` (empty when B is 0 or empty,
 * as any division by zero).
 */
export const ROW_SUMMARY = ['sum', 'avg', 'min', 'max', 'count', 'pct'];

/** The formula of a summary of the row: `sum([r:a];[r:b])`, `[r:a] / [r:b] * 100`. Empty when the columns it needs are not chosen. */
export function rowSummary(fn, keys) {
    if (!ROW_SUMMARY.includes(fn) || keys.length === 0) {
        return '';
    }
    if (fn === 'pct') {
        return keys.length === 2 && keys[0] && keys[1] ? `[r:${keys[0]}] / [r:${keys[1]}] * 100` : '';
    }

    return `${fn}(${keys.map((k) => `[r:${k}]`).join(';')})`;
}

/**
 * What summary of the row a formula is, when it is exactly one the assistant writes ({fn, keys}); null for any other formula, which the
 * assistant then leaves alone.
 */
export function readRowSummary(formula) {
    const pct = /^\s*\[r:([^\]\s;]+)\]\s*\/\s*\[r:([^\]\s;]+)\]\s*\*\s*100\s*$/.exec(formula || '');
    if (pct) {
        return { fn: 'pct', keys: [pct[1], pct[2]] };
    }
    const match = /^\s*([a-z_]+)\s*\(((?:\s*\[r:[^\]\s;]+\]\s*;?)+)\)\s*$/.exec(formula || '');
    if (!match || !ROW_SUMMARY.includes(match[1])) {
        return null;
    }
    const keys = [...match[2].matchAll(/\[r:([^\]\s;]+)\]/g)].map((m) => m[1]);
    // `;` only between references: «sum([r:a];;[r:b])» or a trailing one is not the assistant's.
    if (match[2].replace(/\s/g, '') !== keys.map((k) => `[r:${k}]`).join(';')) {
        return null;
    }

    return { fn: match[1], keys };
}
