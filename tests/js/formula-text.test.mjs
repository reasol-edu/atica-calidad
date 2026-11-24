// The text side of the visual formula editor (assets/utils/formula_text.js) and what it reads from the expression parser: where each
// mistake is, how many values each function takes, which function the cursor is in. `node --test tests/js/`
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parse, tokens, arityProblems, callAt } from '../../assets/utils/form_expression.js';
import { fold, matches, wordStarts, suggestFunction, templateText, templateToken, splitLine, joinLine } from '../../assets/utils/formula_text.js';

test('a formula splits into text and references, and joins back the same', () => {
    const formula = '[r:a] / [r:b] * 100 + count_if([t:x.y]; "=" ; "[no]")';
    const pieces = splitLine(formula, 'formula');
    assert.deepEqual(pieces.filter((p) => p.ref).map((p) => p.ref), ['r:a', 'r:b', 't:x.y']);
    // Brackets inside quotes are text, not a reference.
    assert.ok(pieces.some((p) => p.text && p.text.includes('"[no]"')));
    assert.equal(joinLine(pieces, 'formula'), formula);
    // An unclosed bracket stays text; spaces inside the brackets are dropped.
    assert.deepEqual(splitLine('1 + [f:a', 'formula'), [{ text: '1 + [f:a' }]);
    assert.equal(joinLine(splitLine('[ f:a ]', 'formula'), 'formula'), '[f:a]');
});

test('a template splits into text and {{label|ref}}, joins back with the current label, and reads with «»', () => {
    const title = 'Seguimiento de {{Elemento de la lista|copy.label}} ({{Campo: Grupo|f:k1}})';
    const pieces = splitLine(title, 'template');
    assert.deepEqual(pieces.filter((p) => p.ref), [{ ref: 'copy.label', label: 'Elemento de la lista' }, { ref: 'f:k1', label: 'Campo: Grupo' }]);
    assert.equal(joinLine(pieces, 'template'), title);
    // A renamed field: the token takes its new name.
    assert.equal(joinLine(pieces, 'template', (ref) => (ref === 'f:k1' ? 'Campo: Curso' : null)),
        'Seguimiento de {{Elemento de la lista|copy.label}} ({{Campo: Curso|f:k1}})');
    assert.equal(templateText(title), 'Seguimiento de «Elemento de la lista» («Campo: Grupo»)');
    assert.equal(templateToken('a|b{c}', 'ctx:year'), '{{abc|ctx:year}}');
    assert.equal(templateText(''), '');
});

test('the search ignores case and accents, and the suggestions match the start of words', () => {
    assert.equal(fold('Matemáticas ÁÉÍ'), 'matematicas aei');
    assert.ok(matches({ label: 'Lengua Castellana', hint: 'Número' }, 'castellana numero'));
    assert.ok(!matches({ label: 'Lengua Castellana' }, 'ingles'));
    assert.ok(wordStarts('Aprobados (%)', 'apro'));
    assert.ok(wordStarts('Aprobados en la evaluación final', 'evalua'));
    assert.ok(!wordStarts('Departamento', 'me'));
});

test('an unknown function gets the one meant: Spanish names and typos', () => {
    assert.equal(suggestFunction('suma'), 'sum');
    assert.equal(suggestFunction('Promedio'), 'avg');
    assert.equal(suggestFunction('máximo'), 'max');
    assert.equal(suggestFunction('si'), 'if');
    assert.equal(suggestFunction('rond'), 'round');
    assert.equal(suggestFunction('cuont_if'), 'count_if');
    assert.equal(suggestFunction('xyzzyx'), null);
});

test('a mistake says what and where', () => {
    const at = (s) => { const p = parse(s); return [p.error, p.at && p.at.start, p.at && p.at.end, p.data]; };
    assert.deepEqual(at('1 +'), ['end', 3, 3, { after: '+' }]);
    assert.deepEqual(at('(1 + 2'), ['parenthesis', 0, 1, {}]);
    assert.deepEqual(at('sum(1; 2'), ['parenthesis', 3, 4, {}]);
    assert.deepEqual(at('sum(1 2)'), ['unexpected', 6, 7, { token: '2' }]);
    assert.deepEqual(at('1 + 2)'), ['unexpected', 5, 6, { token: ')' }]);
    assert.deepEqual(at('suma(1)'), ['function', 0, 4, { name: 'suma' }]);
    assert.deepEqual(at('sum 1'), ['function', 0, 3, { name: 'sum', call: true }]);
    assert.deepEqual(at('1 $ 2'), ['character', 2, 3, { char: '$' }]);
    assert.deepEqual(at('"abc'), ['string', 0, 4, {}]);
    assert.deepEqual(at('[f:a'), ['reference', 0, 4, {}]);
    const ok = parse('sum([f:a]; 2)');
    assert.equal(ok.error, null);
    assert.equal(ok.at, null);
});

test('the lenient tokens go on past what cannot be read, with their places', () => {
    const toks = tokens('1 $ [f:a', true);
    assert.deepEqual(toks.map((t) => [t.t, t.s, t.e]), [['num', 0, 1], ['bad', 2, 3], ['ref', 4, 8]]);
    assert.equal(toks[2].open, true);
    assert.throws(() => tokens('1 $'));
});

test('calls with too few or too many values are found, where their name is', () => {
    const found = arityProblems(parse('if(1) + round(1; 2; 3) + sum(1; 2; 3; 4) + if(1; 2)').ast);
    assert.deepEqual(found.map((p) => [p.name, p.s, p.e, p.given]), [['if', 0, 2, 1], ['round', 8, 13, 3]]);
    assert.deepEqual(arityProblems(parse('count_if([t:a.b]; "="; 1)').ast), []);
});

test('the function the cursor is in, and which of its values', () => {
    const f = 'if([f:a] > 2; round([f:b]; 1); 0)';
    assert.deepEqual(callAt(f, 4), { name: 'if', arg: 0, s: 2 });
    assert.deepEqual(callAt(f, 21), { name: 'round', arg: 0, s: 19 });
    assert.deepEqual(callAt(f, 28), { name: 'round', arg: 1, s: 19 });
    assert.deepEqual(callAt(f, 32), { name: 'if', arg: 2, s: 2 });
    assert.equal(callAt(f, f.length), null);
    // Half written, and a plain parenthesis inside a call.
    assert.deepEqual(callAt('sum(1; (2', 9), { name: 'sum', arg: 1, s: 3 });
    assert.equal(callAt('(1 + 2', 3), null);
});
