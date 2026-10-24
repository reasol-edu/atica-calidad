/**
 * Captures the screenshots of the Responsabilidades chapter of the manual
 * (docs/manual/img/responsabilidades-*.png), against the demo data from `app:load-demo-data`
 * (IES Ada Lovelace) — see skills/screenshots.md for what each fixture is there to show.
 *
 * Requires a server already running at SHOTS_BASE_URL on a DISPOSABLE database seeded with that
 * data (never the real one). Almost everything is read-only; the two exceptions are the setup of
 * the Séneca preview (it deactivates the group «4º ESO A» through SHOTS_DB and adds a group
 * «2º PMAR», so the preview has something to reactivate and something unused to delete) and the
 * confirmation shots, which are cancelled. Nothing is ever confirmed.
 *
 * Env: SHOTS_BASE_URL (default http://127.0.0.1:8744), SHOTS_OUT_DIR (default docs/manual/img),
 * SHOTS_DB (default var/capturas.db, needs the `sqlite3` CLI).
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const outDir  = process.env.SHOTS_OUT_DIR ?? 'docs/manual/img';
const dbPath  = process.env.SHOTS_DB ?? 'var/capturas.db';
const fixtures = new URL('./fixtures/', import.meta.url).pathname;

mkdirSync(outDir, { recursive: true });

const browser = await chromium.launch({ args: ['--lang=es-ES'] });
const page    = await browser.newPage({ viewport: { width: 1360, height: 900 }, locale: 'es-ES' });

async function hideToolbar() {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
    // A full-page capture of a page that was scrolled (after a click) misplaces the fixed sidebar.
    await page.evaluate(() => window.scrollTo(0, 0));
}

async function settle() {
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(250);
}

async function shot(name, options = { fullPage: true }) {
    await hideToolbar();
    await page.screenshot({ path: `${outDir}/${name}.png`, ...options });
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
const lists = `${baseUrl}/centro/${centreId}/responsabilidades/listas`;

/** Opens the screen of one list (root element) from the lists page. */
async function openList(name) {
    await page.goto(lists);
    await settle();
    await page.locator('li:has(a[href*="/responsabilidades/listas/"])', { hasText: name }).first().locator('a').first().click();
    await settle();
}

/** Clicks an element of the desktop tree and waits for the detail panel to show it. */
async function selectElement(name) {
    await page.getByRole('button', { name, exact: true }).first().click();
    await page.waitForSelector('text=Editar elemento');
    await settle();
}

// ── 1. The lists page, and one list's screen ────────────────────────────────
await page.goto(lists);
await settle();
await shot('responsabilidades-listas');

await openList('Departamento');
await shot('responsabilidades-lista-elementos');

// ── 2. The nested list: tree with its icons, then the three kinds of association ──
await openList('Enseñanza');
await shot('responsabilidades-lista-arbol');

await selectElement('1º DAW');                 // no association of its own: inherits «Formación Profesional»'s
await shot('responsabilidades-elemento-hereda');

await openList('Enseñanza');                   // reloaded: switching element in place leaves a second picker behind
await selectElement('2º DAW');                 // own association: replaces the inherited one
await shot('responsabilidades-elemento-propia');

await openList('Materia');
await selectElement('Tecnología');             // two associations: both departments deliver it
await shot('responsabilidades-elemento-varias');

// ── 3. Bulk move to another list, and its confirmation (cancelled) ──────────
await openList('Departamento');
await page.locator('button:has-text("Seleccionar varios"):visible').first().click();
await settle();
await page.locator('input[type="checkbox"][data-model="bulkSelectedIds"]:visible').first().check();
await settle();
await page.locator('[id^="bulk-move-picker"] .ts-control').last().click();
await page.waitForTimeout(300);
await shot('responsabilidades-lista-mover');

await page.locator('.ts-dropdown .option', { hasText: 'Física y Química' }).last().click();
await page.waitForTimeout(300);
await page.locator('button:has-text("Mover"):visible', { hasNotText: 'Mover dentro' }).first().click();
await page.waitForSelector('text=cambian los perfiles');
await shot('responsabilidades-lista-mover-confirmar');
await page.locator('button:has-text("Cancelar"):visible').last().click();
await settle();

