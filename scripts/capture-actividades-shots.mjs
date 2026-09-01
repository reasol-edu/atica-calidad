/**
 * Captures the desktop screenshots for the "Activities" chapter of the manual
 * (docs/manual/img/actividades-*.png), against the demo data from
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

// ── 1. Main panel (dashboard) ────────────────────────────────────────────────
await page.goto(`${baseUrl}/`);
await page.waitForLoadState('networkidle');
await hideToolbar();
await page.screenshot({ path: `${outDir}/actividades-dashboard.png` });

// ── 2. My activities ─────────────────────────────────────────────────────────
await page.goto(`${baseUrl}/actividades?tab=mine`);
await page.waitForLoadState('networkidle');
await hideToolbar();
await page.screenshot({ path: `${outDir}/actividades-mias.png` });

// ── 3. View (categories) ─────────────────────────────────────────────────────
await page.goto(`${baseUrl}/actividades?tab=view`);
await page.waitForLoadState('networkidle');
await hideToolbar();
await page.screenshot({ path: `${outDir}/actividades-ver.png` });

// ── 4. Submissions with Approve/Reject visible ───────────────────────────────
await page.click('text=Sobre planes de acción tutorial');
await page.waitForSelector('text=Plan de Acción Tutorial (PAT)');
await page.click('text=Plan de Acción Tutorial (PAT)');
await page.waitForSelector('text=Mis entregas');
await hideToolbar();

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
    await toggles.nth(i).click();
}
if (!found) {
    // "Todas las entregas" (collapsed by default) might have the one we're looking for.
    await page.click('text=Todas las entregas');
    await page.waitForTimeout(300);
    const toggles2 = page.locator('button[data-live-action-param="toggleRevisionPanel"]');
    const count2 = await toggles2.count();
    for (let i = 0; i < count2; i++) {
        await toggles2.nth(i).click();
        await page.waitForTimeout(300);
        if (await page.locator('button:has-text("Aprobar")').isVisible().catch(() => false)) {
            found = true;
            break;
        }
        await toggles2.nth(i).click();
    }
}
if (!found) throw new Error('No se encontró ninguna entrega pendiente con Aprobar/Rechazar visibles.');
await page.locator('button:has-text("Aprobar")').first().evaluate(el =>
    el.closest('div').scrollIntoView({ block: 'center' })
);
await page.evaluate(() => window.scrollBy(0, -220));
await hideToolbar();
await page.screenshot({ path: `${outDir}/actividades-entregas.png` });

// ── 5. Confirm manual completion ─────────────────────────────────────────────
await page.goto(`${baseUrl}/actividades?tab=view`);
await page.waitForLoadState('networkidle');
await page.click('text=Sensibilización y compromiso');
await page.waitForTimeout(300);
const readingActivity = page.locator('text=Lectura y conformidad con la Política de Calidad').locator('visible=true');
await readingActivity.first().waitFor();
await readingActivity.first().click();
await page.waitForSelector('text=Marcar como completada');
await hideToolbar();
await page.click('button[data-live-action-param="askMarkCompleted"] >> nth=0');
await page.waitForSelector('text=¿Confirmar como completada?');
await hideToolbar();
await page.screenshot({ path: `${outDir}/actividades-completar.png` });

// ── 6. Edit categories (admin) ───────────────────────────────────────────────
await page.goto(`${baseUrl}/actividades?tab=edit`);
await page.waitForLoadState('networkidle');
await hideToolbar();
await page.screenshot({ path: `${outDir}/actividades-editar-categorias.png` });

await browser.close();
console.log('Capturas guardadas en', outDir);
