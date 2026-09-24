/**
 * Captures the desktop screenshots of the centre-management screens in the manual
 * (docs/manual/img/): "Preparar el nuevo curso", the trash ("Papelera"), read acknowledgement
 * (it confirms the reading of the demo policy as "calidad") and the activity log with its export
 * buttons, against the demo data from `app:load-demo-data` (IES Ada Lovelace).
 *
 * To have something in the trash, it DELETES an activity ("Auditoría interna (planificación)")
 * and a submission of "Plan de Acción Tutorial (PAT)" first — so it requires a server already
 * running at SHOTS_BASE_URL with a DISPOSABLE database seeded with that data, never the real
 * one. Run it last, after the other capture scripts: they expect those two still there.
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

async function login(username) {
    await page.context().clearCookies();
    await page.goto(`${baseUrl}/login`);
    await page.fill('#username', username);
    await page.fill('#password', username);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    if (page.url().includes('/seleccion/centro')) {
        await page.click('text=IES Ada Lovelace');
        await page.waitForLoadState('networkidle');
    }
}

/** Clicks the accept button of the app's confirmation dialog (confirm_controller.js). */
async function acceptConfirmDialog() {
    await page.locator('[role="dialog"] .js-confirm-accept').click();
    await page.waitForLoadState('networkidle');
}

await login('direccion');

// ── 1. Preparar el nuevo curso ───────────────────────────────────────────────
await page.goto(`${baseUrl}/`);
await page.click('a:has-text("Centro educativo")');
await page.waitForLoadState('networkidle');
await page.click('a:has-text("Cursos académicos")');
await page.waitForLoadState('networkidle');
await page.click('a:has-text("Preparar el nuevo curso")');
await page.waitForLoadState('networkidle');
await hideToolbar();
await page.screenshot({ path: `${outDir}/curso-preparar.png`, fullPage: true });

// ── 2. Fill the trash: an activity and a submission ──────────────────────────
await page.goto(`${baseUrl}/actividades?tab=view`);
await page.waitForLoadState('networkidle');
await page.locator('button:has-text("Seguimiento del SGC")').first().click();
// The innermost match (last in document order) is the activity's own card, not the page's containers.
const audit = page.locator('div.rounded-2xl', { has: page.locator('h3:text-is("Auditoría interna (planificación)")') }).last();
await audit.waitFor();
await audit.locator('button[data-action="dropdown#toggle"]').click();
await audit.locator('button[data-live-action-param="askDeleteActivity"]').click();
await audit.locator('button[data-live-action-param="deleteActivity"]').click();
await page.waitForLoadState('networkidle');
await page.waitForTimeout(500);

await page.goto(`${baseUrl}/actividades?tab=view`);
await page.waitForLoadState('networkidle');
await page.click('text=Sobre planes de acción tutorial');
await page.click('text=Plan de Acción Tutorial (PAT)');
await page.waitForSelector('text=Mis entregas');
const pending = page.locator('[data-role="submission-row"]', { hasText: 'Pendiente de visto bueno' });
if (await pending.count() === 0) {
    await page.click('text=Todas las entregas');
    await page.waitForTimeout(300);
}
await pending.first().locator('button[data-live-action-param="askDeleteDocument"]').click();
await page.locator('button[data-live-action-param="deleteDocument"]').first().click();
await page.waitForLoadState('networkidle');
await page.waitForTimeout(500);

// ── 3. Papelera ──────────────────────────────────────────────────────────────
await page.click('a:has-text("Papelera")');
await page.waitForLoadState('networkidle');
await page.waitForSelector('[data-trash-item]');
await hideToolbar();
await page.screenshot({ path: `${outDir}/papelera.png` });

// ── 4. Read acknowledgement: the policy (uploaded by "direccion") ────────────
async function openPolicyFolder() {
    await page.goto(`${baseUrl}/arbol-documental`);
    await page.waitForLoadState('networkidle');
    await page.click('text=5. Liderazgo');
    await page.click('text=5.2 Política');
    await page.locator('button[data-live-action-param="toggleFolder"]', { hasText: 'Política de Calidad y Objetivos' }).click();
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(300);
}

// A reader: the dashboard card, then the document in the tree, still to read.
await login('calidad');
await page.goto(`${baseUrl}/`);
await page.waitForLoadState('networkidle');
await page.locator('h2:has-text("Documentos por leer")').evaluate(el => el.scrollIntoView({ block: 'center' }));
await hideToolbar();
await page.screenshot({ path: `${outDir}/lectura-panel.png` });

await openPolicyFolder();
await page.waitForSelector('button:has-text("Confirmar lectura")');
await hideToolbar();
await page.screenshot({ path: `${outDir}/lectura-confirmar.png` });
await page.click('button:has-text("Confirmar lectura")');
await page.waitForSelector('text=Leído el');

// Whoever manages the folder: who has read it and who hasn't.
await login('direccion');
await openPolicyFolder();
await page.click('button[data-live-action-param="toggleReadStatus"]');
await page.waitForSelector('text=Sin leer');
await hideToolbar();
await page.screenshot({ path: `${outDir}/lectura-estado.png` });

// ── 5. Activity log, with a filter applied and the export buttons ────────────
await login('admin');
await page.goto(`${baseUrl}/admin/registro-actividad`);
await page.waitForLoadState('networkidle');
await page.click('button[data-live-range-param="last_24h"]');
await page.waitForLoadState('networkidle');
await page.waitForTimeout(500);
await hideToolbar();
await page.screenshot({ path: `${outDir}/registro-actividad.png` });

await browser.close();
console.log('Capturas guardadas en', outDir);
