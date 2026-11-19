import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { chromium } from 'playwright-core';
const { POS_BASE_URL, POS_TEST_EMAIL, POS_TEST_PASSWORD, POS_SCREENSHOT_DIR } = process.env;
const enabled = POS_BASE_URL && POS_TEST_EMAIL && POS_TEST_PASSWORD;

for (const [width, height] of [[320, 780], [390, 844], [768, 1024], [844, 390], [1440, 1000]]) {
    test(`responsive app bar ${width}x${height}`, { skip: !enabled, timeout: 90000 }, async () => {
        const browser = await chromium.launch({ executablePath: process.env.POS_BROWSER_EXECUTABLE || '/usr/bin/chromium', args: ['--no-sandbox','--disable-dev-shm-usage'] });
        let page, currentPath='login';
        try {
            page = await browser.newPage({ viewport: { width, height } });
            page.setDefaultTimeout(15000);
            const errors = [];
            page.on('pageerror', (e) => errors.push(e.message));
            await page.goto(new URL('/login', POS_BASE_URL).href);
            await page.getByLabel('Email', { exact: true }).fill(POS_TEST_EMAIL);
            await page.getByLabel('Password', { exact: true }).fill(POS_TEST_PASSWORD);
            await page.getByRole('button', { name: 'Log in', exact: true }).click();
            await page.waitForURL('**/dashboard');
            await page.getByText('Inventory attention desk', { exact: true }).waitFor();
            const bar = page.locator('header.MuiAppBar-root');
            const mobile = width < 981;
            for (const path of ['/inventory','/suppliers','/purchases','/customers','/products','/settings?section=prices','/settings?section=business','/products/create','/pos']) {
                currentPath=path;
                await page.goto(new URL(path, POS_BASE_URL).href);
                await page.waitForFunction(() => !document.querySelector('[aria-label="Loading page content"]'));
                await bar.waitFor();
                if (path === '/pos') {
                    const shift = page.getByRole('dialog').filter({hasText:'Cashier Session'});
                    if (await shift.count()) await shift.locator('.MuiDialogTitle-root button').click();
                    await bar.getByRole('button',{name:mobile?'Open navigation':/Collapse navigation|Expand navigation/}).waitFor();
                }
                await page.waitForFunction(() => {
                    const header = document.querySelector('header.MuiAppBar-root');
                    const spacer = document.querySelector('main')?.firstElementChild;
                    return header && spacer && Math.abs(header.getBoundingClientRect().height - spacer.getBoundingClientRect().height) < 1;
                });
                const geometry = await bar.evaluate((element) => ({ left: element.getBoundingClientRect().left, right: element.getBoundingClientRect().right, width: element.clientWidth, content: element.scrollWidth, tools: document.querySelector('#mkpos-appbar-tools')?.scrollWidth, toolsWidth: document.querySelector('#mkpos-appbar-tools')?.clientWidth }));
                assert(geometry.left >= -1 && geometry.right <= width + 1, `${path}: header fits viewport`);
                assert(geometry.content <= geometry.width + 1, `${path}: header content does not overflow`);
                if (mobile) {
                    assert(geometry.tools <= geometry.toolsWidth + 1, `${path}: page actions fit without horizontal scrolling (${geometry.tools}/${geometry.toolsWidth})`);
                    assert.equal(await bar.locator('[data-mobile-appbar]').count(), 1);
                    assert(await bar.getByRole('button',{name:'Open navigation',exact:true}).count());
                    assert(await bar.getByRole('button',{name:'Open account menu',exact:true}).count());
                } else {
                    assert.equal(await bar.locator('[data-mobile-appbar]').count(),0);
                    assert(await bar.getByRole('button',{name:/Collapse navigation|Expand navigation/}).isVisible());
                }
                if (mobile && path === '/suppliers') {
                    await bar.locator('button.MuiButton-contained').click();
                    await page.getByRole('dialog').getByRole('button',{name:'Cancel',exact:true}).click();
                    await bar.getByRole('button',{name:'Open navigation',exact:true}).waitFor();
                }
                if (POS_SCREENSHOT_DIR && ['/inventory','/suppliers','/products/create'].includes(path)) {
                    await fs.mkdir(POS_SCREENSHOT_DIR,{recursive:true});
                    await page.screenshot({path:`${POS_SCREENSHOT_DIR}/${path.slice(1).replaceAll('/','-')}-${width}-${height}.png`});
                }
            }
            // Test controls on a normal list page, outside the optional POS shift dialog.
            await page.goto(new URL('/inventory',POS_BASE_URL).href);
            await bar.getByRole('button',{name:'Expand search',exact:true}).waitFor();
            if (mobile) {
                await bar.getByRole('button',{name:'Open navigation',exact:true}).click();
                await page.getByRole('textbox',{name:'Search navigation',exact:true}).waitFor();
                await page.keyboard.press('Escape');
                await bar.getByRole('button',{name:'More options',exact:true}).click();
                await page.getByRole('menuitem',{name:'Switch to dark mode',exact:true}).click();
                await page.waitForFunction(()=>document.documentElement.dataset.theme==='dark');
                await bar.getByRole('button',{name:'Open account menu',exact:true}).click();
                await page.getByRole('menu').getByRole('menuitem',{name:'My profile',exact:true}).waitFor();
                await page.keyboard.press('Escape');
                await bar.getByRole('button',{name:'Expand search',exact:true}).click();
                const search = bar.locator('.appbar-search-field input');
                await search.fill('temporary search');
                assert(await search.evaluate((element)=>element.getBoundingClientRect().width>=180));
                assert(await bar.locator('#mkpos-appbar-tools').evaluate((element)=>element.scrollWidth<=element.clientWidth+1));
                await bar.getByRole('button',{name:'Collapse search',exact:true}).click();
                await bar.getByRole('button',{name:'Filters',exact:true}).click();
                await page.getByRole('button',{name:'Close filters',exact:true}).waitFor();
                await page.getByRole('button',{name:'Close filters',exact:true}).click();
                await bar.getByRole('button',{name:'More page actions',exact:true}).click();
                const actions = page.getByRole('dialog',{name:'More page actions',exact:true});
                await actions.getByRole('button',{name:'Export CSV',exact:true}).waitFor();
                await page.waitForFunction(() => { const button=document.querySelector('[role=dialog][aria-label="More page actions"] button'); return button && button.getBoundingClientRect().height>=40; });
                assert(await actions.getByRole('button',{name:'Export CSV',exact:true}).evaluate((e)=>e.getBoundingClientRect().height>=40));
                const exportButton = actions.getByRole('button',{name:'Export CSV',exact:true});
                if (width === 320 && await exportButton.isEnabled()) {
                    const downloaded = page.waitForEvent('download');
                    await exportButton.click();
                    assert((await downloaded).suggestedFilename().endsWith('.csv'));
                    assert(await actions.isVisible(), 'Async export stays mounted in the action menu');
                }
                await actions.getByRole('button',{name:'Movement history',exact:true}).click();
                await page.waitForURL('**/inventory/movements');
                await page.getByRole('table',{name:'Stock movement history',exact:true}).waitFor();
                assert.equal(await page.getByRole('dialog',{name:'More page actions',exact:true}).count(),0);
                await page.setViewportSize({width:1440,height:1000});
                await bar.getByRole('button',{name:/Collapse navigation|Expand navigation/}).waitFor();
                await page.setViewportSize({width,height});
                await bar.getByRole('button',{name:'Open navigation',exact:true}).waitFor();
                assert.equal(await page.locator('#mkpos-appbar-tools').count(),1);
                assert.equal(await page.locator('#mkpos-appbar-back').count(),1);
            }
            assert.deepEqual(errors,[]);
        } catch (error) { if (page && POS_SCREENSHOT_DIR) { await fs.mkdir(POS_SCREENSHOT_DIR,{recursive:true}); await page.screenshot({path:`${POS_SCREENSHOT_DIR}/failure-${width}.png`}).catch(()=>{}); } error.message=`${currentPath}: ${error.message}`; throw error; } finally { await browser.close(); }
    });
}
