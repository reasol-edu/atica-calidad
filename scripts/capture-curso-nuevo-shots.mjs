/**
 * Captura las capturas de escritorio de la ficha «Configurar un curso académico nuevo»
 * (docs/cheatsheets/curso-nuevo.md), dirigida al equipo directivo.
 *
 * A diferencia de scripts/capture-cheatsheet-shots.mjs (viewport móvil, datos ya sembrados),
 * este script usa el viewport de escritorio 1280×900 (la app se administra desde escritorio) y
 * ejecuta él mismo, de principio a fin, el flujo real de puesta a punto de un curso académico:
 * crea y activa el curso 2026-2027, importa profesorado desde un CSV de Séneca, crea la oferta
 * formativa (cursos y grupos) e importa los días no lectivos desde un .ics de muestra, y asigna
 * los perfiles de responsable de calidad y auditor/a interno/a.
 *
 * Requiere una BASE DE DATOS DESECHABLE PROPIA para esta ejecución: este script deja el curso
 * 2026-2027 como curso activo del centro, lo que cambiaría el curso de referencia que dan por
 * hecho el resto de capturas si se reutilizara la misma pasada.
 *
 * Los ficheros de muestra referenciados abajo (src/DataFixtures/data/) no existen todavía: la
 * aplicación aún no tiene datos de demostración (fixtures). Añádelos antes de ejecutar este
 * script, o adapta las rutas a los tuyos propios.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const baseUrl  = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const root     = process.env.SHOTS_OUT_DIR ?? 'docs/cheatsheets/img';
const centreId = process.env.SHOTS_CENTRE_ID;

mkdirSync(root, { recursive: true });

const browser = await chromium.launch({ args: ['--lang=es-ES'] });

async function hideToolbar(page) {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
}

async function login(page, username, password) {
    await page.goto(`${baseUrl}/login`);
    await page.fill('#username', username);
    await page.fill('#password', password);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');

    if (page.url().includes('/seleccion/centro')) {
        await page.click('button:has-text("IES Ada Lovelace")');
        await page.waitForLoadState('networkidle');
    }
    await hideToolbar(page);
}

const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' });
await login(page, 'admin', 'admin');

// ── 1. Crear y activar el curso académico ───────────────────────────────────
await page.goto(`${baseUrl}/centro/${centreId}/cursos`);
await page.waitForLoadState('networkidle');
await hideToolbar(page);

await page.fill('input[name="name"]', '2026-2027');
// Ojo: la página tiene varios "button[type=submit]" (el de añadir curso y uno por cada curso
// existente para eliminarlo); hay que acotar al formulario de alta o se puede pulsar el botón
// equivocado.
await page.locator('form[action*="/nuevo"] button[type="submit"]').click();
await page.waitForLoadState('networkidle');
await hideToolbar(page);

const newYearRow = page.locator('li', { hasText: '2026-2027' });
await newYearRow.waitFor();
await newYearRow.locator('button:has-text("Establecer activo")').click();
await page.waitForLoadState('networkidle');
await hideToolbar(page);
await page.screenshot({ path: `${root}/curso-nuevo-1-activado.png` });

// ── 2. Añadir el profesorado ─────────────────────────────────────────────────
await page.goto(`${baseUrl}/centro/${centreId}/docentes-curso/importar`);
await page.waitForLoadState('networkidle');
await hideToolbar(page);

await page.setInputFiles('#csv', 'src/DataFixtures/data/docentes-ada-lovelace.csv');
await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle');
await hideToolbar(page);
await page.screenshot({ path: `${root}/curso-nuevo-2-docentes-listado.png` });

// ── 3. Definir la oferta formativa ───────────────────────────────────────────
await page.goto(`${baseUrl}/centro/${centreId}/offer`);
await page.waitForLoadState('networkidle');
await hideToolbar(page);

await page.fill('input[data-model="norender|addCourseName"]', '1º ESO');
await page.locator('form[data-live-action-param="addCourse"] button[type="submit"]').click();
await page.waitForLoadState('networkidle');
await hideToolbar(page);

await page.fill('input[data-model="norender|addGroupName"]', '1ºESO A');
await page.locator('form[data-live-action-param="addGroup"] button[type="submit"]').click();
await page.waitForLoadState('networkidle');
await hideToolbar(page);
await page.screenshot({ path: `${root}/curso-nuevo-3-oferta-formativa.png` });

// Asignaciones docente-grupo-materia también importables desde CSV de Séneca:
await page.goto(`${baseUrl}/centro/${centreId}/docentes-curso/importar-asignaciones`);
await page.waitForLoadState('networkidle');
await hideToolbar(page);

await page.setInputFiles('#csv', 'src/DataFixtures/data/asignaciones-ada-lovelace.csv');
await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle');
await hideToolbar(page);
await page.screenshot({ path: `${root}/curso-nuevo-4-asignaciones-listado.png` });

// ── 4. Definir los días no lectivos ──────────────────────────────────────────
await page.goto(`${baseUrl}/centro/${centreId}/dias-no-lectivos/importar`);
await page.waitForLoadState('networkidle');
await hideToolbar(page);

await page.setInputFiles('#ics', 'src/DataFixtures/data/dias-no-lectivos-ada-lovelace.ics');
await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle');
await hideToolbar(page);
await page.screenshot({ path: `${root}/curso-nuevo-5-dias-no-lectivos.png` });

// ── 5. Asignar los perfiles de calidad ───────────────────────────────────────
await page.goto(`${baseUrl}/centro/${centreId}/perfiles`);
await page.waitForLoadState('networkidle');
await hideToolbar(page);
await page.screenshot({ path: `${root}/curso-nuevo-6-perfiles.png` });

// ── Resultado: el centro, listo para trabajar ────────────────────────────────
await page.goto(`${baseUrl}/centro/${centreId}`);
await page.waitForLoadState('networkidle');
await hideToolbar(page);
await page.screenshot({ path: `${root}/curso-nuevo-7-centro-listo.png` });

await page.close();
await browser.close();
console.log('Capturas guardadas en', root);
