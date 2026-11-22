// The client side of the scoped data (copies, calculations, totals): `node --test tests/js/form-scopes.test.mjs`
import { test } from 'node:test';
import assert from 'node:assert/strict';

globalThis.window = globalThis;
const { sync, calculate, totals, walk, numbers } = await import('../../assets/utils/form_scopes.js');

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
