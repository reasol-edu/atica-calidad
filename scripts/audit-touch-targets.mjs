/**
 * Mobile usability audit: walks the screens reachable from the menu (and the Centro educativo and Administración panels)
 * with an emulated phone and, for each one, reports
 *
 *  · horizontal page overflow;
 *  · buttons and button-looking links shorter than MIN_HEIGHT (40 px by default), grouped by «signature» (tag and classes) so
 *    repeated patterns show up, not every single case;
 *  · interactive elements whose right edge falls outside the screen.
 *
 * Needs a server already running at SHOTS_BASE_URL on a DISPOSABLE database built from the migrations + `app:load-demo-data`
 * (see skills/screenshots.md). It only does GETs: it clicks nothing.
 *
 * Usage: `node scripts/audit-touch-targets.mjs [widthPx]` (run it at 320, 360, 390 and 768; it must report nothing).
 * Environment: SHOTS_BASE_URL, MIN_HEIGHT, MAX_PAGES (per user, 120 by default).
 */
import { chromium, devices } from 'playwright';

const baseUrl   = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const minHeight = Number(process.env.MIN_HEIGHT ?? 40);
const maxPages  = Number(process.env.MAX_PAGES ?? 120);
const width     = Number(process.argv[2] ?? 390);
// Platform admin, management team, quality manager, a teacher in two centres and a rank-and-file teacher.
const users     = [['admin', 'admin'], ['direccion', 'direccion'], ['calidad', 'calidad'], ['multicentro', 'multicentro'], ['e.cabrera', 'prueba']];

// Routes that are not screens (downloads, sign-out, feeds, impersonation).
const SKIP = /\/(logout|pdf|excel|xlsx|csv|export|exportar|descargar|descarga|acceder|cambiar-docente|impersonar|feed|bandeja-pdf)\b|_switch_user|_profiler|_wdt|\.(pdf|xlsx|zip|png|svg|ics|csv)$/i;

const browser = await chromium.launch();
const signatures = new Map();   // signature → {count, pages:Set, example:{text,h,w}}
const overflows  = [];
const offscreen  = [];
let visited = 0;

