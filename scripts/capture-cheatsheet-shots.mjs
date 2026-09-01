/**
 * Captures the mobile screenshots for the cheatsheets (docs/cheatsheets/img/).
 *
 * Uses Playwright's mobile viewport (devices['iPhone 13']) — the app is a PWA also
 * designed for mobile. Requires a server already running at SHOTS_BASE_URL with
 * data seeded (fixtures).
 */
import { chromium, devices } from 'playwright';
import { mkdirSync } from 'node:fs';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const root    = process.env.SHOTS_OUT_DIR ?? 'docs/cheatsheets/img';

mkdirSync(root, { recursive: true });

const browser = await chromium.launch({ args: ['--lang=es-ES'] });
const iphone  = devices['iPhone 13'];

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

// ── Add the app to the home screen ──────────────────────────────────────────
{
    const page = await browser.newPage({ ...iphone, locale: 'es-ES' });
    await login(page, 'admin', 'admin');

    await page.goto(`${baseUrl}/`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/instalar-app-1.png` });

    await page.close();
}

// ── Search with the command palette ─────────────────────────────────────────
{
    const page = await browser.newPage({ ...iphone, locale: 'es-ES' });
    await login(page, 'admin', 'admin');

    await page.goto(`${baseUrl}/`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/busqueda-rapida-1.png` });

    await page.click('header button[data-action="command-palette#open"]:visible');
    await page.waitForTimeout(200);
    await hideToolbar(page);
    await page.locator('[data-command-palette-target="dialog"]').screenshot({ path: `${root}/busqueda-rapida-2.png` });

    const paletteInput = page.locator('[data-command-palette-target="input"]');
    await paletteInput.click();
    await page.keyboard.type('admin', { delay: 30 });
    await page.waitForTimeout(700);
    await hideToolbar(page);
    await page.locator('[data-command-palette-target="dialog"]').screenshot({ path: `${root}/busqueda-rapida-3.png` });

    await page.close();
}

await browser.close();
console.log('Capturas guardadas en', root);
