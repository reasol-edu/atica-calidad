// The client side of the scoped data (copies, calculations, totals): `node --test tests/js/form-scopes.test.mjs`
import { test } from 'node:test';
import assert from 'node:assert/strict';

globalThis.window = globalThis;
const { sync, calculate, totals, walk, numbers, countedCount, format, references, referencesHtml } = await import('../../assets/utils/form_scopes.js');

const k = (n) => `${n}0000000-0000-4000-8000-000000000000`;

function definition() {
    return { version: 1, sections: [{
        key: k('s'), title: 'Todo', numbered: true, children: [], blocks: [
            { key: k('limit'), type: 'field', fieldType: 'number', label: 'Horas' },
            { key: k('units'), type: 'table', mode: 'free', label: 'U', columns: [
                { key: k('title'), label: 'T', fieldType: 'short' },
                { key: k('hours'), label: 'H', fieldType: 'number', total: 'sum' },
                { key: k('price'), label: 'P', fieldType: 'number' },
                { key: k('cost'), label: 'C', fieldType: 'calculated', formula: `[r:${k('hours')}] * [r:${k('price')}]`, decimals: 2, unit: '€', total: 'sum' },
            ] },
            { key: k('total'), type: 'field', fieldType: 'calculated', label: 'Total', formula: `sum([t:${k('units')}.${k('hours')}])`, decimals: 0 },
            { key: k('ras'), type: 'table', mode: 'free', label: 'RA', columns: [{ key: k('ra'), label: 'RA', fieldType: 'short' }] },
            { key: k('w'), type: 'table', mode: 'matrix', label: 'W', rowTable: k('units'), rowLabel: k('title'), colTable: k('ras'), colLabel: k('ra'), cell: { fieldType: 'number' } },
            { key: k('acts'), type: 'table', mode: 'free', label: 'A', columns: [{ key: k('rel'), label: 'R', fieldType: 'relation', relTable: k('ras'), relLabel: k('ra'), multiple: true }] },
        ],
    }] };
}

test('calculated columns and fields follow the typed values', () => {
    const def = definition();
    const state = { fields: { [k('limit')]: 100 }, texts: {}, tables: {
        [k('units')]: [
            { _key: 'a0000000-0000-4000-8000-000000000001', [k('hours')]: 40, [k('price')]: 2.5 },
            { _key: 'a0000000-0000-4000-8000-000000000002', [k('hours')]: 60, [k('price')]: 1 },
        ],
    }, copies: {} };
    sync(def.sections, state);
    calculate(def, state);

    assert.equal(state.tables[k('units')][0][k('cost')], 100);
    assert.equal(state.tables[k('units')][1][k('cost')], 60);
    assert.equal(state.fields[k('total')], 100);

    state.tables[k('units')][0][k('hours')] = 50;
    calculate(def, state);
    assert.equal(state.fields[k('total')], 110);
    assert.equal(state.tables[k('units')][0][k('cost')], 125);

    delete state.tables[k('units')][0][k('price')];
    calculate(def, state);
    assert.equal(k('cost') in state.tables[k('units')][0], false, 'a missing term leaves the cell without value');
});

test('totals at the foot of a column', () => {
    const def = definition();
    const columns = def.sections[0].blocks[1].columns;
    const rows = [{ [k('hours')]: 40, [k('cost')]: 100 }, { [k('hours')]: 60, [k('cost')]: 60 }];
    assert.deepEqual(totals(columns, rows), ['', '100', '', '160,00 €']);
});

test('a double-entry table has a row per unit and a relation forgets rows that went', () => {
    const def = definition();
    const state = { fields: {}, texts: {}, copies: {}, tables: {
        [k('units')]: [{ _key: 'a0000000-0000-4000-8000-000000000001' }, { _key: 'a0000000-0000-4000-8000-000000000002' }],
        [k('ras')]: [{ _key: 'b0000000-0000-4000-8000-000000000001' }, { _key: 'b0000000-0000-4000-8000-000000000002' }],
        [k('acts')]: [{ _key: 'c0000000-0000-4000-8000-000000000001', [k('rel')]: ['b0000000-0000-4000-8000-000000000002', 'b0000000-0000-4000-8000-0000000000ff'] }],
    } };
    sync(def.sections, state);
    assert.equal(state.tables[k('w')].length, 2);
    assert.deepEqual(state.tables[k('acts')][0][k('rel')], ['b0000000-0000-4000-8000-000000000002']);

    state.tables[k('units')].push({ _key: 'a0000000-0000-4000-8000-000000000003' });
    sync(def.sections, state);
    assert.equal(state.tables[k('w')].length, 3, 'a new unit is a new row');
});

