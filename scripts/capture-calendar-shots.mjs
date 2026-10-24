import { chromium } from 'playwright';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const outDir  = process.env.SHOTS_OUT_DIR ?? 'docs/manual/img/calendario';

const browser = await chromium.launch({ args: ['--lang=es-ES'] });
const page    = await browser.newPage({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' });

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

await page.goto(`${baseUrl}/calendario`);
await page.waitForLoadState('networkidle');
await hideToolbar();
await page.screenshot({ path: `${outDir}/calendario.png` });

// A month with non-working days (demo data: Constitución bridge, Inmaculada and the Christmas
// break, all in December — October, the month above, has none). Two clicks forward from October.
await page.waitForTimeout(1000);   // let the Live Component connect, or the clicks do nothing
for (let i = 0; i < 2; i += 1) {
    await page.click('button[data-live-action-param="nextMonth"]');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(500);
}
await hideToolbar();
await page.screenshot({ path: `${outDir}/calendario-no-lectivos.png` });

// The detail of one of those days: it says why it is not a school day.
await page.goto(`${baseUrl}/calendario/dia/2026-12-08`);
await page.waitForLoadState('networkidle');
await hideToolbar();
await page.screenshot({ path: `${outDir}/calendario-dia-no-lectivo.png` });

// Detail view of a day with pending activities (demo deadlines, Sep. 2026).
await page.goto(`${baseUrl}/calendario/dia/2026-09-15`);
await page.waitForLoadState('networkidle');
await hideToolbar();
await page.screenshot({ path: `${outDir}/calendario-dia.png` });

// Subscription page, with the feed address already generated (writes a token: throwaway database only).
await page.goto(`${baseUrl}/calendario/suscripcion`);
await page.waitForLoadState('networkidle');
await page.click('text=Activar mi calendario');
await page.waitForLoadState('networkidle');
await hideToolbar();
await page.screenshot({ path: `${outDir}/calendario-suscripcion.png` });

await browser.close();
console.log('Screenshots saved to', outDir);
