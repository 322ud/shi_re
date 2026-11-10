'use strict';
const {chromium} = require('playwright');
const {execFileSync} = require('node:child_process');
const assert = require('node:assert/strict');
const path = require('node:path');
(async () => {
    const html = execFileSync('php', ['-r', `
        function p($value) { echo htmlspecialchars((string)$value, ENT_QUOTES); }
        function print_unescaped($value) { echo $value; }
        $_ = ['status' => ['auto_update' => false, 'hide_navigation' => true, 'installed' => '1.0', 'enabled' => true, 'navigation_supported' => true],
            'allowDraftDeletion' => false, 'customTemplates' => false,
            'pdfStatus' => ['enabled' => true, 'errors' => [], 'versions' => [], 'customTemplatesStatus' => 'Deaktiviert']];
        require 'templates/admin.php';
    `], {encoding: 'utf8'});
    const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless: true});
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.setContent(html);
        await page.addStyleTag({path: path.resolve('css/admin.css')});
        await page.evaluate(() => {
            window.OC = {generateUrl: path => path, requestToken: 'test'};
            window.calls = [];
            window.fail = false;
            window.fetch = async (url, options) => {
                calls.push({url, body: JSON.parse(options.body)});
                return {ok: !window.fail, json: async () => ({success: !window.fail, error: 'Speichern fehlgeschlagen',
                    status: {enabled: true, versions: {}, customTemplatesStatus: 'Aktiv'}})};
            };
        });
        await page.addScriptTag({path: path.resolve('js/admin.js')});
        await page.evaluate(() => {
            window.OCA = {Text: {createEditor() { throw new Error('README must not use the text editor'); }}};
        });
        await page.addScriptTag({path: path.resolve('js/readme.js')});
        assert.equal(await page.locator('[role=switch]').count(), 4);
        assert.equal(await page.locator('#myx-hide-validator').isChecked(), true);
        assert.equal(await page.locator('#myx-pdf-templates').isVisible(), false);
        assert.equal(await page.locator('.shire-readme-link').count(), 0);
        await page.locator('#myx-custom-templates').check();
        await page.waitForFunction(() => !document.getElementById('myx-pdf-templates').hidden);
        assert.equal(await page.locator('#myx-pdf-upload').textContent(), 'Vorlagen prüfen');
        await page.locator('#myx-auto-update').check();
        await page.waitForFunction(() => !document.getElementById('myx-auto-update').disabled);
        assert.ok((await page.evaluate(() => calls)).some(call => call.body.auto_update === true));
        await page.evaluate(() => { window.fail = true; });
        await page.locator('#myx-hide-validator').click();
        await page.waitForFunction(() => !document.getElementById('myx-hide-validator').disabled);
        assert.equal(await page.locator('#myx-hide-validator').isChecked(), true, 'Failed save restores switch');
        assert.equal(await page.locator('.shire-readme-rich h1').textContent(), 'ShiRE');
        assert.ok(await page.locator('.shire-readme-rich pre code').count());
        assert.ok(await page.locator('.shire-readme-rich strong').count());
        assert.equal(await page.locator('.shire-readme-fallback').isVisible(), false);
        const rendered = await page.locator('.shire-readme-rich').textContent();
        assert.ok(!rendered.includes('## Einstellungen'));
        assert.ok(!rendered.includes('**nein**'));
        assert.ok(!rendered.includes('```php'));
        assert.deepEqual(errors, []);
        console.log('Admin browser: defaults, immediate saves, failure rollback, conditional uploads and formatted README passed.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