test('sections that repeat by the rows of a table are numbered with their copies', () => {
    const def = { version: 1, sections: [
        { key: k('a'), title: 'Unidades', numbered: true, children: [], blocks: [{ key: k('t'), type: 'table', mode: 'free', label: 'U', columns: [{ key: k('c'), label: 'T', fieldType: 'short' }] }] },
        { key: k('b'), title: 'Desarrollo', numbered: true, blocks: [], children: [{ key: k('d'), title: 'UD {{copy.number}}', numbered: true, numberCopies: true, repeat: { mode: 'table', table: k('t') }, blocks: [], children: [] }] },
    ] };
    const state = { fields: {}, texts: {}, copies: {}, tables: { [k('t')]: [{ _key: 'a0000000-0000-4000-8000-000000000001' }, { _key: 'a0000000-0000-4000-8000-000000000002' }] } };
    sync(def.sections, state);
    const nums = numbers(walk(def, state));
    assert.deepEqual([...nums.values()], ['1', '2', '2.1', '2.2']);
});

test('a counted table has as many rows as its number field says and keeps the first ones', () => {
    const def = { version: 1, sections: [{ key: k('s'), title: 'Todo', numbered: true, children: [], blocks: [
        { key: k('n'), type: 'field', fieldType: 'number', label: 'Reuniones' },
        { key: k('t'), type: 'table', mode: 'counted', rowsField: k('n'), maxRows: 4, label: 'Fechas', columns: [{ key: k('c'), label: 'Asunto', fieldType: 'short' }] },
    ] }] };
    const state = { fields: { [k('n')]: 3 }, texts: {}, copies: {}, tables: {} };
    sync(def.sections, state);
    assert.equal(state.tables[k('t')].length, 3);

    const first = state.tables[k('t')][0]._key;
    state.tables[k('t')][0][k('c')] = 'Primera';
    state.fields[k('n')] = 9;
    sync(def.sections, state);
    assert.equal(state.tables[k('t')].length, 4, 'the cap of the table limits the rows');
    assert.equal(state.tables[k('t')][0]._key, first);

    state.fields[k('n')] = 1;
    sync(def.sections, state);
    assert.deepEqual(state.tables[k('t')].map((r) => r[k('c')]), ['Primera']);

    delete state.fields[k('n')];
    sync(def.sections, state);
    assert.equal(state.tables[k('t')].length, 0, 'no number, no rows');
    assert.equal(countedCount(def.sections[0].blocks[1], [state]), 0);
});

test('a rich text reads as the text it says', () => {
    assert.equal(format({ fieldType: 'long' }, '<p>Hola <strong>mundo</strong></p><ul><li>uno</li><li>a &amp; b</li></ul>', {}), 'Hola mundo uno a & b');
    assert.equal(format({ fieldType: 'long' }, 'texto\nplano', {}), 'texto plano');
});

test('a section repeated by a list has a copy per item, keyed by it, and each copy knows its name (the twin of FormDataNormalizer::copies())', () => {
    const def = { version: 1, sections: [{
        key: k('s'), title: 'Materia: {{Elemento|copy.label}}', numbered: true, children: [],
        repeat: { mode: 'list', list: k('l'), own: true, items: [{ key: k('a'), label: 'Física' }, { key: k('b'), label: 'Química' }] },
        blocks: [{ key: k('f'), type: 'field', fieldType: 'short', label: 'Valoración' }],
    }] };
    // What was typed for Química stays with it whatever the order; a copy of an item no longer listed goes.
    const state = { fields: {}, texts: {}, tables: {}, copies: { [k('s')]: [{ _key: k('c'), fields: { [k('f')]: 'x' } }, { _key: k('b'), fields: { [k('f')]: 'Bien' } }] } };
    sync(def.sections, state);
    assert.deepEqual(state.copies[k('s')].map((c) => c._key), [k('a'), k('b')]);
    assert.equal(state.copies[k('s')][1].fields[k('f')], 'Bien');
    assert.equal(state.copies[k('s')][1]._source, k('b'));

    const instances = walk(def, state);
    assert.deepEqual(instances.map((i) => references(i.section.title, i, new Map(), {}, {})), ['Materia: Física', 'Materia: Química']);

    // A fixed text reads its references too, as HTML: the values escaped, the tags of the text kept.
    const html = '<p>Propuestas para <strong>{{Elemento de la lista|copy.label}}</strong> ({{Centro &amp; curso|ctx:centre}})</p>';
    assert.equal(referencesHtml(html, instances[0], new Map(), { centre: 'IES <Ada> & "Co"' }, {}),
        '<p>Propuestas para <strong>Física</strong> (IES &lt;Ada&gt; &amp; &quot;Co&quot;)</p>');
    assert.equal(referencesHtml('<p>Sin referencias</p>', instances[0], new Map(), {}, {}), '<p>Sin referencias</p>');
    assert.equal(referencesHtml('', instances[0], new Map(), {}, {}), '');
});

