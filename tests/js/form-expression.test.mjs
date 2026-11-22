// Runs the shared expression cases against the JavaScript interpreter: `node --test tests/js/`
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parse, evaluate } from '../../assets/utils/form_expression.js';

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
