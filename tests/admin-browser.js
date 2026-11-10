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
            'pdfStatus' => ['enabled' => true, 'errors' => [], 'versions' => [], 'checks' => array_fill_keys(['libreoffice', 'mustang', 'verapdf', 'templates'], ['ok' => false, 'version' => '', 'detail' => 'Nicht eingerichtet'])]];
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
                    status: url === '/apps/shi_re/validator/status' ? {installed: '1.2', enabled: true} : {enabled: true, checks: {libreoffice: {ok: true, version: '24.2', detail: '/usr/bin/libreoffice'}}}})};
            };
        });
        await page.addScriptTag({path: path.resolve('js/admin.js')});
        await page.evaluate(() => {
            window.OCA = {Text: {async createEditor(options) {
                window.editorOptions = {content: options.content, readOnly: options.readOnly, autofocus: options.autofocus};
                // Contract double for Nextcloud Text: renderer itself belongs to the Text app.
                const heading = document.createElement('h1');
                heading.textContent = 'ShiRE';
                options.el.append(heading);
                const section = document.createElement('h2');
                section.textContent = 'Vorlagen und Mapping';
                const decoration = document.createElement('button');
                decoration.textContent = 'Link kopieren';
                section.append(decoration);
                section.scrollIntoView = () => { window.helpHeadingScrolled = true; };
                options.el.append(section);
                return {setReadOnly(value) { window.editorReadOnly = value; }};
            }}};
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
        assert.deepEqual(await page.locator('.shire-system thead th').allTextContents(), ['Ergebnis', 'Komponente', 'Version', 'Pfad/Details', 'Aktion/Hilfe']);
        assert.deepEqual(await page.locator('.shire-system tbody th').allTextContents(), ['INValidator', 'LibreOffice', 'MustangCLI', 'veraPDF', 'eigene Vorlagen']);
        await page.locator('#myx-check-system').click();
        await page.waitForFunction(() => !document.getElementById('myx-check-system').disabled);
        assert.equal(await page.locator('[data-check=libreoffice] .shire-state').textContent(), '✓');
        assert.equal(await page.locator('[data-check=mustang] .shire-state').textContent(), '✕');
        assert.equal(await page.locator('[data-check=validator] [data-version]').textContent(), '1.2');
        assert.equal(await page.locator('[data-check=libreoffice] [data-version]').textContent(), '24.2');
        await page.evaluate(() => { window.fail = true; });
        await page.locator('#myx-hide-validator').click();
        await page.waitForFunction(() => !document.getElementById('myx-hide-validator').disabled);
        assert.equal(await page.locator('#myx-hide-validator').isChecked(), true, 'Failed save restores switch');
        assert.equal(await page.locator('.shire-readme-rich h1').textContent(), 'ShiRE');
        await page.locator('[data-check=templates] [data-readme-help]').click();
        assert.equal(await page.evaluate(() => window.helpHeadingScrolled), true);
        assert.equal(await page.locator('.shire-readme-fallback').isVisible(), false);
        assert.equal(await page.evaluate(() => editorOptions.readOnly && editorReadOnly), true);
        assert.ok(await page.evaluate(() => editorOptions.content.startsWith('# ShiRE')));
        const appHtml = execFileSync('php', ['-r', `
            function p($value) { echo htmlspecialchars((string)$value, ENT_QUOTES); }
            function print_unescaped($value) { echo $value; }
            $_ = ['readmeUrl' => '/apps/shi_re/readme'];
            require 'templates/readme-link.php';
            echo '<input id="invoice-value" value="Entwurf bleibt erhalten">';
        `], {encoding: 'utf8'});
        await page.setContent(appHtml);
        await page.addStyleTag({path: path.resolve('css/readme.css')});
        await page.addScriptTag({path: path.resolve('js/readme.js')});
        const originalUrl = page.url();
        for (const action of ['button', 'escape', 'backdrop']) {
            await page.locator('.shire-readme-link').click();
            assert.equal(await page.locator('dialog').isVisible(), true);
            assert.equal(page.url(), originalUrl);
            if (action === 'button') await page.locator('#shire-readme-close').click();
            else if (action === 'escape') await page.keyboard.press('Escape');
            else await page.mouse.click(2, 2);
            assert.equal(await page.locator('dialog').isVisible(), false);
            assert.equal(await page.locator('#invoice-value').inputValue(), 'Entwurf bleibt erhalten');
            assert.equal(await page.locator('.shire-readme-link').evaluate(node => node === document.activeElement), true);
        }
        assert.deepEqual(errors, []);
        console.log('Admin browser: defaults, immediate saves, failure rollback, conditional uploads system check rows and Nextcloud Text viewer integration passed.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
