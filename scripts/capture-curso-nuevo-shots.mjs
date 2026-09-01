/**
 * Captures the desktop screenshots for the "Set up a new academic year" cheatsheet
 * (docs/cheatsheets/curso-nuevo.md), aimed at the management team.
 *
 * Unlike scripts/capture-cheatsheet-shots.mjs (mobile viewport, data already seeded),
 * this script uses the 1280x900 desktop viewport (the app is administered from desktop) and
 * itself runs, start to finish, the real setup flow for an academic year: it creates and
 * activates the 2026-2027 academic year, imports teaching staff from a Séneca CSV, creates the
 * course offering (courses and groups) and imports non-working days from a sample .ics file, and
 * assigns the quality-manager and internal-auditor profiles.
 *
 * Requires its own DISPOSABLE DATABASE for this run: this script leaves the 2026-2027 academic
 * year as the centre's active year, which would change the reference year the rest of the
 * screenshots assume if the same run were reused.
 *
 * The sample files referenced below (src/DataFixtures/data/) do not exist yet: the
 * application does not yet have demo data (fixtures). Add them before running this
 * script, or adapt the paths to your own.
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

// ── 1. Create and activate the academic year ─────────────────────────────────
await page.goto(`${baseUrl}/centro/${centreId}/cursos`);
await page.waitForLoadState('networkidle');
await hideToolbar(page);

await page.fill('input[name="name"]', '2026-2027');
// Note: the page has several "button[type=submit]" elements (the one to add a course and one
// per existing course to delete it); we need to scope to the creation form or we might click
// the wrong button.
await page.locator('form[action*="/nuevo"] button[type="submit"]').click();
await page.waitForLoadState('networkidle');
await hideToolbar(page);

const newYearRow = page.locator('li', { hasText: '2026-2027' });
await newYearRow.waitFor();
await newYearRow.locator('button:has-text("Establecer activo")').click();
await page.waitForLoadState('networkidle');
await hideToolbar(page);
await page.screenshot({ path: `${root}/curso-nuevo-1-activado.png` });

// ── 2. Add the teaching staff ────────────────────────────────────────────────
await page.goto(`${baseUrl}/centro/${centreId}/docentes-curso/importar`);
await page.waitForLoadState('networkidle');
await hideToolbar(page);

await page.setInputFiles('#csv', 'src/DataFixtures/data/docentes-ada-lovelace.csv');
await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle');
await hideToolbar(page);
await page.screenshot({ path: `${root}/curso-nuevo-2-docentes-listado.png` });

// ── 3. Define the course offering ────────────────────────────────────────────
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

// Teacher-group-subject assignments can also be imported from a Séneca CSV:
await page.goto(`${baseUrl}/centro/${centreId}/docentes-curso/importar-asignaciones`);
await page.waitForLoadState('networkidle');
await hideToolbar(page);

await page.setInputFiles('#csv', 'src/DataFixtures/data/asignaciones-ada-lovelace.csv');
await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle');
await hideToolbar(page);
await page.screenshot({ path: `${root}/curso-nuevo-4-asignaciones-listado.png` });

// ── 4. Define the non-working days ───────────────────────────────────────────
await page.goto(`${baseUrl}/centro/${centreId}/dias-no-lectivos/importar`);
await page.waitForLoadState('networkidle');
await hideToolbar(page);

await page.setInputFiles('#ics', 'src/DataFixtures/data/dias-no-lectivos-ada-lovelace.ics');
await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle');
await hideToolbar(page);
await page.screenshot({ path: `${root}/curso-nuevo-5-dias-no-lectivos.png` });

// ── 5. Assign the quality profiles ───────────────────────────────────────────
await page.goto(`${baseUrl}/centro/${centreId}/perfiles`);
await page.waitForLoadState('networkidle');
await hideToolbar(page);
await page.screenshot({ path: `${root}/curso-nuevo-6-perfiles.png` });

// ── Result: the centre, ready to work ────────────────────────────────────────
await page.goto(`${baseUrl}/centro/${centreId}`);
await page.waitForLoadState('networkidle');
await hideToolbar(page);
await page.screenshot({ path: `${root}/curso-nuevo-7-centro-listo.png` });

await page.close();
await browser.close();
console.log('Capturas guardadas en', root);
