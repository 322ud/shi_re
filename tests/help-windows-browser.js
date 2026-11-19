const assert = require('node:assert/strict');
const fs = require('node:fs');
const {chromium} = require('playwright');

(async () => {
    const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless: true});
    try {
        const context = await browser.newContext();
        const script = fs.readFileSync('js/readme.js', 'utf8');
        await context.route('http://shire.test/**', route => {
            const url = new URL(route.request().url());
            const body = url.pathname === '/gui'
                ? '<a data-shire-help id="admin" href="/readme?document=admin#h-first">Admin</a><a data-shire-help id="user" href="/readme?document=user#h-first">User</a>'
                : '<div data-shire-readme><div class="shire-readme-rich" hidden></div><pre class="shire-readme-fallback"><span data-readme-heading="First">First</span><span data-readme-heading="Second">Second</span></pre></div>';
            return route.fulfill({contentType: 'text/html', body: '<html><body>' + body +
                '<script>Element.prototype.scrollIntoView = function () { window.lastHeading = this.textContent; window.scrollCalls = (window.scrollCalls || 0) + 1; };</script><script>' + script + '</script></body></html>'});
        });
        const page = await context.newPage();
        await page.goto('http://shire.test/gui');
        const opened = page.waitForEvent('popup');
        await page.locator('#admin').click();
        const admin = await opened;
        await admin.waitForFunction(() => window.lastHeading === 'First');
        assert.equal(await admin.evaluate(() => window.opener), null);
        const calls = await admin.evaluate(() => window.scrollCalls);
        await page.locator('#admin').click();
        await admin.waitForFunction(count => window.scrollCalls > count, calls);
        assert.equal(context.pages().length, 2, 'Same chapter reuses its window');
        await page.locator('#admin').evaluate(link => { link.hash = 'h-second'; });
        await page.locator('#admin').click();
        await admin.waitForFunction(() => window.lastHeading === 'Second');
        assert.equal(context.pages().length, 2, 'Chapter change reuses its window');
        const userOpened = page.waitForEvent('popup');
        await page.locator('#user').click();
        const user = await userOpened;
        await user.waitForFunction(() => window.lastHeading === 'First');
        assert.equal(context.pages().length, 3, 'Admin and user have separate windows');
        await page.locator('#admin').click();
        assert.equal(context.pages().length, 3);
        await admin.close();
        const reopened = page.waitForEvent('popup');
        await page.locator('#admin').click();
        const replacement = await reopened;
        await replacement.waitForFunction(() => window.lastHeading === 'Second');
        assert.equal(context.pages().length, 3, 'Closed window is replaced');
        console.log('Help windows: reuse, repeated chapter, chapter change, separate documents, opener isolation and reopening passed.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
