/**
 * Captures the desktop screenshots of the "Modelos de documento" chapter of the manual
 * (docs/manual/img/modelos-*.png), against the demo data from `app:load-demo-data` (IES Ada Lovelace) plus
 * `app:load-demo-form-models` (the model «Memoria de departamento» and its activity). MUTATING: it fills in
 * and delivers the form as `calidad`, so it needs a database built from scratch (docs/manual/README.md) and runs
 * after the read-only scripts, before `capture-gestion`. Needs the `sqlite3` CLI (`SHOTS_DB`, default var/capturas.db)
 * to find the ids the pages are addressed by.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
// The files it attaches (scripts/fixtures/): a photo of the board and the act of the meeting.
const fixtures = new URL('./fixtures/', import.meta.url).pathname;
const outDir  = process.env.SHOTS_OUT_DIR ?? 'docs/manual/img';
const dbPath  = process.env.SHOTS_DB ?? 'var/capturas.db';

mkdirSync(outDir, { recursive: true });

const sql  = (query) => execFileSync('sqlite3', [dbPath, query]).toString().trim();
const uuid = (h) => `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`.toLowerCase();
const id   = (query) => uuid(sql(query));

const centreId   = id("select hex(id) from educational_centre where name = 'IES Ada Lovelace'");
const activityId = id("select hex(id) from activity where title = 'Memoria final de departamento'");
const profileId  = id("select hex(id) from specific_profile where name = 'Responsable de memorias'");

const browser = await chromium.launch({ args: ['--lang=es-ES'] });
const page    = await browser.newPage({ viewport: { width: 1360, height: 900 }, locale: 'es-ES' });

async function hideToolbar() {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
}

async function settle() {
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(600);
}

async function shot(name, options = {}) {
    await settle();
    await hideToolbar();
    await page.screenshot({ path: `${outDir}/${name}.png`, ...options });
}

async function login(username, password = username) {
    await page.context().clearCookies();
    await page.goto(`${baseUrl}/login`);
    await page.fill('#username', username);
    await page.fill('#password', password);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    if (page.url().includes('/seleccion/centro')) {
        await page.click('text=IES Ada Lovelace');
        await page.waitForLoadState('networkidle');
    }
}

await login('calidad');

// ── 1. The models screen: the new model in force, with its actions ────────────
await page.goto(`${baseUrl}/modelos`);
await settle();
await page.locator('text=Memoria de departamento').first().evaluate((el) => el.scrollIntoView({ block: 'center' }));
await shot('modelos-lista');

// ── 2. The editor: a choice whose options come from a list of the centre ──────
await page.locator('a:has-text("Editar")').first().click();
await settle();
await page.locator('select:has(option:text-is("De una lista del catálogo"))').first().scrollIntoViewIfNeeded();
await shot('modelos-editor-lista', { clip: await clipAround(page.locator('select:has(option:text-is("De una lista del catálogo"))').first(), 40, 235, 800, 430) });

// The field «Evidencias»: how many files, and whether the pages of the attached PDFs go into the delivered PDF.
const filesBox = page.locator('text=Archivos como máximo').first();
await filesBox.evaluate((el) => el.scrollIntoView({ block: 'center' }));
await shot('modelos-editor-adjuntos', { clip: await clipAround(filesBox, 30, 140, 800, 255) });

// A fixed table whose rows are the items of a list («Materia»), only those of who delivers it.
const ownBoxes = page.locator('label:has-text("Solo los elementos asociados a quien entrega")');
await ownBoxes.first().evaluate((el) => el.scrollIntoView({ block: 'center' }));
await shot('modelos-editor-filas-lista', { clip: await clipAround(ownBoxes.first(), 30, 215, 800, 330) });

// A section repeated once per item of a list (Enseñanza › Formación Profesional), its title naming each.
await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { if (d.textContent.includes('Se repite una vez por elemento')) { d.open = true; } }));
await page.waitForTimeout(300);
await ownBoxes.last().evaluate((el) => el.scrollIntoView({ block: 'center' }));
await shot('modelos-editor-repetir-lista', { clip: await clipAround(ownBoxes.last(), 50, 270, 800, 360) });

// The formula editor: in «Resultados por materia», a column «Matriculados» and a calculated «% sobre matriculados» written with the
// «Calcular %» assistant; then a slip (« + suma(2)») with its explanation and fix, and the list of functions. (Only the draft changes:
// the activity fills in the revision in force.)
const tableBlock = page.locator('#' + await page.evaluate(() => [...document.querySelectorAll('[id^="blk-"] input')].find((i) => i.value === 'Aprobados (%)').closest('[id^="blk-"]').id));
const pickIn = (select, value) => select.evaluate((s, v) => (s.tomselect ? s.tomselect.setValue(v) : (s.value = v, s.dispatchEvent(new Event('change')))), value);
const optionOf = (select, text) => select.evaluate((s, t) => [...s.options].find((o) => o.text.startsWith(t)).value, text);
await tableBlock.locator('button:has-text("Añadir columna")').click();
await page.waitForTimeout(400);
await pickIn(tableBlock.locator('select[aria-label="Tipo"]').last(), 'number');
await page.waitForTimeout(400);
await tableBlock.locator('label', { hasText: /^Columna 2$/ }).locator('input').fill('Matriculados');
await tableBlock.locator('button:has-text("Añadir columna")').click();
await page.waitForTimeout(400);
await tableBlock.locator('label', { hasText: /^Columna 3$/ }).locator('input').fill('% sobre matriculados');
await pickIn(tableBlock.locator('select[aria-label="Tipo"]').last(), 'calculated');
await page.waitForTimeout(500);
await pickIn(tableBlock.locator('select[aria-label="Cálculo"]').last(), 'pct');
await page.waitForTimeout(400);
const partA = tableBlock.locator('select[aria-label="Columna A (la parte)"]').last();
await pickIn(partA, await optionOf(partA, 'Aprobados'));
const partB = tableBlock.locator('select[aria-label="Columna B (el total)"]').last();
await pickIn(partB, await optionOf(partB, 'Matriculados'));
await page.waitForTimeout(400);
const formula = tableBlock.locator('.fx-formula').last();
const formulaLine = formula.locator('.fx-content');
await formulaLine.evaluate((el) => el.scrollIntoView({ block: 'center' }));
const lineBox = await formulaLine.boundingBox();
await page.mouse.click(lineBox.x + lineBox.width - 6, lineBox.y + lineBox.height / 2);
await page.keyboard.type(' + suma(2)');
await page.keyboard.press('Escape');
await page.evaluate(() => document.activeElement?.blur());
await page.waitForTimeout(500);
await shot('modelos-editor-formula', { clip: await clipAround(formulaLine, 30, 48, 800, 150) });
await formula.locator('.fx-fix').click();
await page.waitForTimeout(300);
await formula.locator('.fx-fn').first().click();
await page.waitForSelector('.fx-menu');
await page.waitForTimeout(300);
await shot('modelos-editor-funciones', { clip: await clipAround(formula.locator('.fx-fn').first(), 420, 60, 800, 470) });
await page.keyboard.press('Escape');

// ── 3. Filling it in: the department is chosen among the list's items ─────────
await page.goto(`${baseUrl}/actividades/${activityId}/formulario/${profileId}:::`);
await settle();
const canvas = page.locator('[data-form-fill-target="canvas"]');
// The department is picked in a searchable select (its list is in the page's body).
await canvas.locator('.ts-wrapper .ts-control').first().click();
await page.locator('.ts-dropdown .option:has-text("Matemáticas")').first().click();
await page.waitForTimeout(800); // the autosave answers after each edit: typing on top of it loses the previous one
// (The first input is not the number: the department select has its own search input.)
await canvas.locator('input:not(.ts-control > input)').first().fill('11');
await page.keyboard.press('Tab');
await page.waitForTimeout(800);
await canvas.locator('.rich-content').first().fill('Curso con buena coordinación entre cursos; se acordó unificar los criterios de evaluación.');
await page.waitForTimeout(800);
await canvas.locator('button:has-text("Añadir fila")').click();
await settle();
await canvas.locator('table input, [data-row] input').first().fill('Criterios de evaluación comunes');
await page.waitForTimeout(800);
// The departments of the row are picked in a searchable multiple select (its list is in the page's body).
await canvas.locator('table .ts-wrapper .ts-control').first().click();
await page.locator('.ts-dropdown .option:has-text("Lengua Castellana")').first().click();
await page.locator('.ts-dropdown .option:has-text("Inglés")').first().click();
await page.keyboard.press('Escape');
await atSection('Datos del departamento');
await shot('modelos-rellenar');

// Section 3: the rating, and two files attached (a photo of the board and the act of the meeting).
const rating = canvas.locator('fieldset:has(legend:text("Satisfacción con el curso"))');
await rating.locator('label:has-text("4")').first().click();
await page.waitForTimeout(800);
await canvas.locator('input[type="file"]').first().setInputFiles([`${fixtures}pizarra-reunion.png`, `${fixtures}acta-coordinacion.pdf`]);
await page.waitForFunction(() => document.querySelectorAll('a[href*="/formularios/adjunto/"]').length >= 2, null, { timeout: 15000 });
await page.waitForTimeout(1500);   // the field is saved after the uploads
await atSection('Satisfacción y evidencias');
await shot('modelos-escala-adjuntos');

// Sections 4 and 5: a row per subject (the list «Materia»), a copy per course of vocational training.
const results = canvas.locator('table:has(th:text("Aprobados (%)"))').first();
// (Its first column has a filter, an input of its own: only the numbers are the cells.)
await results.locator('input[type="number"]').nth(0).fill('86');
await page.keyboard.press('Tab');
await page.waitForTimeout(500);
await results.locator('input[type="number"]').nth(1).fill('78');
await page.keyboard.press('Tab');
await page.waitForTimeout(800);
await atSection('Resultados por materia');
await shot('modelos-filas-lista');
await canvas.locator('section:has(h2:has-text("Seguimiento de")) input').first().fill('Más horas de prácticas en empresa');
await page.keyboard.press('Tab');
await page.waitForTimeout(1500);
await atSection('Seguimiento de');
await shot('modelos-copias-lista');

// ── 4. Delivering it: the reviewer's view keeps reading as it was delivered ───
await page.locator('form[action$="/entregar"] button[type="submit"]').click();
await settle();
const revisionId = id('select hex(revision_id) from form_submission_revision limit 1');
await page.goto(`${baseUrl}/formularios/revision/${revisionId}`);
await shot('modelos-entregado');

// The same document further down: the annexes, the picture shown where its field is.
await page.locator('text=Anexo 1').first().evaluate((el) => el.scrollIntoView({ block: 'center' }));
await shot('modelos-anexos');

// ── 5. The data explorer: the department as a name, filterable ───────────────
await page.goto(`${baseUrl}/centro/${centreId}/informes/datos-de-modelos`);
await settle();
await shot('modelos-explorador');

// ── 6. The indicator form: the condition is picked, with a search box ────────
await page.goto(`${baseUrl}/mejora/indicadores/nuevo`);
await settle();
const source = page.locator('#ind-source-field');
await source.scrollIntoViewIfNeeded();
await source.selectOption({ label: 'Departamento' });
await page.selectOption('#ind-source-agg', 'count_if');
await settle();
// Centred, so the open list fits under the box (the screenshot is the viewport).
await page.locator('#ind-source-op').evaluate((el) => el.scrollIntoView({ block: 'center' }));
await page.locator('[data-testid="source-fieldset"] .ts-control').first().click();
await page.waitForTimeout(400);
await page.setViewportSize({ width: 1360, height: 1150 });   // room for the open list below the box
await page.locator('#ind-source-op').evaluate((el) => el.scrollIntoView({ block: 'center' }));
await shot('modelos-indicador-condicion');
await page.keyboard.press('Escape');
await page.setViewportSize({ width: 1360, height: 900 });

// ── 7. Responsabilidades: deleting an item that delivered documents use ──────
await page.goto(`${baseUrl}/centro/${centreId}/responsabilidades/listas`);
await settle();
await page.locator('li:has(a[href*="/responsabilidades/listas/"])', { hasText: 'Departamento' }).first().locator('a').first().click();
await settle();
await page.waitForTimeout(1000);   // let the Live Component connect
await page.locator('button:visible', { hasText: 'Matemáticas' }).first().click();
await page.waitForSelector('button[data-live-action-param="askDelete"]');
await page.locator('button[data-live-action-param="askDelete"]').click();
await page.waitForSelector('[data-testid="form-usage"]');
await page.locator('[data-testid="form-usage"]').evaluate((el) => el.scrollIntoView({ block: 'center' }));
await shot('modelos-borrar-elemento');

// ── 8. The same item, moved to another list: the confirmation tells the same ──
await page.reload();
await settle();
await page.getByRole('button', { name: 'Seleccionar varios' }).click();
await page.waitForTimeout(800);
await page.locator('input[type="checkbox"][data-model="bulkSelectedIds"]:visible').first().check();
await page.waitForTimeout(1000);
await page.locator('.ts-wrapper:visible').last().locator('.ts-control').click();
await page.locator('.ts-dropdown .option:visible', { hasText: 'Grupo' }).first().click();
await page.waitForTimeout(600);
await page.locator('button[data-live-action-param="bulkMoveSelected"]').click();
await page.waitForSelector('[data-testid="move-form-usage"]');
await page.locator('[data-testid="move-form-usage"]').evaluate((el) => el.scrollIntoView({ block: 'center' }));
await shot('modelos-mover-elemento');

/**
 * The fill form at a section: its heading right under the page's header and the form's bar, nothing focused (no focus ring in the
 * picture) and everything saved («Guardado» in the bar, not «Guardando…»).
 */
async function atSection(title) {
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForFunction(() => document.querySelector('[data-form-fill-target="status"]')?.textContent.trim() !== 'Guardando…', null, { timeout: 15000 }).catch(() => {});
    await page.waitForTimeout(400);
    await page.locator(`section > div h2:has-text("${title}")`).first().evaluate((el) => el.closest('section').scrollIntoView({ block: 'start' }));
    await page.waitForTimeout(300);
}

async function clipAround(locator, dx, dy, width, height) {
    const box = await locator.boundingBox();
    return { x: Math.max(0, box.x - dx), y: Math.max(0, box.y - dy), width, height };
}

await browser.close();