test('a rating reads as points out of the top, with the word of an end (the same as FormResolver::format())', () => {
    const spec = { fieldType: 'scale', points: 5, lowLabel: 'Nada', highLabel: 'Mucho' };
    assert.equal(format(spec, 4, {}), '4 / 5');
    assert.equal(format(spec, 1, {}), '1 / 5 (Nada)');
    assert.equal(format(spec, 5, {}), '5 / 5 (Mucho)');
    assert.equal(format({ fieldType: 'scale', points: 4 }, 4, {}), '4 / 4');
    assert.equal(format(spec, null, {}), '');
});

test('a scale column is a number for calculations and totals', () => {
    const def = { version: 1, sections: [{ key: k('s'), title: 'Todo', numbered: true, children: [], blocks: [
        { key: k('t'), type: 'table', mode: 'free', label: 'T', columns: [
            { key: k('a'), label: 'Valoración', fieldType: 'scale', points: 4, total: 'avg' },
            { key: k('b'), label: 'Doble', fieldType: 'calculated', formula: `[r:${k('a')}] * 2`, decimals: 0 },
        ] },
    ] }] };
    const state = { fields: {}, texts: {}, tables: { [k('t')]: [{ _key: 'r1', [k('a')]: 3 }, { _key: 'r2', [k('a')]: 4 }] }, copies: {} };
    calculate(def, state);
    assert.deepEqual(state.tables[k('t')].map((r) => r[k('b')]), [6, 8]);
    assert.deepEqual(totals(def.sections[0].blocks[0].columns, state.tables[k('t')]), ['3,5', '']);
});

test('the field of a named foot total is calculated but not shown as a block', () => {
    const def = { version: 1, sections: [{ key: k('s'), title: 'Todo', numbered: true, children: [], blocks: [
        { key: k('t'), type: 'table', mode: 'free', label: 'T', columns: [{ key: k('c'), label: 'N', fieldType: 'number', total: 'sum', totalName: 'Suma', totalKey: k('f') }] },
        { key: k('f'), type: 'field', fieldType: 'calculated', label: 'Suma', formula: `sum([t:${k('t')}.${k('c')}])`, decimals: 2, foot: true },
    ] }] };
    const rows = [3, 4].map((v, i) => ({ _key: `e000000${i}-0000-4000-8000-000000000000`, [k('c')]: v }));
    const state = { fields: {}, texts: {}, copies: {}, tables: { [k('t')]: rows } };
    sync(def.sections, state);
    calculate(def, state);

    assert.equal(Number(state.fields[k('f')]), 7);
    const shown = walk(def, state)[0].blocks.filter((b) => b.visible).map((b) => b.block.key);
    assert.deepEqual(shown, [k('t')]);
});

test('the summary of a table column counts the filled values and sums, averages and finds the extremes (as the server does)', () => {
    const fns = ['count', 'sum', 'avg', 'min', 'max'];
    const def = { version: 1, sections: [{ key: k('s'), title: 'Todo', numbered: true, children: [], blocks: [
        { key: k('t'), type: 'table', mode: 'free', label: 'Reuniones', columns: [{ key: k('c'), label: 'Asistentes', fieldType: 'number' }] },
        ...fns.map((fn, i) => ({ key: k('f' + i), type: 'field', fieldType: 'calculated', label: fn, formula: `${fn}([t:${k('t')}.${k('c')}])`, decimals: 2 })),
    ] }] };
    const rows = [10, 4, null, 7].map((v, i) => ({ _key: `e000000${i}-0000-4000-8000-000000000000`, ...(v === null ? {} : { [k('c')]: v }) }));
    const state = { fields: {}, texts: {}, copies: {}, tables: { [k('t')]: rows } };
    sync(def.sections, state);
    calculate(def, state);

    assert.deepEqual(fns.map((_, i) => Number(state.fields[k('f' + i)])), [3, 21, 7, 4, 10]);
});