for (const [username, password] of users) {
    const context = await browser.newContext({ ...devices['iPhone 13'], viewport: { width, height: 844 }, locale: 'es-ES' });
    const page    = await context.newPage();
    await page.goto(`${baseUrl}/login`);
    await page.fill('#username', username);
    await page.fill('#password', password);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    if (page.url().includes('/seleccion/centro')) {
        await page.click('text=IES Ada Lovelace');
        await page.waitForLoadState('networkidle');
    }

    // Starting points: the sidebar menu and the centre and administration panels.
    const seeds = new Set(['/']);
    const collect = async () => {
        for (const href of await page.$$eval('a[href^="/"]', (as) => as.map((a) => a.getAttribute('href') ?? ''))) {
            const path = href.split('#')[0];
            if (path !== '' && !SKIP.test(path) && !path.includes('?')) { seeds.add(path); }
        }
    };
    await collect();
    for (const hub of [...seeds].filter((p) => /^\/(centro\/[^/]+|admin)$/.test(p))) {
        await page.goto(`${baseUrl}${hub}`);
        await page.waitForLoadState('networkidle');
        await collect();
    }
    // One level deeper from the section hubs, to reach the lists and detail pages.
    for (const hub of [...seeds].slice(0, 40)) {
        const response = await page.goto(`${baseUrl}${hub}`).catch(() => null);
        if (!response || response.status() >= 400) { continue; }
        await page.waitForLoadState('networkidle').catch(() => {});
        await collect();
    }

    for (const path of [...seeds].slice(0, maxPages)) {
        const response = await page.goto(`${baseUrl}${path}`).catch(() => null);
        if (!response || response.status() >= 400) { continue; }
        await page.waitForLoadState('networkidle').catch(() => {});
        ++visited;
        // The dev profiler toolbar (fixed, wider than the phone) is not part of the page.
        await page.addStyleTag({ content: '.sf-toolbarreset, div[id^="sfwdt"] { display: none !important; }' });

        const result = await page.evaluate((minH) => {
            const vw = document.documentElement.clientWidth;
            const visible = (e) => { const r = e.getBoundingClientRect(); const s = getComputedStyle(e); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && e.closest('[hidden], .hidden') === null; };
            // Buttons and «button-looking» links (border, background or rounding), inside the content or the header.
            const candidates = [...document.querySelectorAll('main button, main summary, main input[type="submit"], main a, header button, header a')]
                .filter((e) => visible(e) && !e.closest('nav') && (e.matches('button, summary, input[type="submit"]') || /\b(rounded|border|bg-)/.test(e.className)));
            const small = [];
            const out   = [];
            // An element inside a horizontally scrolling container (a wide table, a calendar) may sit past the screen edge.
            const inScroller = (e) => { for (let p = e.parentElement; p; p = p.parentElement) { const o = getComputedStyle(p).overflowX; if (o === 'auto' || o === 'scroll') { return true; } } return false; };
            // The touch area can be enlarged without changing the look with an absolute ::after (see «Touch targets» in app.css).
            const hasHitArea = (e) => { const a = getComputedStyle(e, '::after'); return a.content !== 'none' && a.position === 'absolute'; };
            for (const e of candidates) {
                const r = e.getBoundingClientRect();
                if (hasHitArea(e)) { if (r.right > vw + 1 && !inScroller(e)) { out.push({ text: (e.innerText || '').trim().slice(0, 30), right: Math.round(r.right) }); } continue; }
                if (inScroller(e)) { if (r.height < minH) { /* still counts for size */ } else { continue; } }
                const text = (e.innerText || e.getAttribute('aria-label') || e.title || '').trim().replace(/\s+/g, ' ').slice(0, 30);
                const sig  = e.tagName.toLowerCase() + '.' + String(e.className).split(/\s+/).filter((c) => /^(px|py|text|rounded|p-|h-|w-)/.test(c)).slice(0, 6).join('.');
                if (r.height < minH) { small.push({ sig, text, h: Math.round(r.height), w: Math.round(r.width) }); }
                if (r.right > vw + 1 && !inScroller(e)) { out.push({ text, right: Math.round(r.right) }); }
            }
            return { overflow: document.documentElement.scrollWidth > vw, small, out };
        }, minHeight);

        if (result.overflow) { overflows.push(`${username} ${path}`); }
        for (const o of result.out) { offscreen.push(`${username} ${path}: «${o.text}» reaches ${o.right}px`); }
        for (const s of result.small) {
            const entry = signatures.get(s.sig) ?? { count: 0, pages: new Set(), example: s };
            entry.count++;
            entry.pages.add(path.replace(/[0-9a-f]{8}-[0-9a-f-]{27}/g, '{id}'));
            signatures.set(s.sig, entry);
        }
    }
    await context.close();
}
await browser.close();

console.log(`\nScreens visited: ${visited} (width ${width}px, minimum ${minHeight}px)\n`);
console.log(`Horizontal overflows: ${overflows.length}`);
for (const o of [...new Set(overflows)]) { console.log('  · ' + o); }
console.log(`\nElements off screen: ${offscreen.length}`);
for (const o of offscreen.slice(0, 40)) { console.log('  · ' + o); }
console.log('\nSignatures of buttons shorter than the minimum (by number of occurrences):');
for (const [sig, e] of [...signatures.entries()].sort((a, b) => b[1].count - a[1].count).slice(0, 50)) {
    console.log(`  ${String(e.count).padStart(4)} × ${sig}  [${e.example.h}px, «${e.example.text}»]  on ${e.pages.size} screen(s), e.g. ${[...e.pages][0]}`);
}
