/**
 * Captures the screenshots of "Administrar la plataforma" for the manual
 * (docs/manual/img/plataforma-*.png): the admin panel, centres, teachers, and the global settings
 * (a very long page, so one image per section the chapter describes).
 *
 * Logs in as the platform administrator («admin») of the demo data from `app:load-demo-data`.
 * The database must have been built with the MIGRATIONS (not `schema:create`): the settings
 * catalogue is seeded by a migration, and without it the settings pages are empty.
 * Read-only. Requires a server already running at SHOTS_BASE_URL on a DISPOSABLE database.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

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

async function open(path) {
    await page.goto(`${baseUrl}${path}`);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(250);
    await hideToolbar();
}

await page.goto(`${baseUrl}/login`);
await page.fill('#username', 'admin');
await page.fill('#password', 'admin');
await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle');
if (page.url().includes('/seleccion/centro')) {
    await page.click('text=IES Ada Lovelace');
    await page.waitForLoadState('networkidle');
}

await open('/admin');
await page.screenshot({ path: `${outDir}/plataforma-panel.png` });

await open('/admin/centros');
await page.screenshot({ path: `${outDir}/plataforma-centros.png` });
await page.locator('tr', { hasText: 'IES Ada Lovelace' }).getByRole('link', { name: 'Editar' }).click();
await page.waitForLoadState('networkidle');
await hideToolbar();
await page.screenshot({ path: `${outDir}/plataforma-centro-editar.png`, fullPage: true });

await open('/admin/docentes');
await page.screenshot({ path: `${outDir}/plataforma-docentes.png` });
await page.locator('tr', { hasText: 'multicentro' }).getByRole('link', { name: 'Editar' }).click();
await page.waitForLoadState('networkidle');
await hideToolbar();
await page.screenshot({ path: `${outDir}/plataforma-docente-editar.png`, fullPage: true });

// ── Global settings: one image per category, as the chapter explains them ───
await open('/admin/ajustes');
for (const [heading, name] of [
    ['Curso académico', 'curso'],
    ['Avisos por correo', 'avisos'],
    ['Plantillas de informes', 'plantillas'],
    ['Seguridad', 'seguridad'],
    ['Registro de actividad', 'registro'],
    ['Papelera', 'papelera'],
    ['Subida de ficheros', 'subida'],
]) {
    await page
        .locator('section', { has: page.getByRole('heading', { name: heading, exact: true }) })
        .screenshot({ path: `${outDir}/plataforma-ajustes-${name}.png` });
}

// ── "Acceder como": the administrator sees the application as another teacher ──
await open('/admin/docentes');
await page.locator('tr', { hasText: 'e.cabrera' }).getByRole('link', { name: 'Acceder como' }).click();
await page.waitForLoadState('networkidle');
await page.waitForTimeout(300);
await hideToolbar();
await page.screenshot({ path: `${outDir}/plataforma-suplantacion.png` });
await page.goto(`${baseUrl}/?_switch_user=_exit`);   // leave the impersonation, as the banner's link does

await browser.close();
