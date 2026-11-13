/**
 * Captures the screenshots of the backups screens for the manual («Administrar la plataforma»,
 * docs/manual/img/plataforma-copias*.png):
 *
 *  · plataforma-copias.png            — the whole page: download card (with the optional password), result of the last
 *                                       restore and the list of backups kept on the server (one encrypted, one taken
 *                                       with another schema, which cannot be restored from the web)
 *  · plataforma-copias-restaurar.png  — review and confirmation screen of a restore
 *  · plataforma-copias-restaurando.png — the page everybody gets while a restore runs
 *
 * Logs in as the platform administrator («admin») of the demo data. Needs a server already running at SHOTS_BASE_URL on a
 * DISPOSABLE database (built from the migrations + app:load-demo-data) and with APP_BACKUP_DIR pointing at an empty,
 * disposable folder (SHOTS_BACKUP_DIR, the same one; see skills/screenshots.md). It does NOT confirm any restore: it
 * only prepares one and looks at it. The «restoring» page comes from writing the lock by hand in `var/restore/` (deleted at
 * the end), and so does the result of the last restore. The encrypted copy is made with `app:backup --password`, so the
 * script must run with the same DATABASE_URL / APP_BACKUP_DIR / MIGRATIONS_PATH environment as the server.
 */
import { chromium } from 'playwright';
import { mkdirSync, writeFileSync, rmSync, copyFileSync, readdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

const baseUrl   = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const outDir    = process.env.SHOTS_OUT_DIR ?? 'docs/manual/img';
const backupDir = process.env.SHOTS_BACKUP_DIR;
if (!backupDir) {
    throw new Error('SHOTS_BACKUP_DIR is missing (the backups folder of the screenshots server)');
}
mkdirSync(outDir, { recursive: true });
mkdirSync('var/restore', { recursive: true });

const browser = await chromium.launch({ args: ['--lang=es-ES'] });
const page    = await browser.newPage({ viewport: { width: 1360, height: 900 }, locale: 'es-ES' });

async function hideToolbar() {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
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

// Two backups saved with the button (the names carry the seconds: wait between one and the other).
for (let i = 0; i < 2; i++) {
    await open('/admin/copias');
    await page.click('form[action$="/guardar"] button[type="submit"]');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(1200);
}

const first = readdirSync(backupDir).filter((n) => n.endsWith('.zip')).sort()[0];

// A backup «from another version» (another schema), which cannot be restored here: a real one with its manifest changed.
const other = `${backupDir}/atica-calidad-backup-2026-10-12-031500.zip`;
copyFileSync(`${backupDir}/${first}`, other);
execFileSync('php', ['-r', `
    $z = new ZipArchive(); $z->open('${other}');
    $m = json_decode($z->getFromName('manifest.json'), true);
    $m['schemaVersion'] = 'DoctrineMigrations\\\\Version20260101000000';
    $z->addFromString('manifest.json', json_encode($m));
    $z->close();
`]);

// An encrypted one, as the nightly job would leave it with `app:backup --password`.
execFileSync('php', ['-d', 'memory_limit=1G', 'bin/console', 'app:backup', `${backupDir}/atica-calidad-backup-2026-11-02-020000.zip`, '--password=clave-de-la-demo', '--no-interaction'], { stdio: 'ignore' });

// The one taken automatically before a previous restore.
copyFileSync(`${backupDir}/${first}`, `${backupDir}/atica-calidad-before-restore-2026-10-30-081500.zip`);

// Result of the last restore (as the process that did it leaves it).
writeFileSync('var/restore/last-result.json', JSON.stringify({
    ok: true, by: 'direccion', file: first, tables: 64, rows: 537, at: Math.floor(Date.now() / 1000) - 3600,
}));

await open('/admin/copias');
// The screenshots server's folder is a disposable path: show the one of a Docker deployment.
await page.evaluate((dir) => {
    for (const p of document.querySelectorAll('p')) {
        p.textContent = p.textContent.replace(dir, '/app/var/backups');
    }
}, backupDir);
await page.screenshot({ path: `${outDir}/plataforma-copias.png`, fullPage: true });

// Review and confirmation screen (looking at it changes nothing).
const name = (await page.locator('a[href$="/restaurar"]').first().getAttribute('href')).split('/copias/')[1].split('/')[0];
await open(`/admin/copias/${name}/restaurar`);
await page.screenshot({ path: `${outDir}/plataforma-copias-restaurar.png`, fullPage: true });

// The 503 page while a restore runs: lock written by hand (no process: it expires after a minute, and is deleted at the end).
writeFileSync('var/restore/restore.lock', JSON.stringify({ started: Math.floor(Date.now() / 1000), pid: 0, by: 'admin', file: name }));
await page.goto(`${baseUrl}/admin/copias`);
await page.waitForLoadState('load');
await hideToolbar();   // the dev profiler injects its «Loading the web debug toolbar…» placeholder into this page too
await page.screenshot({ path: `${outDir}/plataforma-copias-restaurando.png` });
rmSync('var/restore', { recursive: true, force: true });

await browser.close();
console.log('Done.');
