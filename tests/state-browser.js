'use strict';
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
(async () => {
    const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless: true});
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('http://shire.test/**', route => route.fulfill({contentType: 'text/html', body: '<html><body></body></html>'}));
        await page.goto('http://shire.test/');
        assert.equal(await page.evaluate(() => window.isSecureContext), false);
        assert.equal(await page.evaluate(() => typeof crypto.randomUUID), 'undefined');
        const load = () => page.addScriptTag({path: path.resolve('js/state.js')});
        const id = () => page.evaluate(() => window.ShiRE.state.windowId);
        const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
        await load();
        const first = await id();
        assert.match(first, uuid);
        await page.reload();
        await load();
        assert.equal(await id(), first, 'Reload keeps ownership of the current draft');
        await page.evaluate(() => window.ShiRE.resetStateAfterClose());
        const next = await id();
        assert.match(next, uuid);
        assert.notEqual(next, first, 'Closing creates a new window identifier');
        assert.equal(await page.evaluate(() => sessionStorage.getItem('shi_re.windowId')), next);
        assert.deepEqual(errors, []);
        console.log('HTTP startup, UUID format, reload and reset tests passed.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exit(1); });
