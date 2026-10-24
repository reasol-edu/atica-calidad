/**
 * Captures the screenshots about getting in and choosing a centre (docs/manual/img/acceso-*.png,
 * used by the introduction and by "Varios centros en un mismo servidor"): the login page, the
 * centre selection screen and the sidebar header with its "change centre" button.
 *
 * Needs the demo data from `app:load-demo-data`: the user «multicentro» belongs to both the IES
 * Ada Lovelace and the CEIP Miguel Hernández, so they are the only one who is asked to choose.
 * Read-only. Requires a server already running at SHOTS_BASE_URL on a DISPOSABLE database.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const outDir  = process.env.SHOTS_OUT_DIR ?? 'docs/manual/img';

mkdirSync(outDir, { recursive: true });

const browser = await chromium.launch({ args: ['--lang=es-ES'] });
const page    = await browser.newPage({ viewport: { width: 1360, height: 800 }, locale: 'es-ES' });

async function hideToolbar() {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
    // A full-page capture of a page that was scrolled (after a click) misplaces the fixed sidebar.
    await page.evaluate(() => window.scrollTo(0, 0));
}

// ── 1. Login ────────────────────────────────────────────────────────────────
await page.goto(`${baseUrl}/login`);
await page.waitForLoadState('networkidle');
await hideToolbar();
await page.screenshot({ path: `${outDir}/acceso-login.png` });

// ── 2. Centre selection, for a user who belongs to two centres ──────────────
await page.fill('#username', 'multicentro');
await page.fill('#password', 'multicentro');
await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle');
await page.waitForURL('**/seleccion/centro');
await hideToolbar();
await page.screenshot({ path: `${outDir}/acceso-seleccion-centro.png` });

// ── 3. Once inside: the sidebar header, with the button that changes centre ─
await page.click('text=IES Ada Lovelace');
await page.waitForLoadState('networkidle');
await hideToolbar();
await page.screenshot({ path: `${outDir}/acceso-cambiar-centro.png`, clip: { x: 0, y: 0, width: 256, height: 190 } });

await browser.close();
