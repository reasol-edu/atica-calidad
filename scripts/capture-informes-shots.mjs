/**
 * Captures the screenshots of the "Informes" chapter of the manual (docs/manual/img/informes-*.png):
 * the reports page, the on-screen compliance report and the first page of several PDF reports,
 * rendered with `pdftoppm` (poppler) — so that tool must be installed.
 *
 * Uses the demo data from `app:load-demo-data`. Read-only (it only downloads reports). Requires a
 * server already running at SHOTS_BASE_URL on a DISPOSABLE database.
 */
import { chromium } from 'playwright';
import { mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const outDir  = process.env.SHOTS_OUT_DIR ?? 'docs/manual/img';

mkdirSync(outDir, { recursive: true });

const browser = await chromium.launch({ args: ['--lang=es-ES'] });
const page    = await browser.newPage({ viewport: { width: 1360, height: 900 }, locale: 'es-ES' });

async function hideToolbar() {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
    // A full-page capture of a page that was scrolled (after a click) misplaces the fixed sidebar.
    await page.evaluate(() => window.scrollTo(0, 0));
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
const reports = `${baseUrl}/centro/${centreId}/informes`;

// ── The reports page, and the one report that is also shown on screen ───────
await page.goto(reports);
await page.waitForLoadState('networkidle');
await hideToolbar();
await page.screenshot({ path: `${outDir}/informes-indice.png`, fullPage: true });

await page.goto(`${reports}/cumplimiento-de-actividades`);
await page.waitForLoadState('networkidle');
await hideToolbar();
await page.screenshot({ path: `${outDir}/informes-cumplimiento.png`, fullPage: true });

// ── First page of some PDF reports ──────────────────────────────────────────
const work = join(tmpdir(), `atica-informes-${process.pid}`);
mkdirSync(work, { recursive: true });
for (const [slug, name] of [
    ['listado-maestro', 'listado-maestro'],
    ['revisiones-de-documentos', 'revisiones'],
    ['estado-de-actividades', 'estado-actividades'],
    ['perfiles-asignados', 'perfiles-asignados'],
    ['no-conformidades', 'no-conformidades'],
    ['indicadores', 'indicadores'],
]) {
    const response = await page.context().request.get(`${reports}/${slug}.pdf`);
    if (!response.ok()) {
        throw new Error(`${slug}.pdf answered ${response.status()}`);
    }
    const pdf = join(work, `${name}.pdf`);
    writeFileSync(pdf, await response.body());
    execFileSync('pdftoppm', ['-png', '-r', '90', '-f', '1', '-l', '1', '-singlefile', pdf, `${outDir}/informes-pdf-${name}`]);
}
rmSync(work, { recursive: true, force: true });

await browser.close();
