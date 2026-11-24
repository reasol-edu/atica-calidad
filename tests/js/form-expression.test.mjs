// Runs the shared expression cases against the JavaScript interpreter: `node --test tests/js/`
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parse, evaluate, rowSummary, readRowSummary } from '../../assets/utils/form_expression.js';

const cases = JSON.parse(readFileSync(new URL('../Fixtures/form-expressions.json', import.meta.url), 'utf8'));

for (const [i, c] of cases.entries()) {
    test(`${i}: ${c.expr}`, () => {
        const parsed = parse(c.expr);
        if (c.error) {
            assert.equal(parsed.ast, null);
            return;
        }
        assert.ok(parsed.ast, parsed.error);
        const refs = c.refs || {};
        const result = evaluate(parsed.ast, (r) => (r in refs ? refs[r] : null));
        if (typeof c.expected === 'number') {
            assert.ok(Math.abs(result - c.expected) < 1e-9, `${result} != ${c.expected}`);
        } else {
            assert.deepEqual(result, c.expected);
        }
    });
}

test('the summary of the row is written and read back by the assistant, and evaluates', () => {
    const formula = rowSummary('sum', ['a', 'b', 'c']);
    assert.equal(formula, 'sum([r:a];[r:b];[r:c])');
    assert.deepEqual(readRowSummary(formula), { fn: 'sum', keys: ['a', 'b', 'c'] });
    assert.deepEqual(readRowSummary(' avg( [r:a] ; [r:b] ) '), { fn: 'avg', keys: ['a', 'b'] });
    const refs = { 'r:a': 2, 'r:b': null, 'r:c': '5' };
    assert.equal(evaluate(parse(formula).ast, (r) => refs[r] ?? null), 7, 'an empty cell does not stop the sum');
    assert.equal(evaluate(parse(rowSummary('count', ['a', 'b', 'c'])).ast, (r) => refs[r] ?? null), 2, 'count: the cells with something');

    assert.equal(rowSummary('sum', []), '');
    assert.equal(rowSummary('if', ['a']), '');
    // The percentage of A over B: empty when B is 0 or empty.
    const pct = rowSummary('pct', ['a', 'c']);
    assert.equal(pct, '[r:a] / [r:c] * 100');
    assert.deepEqual(readRowSummary(pct), { fn: 'pct', keys: ['a', 'c'] });
    assert.deepEqual(readRowSummary(' [r:x]/[r:y]*100 '), { fn: 'pct', keys: ['x', 'y'] });
    assert.equal(evaluate(parse(pct).ast, (r) => refs[r] ?? null), 40);
    assert.equal(evaluate(parse(pct).ast, (r) => ({ 'r:a': 3, 'r:c': 0 })[r] ?? null), null, 'B is 0');
    assert.equal(evaluate(parse(pct).ast, (r) => ({ 'r:a': 3 })[r] ?? null), null, 'B is empty');
    assert.equal(rowSummary('pct', ['a']), '');
    assert.equal(rowSummary('pct', ['a', '']), '');

    for (const other of ['[r:a] / [r:b] * 10', '[r:a] / [f:b] * 100', 'sum([r:a]) / 2', 'sum([f:a];[r:b])', 'if([r:a];1;2)', 'sum([r:a];;[r:b])', 'sum([r:a];)', '', 'sum()']) {
        assert.equal(readRowSummary(other), null, other);
    }
});
