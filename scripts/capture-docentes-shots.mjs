/**
 * Captures the screenshots of the teachers associated with the elements of the catalogue
 * (docs/manual/img/responsabilidades-docentes-*.png in chapter 6, docs/manual/img/actividades-docentes-*.png in chapter 8), against
 * the demo data from `app:load-demo-data` PLUS `app:load-demo-module-programs` — see skills/screenshots.md for what each fixture is
 * there to show (run that command after capture-modelos-shots.mjs).
 *
 * Requires a server already running at SHOTS_BASE_URL on a DISPOSABLE database seeded with that data (never the real one). Read-only:
 * it logs in as `direccion` (the catalogue and the activity form), and as `c.núñez`, head of the Informática department (her
 * «Entregas que gestionas»), and never saves anything.
 *
 * Env: SHOTS_BASE_URL (default http://127.0.0.1:8744), SHOTS_OUT_DIR (default docs/manual/img).
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const outDir  = process.env.SHOTS_OUT_DIR ?? 'docs/manual/img';
const activityTitle = 'Entrega de programaciones de los módulos';

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
    await page.waitForTimeout(250);
}

async function hideToolbar(page) {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
    await page.evaluate(() => window.scrollTo(0, 0));
}

// ── 1. The catalogue: the panel of an element with two teachers, and the tree with its badges ──
const direccion = await login('direccion', 'direccion');
const centreId  = (await direccion.$$eval('a[href*="/centro/"]', (as) => as.map((a) => a.getAttribute('href'))))
    .map((h) => h.match(/\/centro\/([0-9a-f-]{36})/)?.[1])
    .find(Boolean);
const lists = `${baseUrl}/centro/${centreId}/responsabilidades/listas`;

async function openList(page, name) {
    await page.goto(lists);
    await settle(page);
    await page.locator('li:has(a[href*="/responsabilidades/listas/"])', { hasText: name }).first().locator('a').first().click();
    await settle(page);
    await page.waitForTimeout(500);   // let the Live Component connect
}

await openList(direccion, 'Materia');
await hideToolbar(direccion);
await direccion.screenshot({ path: `${outDir}/responsabilidades-docentes-arbol.png`, fullPage: true });

await direccion.getByRole('button', { name: 'Bases de Datos', exact: true }).first().click();
await direccion.waitForSelector('text=Editar elemento');
await direccion.waitForSelector('[data-testid="teachers-panel"] .ts-wrapper');   // Tom Select has built its control
await settle(direccion);
await hideToolbar(direccion);
await direccion.screenshot({ path: `${outDir}/responsabilidades-docentes-panel.png`, fullPage: true });

// ── 2. The activity's form with the box checked ──────────────────────────────
await direccion.goto(`${baseUrl}/actividades?tab=view`);
await settle(direccion);
await direccion.click('text=Sobre programaciones didácticas');
const card = direccion.locator('[data-testid="activity-card"]', { hasText: activityTitle }).first();
await card.waitFor();
await card.locator('[data-live-action-param="startEditActivity"]').evaluate((el) => el.click());
const box = direccion.locator('[id^="activity-form-teachers-deliver-"]').first();
await box.waitFor();
await settle(direccion);
await box.scrollIntoViewIfNeeded();
await hideToolbar(direccion);
const form = await card.boundingBox();
const at   = await box.boundingBox();
if (!form || !at) throw new Error('No se encuentra el formulario de la actividad.');
const top = Math.max(form.y, at.y - 330);
await direccion.screenshot({
    path: `${outDir}/actividades-docentes-casilla.png`,
    fullPage: true,
    clip: { x: form.x, y: top, width: form.width, height: Math.min(at.y + at.height + 150, form.y + form.height) - top },
});
await direccion.context().close();

// ── 3. «Entregas que gestionas» of the head of Informática, with the teachers under each delivery ──
const claudia = await login('c.núñez', 'prueba');
await claudia.goto(`${baseUrl}/actividades?tab=view`);
await settle(claudia);
await claudia.click('text=Sobre programaciones didácticas');
const mine = claudia.locator('[data-testid="activity-card"]', { hasText: activityTitle }).first();
await mine.waitFor();
await mine.locator('[data-testid="activity-toggle"]').click();
await mine.locator('text=Entregas que gestionas').first().waitFor();
await mine.locator('button:has-text("Entregas que gestionas")').first().click();
await mine.locator('[data-testid="slot-teachers"]').first().waitFor();
await settle(claudia);
await mine.scrollIntoViewIfNeeded();
await hideToolbar(claudia);
const cardBox = await mine.boundingBox();
if (!cardBox) throw new Error('No se encuentra la actividad de Claudia.');
await claudia.screenshot({ path: `${outDir}/actividades-docentes-entregas.png`, fullPage: true, clip: { x: cardBox.x, y: cardBox.y, width: cardBox.width, height: Math.min(cardBox.height, 1100) } });
await claudia.context().close();

await browser.close();
