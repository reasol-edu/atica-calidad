/**
 * Captures the desktop screenshots of the Utilidades screens in the manual (docs/manual/img/):
 * the calendar generator's list and edit form (periods), and the date calculator with a result —
 * against the demo data from `app:load-demo-data` (IES Ada Lovelace).
 *
 * Nothing in the demo data seeds a printable calendar (it's personal, per-teacher data), so this
 * creates one along the way — needs a server already running at SHOTS_BASE_URL with a
 * DISPOSABLE database, never the real one.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const outDir  = process.env.SHOTS_OUT_DIR ?? 'docs/manual/img';

mkdirSync(outDir, { recursive: true });

const browser = await chromium.launch({ args: ['--lang=es-ES'] });
const page    = await browser.newPage({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' });

async function hideToolbar() {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
}

await page.goto(`${baseUrl}/login`);
await page.fill('#username', 'direccion');
await page.fill('#password', 'direccion');
await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle');
if (page.url().includes('/seleccion/centro')) {
    await page.click('text=IES Ada Lovelace');
    await page.waitForLoadState('networkidle');
}

// ── 1. Nuevo calendario, con un periodo "Desde el inicio, con horas" ─────────
await page.goto(`${baseUrl}/utilidades/generador-calendarios/nuevo`);
await page.waitForLoadState('networkidle');
await hideToolbar();
await page.fill('#cal-title', 'FFEOE 2º DAM');
await page.locator('.ql-editor').click();
await page.keyboard.type('Seguimiento de la FFEOE.');

await page.fill('input[name="periods[0][description]"]', 'FFEOE');
// The mode radios are visually hidden (sr-only) — the label around each one is what's clickable —
// so check() needs force: true rather than clicking the label text (which the toolbar can occlude).
await page.locator('input[name="periods[0][mode]"][value="start_with_hours"]').check({ force: true });
await page.click('input[name="periods[0][showJourneySummary]"]');
// startDate/totalHours exist in more than one mode's field block (only one is visible at a time).
await page.fill('input[name="periods[0][startDate]"]:visible', '2027-03-01');
await page.fill('input[name="periods[0][totalHours]"]:visible', '160');
for (const day of ['monday', 'tuesday', 'wednesday', 'thursday', 'friday']) {
    await page.fill(`input[name="periods[0][${day}Hours]"]:visible`, '6');
}

await page.click('button:has-text("Guardar")');
await page.waitForLoadState('networkidle');

await page.locator('h2:has-text("Periodos")').evaluate((el) => el.scrollIntoView({ block: 'start' }));
await hideToolbar();
await page.screenshot({ path: `${outDir}/utilidades-calendario-periodos.png` });

// ── 2. Listado del generador de calendarios ──────────────────────────────────
await page.goto(`${baseUrl}/utilidades/generador-calendarios`);
await page.waitForLoadState('networkidle');
await hideToolbar();
await page.screenshot({ path: `${outDir}/utilidades-generador-calendarios.png` });

// ── 3. Calculadora de fechas, con un resultado ───────────────────────────────
await page.goto(`${baseUrl}/utilidades/calculadora-fechas`);
await page.waitForLoadState('networkidle');
await hideToolbar();
await page.fill('input[name="startDate"]:visible', '2027-03-01');
await page.fill('input[name="endDate"]:visible', '2027-03-19');
for (const day of ['monday', 'tuesday', 'wednesday', 'thursday', 'friday']) {
    await page.fill(`input[name="${day}Hours"]`, '6');
}
await page.click('button:has-text("Calcular")');
await page.waitForLoadState('networkidle');
await hideToolbar();
await page.screenshot({ path: `${outDir}/utilidades-calculadora-fechas.png` });

await browser.close();
console.log('Capturas guardadas en', outDir);
