/**
 * Captures the desktop screenshots of the "Mejora continua" chapter of the manual
 * (docs/manual/img/mejora-*.png), against the demo data from `app:load-demo-data` (IES Ada
 * Lovelace), whose five sample findings cover every step and whose improvement plan has an action
 * in each state. Read-only: it opens forms but submits
 * nothing, so it can run in any order with the other capture scripts — still, only against a
 * disposable database (see docs/manual/README.md).
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

async function login(username, password = username) {
    await page.context().clearCookies();
    await page.goto(`${baseUrl}/login`);
    await page.fill('#username', username);
    await page.fill('#password', password);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    if (page.url().includes('/seleccion/centro')) {
        await page.click('text=IES Ada Lovelace');
        await page.waitForLoadState('networkidle');
    }
}

async function shot(name, options = {}) {
    await page.waitForLoadState('networkidle');
    await hideToolbar();
    await page.screenshot({ path: `${outDir}/${name}.png`, ...options });
}

async function openFinding(title) {
    await page.goto(`${baseUrl}/mejora/fichas`);
    await page.waitForLoadState('networkidle');
    await page.click(`a:has-text("${title}") >> visible=true`);
    await page.waitForLoadState('networkidle');
}

// ── A teacher: reporting an incident ─────────────────────────────────────────
await login('c.núñez', 'prueba');
await page.goto(`${baseUrl}/mejora/comunicar`);
await page.fill('#report-description', 'La impresora de la sala de profesores atasca el papel cada pocas hojas\nLlevamos así desde el lunes.');
await shot('mejora-comunicar');

// ── The quality manager: hub with the inbox, classifying, the board ──────────
await login('calidad');
await page.goto(`${baseUrl}/mejora`);
await shot('mejora-portada');

// ">> visible=true": the bell's closed dropdown has the same links.
await page.click('a:has-text("El proyector del aula 12") >> visible=true');
await page.waitForLoadState('networkidle');
await page.check('input[name="kind"][value="nonconformity"]');
await page.waitForSelector('#classify-responsible');
await shot('mejora-clasificar');

await page.goto(`${baseUrl}/mejora/fichas?view=board`);
await shot('mejora-tablero');

await openFinding('La Política de Calidad no llega');
await shot('mejora-ficha', { fullPage: true });

// ── The quality manager: the improvement plan ────────────────────────────────
await page.goto(`${baseUrl}/mejora/plan`);
await shot('mejora-plan', { fullPage: true });

// ── The quality manager: indicators ──────────────────────────────────────────
await page.goto(`${baseUrl}/mejora/indicadores`);
await shot('mejora-indicadores', { fullPage: true });

await page.click('a:has-text("Alumnado con tres o más materias suspensas") >> visible=true');
await page.waitForLoadState('networkidle');
await shot('mejora-indicador', { fullPage: true });

await page.goto(`${baseUrl}/mejora/indicadores/calendarios`);
await shot('mejora-calendarios-medicion', { fullPage: true });
await page.click('a:has-text("Evaluaciones") >> visible=true');
await page.waitForLoadState('networkidle');
await shot('mejora-calendario-medicion', { fullPage: true });

await page.goto(`${baseUrl}/mejora/indicadores/nuevo`);
await page.fill('#ind-name', 'Alumnado que titula en 4.º de ESO');
await page.fill('#ind-description', 'Alumnado que obtiene el título / alumnado de 4.º de ESO × 100');
await shot('mejora-indicador-nuevo', { fullPage: true });

await page.goto(`${baseUrl}/mejora/plan/nueva`);
await page.fill('#plan-description', 'Organizar una jornada de buenas prácticas entre departamentos');
await page.fill('#plan-goal', 'Que cada departamento comparta al menos una práctica que le haya funcionado.');
await shot('mejora-plan-nueva', { fullPage: true });

// ── The analyst: "Tus próximos pasos" and the cause analysis ────────────────
await login('a.ruiz', 'prueba');
await page.goto(`${baseUrl}/`);
await shot('mejora-proximos-pasos');

await page.click('a:has-text("Programaciones didácticas entregadas fuera de plazo") >> visible=true');
await page.waitForLoadState('networkidle');
await shot('mejora-analisis', { fullPage: true });

// Her plan action: its page, the bell and the calendar.
await page.goto(`${baseUrl}/mejora`);
await page.click('a:has-text("Revisar con cada departamento el calendario de entregas") >> visible=true');
await page.waitForLoadState('networkidle');
await shot('mejora-accion', { fullPage: true });

await page.click('button[aria-label="Notificaciones"]');
await page.waitForTimeout(300);
await shot('mejora-campana');

await page.goto(`${baseUrl}/calendario`);
await shot('mejora-calendario');

// Her indicator: the value to record.
await page.goto(`${baseUrl}/mejora`);
await page.click('a:has-text("Registrar: Alumnado con todas las materias aprobadas") >> visible=true');
await page.waitForLoadState('networkidle');
await page.goto(page.url().split('#')[0]); // from the top, not scrolled to the period
await shot('mejora-indicador-registrar', { fullPage: true });

// ── The management team: last year's full board ─────────────────────────────
// (Switching the year being viewed is theirs; it only changes their own session.)
await login('direccion');
await page.goto(`${baseUrl}/curso/año`);
// Newest first: the second is last year.
await page.locator('form[action*="/curso/"] button[type="submit"]').nth(1).click();
await page.waitForLoadState('networkidle');
await page.goto(`${baseUrl}/mejora/indicadores`);
await shot('mejora-indicadores-anterior', { fullPage: true });

// ── Internal audits: the quality manager ─────────────────────────────────────
await login('calidad');
await page.goto(`${baseUrl}/mejora/auditorias`);
await shot('mejora-auditorias', { fullPage: true });

await page.click('li a:has-text("Política de calidad") >> visible=true');
await page.waitForLoadState('networkidle');
await shot('mejora-auditoria-informe', { fullPage: true });

await page.goto(`${baseUrl}/mejora/auditorias`);
await page.click('li a:has-text("Planificación y control operacional") >> visible=true');
await page.waitForLoadState('networkidle');
await shot('mejora-auditoria-independencia');

await page.goto(`${baseUrl}/mejora/auditorias`);
await page.click('li a:has-text("Seguimiento, medición y análisis") >> visible=true');
await page.waitForLoadState('networkidle');
await page.goto(page.url() + '/preparar');
await shot('mejora-auditoria-preparar', { fullPage: true });

await page.goto(`${baseUrl}/mejora/auditorias/nueva`);
await page.fill('#audit-title', 'Información documentada');
await shot('mejora-auditoria-nueva', { fullPage: true });

await page.goto(`${baseUrl}/mejora/auditorias/listas`);
await shot('mejora-listas-comprobacion', { fullPage: true });

// ── The internal auditor, on a tablet: carrying out the audit under way ──────
const tablet = await browser.newPage({ viewport: { width: 820, height: 1180 }, locale: 'es-ES' });
await tablet.goto(`${baseUrl}/login`);
await tablet.fill('#username', 'i.campos');
await tablet.fill('#password', 'prueba');
await tablet.click('button[type="submit"]');
await tablet.waitForLoadState('networkidle');
await tablet.goto(`${baseUrl}/mejora/auditorias`);
await tablet.click('li a:has-text("Planificación y control operacional") >> visible=true');
await tablet.waitForLoadState('networkidle');
await tablet.goto(tablet.url() + '/realizar');
await tablet.waitForLoadState('networkidle');
await tablet.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
await tablet.screenshot({ path: `${outDir}/mejora-auditoria-realizar.png`, fullPage: true });

await browser.close();
console.log('Capturas guardadas en', outDir);
