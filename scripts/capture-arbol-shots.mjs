/**
 * Captures the desktop screenshots for the "Document Tree" chapter of the manual
 * (docs/manual/img/arbol-*.png), against the demo data from
 * `app:load-demo-data` (IES Ada Lovelace).
 *
 * Requires a server already running at SHOTS_BASE_URL with that data seeded
 * — never against the real database.
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

await page.goto(`${baseUrl}/arbol-documental`);
await page.waitForLoadState('networkidle');
await hideToolbar();

// ── 1. Edit tree: section structure ──────────────────────────────────────────
await page.click('text=Editar árbol');
await page.waitForTimeout(300);
await hideToolbar();
await page.screenshot({ path: `${outDir}/arbol-editar.png` });

// ── Navigate to Ver → 8. Operación → 8.1 → Programaciones didácticas ────────
await page.click('text=Ver');
await page.waitForTimeout(200);
await page.click('text=8. Operación');
await page.waitForSelector('text=8.1 Planificación y control operacional');
await page.click('text=8.1 Planificación y control operacional');
await page.waitForSelector('text=Programaciones didácticas');
await page.waitForLoadState('networkidle');

// Expand the "Programaciones didácticas" folder (collapsed by default)
await page.locator('button[data-live-action-param="toggleFolder"]', { hasText: 'Programaciones didácticas' }).click();
await page.waitForSelector('text=Lengua Castellana y Literatura');
await hideToolbar();

// ── 2. Contents of a folder, with a pending revision ─────────────────────────
await page.screenshot({ path: `${outDir}/arbol-carpeta-contenido.png` });

// ── 3. Folder settings (toggles + profile lists) ──────────────────────────────
await page.click('button[data-live-action-param="toggleFolderSettings"]');
await page.waitForSelector('text=Perfiles responsables');
await hideToolbar();
await page.screenshot({ path: `${outDir}/arbol-carpeta-ajustes.png` });
await page.click('button[data-live-action-param="toggleFolderSettings"]');
await page.waitForTimeout(300);

// ── 4. Version panel of a document with Approve/Reject visible ───────────────
// The genuinely pending submission for "Programaciones didácticas" is not
// visible; "Planes de Acción Tutorial" has one of its two sample submissions
// pending (individual scope, simpler).
await page.locator('button[data-live-action-param="toggleFolder"]', { hasText: 'Planes de Acción Tutorial' }).click();
await page.waitForTimeout(500);

const toggles = page.locator('button[data-live-action-param="toggleRevisionPanel"]');
const toggleCount = await toggles.count();
let found = false;
for (let i = 0; i < toggleCount; i++) {
    await toggles.nth(i).click();
    await page.waitForTimeout(300);
    if (await page.locator('button:has-text("Aprobar")').isVisible().catch(() => false)) {
        found = true;
        break;
    }
    await toggles.nth(i).click(); // close again before trying the next one
}
if (!found) throw new Error('No se encontró ninguna revisión pendiente con botones Aprobar/Rechazar visibles.');
await hideToolbar();
await page.locator('button:has-text("Aprobar")').first().scrollIntoViewIfNeeded();
await page.screenshot({ path: `${outDir}/arbol-revisiones.png` });

// ── 5. Global search ──────────────────────────────────────────────────────────
await page.click('text=Raíz');
await page.waitForLoadState('networkidle');
await page.fill('input[placeholder*="Buscar documentos"]', 'program');
await page.waitForTimeout(1500);
await hideToolbar();
await page.screenshot({ path: `${outDir}/arbol-busqueda-global.png` });

await browser.close();
console.log('Capturas guardadas en', outDir);