// ── 4. The same list on a phone ─────────────────────────────────────────────
const phone = await browser.newPage({ viewport: { width: 390, height: 844 }, locale: 'es-ES', deviceScaleFactor: 2 });
await phone.context().addCookies(await page.context().cookies());
await phone.goto(lists);
await phone.waitForLoadState('networkidle');
await phone.locator('li:has(a[href*="/responsabilidades/listas/"])', { hasText: 'Enseñanza' }).first().locator('a').first().click();
await phone.waitForLoadState('networkidle');
await phone.waitForTimeout(1000);   // let the Live Component connect, or the click does nothing
await phone.locator('li', { hasText: 'Formación Profesional' }).locator('button[title="Ver elementos"]').click();
await phone.locator('button:visible', { hasText: /^\s*DAM\b/ }).waitFor();   // the next level (DAM, DAW) has been drawn
await phone.waitForLoadState('networkidle');
await phone.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
await phone.screenshot({ path: `${outDir}/responsabilidades-lista-movil.png`, fullPage: true });
await phone.close();

// ── 5. Séneca imports: previews only ────────────────────────────────────────
// Run LAST (see the call at the bottom): its setup deactivates a group, which would show up in
// red in the profile screens captured after it.
async function senecaPreviews() {
execFileSync('sqlite3', [dbPath, "UPDATE list_item SET active = 0 WHERE name = '4º ESO A'"]);
await openList('Grupo');
await page.getByRole('button', { name: 'Añadir elemento', exact: true }).click();
await page.locator('input[placeholder="ej: Grupo"]:visible').first().fill('2º PMAR');
await page.keyboard.press('Enter');
await settle();

await page.goto(lists);
await settle();
await page.click('a:has-text("Importar grupos desde Séneca")');
await settle();
await shot('responsabilidades-seneca-formulario');
await page.setInputFiles('input[type="file"]', `${fixtures}seneca-grupos.csv`);
await page.locator('form:has(input[type="file"]) button[type="submit"]').first().click();
await settle();
await shot('responsabilidades-seneca-previsualizacion');

await page.goto(lists);
await settle();
await page.click('a:has-text("Importar materias desde Séneca")');
await settle();
await page.setInputFiles('input[type="file"]', `${fixtures}seneca-materias.csv`);
await page.locator('input[name="root_name"], input[type="text"]').first().fill('Materias por grupo');
await page.locator('form:has(input[type="file"]) button[type="submit"]').first().click();
await settle();
await shot('responsabilidades-seneca-materias');
}

// ── 6. Specific profiles ────────────────────────────────────────────────────
const profiles = `${baseUrl}/centro/${centreId}/responsabilidades/perfiles-especificos`;
await page.goto(profiles);
await settle();
await shot('responsabilidades-perfiles');

for (const [name, file] of [
    ['Coordinador/a de Nivel', 'perfil-subperfiles'],
    ['Coordinador/a de FFEOE', 'perfil-no-raiz'],
    ['Coordinador/a TIC', 'perfil-directo'],
]) {
    await page.goto(profiles);
    await settle();
    await page.getByRole('button', { name: new RegExp(`^${name}`) }).first().click();
    await settle();
    await shot(`responsabilidades-${file}`);

    if (file === 'perfil-subperfiles') {
        // «2º DAW» has no teacher yet: the panel to look one up and assign them.
        await page.getByRole('button', { name: /Coordinador\/a de Nivel 2º DAW/ }).first().click();
        await settle();
        await shot('responsabilidades-perfil-asignar-docente');
    }
}

// The same profile as it is offered where permissions are given (here, a calendar event
// restricted to profiles): a leaf, an intermediate element «(todos)», or the whole profile.
await page.goto(`${baseUrl}/eventos/nuevo`);
await settle();
await page.getByText('Restringido a perfiles').click();
await page.locator('#profiles + .ts-wrapper .ts-control, .ts-wrapper:has(#profiles) .ts-control').first().click();
await page.keyboard.type('Nivel (todos)');
await page.waitForTimeout(400);
await shot('responsabilidades-selector-todos', { fullPage: true });

// ── 7. Assign profiles ──────────────────────────────────────────────────────
const assignments = `${baseUrl}/centro/${centreId}/responsabilidades/asignar-perfiles`;
await page.goto(assignments);
await settle();
await shot('responsabilidades-asignar-perfiles');

await page.getByRole('button', { name: /Mostrar inactivos/ }).click();
await settle();
await page.fill('input[type="search"], input[placeholder*="Buscar"]', 'ALDEA');
await settle();
await shot('responsabilidades-asignar-inactivos', { fullPage: false });

await page.goto(assignments);
await settle();
await page.getByRole('tab', { name: 'Docentes' }).or(page.getByRole('button', { name: 'Docentes', exact: true })).first().click();
await settle();
await page.fill('input[type="search"], input[placeholder*="Buscar"]', 'Bravo');
await settle();
await page.getByText('Bravo Escudero, Noelia').first().click();
await settle();
await shot('responsabilidades-asignar-docentes');

await senecaPreviews();

await browser.close();
