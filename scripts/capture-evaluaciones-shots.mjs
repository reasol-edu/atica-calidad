/**
 * Captures the screenshots of the activities with «Una entrega por» (one submission per combination of an element with the values of
 * other lists) of chapter 8 (docs/manual/img/actividades-una-entrega-por-*.png), against the demo data from `app:load-demo-data` PLUS
 * `app:load-demo-module-programs` PLUS `app:load-demo-evaluations` — see skills/screenshots.md.
 *
 * Also the screenshots of the tables with a row per delivery of chapter 12 (docs/manual/img/modelos-tabla-entregas-*.png).
 *
 * Requires a server already running at SHOTS_BASE_URL on a DISPOSABLE database seeded with that data (never the real one). It never
 * presses save or deliver, but opening a fill page starts a draft (and stores what its tables of deliveries read): it logs in as
 * `direccion` (the activity forms and the card), `n.bravo` (her tasks and draft), `c.núñez` (the report of Informática), `calidad` (the
 * model editor) and `p.sánchez` (the delivered report of Matemáticas).
 *
 * Env: SHOTS_BASE_URL (default http://127.0.0.1:8744), SHOTS_OUT_DIR (default docs/manual/img).
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const outDir  = process.env.SHOTS_OUT_DIR ?? 'docs/manual/img';
const dbPath  = process.env.SHOTS_DB ?? 'var/capturas.db';
const review  = 'Revisión de la programación';

mkdirSync(outDir, { recursive: true });

const browser = await chromium.launch({ args: ['--lang=es-ES'] });

async function login(username, password) {
    const context = await browser.newContext({ viewport: { width: 1360, height: 900 }, locale: 'es-ES' });
    const page    = await context.newPage();
    await page.goto(`${baseUrl}/login`);
    await page.fill('#username', username);
    await page.fill('#password', password);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    if (page.url().includes('/seleccion/centro')) {
        await page.click('text=IES Ada Lovelace');
        await page.waitForLoadState('networkidle');
    }

    return page;
}

async function settle(page) {
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(300);
}

async function hideToolbar(page) {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
    await page.evaluate(() => window.scrollTo(0, 0));
}

async function clipTo(page, locator, file, maxHeight = 1400) {
    await locator.scrollIntoViewIfNeeded();
    await hideToolbar(page);
    const box = await locator.boundingBox();
    if (!box) throw new Error(`No se encuentra lo que hay que capturar en ${file}.`);
    await page.screenshot({ path: `${outDir}/${file}.png`, fullPage: true, clip: { x: box.x, y: box.y, width: box.width, height: Math.min(box.height, maxHeight) } });
}

// ── 1. The card of the review: the periods, the current one open, its progress ──
const direccion = await login('direccion', 'direccion');
await direccion.goto(`${baseUrl}/actividades?tab=view`);
await settle(direccion);
await direccion.click('text=Sobre programaciones didácticas');
const card = direccion.locator('[data-testid="activity-card"]', { hasText: review }).first();
await card.waitFor();
await card.locator('[data-testid="activity-toggle"]').click();
await card.locator('[data-testid="submission-period"]').first().waitFor();
await settle(direccion);
await clipTo(direccion, card, 'actividades-una-entrega-por-tarjeta');

// ── 2. Its form: «Una entrega por» and the dates of each evaluation ──
await card.locator('[data-live-action-param="startEditActivity"]').first().evaluate((el) => el.click());
const dimensions = direccion.locator('[data-testid="dimensions"]').first();
await dimensions.waitFor();
await settle(direccion);
const dates = direccion.locator('[id^="activity-form-deadlines-"]').first();
await dimensions.scrollIntoViewIfNeeded();
await hideToolbar(direccion);
const top    = await dimensions.boundingBox();
const bottom = await dates.boundingBox();
if (!top || !bottom) throw new Error('No se encuentra la sección «Una entrega por».');
await direccion.screenshot({
    path: `${outDir}/actividades-una-entrega-por-formulario.png`,
    fullPage: true,
    clip: { x: top.x - 16, y: top.y - 16, width: top.width + 32, height: bottom.y + bottom.height - top.y + 32 },
});
await direccion.context().close();

// ── 3. The tasks of the teacher of Programación: only the 2ª, the one open ──
const noelia = await login('n.bravo', 'prueba');
await settle(noelia);
const steps = noelia.locator('text=Tus próximos pasos').first().locator('xpath=ancestor::*[contains(@class, "rounded")][1]');
await clipTo(noelia, steps, 'actividades-una-entrega-por-tareas', 900);

// ── 4. Her draft of the 2ª of Programación: started from what she delivered in the 1ª ──
const sql = (query) => execFileSync('sqlite3', [dbPath, query]).toString().trim();
const uuid = (hex) => hex.toLowerCase().replace(/^(.{8})(.{4})(.{4})(.{4})(.{12})$/, '$1-$2-$3-$4-$5');
const reviewId = uuid(sql("SELECT hex(id) FROM activity WHERE title = 'Revisión de la programación'"));
const draftKey = sql(`SELECT slot_key FROM form_submission WHERE prefilled_from IS NOT NULL AND activity_id = (SELECT id FROM activity WHERE title = 'Revisión de la programación')`);
await noelia.goto(`${baseUrl}/actividades/${reviewId}/formulario/${encodeURIComponent(draftKey)}`);
await settle(noelia);
await hideToolbar(noelia);
await noelia.screenshot({ path: `${outDir}/actividades-una-entrega-por-copia.png`, clip: { x: 0, y: 0, width: 1360, height: 640 } });
await noelia.context().close();

// ── 5. The report of the head of Informática for the 1ª: only its subjects' reviews of the 1ª ──
const claudia = await login('c.núñez', 'prueba');
const reportId = uuid(sql("SELECT hex(id) FROM activity WHERE title = 'Informe de evaluación del departamento'"));
const head     = uuid(sql("SELECT hex(id) FROM specific_profile WHERE name = 'Jefe/a de Departamento'"));
const informat = uuid(sql("SELECT hex(li.id) FROM list_item li JOIN list_item p ON p.id = li.parent_id WHERE li.name = 'Informática' AND p.name = 'Departamento'"));
const first    = uuid(sql("SELECT hex(li.id) FROM list_item li JOIN list_item p ON p.id = li.parent_id WHERE li.name = '1ª' AND p.name = 'Evaluación'"));
await claudia.goto(`${baseUrl}/actividades/${reportId}/formulario/${encodeURIComponent(`${head}:${informat}:::${first}`)}`);
await settle(claudia);
await claudia.locator('text=Revisión de las materias').first().waitFor();
const table = claudia.locator('text=Revisión de las materias').first().locator('xpath=ancestor::section[1] | ancestor::*[contains(@class, "rounded-2xl")][1]').first();
await clipTo(claudia, table, 'actividades-una-entrega-por-informe', 900);
await claudia.context().close();

// ── 6. Chapter 12: the editor of the report's model, its table «Revisión de las materias» (one row per delivery) ──
const calidad = await login('calidad', 'calidad');
const modelId = uuid(sql("SELECT hex(m.id) FROM form_model m JOIN document d ON d.id = m.document_id WHERE d.name = 'Informe de evaluación del departamento'"));
await calidad.goto(`${baseUrl}/modelos/${modelId}`);
await settle(calidad);
const sourceBox = calidad.locator('text=Modelo de las entregas').first();
await sourceBox.waitFor();
const editorBlock = sourceBox.locator('xpath=ancestor::div[starts-with(@id, "blk-")][1]');
await clipTo(calidad, editorBlock, 'modelos-tabla-entregas-editor', 1100);
await calidad.context().close();

// ── 7. Chapter 12: which activity the table reads, in the form of the report activity ──
const manager = await login('direccion', 'direccion');
await manager.goto(`${baseUrl}/actividades?tab=view`);
await settle(manager);
await manager.click('text=Sobre programaciones didácticas');
const reportCard = manager.locator('[data-testid="activity-card"]', { hasText: 'Informe de evaluación del departamento' }).first();
await reportCard.waitFor();
await reportCard.locator('[data-live-action-param="startEditActivity"]').first().evaluate((el) => el.click());
const sources = manager.locator('text=Datos de otras entregas').first();
await sources.waitFor();
await settle(manager);
await clipTo(manager, sources.locator('xpath=ancestor::*[self::fieldset or self::section or contains(@class, "rounded-xl")][1]'), 'modelos-tabla-entregas-actividad', 500);
await manager.context().close();

// ── 8. Chapter 12: the report of Matemáticas for the 1ª, delivered: the state of each review, «—» and where they came from ──
const pablo = await login('p.sánchez', 'prueba');
const mates = uuid(sql("SELECT hex(li.id) FROM list_item li JOIN list_item p ON p.id = li.parent_id WHERE li.name = 'Matemáticas' AND p.name = 'Departamento'"));
await pablo.goto(`${baseUrl}/actividades/${reportId}/formulario/${encodeURIComponent(`${head}:${mates}:::${first}`)}`);
await settle(pablo);
const note = pablo.locator('[data-testid="delivered-from"]').first();
await note.waitFor();
const deliveredTable = note.locator('xpath=ancestor::*[contains(@class, "rounded-2xl") or self::section][1]');
await clipTo(pablo, deliveredTable, 'modelos-tabla-entregas-entregado', 900);

// ── 9. Chapter 12: the review of Matemáticas was corrected after the report: «Hay datos más recientes…» and its badge ──
const outdated = pablo.locator('[data-testid="delivered-outdated"]').first();
await outdated.waitFor();
await hideToolbar(pablo);
const band = await outdated.boundingBox();
if (!band) throw new Error('No se encuentra el aviso «Hay datos más recientes».');
await pablo.screenshot({ path: `${outDir}/modelos-tabla-entregas-desactualizado.png`, clip: { x: band.x - 16, y: Math.max(0, band.y - 110), width: band.width + 32, height: band.height + 180 } });
await pablo.goto(`${baseUrl}/actividades?tab=view`);
await settle(pablo);
await pablo.click('text=Sobre programaciones didácticas');
const pabloCard = pablo.locator('[data-testid="activity-card"]', { hasText: 'Informe de evaluación del departamento' }).first();
await pabloCard.waitFor();
await pabloCard.locator('[data-testid="activity-toggle"]').click();
const badge = pabloCard.locator('[data-testid="submission-outdated"]').first();
await badge.waitFor({ state: 'attached' });
// The 1ª is over: its period is folded.
await badge.evaluate((el) => { for (let d = el.closest('details'); d; d = d.parentElement?.closest('details')) { d.open = true; } });
await badge.waitFor();
await settle(pablo);
await clipTo(pablo, badge.locator('xpath=ancestor::*[@data-role="submission-row"][1]'), 'modelos-tabla-entregas-insignia', 200);
await pablo.context().close();

// ── 10. Chapter 12: the final report of Informática, a column per evaluation («Solo la de»), a row per subject ──
const finalHead = await login('c.núñez', 'prueba');
const finalId   = uuid(sql("SELECT hex(id) FROM activity WHERE title = 'Memoria final del departamento'"));
await finalHead.goto(`${baseUrl}/actividades/${finalId}/formulario/${encodeURIComponent(`${head}:${informat}::`)}`);
await settle(finalHead);
await finalHead.locator('text=Cumplimiento por evaluación').first().waitFor();
const finalTable = finalHead.locator('text=Cumplimiento por evaluación').first().locator('xpath=ancestor::section[1] | ancestor::*[contains(@class, "rounded-2xl")][1]').first();
await clipTo(finalHead, finalTable, 'modelos-tabla-entregas-por-valor', 900);
await finalHead.context().close();

await browser.close();
