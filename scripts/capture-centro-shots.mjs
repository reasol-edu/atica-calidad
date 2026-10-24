/**
 * Captures the screenshots of "Centro educativo" for the manual (docs/manual/img/centro-*.png):
 * the hub, academic years, the course's teachers (list and the Séneca import preview), the fixed
 * profiles, non-working days, the e-mail notification log and the centre settings. They go in
 * "Preparar el curso académico" and "Administrar el centro educativo".
 *
 * Uses the demo data from `app:load-demo-data` (see skills/screenshots.md): 16 non-working days,
 * 8 logged e-mails (one of them failed), a former teacher («r.fuentes») who is not in the course.
 * The Séneca preview uploads scripts/fixtures/seneca-docentes.csv but NEVER confirms it, so the
 * script changes nothing. Requires a server already running at SHOTS_BASE_URL on a DISPOSABLE
 * database.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const baseUrl  = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const outDir   = process.env.SHOTS_OUT_DIR ?? 'docs/manual/img';
const fixtures = new URL('./fixtures/', import.meta.url).pathname;

mkdirSync(outDir, { recursive: true });

const browser = await chromium.launch({ args: ['--lang=es-ES'] });
const page    = await browser.newPage({ viewport: { width: 1360, height: 900 }, locale: 'es-ES' });

async function hideToolbar() {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
    // A full-page capture of a page that was scrolled (after a click) misplaces the fixed sidebar.
    await page.evaluate(() => window.scrollTo(0, 0));
}

async function open(path) {
    await page.goto(`${baseUrl}${path}`);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(250);
    await hideToolbar();
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

const centreId = (await page.$$eval('a[href*="/centro/"]', (as) => as.map((a) => a.getAttribute('href'))))
    .map((h) => h.match(/\/centro\/([0-9a-f-]{36})/)?.[1])
    .find(Boolean);
const centre = `/centro/${centreId}`;

// ── Hub, years, profiles, settings ──────────────────────────────────────────
await open(centre);
await page.screenshot({ path: `${outDir}/centro-inicio.png` });

await open(`${centre}/cursos`);
await page.screenshot({ path: `${outDir}/centro-cursos.png` });

await open(`${centre}/perfiles`);
await page.screenshot({ path: `${outDir}/centro-perfiles.png`, clip: { x: 256, y: 90, width: 1104, height: 480 } });

await open(`${centre}/ajustes`);
await page.screenshot({ path: `${outDir}/centro-ajustes.png` });

// ── Teachers: list, then the import preview (never confirmed) ───────────────
await open(`${centre}/docentes-curso`);
await page.screenshot({ path: `${outDir}/centro-docentes.png` });

await open(`${centre}/docentes-curso/importar`);
await page.screenshot({ path: `${outDir}/centro-docentes-importar.png` });
await page.setInputFiles('input[type="file"]', `${fixtures}seneca-docentes.csv`);
await page.locator('form:has(input[type="file"]) button[type="submit"]').first().click();
await page.waitForLoadState('networkidle');
await page.waitForTimeout(400);
await hideToolbar();
await page.screenshot({ path: `${outDir}/centro-docentes-previsualizacion.png`, fullPage: true });

// Second option: the teachers who would be taken out of the course (those who are not in the file
// and hold no responsibility), listed so that any of them can be kept.
await page.getByText('Retirar del curso a los docentes que no figuran en el listado').click();
await page.waitForTimeout(500);
await hideToolbar();
await page.screenshot({ path: `${outDir}/centro-docentes-retirar.png`, fullPage: true });

// ── Non-working days ────────────────────────────────────────────────────────
await open(`${centre}/dias-no-lectivos`);
await page.screenshot({ path: `${outDir}/centro-dias-no-lectivos.png` });

// ── E-mail notification log: wide enough to see the «Resultado» column ──────
await page.setViewportSize({ width: 1700, height: 700 });
await open(`${centre}/registro-avisos`);
await page.screenshot({ path: `${outDir}/centro-registro-avisos.png` });

await browser.close();
