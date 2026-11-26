/**
 * Captures the screenshots of the activities with «Una entrega por» (one submission per combination of an element with the values of
 * other lists) of chapter 8 (docs/manual/img/actividades-una-entrega-por-*.png), against the demo data from `app:load-demo-data` PLUS
 * `app:load-demo-module-programs` PLUS `app:load-demo-evaluations` — see skills/screenshots.md.
 *
 * Requires a server already running at SHOTS_BASE_URL on a DISPOSABLE database seeded with that data (never the real one). Read-only:
 * it logs in as `direccion` (the activity form and the card) and `n.bravo` (her tasks), and never saves anything.
 *
 * Env: SHOTS_BASE_URL (default http://127.0.0.1:8744), SHOTS_OUT_DIR (default docs/manual/img).
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const outDir  = process.env.SHOTS_OUT_DIR ?? 'docs/manual/img';
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
await noelia.context().close();

await browser.close();
