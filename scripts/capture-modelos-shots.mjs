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
await page.locator('select:has(option:text-is("De una lista del centro"))').first().scrollIntoViewIfNeeded();
await shot('modelos-editor-lista', { clip: await clipAround(page.locator('select:has(option:text-is("De una lista del centro"))').first(), 40, 235, 800, 430) });

// ── 3. Filling it in: the department is chosen among the list's items ─────────
await page.goto(`${baseUrl}/actividades/${activityId}/formulario/${profileId}:::`);
await settle();
const canvas = page.locator('[data-form-fill-target="canvas"]');
await canvas.locator('select').first().selectOption({ label: 'Matemáticas' });
await canvas.locator('input').first().fill('11');
await canvas.locator('textarea').first().fill('Curso con buena coordinación entre cursos; se acordó unificar los criterios de evaluación.');
await canvas.locator('button:has-text("Añadir fila")').click();
await settle();
await canvas.locator('table input, [data-row] input').first().fill('Criterios de evaluación comunes');
await canvas.locator('table input[type="checkbox"], [data-row] input[type="checkbox"]').nth(0).check();
await canvas.locator('table input[type="checkbox"], [data-row] input[type="checkbox"]').nth(3).check();
await shot('modelos-rellenar');

// ── 4. Delivering it: the reviewer's view keeps reading as it was delivered ───
await page.locator('form[action$="/entregar"] button[type="submit"]').click();
await settle();
const revisionId = id('select hex(revision_id) from form_submission_revision limit 1');
await page.goto(`${baseUrl}/formularios/revision/${revisionId}`);
await shot('modelos-entregado');

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

async function clipAround(locator, dx, dy, width, height) {
    const box = await locator.boundingBox();
    return { x: Math.max(0, box.x - dx), y: Math.max(0, box.y - dy), width, height };
}

await browser.close();
