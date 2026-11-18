'use strict';
const {chromium} = require('playwright');
const {execFileSync} = require('node:child_process');
const assert = require('node:assert/strict');
const path = require('node:path');
(async () => {
    const html = execFileSync('php', ['-r', `
        function p($value) { echo htmlspecialchars((string)$value, ENT_QUOTES); }
        function print_unescaped($value) { echo $value; }
        $_ = ['readmeUrl' => '/nextcloud/index.php/apps/shi_re/readme', 'status' => ['validation_enabled' => true, 'auto_update' => false, 'hide_navigation' => true, 'installed' => '1.0', 'enabled' => true, 'navigation_supported' => true],
            'allowDraftDeletion' => false, 'customTemplates' => false,
            'pdfStatus' => ['enabled' => true, 'errors' => [], 'versions' => [], 'checks' => array_fill_keys(['libreoffice', 'mustang', 'verapdf', 'templates'], ['ok' => false, 'version' => '', 'detail' => 'Nicht eingerichtet'])]];
        $_['pdfStatus']['checks']['templates'] = ['ok' => true, 'version' => 'abc123', 'detail' => 'geprüft'];
        require 'templates/admin.php';
    `], {encoding: 'utf8'});
    const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless: true});
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('http://shire.test/**', route => route.fulfill({body: '<html></html>', contentType: 'text/html'}));
        await page.route('http://shire.test/nextcloud/apps/shi_re/img/*.png', route =>
            route.fulfill({path: path.resolve('img', new URL(route.request().url()).pathname.split('/').pop()), contentType: 'image/png'}));
        await page.goto('http://shire.test/nextcloud/index.php/settings/admin/shi_re');
        await page.setContent(html);
        assert.equal(await page.locator('#myx-clear-archive + .shire-admin-help').getAttribute('href'),
            '/nextcloud/index.php/apps/shi_re/readme?document=admin#optionen-f%C3%BCr-die-nutzung');
        await page.addStyleTag({path: path.resolve('css/admin.css')});
        await page.evaluate(() => {
            window.OC = {generateUrl: path => path, imagePath: (app, file) => '/nextcloud/apps/' + app + '/img/' + file, requestToken: 'test'};
            window.calls = [];
            window.fail = false;
            window.fetch = async (url, options) => {
                calls.push({url, body: JSON.parse(options.body)});
                return {ok: !window.fail, json: async () => ({success: !window.fail, error: 'Speichern fehlgeschlagen',
                    status: url === '/apps/shi_re/admin/validator/settings' ? {installed: '1.0', enabled: true, ...JSON.parse(options.body)} : url === '/apps/shi_re/validator/status' ? {installed: '1.2', enabled: true} : {enabled: true, checks: {templates: {ok: true, version: 'def456', detail: 'geprüft', files: {mapping: {name: 'XRechnung.tsv', source: 'default'}, xml: {name: 'meine-rechnung.xml', date: '13.11.2026 10:30 UTC'}}}, libreoffice: {ok: true, version: '24.2', detail: '/usr/bin/libreoffice'}}}})};
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
                for (const [, alt, href] of options.content.matchAll(/\[([^\]]*)\]\((#shire-document-image-\d+)\)/g)) {
                    const link = document.createElement('a');
                    link.setAttribute('href', href);
                    link.textContent = alt;
                    options.el.append(link);
                }
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
        // Simulate Text registering after the README initializer has already run.
        await page.evaluate(() => {
            const text = window.OCA.Text;
            delete window.OCA.Text;
            setTimeout(() => { window.OCA.Text = text; }, 150);
        });
        await page.addScriptTag({path: path.resolve('js/readme.js')});
        await page.waitForSelector('.shire-readme-rich:not([hidden])');
        assert.equal(await page.locator('.shire-readme-fallback a').filter({hasText: 'Administrationsanleitung'}).getAttribute('href'),
            '/nextcloud/index.php/apps/shi_re/readme?document=admin');
        const editorMarkdown = await page.evaluate(() => window.editorOptions.content);
        for (const doc of ['user', 'admin', 'developer']) {
            assert.ok(editorMarkdown.includes('(/nextcloud/index.php/apps/shi_re/readme?document=' + doc + ')'));
        }
        assert.ok(editorMarkdown.includes('(https://github.com/322ud/shi_re/blob/HEAD/LICENSE)'));
        assert.ok(!editorMarkdown.includes('(./docs/'));
        assert.ok(!editorMarkdown.includes('!['), 'App screenshots bypass Text attachment loading');
        assert.equal(await page.locator('.shire-document-image').count(), 2);
        await page.waitForFunction(() => [...document.querySelectorAll('.shire-document-image')].every(img => img.complete && img.naturalWidth > 0));
        assert.equal(await page.locator('.shire-document-image').first().getAttribute('src'), '/nextcloud/apps/shi_re/img/eingabemaske.png');
        for (const answer of [null, 'nein', 'JA', 'ja']) {
            const before = await page.evaluate(() => calls.length);
            page.once('dialog', dialog => answer === null ? dialog.dismiss() : dialog.accept(answer));
            await page.locator('#myx-clear-archive').click();
            assert.equal(await page.evaluate(() => calls.length), before + (answer === 'ja' ? 1 : 0));
        }
        assert.deepEqual(await page.evaluate(() => calls.at(-1)), {
            url: '/apps/shi_re/admin/pdf/settings', body: {action: 'clear_archive', confirmation: 'ja'},
        });
        assert.equal(await page.locator('[role=switch]').count(), 6);
        assert.deepEqual(await page.locator('.shire-settings-group h4').allTextContents(), ['Validierung', 'Vorlagen', 'andere']);
        assert.equal(await page.locator('[aria-labelledby="shire-validation-title"] #myx-validation-enabled').count(), 1);
        assert.equal(await page.locator('[aria-labelledby="shire-templates-title"] #myx-pdf-templates').count(), 1);
        assert.equal(await page.locator('[aria-labelledby="shire-other-title"] #myx-clear-archive').count(), 1);
        assert.equal(await page.locator('#myx-detailed-progress').isDisabled(), false);
        assert.equal(await page.locator('#myx-detailed-progress').isChecked(), false);
        await page.locator('#myx-detailed-progress').check();
        await page.waitForFunction(() => !document.getElementById('myx-detailed-progress').disabled);
        assert.ok((await page.evaluate(() => calls)).some(call => call.url.endsWith('/admin/validator/settings') && call.body.detailed_progress === true));
        assert.equal(await page.locator('#myx-clear-archive').evaluate(node => Boolean(
            node.compareDocumentPosition(document.getElementById('shire-admin-readme-title')) & Node.DOCUMENT_POSITION_FOLLOWING)), true);

        assert.equal(await page.locator('#myx-validator-install-help').isVisible(), false);
        assert.equal(await page.locator('#myx-validation-enabled').isChecked(), true);
        assert.equal(await page.locator('#myx-check-validator').count(), 0);
        await page.locator('#myx-validation-enabled').uncheck();
        await page.waitForFunction(() => !document.getElementById('myx-validation-enabled').disabled);
        assert.equal(await page.locator('#myx-auto-update').isVisible(), false);
        assert.equal(await page.locator('#myx-xml-status').textContent(), 'XML-Rechnung wird erzeugt, aber nicht validiert.');
        assert.ok((await page.evaluate(() => calls)).some(call => call.body.validation_enabled === false));
        await page.locator('#myx-validation-enabled').check();
        await page.waitForFunction(() => !document.getElementById('myx-validation-enabled').disabled);
        // Refresh after an external removal: show administrative installation guidance.
        await page.evaluate(() => {
            window.savedFetch = window.fetch;
            window.fetch = async url => ({ok: true, json: async () => ({success: true,
                status: url.endsWith('/admin/validator/install')
                    ? {installed: '1.2', enabled: true, validation_enabled: true, auto_update: true}
                    : {installed: null, enabled: false, validation_enabled: true, auto_update: true}})});
        });
        await page.locator('#myx-check-system').click();
        await page.waitForFunction(() => !document.getElementById('myx-check-system').disabled);
        assert.equal(await page.locator('#myx-validator-install-help').isVisible(), true);
        assert.match(await page.locator('#myx-validator-install-help').textContent(), /Nextcloud-Administration/);
        assert.equal(await page.locator('#myx-install-validator').count(), 0);
        assert.equal(await page.locator('#myx-validation-enabled').isVisible(), false);
        assert.equal(await page.locator('#myx-auto-update').isVisible(), false);
        await page.evaluate(() => { window.fetch = window.savedFetch; });
        await page.locator('#myx-check-system').click();
        await page.waitForFunction(() => !document.getElementById('myx-check-system').disabled);
        assert.equal(await page.locator('#myx-validator-install-help').isVisible(), false);
        assert.equal(await page.locator('#myx-validation-enabled').isVisible(), true);
        await page.locator('#myx-auto-update').uncheck();
        await page.waitForFunction(() => !document.getElementById('myx-auto-update').disabled);
        assert.equal(await page.locator('#myx-hide-validator').isChecked(), true);
        assert.equal(await page.locator('#myx-pdf-templates').isVisible(), false);
        assert.equal(await page.locator('.shire-readme-link').count(), 0);
        await page.locator('#myx-custom-templates').check();
        await page.waitForFunction(() => !document.getElementById('myx-pdf-templates').hidden);
        assert.equal(await page.locator('#myx-pdf-upload').textContent(), 'neue Vorlagen prüfen');
        assert.match(await page.locator('#myx-template-files').textContent(), /meine-rechnung.xml — Upload-Datum: 13.11.2026 10:30 UTC/);
        assert.equal(await page.locator('#myx-template-files li').count(), 1);
        assert.deepEqual(await page.locator('.shire-template-uploads thead th').allTextContents(), ['Format', 'XRechnung', 'Storno']);
        assert.equal(await page.locator('.shire-template-uploads input[type=file]').count(), 6);
        assert.equal(await page.locator('#myx-pdf-xml').isVisible(), false);
        assert.equal(await page.locator('#myx-pdf-upload').isVisible(), false);
        await page.locator('#myx-replace-templates summary').click();
        assert.equal(await page.locator('#myx-pdf-xml').isVisible(), true);
        assert.equal(await page.locator('#myx-pdf-upload').isVisible(), true);
        assert.equal(await page.locator('#myx-pdf-xml').inputValue(), '', 'Stored templates do not populate upload inputs');
        await page.locator('#myx-auto-update').check();
        await page.waitForFunction(() => !document.getElementById('myx-auto-update').disabled);
        assert.ok((await page.evaluate(() => calls)).some(call => call.body.auto_update === true));
        assert.deepEqual(await page.locator('.shire-system').first().locator('thead th').allTextContents(), ['Ergebnis', 'Komponente', 'Version', 'Pfad/Details', 'Aktion/Hilfe']);
        assert.deepEqual(await page.locator('.shire-system tbody th').allTextContents(), ['eigene Vorlagen', 'INValidator', 'LibreOffice', 'MustangCLI', 'veraPDF']);
        await page.locator('#myx-check-system').click();
        await page.waitForFunction(() => !document.getElementById('myx-check-system').disabled);
        assert.equal(await page.locator('[data-check=libreoffice] .shire-state').textContent(), '✓');
        assert.equal(await page.locator('[data-check=mustang] .shire-state').textContent(), '✕');
        assert.equal(await page.locator('[data-check=validator] [data-version]').textContent(), '1.2');
        assert.equal(await page.locator('[data-check=libreoffice] [data-version]').textContent(), '24.2');
        assert.equal(await page.locator('#myx-xml-status').textContent(), 'XML-Rechnung wird erzeugt und validiert.');
        assert.match(await page.locator('#myx-pdf-status').textContent(), /PDF-Rechnung wird erzeugt und mit MustangCLI geprüft/);
        assert.match(await page.locator('#myx-pdf-status').textContent(), /PDF\/A-3b wird nicht mit veraPDF geprüft/);
        await page.evaluate(() => {
            window.fetch = async url => ({ok: true, json: async () => ({success: true,
                status: url.endsWith('/validator/status') ? {installed: '1.2', enabled: false}
                    : {enabled: true, checks: {verapdf: {ok: true}}}})});
        });
        await page.locator('#myx-check-system').click();
        await page.waitForFunction(() => !document.getElementById('myx-check-system').disabled);
        assert.equal(await page.locator('#myx-xml-status').textContent(), 'XML-Rechnung wird erzeugt, aber nicht validiert.');
        assert.match(await page.locator('#myx-pdf-status').textContent(), /PDF-Rechnung wird erzeugt und mit MustangCLI geprüft/);
        await page.evaluate(() => {
            window.fetch = async () => ({ok: false, json: async () => ({success: false, error: 'Speichern fehlgeschlagen'})});
        });
        await page.evaluate(() => {
            window.fetch = async () => ({ok: false, status: 404,
                json: async () => { throw new SyntaxError('Unexpected token <'); }});
        });
        await page.locator('#myx-discard-templates').click();
        await page.waitForFunction(() => !document.getElementById('myx-discard-templates').disabled);
        assert.match(await page.locator('#myx-admin-result').textContent(), /HTTP 404, \/admin\/pdf\/settings/);
        assert.equal(await page.locator('#myx-custom-templates').isChecked(), true, 'Failed discard preserves UI state');
        await page.evaluate(() => {
            window.fetch = async (url, options) => {
                window.discardBody = JSON.parse(options.body);
                window.discardUrl = url;
                return {ok: true, json: async () => ({success: true, status: {enabled: true,
                    checks: {templates: {ok: false, version: '', detail: 'inaktiv'}}}})};
            };
        });
        await page.locator('#myx-discard-templates').click();
        await page.waitForFunction(() => !document.getElementById('myx-discard-templates').disabled);
        assert.equal(await page.evaluate(() => window.discardUrl), '/apps/shi_re/admin/pdf/settings');
        assert.deepEqual(await page.evaluate(() => window.discardBody), {action: 'discard_templates'});
        assert.equal(await page.locator('#myx-custom-templates').isChecked(), false);
        assert.equal(await page.locator('#myx-pdf-templates').isVisible(), false);
        assert.equal(await page.locator('[data-check=templates] [data-detail]').textContent(), 'inaktiv');
        assert.equal(await page.locator('#myx-saved-templates').count(), 0);
        await page.evaluate(() => {
            window.fetch = async () => ({ok: false, json: async () => ({success: false, error: 'Speichern fehlgeschlagen'})});
        });
        await page.evaluate(() => { window.fail = true; });
        await page.locator('#myx-hide-validator').click();
        await page.waitForFunction(() => !document.getElementById('myx-hide-validator').disabled);
        assert.equal(await page.locator('#myx-hide-validator').isChecked(), true, 'Failed save restores switch');
        await page.waitForSelector('.shire-readme-rich:not([hidden]) h1');
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
        await page.setContent('<div id=content><div id=app-content>' + appHtml + '</div></div>');
        await page.addScriptTag({path: path.resolve('js/readme.js')});
        assert.equal(await page.locator('#shire-readme-dialog').count(), 0);
        assert.equal(await page.locator('.shire-readme-link').getAttribute('href'),
            '/apps/shi_re/readme?document=user#rechnung-erstellen');
        assert.equal(await page.locator('.shire-readme-link').getAttribute('target'), '_blank');
        await page.evaluate(() => { window.open = (...args) => { window.helpWindow = args; }; });
        await page.locator('.shire-readme-link').click();
        assert.deepEqual(await page.evaluate(() => window.helpWindow), [
            'http://shire.test/apps/shi_re/readme?document=user#rechnung-erstellen',
            '_blank', 'popup,width=960,height=800,noopener,noreferrer',
        ]);
        assert.equal(await page.locator('#invoice-value').inputValue(), 'Entwurf bleibt erhalten');
        const userDoc = execFileSync('php', ['-r', `
            function p($value) { echo htmlspecialchars((string)$value, ENT_QUOTES); }
            function print_unescaped($value) { echo $value; }
            $_ = ['document' => 'user', 'readmeUrl' => '/apps/shi_re/readme', 'appUrl' => '/apps/shi_re/'];
            require 'templates/readme.php';
        `], {encoding: 'utf8'});
        for (const section of ['rechnung-erstellen', 'rechnungsarchiv', 'storno-rechnung']) {
            await page.goto('http://shire.test/apps/shi_re/readme?document=user#' + section);
            await page.setContent(userDoc);
            await page.evaluate(() => {
                Element.prototype.scrollIntoView = function () { window.helpHeading = this.textContent; };
            });
            await page.addScriptTag({path: path.resolve('js/readme.js')});
            await page.waitForFunction(() => Boolean(window.helpHeading));
            assert.equal(await page.evaluate(() => window.helpHeading), {
                'rechnung-erstellen': '## Rechnung erstellen',
                'rechnungsarchiv': '## Rechnungsarchiv',
                'storno-rechnung': '## Storno-Rechnung',
            }[section]);
            assert.ok(await page.locator('.shire-readme-fallback a[href="#rechnung-erstellen"]').count());
        }
        const adminDoc = execFileSync('php', ['-r', `
            function p($value) { echo htmlspecialchars((string)$value, ENT_QUOTES); }
            function print_unescaped($value) { echo $value; }
            $_ = ['document' => 'admin', 'readmeUrl' => '/nextcloud/index.php/apps/shi_re/readme', 'appUrl' => '/nextcloud/index.php/apps/shi_re/'];
            require 'templates/readme.php';
        `], {encoding: 'utf8'});
        await page.goto('http://shire.test/nextcloud/index.php/apps/shi_re/readme?document=admin#systemvoraussetzungen');
        await page.setContent(adminDoc);
        await page.evaluate(() => {
            window.OCA = {Text: {async createEditor({el}) {
                el.innerHTML = '<h2>Systemvoraussetzungen</h2><a href="../README.md#tests">README</a>';
                el.querySelector('h2').scrollIntoView = () => { window.anchorReached = true; };
                return {setReadOnly() {}};
            }}};
        });
        await page.addScriptTag({path: path.resolve('js/readme.js')});
        await page.waitForFunction(() => window.anchorReached === true);
        assert.equal(await page.locator('.shire-readme-rich a').getAttribute('href'),
            '/nextcloud/index.php/apps/shi_re/readme?document=readme#tests');
        assert.match(await page.locator('.shire-readme-fallback').textContent(), /ShiRE administrieren/);
        assert.deepEqual(errors, []);
        console.log('Admin browser: defaults, immediate saves, failure rollback, conditional uploads system check rows and Nextcloud Text viewer integration passed.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
