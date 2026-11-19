import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright-core';
import fs from 'node:fs/promises';

const { POS_BASE_URL, POS_TEST_EMAIL, POS_TEST_PASSWORD, POS_BROWSER_EXECUTABLE, POS_SCREENSHOT_DIR } = process.env;
const enabled = POS_BASE_URL && POS_TEST_EMAIL && POS_TEST_PASSWORD;
const gate = () => { let release; const promise = new Promise((resolve) => { release = resolve; }); return { promise, release }; };

for (const [width, mode] of [[1440, 'light'], [390, 'light'], [1440, 'dark']]) {
    test(`lightweight page loading: ${width}px ${mode}`, { skip: !enabled, timeout: 60000 }, async () => {
        const browser = await chromium.launch({ executablePath: POS_BROWSER_EXECUTABLE || '/usr/bin/chromium', args: ['--no-sandbox', '--disable-dev-shm-usage'] });
        const pending = [];
        try {
            const page = await browser.newPage({ viewport: { width, height: 940 } });
            const errors = [];
            page.on('pageerror', (error) => errors.push(error.message));
            await page.addInitScript((theme) => localStorage.setItem('app.theme', theme), mode);
            await page.goto(new URL('/login', POS_BASE_URL).href);
            await page.getByLabel('Email', { exact: true }).fill(POS_TEST_EMAIL);
            await page.getByLabel('Password', { exact: true }).fill(POS_TEST_PASSWORD);
            await page.getByRole('button', { name: 'Log in', exact: true }).click();
            await page.waitForURL('**/dashboard');
            await page.getByText('Inventory attention desk', { exact: true }).waitFor();

            // Hold only the lazy page module; navigation and its shell remain usable.
            const moduleGate = gate(); pending.push(moduleGate);
            await page.route('**/build/assets/Movements-*.js', async (route) => { await moduleGate.promise; await route.continue(); });
            const response = await page.goto(new URL('/inventory/movements', POS_BASE_URL).href, { waitUntil: 'domcontentloaded' });
            const html = await response.text();
            const match = html.match(/<script id="spa-page" type="application\/json">([\s\S]*?)<\/script>/);
            assert(match, 'Server page payload is present');
            const payload = JSON.parse(match[1]);
            const skeleton = page.getByRole('status', { name: 'Loading page content', exact: true });
            await skeleton.locator('.MuiSkeleton-root').first().waitFor();
            const shell = await page.locator('header.MuiAppBar-root').elementHandle();
            assert(shell, 'App bar is visible before the lazy module resolves');
            const styles = await skeleton.locator('.MuiSkeleton-root').first().evaluate((element) => {
                const style = getComputedStyle(element);
                return { color: style.backgroundColor, duration: style.animationDuration, height: element.getBoundingClientRect().height };
            });
            assert(styles.color.endsWith(mode === 'dark' ? '0.07)' : '0.055)'), styles.color);
            assert.equal(styles.duration, '1.8s');
            assert(styles.height <= 20, 'Use small content shapes instead of large solid blocks');
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
            if (POS_SCREENSHOT_DIR) { await fs.mkdir(POS_SCREENSHOT_DIR, { recursive: true }); await page.screenshot({ path: `${POS_SCREENSHOT_DIR}/initial-${width}-${mode}.png` }); }
            await page.emulateMedia({ reducedMotion: 'reduce' });
            assert.equal(await skeleton.locator('.MuiSkeleton-root').first().evaluate((e) => getComputedStyle(e).animationName), 'none');
            moduleGate.release();
            await page.getByRole('table', { name: 'Stock movement history', exact: true }).waitFor();
            assert(await shell.evaluate((element) => element.isConnected), 'Shell stays mounted after initial loading');
            await page.unroute('**/build/assets/Movements-*.js');

            let requestGate = gate(); pending.push(requestGate);
            let failure = false;
            await page.route('**/inventory/movements?*', async (route) => {
                const currentGate = requestGate;
                await currentGate.promise;
                if (failure) return route.fulfill({ status: 503, json: { message: 'Test navigation unavailable' } });
                const url = new URL(route.request().url());
                const next = structuredClone(payload);
                next.url = url.pathname + url.search;
                next.props.filters = Object.fromEntries(url.searchParams);
                await route.fulfill({ status: 200, json: next });
            });
            const search = page.getByLabel('Product, reference or batch', { exact: true });
            await search.fill('Pending search');
            const input = await search.elementHandle();
            await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
            const progress = page.getByRole('progressbar', { name: 'Loading latest data', exact: true });
            await progress.waitFor();
            assert.equal(await page.locator('.MuiSkeleton-root').count(), 0);
            assert(await input.evaluate((element) => element.isConnected), 'Existing controls remain mounted during refresh');
            assert.equal(await search.inputValue(), 'Pending search');
            assert(await page.getByRole('table', { name: 'Stock movement history', exact: true }).isVisible());
            assert.equal(await progress.evaluate((e) => e.getBoundingClientRect().height), 2);
            const table = page.getByRole('table', { name: 'Stock movement history', exact: true });
            await table.evaluate((element) => { element.style.height = '1600px'; });
            await page.evaluate(() => window.scrollTo(0, 500));
            const progressTop = await progress.evaluate((element) => element.getBoundingClientRect().top);
            assert(progressTop >= 48 && progressTop <= 49, `Progress stays below the app bar while scrolling: ${progressTop}`);
            await table.evaluate((element) => { element.style.height = ''; });
            await page.evaluate(() => window.scrollTo(0, 0));
            assert.equal(await progress.locator('.MuiLinearProgress-bar1Indeterminate').evaluate((e) => getComputedStyle(e).animationName), 'none');
            if (POS_SCREENSHOT_DIR) await page.screenshot({ path: `${POS_SCREENSHOT_DIR}/refresh-${width}-${mode}.png` });
            requestGate.release();
            await progress.waitFor({ state: 'detached' });
            assert.equal(await search.inputValue(), 'Pending search');
            assert.equal(await input.evaluate((element) => element.isConnected), false, 'Default non-preserved navigation resets the page only after success');

            // A fast response should never mount a progress indicator.
            requestGate = gate(); pending.push(requestGate);
            await page.evaluate(() => {
                window.__loadingFlashes = 0;
                window.__loadingObserver = new MutationObserver(() => { if (document.querySelector('[role="progressbar"]')) window.__loadingFlashes++; });
                window.__loadingObserver.observe(document.body, { childList: true, subtree: true });
            });
            await search.fill('Fast search');
            await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
            requestGate.release();
            await page.waitForFunction(() => !document.querySelector('[aria-busy="true"]'));
            assert.equal(await page.evaluate(() => { window.__loadingObserver.disconnect(); return window.__loadingFlashes; }), 0);

            // A failed refresh retains content and provides a useful error.
            requestGate = gate(); pending.push(requestGate); failure = true;
            await search.fill('Failed search');
            const retained = await search.elementHandle();
            await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
            requestGate.release();
            await page.getByRole('alert').filter({ hasText: 'Test navigation unavailable' }).waitFor();
            assert(await retained.evaluate((element) => element.isConnected));
            assert(await shell.evaluate((element) => element.isConnected));
            if (width === 1440 && mode === 'light') {
                await page.route('**/build/assets/Movements-*.js', (route) => route.fulfill({ status: 503, contentType: 'text/javascript', body: '' }));
                await page.goto(new URL('/inventory/movements', POS_BASE_URL).href);
                await page.getByRole('alert').filter({ hasText: 'Unable to load this page. Please retry.' }).waitFor();
                assert(await page.locator('header.MuiAppBar-root').isVisible());
                await page.unroute('**/build/assets/Movements-*.js');
                await page.getByRole('button', { name: 'Retry', exact: true }).click();
                await page.getByRole('table', { name: 'Stock movement history', exact: true }).waitFor();
            }
            assert.deepEqual(errors, []);
        } finally { pending.forEach((item) => item.release()); await browser.close(); }
    });
}
