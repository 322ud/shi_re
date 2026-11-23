'use strict';
const {chromium} = require('playwright');
const {execFileSync} = require('node:child_process');
const assert = require('node:assert/strict');
const path = require('node:path');
(async () => {
    const html = execFileSync('php', ['-d', 'memory_limit=64M', '-d', 'max_execution_time=5', '-r', `
        function p($value) { echo htmlspecialchars((string)$value, ENT_QUOTES); }
        function print_unescaped($value) { echo $value; }
        $_ = ['readmeUrl' => '/nextcloud/index.php/apps/shi_re/readme', 'status' => ['validation_enabled' => true, 'auto_update' => false, 'hide_navigation' => true, 'installed' => '1.0', 'enabled' => true, 'navigation_supported' => true],
            'allowDraftDeletion' => false, 'customTemplates' => false, 'archiveStatus' => 'bis 26 121',
            'pdfStatus' => ['enabled' => true, 'errors' => [], 'versions' => [], 'checks' => array_fill_keys(['libreoffice', 'mustang', 'verapdf', 'templates'], ['ok' => false, 'version' => '', 'detail' => 'Nicht eingerichtet'])]];
        $_['pdfStatus']['checks']['templates'] = ['ok' => true, 'version' => 'abc123', 'detail' => 'geprüft'];
        require 'templates/admin.php';
    `], {encoding: 'utf8', timeout: 10000});
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
        assert.equal(await page.locator('[data-check=archive] .shire-admin-help').getAttribute('href'),
            '/nextcloud/index.php/apps/shi_re/readme?document=admin#h-betrieb-und-archiv');
        await page.addStyleTag({path: path.resolve('css/admin.css')});
        await page.evaluate(() => {
            window.OC = {generateUrl: path => path, imagePath: (app, file) => '/nextcloud/apps/' + app + '/img/' + file, requestToken: 'test'};
            window.calls = [];
            window.fail = false;
            window.fetch = async (url, options) => {
                calls.push({url, body: JSON.parse(options.body)});
                return {ok: !window.fail, json: async () => ({success: !window.fail, error: 'Speichern fehlgeschlagen',
                    status: url === '/apps/shi_re/admin/validator/settings' ? {installed: '1.0', enabled: true, ...JSON.parse(options.body)} : url === '/apps/shi_re/validator/status' ? {installed: '1.2', enabled: true} : {enabled: true, checks: {templates: {ok: true, version: 'def456', detail: 'geprüft', files: {mapping: {name: 'eigenes-mapping.tsv', source: 'upload'}, odt: {name: 'eigene-vorlage.odt', source: 'upload'}, storno_mapping: {name: 'storno-mapping.tsv', source: 'upload'}, storno_odt: {name: 'storno-vorlage.odt', source: 'upload'}, xml: {name: 'meine-rechnung.xml', date: '13.11.2026 10:30 UTC'}}}, libreoffice: {ok: true, version: '24.2', detail: '/usr/bin/libreoffice'}}}})};
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
        assert.equal(await page.locator('#myx-archive-status').textContent(), 'bis 26 121');
        for (const answer of [null, 'nein', 'JA', 'ja']) {
            const before = await page.evaluate(() => calls.length);
            page.once('dialog', dialog => answer === null ? dialog.dismiss() : dialog.accept(answer));
            await page.locator('#myx-clear-archive').click();
            assert.equal(await page.evaluate(() => calls.length), before + (answer === 'ja' ? 1 : 0));
        }
        assert.equal(await page.locator('#myx-archive-status').textContent(), 'leer');
        assert.equal(await page.locator('[data-check=archive]').isVisible(), false);
        assert.deepEqual(await page.evaluate(() => calls.at(-1)), {
            url: '/apps/shi_re/admin/pdf/settings', body: {action: 'clear_archive', confirmation: 'ja'},
        });
        assert.deepEqual(await page.locator('[data-setting] > th').allTextContents(),
            ['eigene Vorlagen', 'XML-Validierung', 'PDF-Erzeugung', 'XML-Einbindung', 'PDF-Prüfung', 'INValidator', 'Meldungsbereich', 'Button „Entwurf löschen“']);
        assert.deepEqual(await page.locator('section[aria-labelledby="shire-settings-title"] h4').allTextContents(), ['Rechnungserzeugung', 'Nutzeroberfläche']);
        assert.equal(await page.locator('table[aria-labelledby="shire-generation-title"] [data-setting]').count(), 5);
        assert.equal(await page.locator('table[aria-labelledby="shire-interface-title"] [data-setting]').count(), 3);
        assert.equal(await page.locator('#myx-xml-embedding').isDisabled(), false);
        assert.equal(await page.locator('[data-setting="myx-xml-embedding"]').isVisible(), false);
        assert.equal(await page.locator('[data-setting="myx-pdf-validation"]').isVisible(), false);
        assert.equal(await page.locator('[data-setting="myx-pdf-enabled"]').isVisible(), false);
        assert.equal(await page.locator('.shire-system > thead').count(), 0);
        assert.ok(await page.locator('.shire-system > tbody > tr:not(#myx-template-panel)').evaluateAll(rows => rows.every(row => row.children.length === 4 && row.lastElementChild.querySelector('[data-shire-help]'))));
        assert.equal(await page.locator('[data-check=validator] + [data-check=libreoffice]').count(), 1);
        assert.equal(await page.locator('[data-check=validator] [data-version]').textContent(), '1.0');
        assert.equal(await page.locator('#myx-auto-update').isChecked(), false);
        await page.locator('#myx-auto-update').check();
        await page.waitForFunction(() => !document.getElementById('myx-auto-update').disabled);
        assert.equal(await page.locator('#myx-auto-update-label').textContent(), 'nicht automatisch aktualisieren');
        await page.locator('#myx-auto-update').uncheck();
        await page.waitForFunction(() => !document.getElementById('myx-auto-update').disabled);
        assert.equal(await page.locator('#myx-auto-update-label').textContent(), 'automatisch aktualisieren');
        assert.equal(await page.locator('#myx-hide-validator').isChecked(), false);
        assert.equal(await page.locator('[data-setting="myx-hide-validator"] [data-setting-state]').textContent(), 'ausgeblendet');
        await page.locator('#myx-hide-validator').check();
        await page.waitForFunction(() => !document.getElementById('myx-hide-validator').disabled);
        assert.equal(await page.evaluate(() => calls.at(-1).body.hide_navigation), false);
        assert.equal(await page.locator('[data-setting="myx-hide-validator"] [data-setting-state]').textContent(), 'eingeblendet');
        await page.locator('#myx-hide-validator').uncheck();
        await page.waitForFunction(() => !document.getElementById('myx-hide-validator').disabled);
        assert.equal(await page.evaluate(() => calls.at(-1).body.hide_navigation), true);
        assert.equal(await page.locator('[data-setting="myx-hide-validator"] [data-setting-state]').textContent(), 'ausgeblendet');
        assert.equal(await page.locator('#myx-message-area').isDisabled(), false);
        assert.equal(await page.locator('#myx-message-area').isChecked(), true);
        assert.equal(await page.locator('#myx-detailed-progress').count(), 0);
        assert.equal(await page.locator('summary').filter({hasText: 'Weitere Einstellungen'}).count(), 0);
        await page.locator('#myx-message-area').uncheck();
        await page.waitForFunction(() => !document.getElementById('myx-message-area').disabled);
        assert.equal(await page.locator('[data-setting="myx-message-area"] [data-setting-state]').textContent(), 'ausgeblendet');
        assert.ok((await page.evaluate(() => calls)).some(call => call.body.message_area === false));
        await page.evaluate(() => { window.fail = true; });
        await page.locator('#myx-message-area').click();
        await page.waitForFunction(() => !document.getElementById('myx-message-area').disabled);
        assert.equal(await page.locator('#myx-message-area').isChecked(), false);
        await page.evaluate(() => { window.fail = false; });

        assert.equal(await page.locator('#myx-template-panel #myx-pdf-templates').count(), 1);
        await page.locator('#myx-validation-enabled').uncheck();
        await page.waitForFunction(() => !document.getElementById('myx-validation-enabled').disabled);
        assert.equal(await page.locator('[data-setting="myx-hide-validator"]').isVisible(), false);
        assert.equal(await page.locator('#myx-auto-update').isVisible(), true);
        await page.locator('#myx-validation-enabled').check();
        await page.waitForFunction(() => !document.getElementById('myx-validation-enabled').disabled);
        assert.equal(await page.locator('[data-setting="myx-hide-validator"]').isVisible(), true);
        await page.locator('#myx-custom-templates').check();
        await page.waitForFunction(() => !document.getElementById('myx-custom-templates').disabled);
        assert.equal(await page.locator('#myx-custom-templates-label').textContent(), 'deaktivieren');
        await page.locator('#myx-replace-templates summary').click();
        await page.locator('#myx-pdf-xml').waitFor({state: 'visible'});
        assert.equal(await page.locator('#myx-pdf-xml').isVisible(), true);
        assert.equal(await page.locator('.shire-template-uploads input[type=file]').count(), 6);
        assert.equal(await page.locator('[data-pdf-template]:visible').count(), 2);
        assert.match(await page.locator('#myx-template-files').textContent(), /eigene-vorlage.odt/);
        await page.locator('#myx-pdf-odt').setInputFiles({name: 'ausgewaehlt.odt', mimeType: 'application/octet-stream', buffer: Buffer.from('test')});

        await page.locator('#myx-check-system').click();
        await page.waitForFunction(() => !document.getElementById('myx-check-system').disabled);
        assert.equal(await page.locator('[data-check=libreoffice] [data-version]').textContent(), '24.2');
        assert.equal(await page.locator('[data-setting="myx-pdf-enabled"]').isVisible(), true);
        assert.equal(await page.locator('#myx-pdf-enabled').isChecked(), true);
        await page.evaluate(() => {
            window.pdfFetch = window.fetch;
            window.fetch = async (url, options) => {
                const response = await window.pdfFetch(url, options);
                const data = await response.json();
                data.status.pdf_enabled = JSON.parse(options.body).pdf_enabled;
                return {...response, json: async () => data};
            };
        });
        await page.locator('#myx-pdf-enabled').uncheck();
        await page.waitForFunction(() => !document.getElementById('myx-pdf-enabled').disabled);
        assert.equal(await page.evaluate(() => calls.at(-1).body.pdf_enabled), false);
        assert.equal(await page.locator('[data-setting="myx-pdf-enabled"] [data-setting-state]').textContent(), 'inaktiv');
        assert.equal(await page.locator('[data-pdf-template]:visible').count(), 0);
        assert.equal(await page.locator('[data-pdf-template] input:disabled').count(), 4);
        assert.equal(await page.locator('#myx-pdf-odt').inputValue(), '');
        assert.equal(await page.locator('#myx-pdf-xml').isVisible(), true);
        assert.equal(await page.locator('#myx-pdf-storno_xml').isVisible(), true);
        assert.doesNotMatch(await page.locator('#myx-template-files').textContent(), /\.(odt|tsv)/);
        assert.match(await page.locator('#myx-template-files').textContent(), /meine-rechnung.xml/);
        assert.equal(await page.locator('#myx-template-xml-help').isVisible(), true);
        assert.equal(await page.evaluate(() => new FormData(document.getElementById('myx-pdf-templates')).has('odt')), false);

        await page.evaluate(() => { window.fail = true; });
        await page.locator('#myx-pdf-enabled').click();
        await page.waitForFunction(() => !document.getElementById('myx-pdf-enabled').disabled);
        assert.equal(await page.locator('#myx-pdf-enabled').isChecked(), false);
        await page.evaluate(() => { window.fetch = window.pdfFetch; window.fail = false; });
        await page.evaluate(() => {
            window.featurePreferences = {pdf_enabled: true, xml_embedding: true, pdf_validation: true};
            window.featureTools = {java: true, mustang: true, verapdf: true};
            window.fetch = async (url, options) => {
                const response = await window.pdfFetch(url, options);
                const data = await response.json();
                if (!window.fail) Object.assign(window.featurePreferences, JSON.parse(options.body));
                const prefs = window.featurePreferences;
                for (const [key, ok] of Object.entries(window.featureTools)) data.status.checks[key] = {ok, version: ok ? '1.0' : ''};
                data.status.pdf_enabled = prefs.pdf_enabled;
                data.status.xmlEmbedding = prefs.pdf_enabled && prefs.xml_embedding && window.featureTools.java && window.featureTools.mustang;
                data.status.pdfaVerification = prefs.pdf_enabled && prefs.pdf_validation && window.featureTools.verapdf;
                return {...response, json: async () => data};
            };
        });
        const refreshPdf = async () => {
            // Refresh only the PDF endpoint so the fixture remains a PDF status response.
            await page.locator('#myx-pdf-enabled').click();
            await page.waitForFunction(() => !document.getElementById('myx-pdf-enabled').disabled);
        };
        await refreshPdf();
        assert.equal(await page.locator('[data-pdf-template]:visible').count(), 2);
        assert.equal(await page.locator('[data-pdf-template] input:disabled').count(), 0);
        assert.match(await page.locator('#myx-template-files').textContent(), /storno-mapping.tsv/);
        assert.equal(await page.locator('#myx-template-pdf-help').isVisible(), true);
        for (const id of ['myx-xml-embedding', 'myx-pdf-validation']) {
            assert.equal(await page.locator('#' + id).isVisible(), true);
            assert.equal(await page.locator('#' + id).isChecked(), true);
            await page.locator('#' + id).uncheck();
            await page.waitForFunction(id => !document.getElementById(id).disabled, id);
            assert.equal(await page.locator('[data-setting="' + id + '"] [data-setting-state]').textContent(), 'inaktiv');
            await page.evaluate(() => { window.fail = true; });
            await page.locator('#' + id).click();
            await page.waitForFunction(id => !document.getElementById(id).disabled, id);
            assert.equal(await page.locator('#' + id).isChecked(), false);
            await page.evaluate(() => { window.fail = false; });
        }
        await refreshPdf();
        assert.equal(await page.locator('#myx-xml-embedding').isVisible(), false);
        assert.equal(await page.locator('#myx-pdf-validation').isVisible(), false);
        await refreshPdf();
        assert.equal(await page.locator('#myx-xml-embedding').isChecked(), false);
        assert.equal(await page.locator('#myx-pdf-validation').isChecked(), false);
        await page.evaluate(() => { window.featureTools.java = false; window.featureTools.verapdf = false; });
        await refreshPdf();
        await refreshPdf();
        assert.equal(await page.locator('#myx-xml-embedding').isVisible(), false);
        assert.equal(await page.locator('#myx-pdf-validation').isVisible(), false);
        await page.evaluate(() => { window.fetch = window.pdfFetch; });


        await page.evaluate(() => { window.fail = true; });
        await page.locator('#myx-hide-validator').click();
        await page.waitForFunction(() => !document.getElementById('myx-hide-validator').disabled);
        assert.equal(await page.locator('#myx-hide-validator').isChecked(), false, 'Failed save restores switch');
        await page.evaluate(() => {
            window.fetch = async () => ({ok: true, json: async () => ({success: true,
                status: {checks: {templates: {version: '', files: {}}}}})});
        });
        await page.locator('#myx-discard-templates').click();
        await page.waitForFunction(() => !document.getElementById('myx-discard-templates').disabled);
        assert.equal(await page.locator('[data-check=templates]').isVisible(), false);
        assert.equal(await page.locator('#myx-template-panel').isVisible(), false);
        assert.equal(await page.locator('#myx-install-templates').isVisible(), true);
        await page.locator('#myx-install-templates').click();
        assert.equal(await page.locator('#myx-pdf-upload').isVisible(), true);
        assert.equal(await page.locator('[data-check=templates]').isVisible(), true);
        await page.locator('#myx-replace-templates summary').click();
        await page.locator('#myx-template-panel').waitFor({state: 'hidden'});
        assert.equal(await page.locator('#myx-template-panel').isVisible(), false);
        await page.waitForSelector('.shire-readme-rich:not([hidden]) h1');
        assert.equal(await page.locator('.shire-readme-rich h1').textContent(), 'ShiRE');
        await page.evaluate(() => { window.open = (...args) => { window.helpWindow = args; }; });
        const adminHelpLinks = page.locator('[data-shire-help]');
        assert.equal(await page.locator('[data-readme-help]').count(), 0);
        const headings = require('fs').readFileSync('docs/admin.md', 'utf8').split('\n')
            .filter(line => /^#{1,6} /.test(line))
            .map(line => line.replace(/^#+ /, '').normalize('NFD').replace(/\p{M}/gu, '').replace(/ß/g, 'ss').toLowerCase().replace(/[^\p{L}\p{N}_\s-]/gu, '').replace(/ /g, '-'));
        for (const link of await adminHelpLinks.all()) {
            const href = await link.getAttribute('href');
            const section = decodeURIComponent(href.split('#')[1]);
            assert.ok(headings.includes(section.replace(/^h-/, '')), 'Help section exists: ' + section);
            await link.evaluate(node => node.click());
            assert.deepEqual(await page.evaluate(() => window.helpWindow), [
                'http://shire.test' + href, 'shire-help-%2Fnextcloud%2Findex.php%2Fapps%2Fshi_re%2Freadme-admin', 'popup,width=1024,height=800',
            ]);
        }
        assert.equal(await page.locator('.shire-readme-fallback').isVisible(), false);
        assert.equal(await page.evaluate(() => editorOptions.readOnly && editorReadOnly), true);
        assert.ok(await page.evaluate(() => editorOptions.content.startsWith('# ShiRE')));
        const appHtml = execFileSync('php', ['-d', 'memory_limit=64M', '-d', 'max_execution_time=5', '-r', `
            function p($value) { echo htmlspecialchars((string)$value, ENT_QUOTES); }
            function print_unescaped($value) { echo $value; }
            $_ = ['readmeUrl' => '/apps/shi_re/readme'];
            require 'templates/readme-link.php';
            echo '<input id="invoice-value" value="Entwurf bleibt erhalten">';
        `], {encoding: 'utf8', timeout: 10000});
        await page.setContent('<div id=content><div id=app-content>' + appHtml + '</div></div>');
        await page.addScriptTag({path: path.resolve('js/readme.js')});
        assert.equal(await page.locator('#shire-readme-dialog').count(), 0);
        assert.equal(await page.locator('.shire-readme-link').getAttribute('href'),
            '/apps/shi_re/readme?document=user#h-rechnung-erstellen');
        assert.equal(await page.locator('.shire-readme-link').getAttribute('target'), '_blank');
        await page.evaluate(() => { window.open = (...args) => { window.helpWindow = args; }; });
        await page.locator('.shire-readme-link').click();
        assert.deepEqual(await page.evaluate(() => window.helpWindow), [
            'http://shire.test/apps/shi_re/readme?document=user#h-rechnung-erstellen',
            'shire-help-%2Fapps%2Fshi_re%2Freadme-user', 'popup,width=1024,height=800',
        ]);
        assert.equal(await page.locator('#invoice-value').inputValue(), 'Entwurf bleibt erhalten');
        const userDoc = execFileSync('php', ['-d', 'memory_limit=64M', '-d', 'max_execution_time=5', '-r', `
            function p($value) { echo htmlspecialchars((string)$value, ENT_QUOTES); }
            function print_unescaped($value) { echo $value; }
            $_ = ['document' => 'user', 'readmeUrl' => '/apps/shi_re/readme', 'appUrl' => '/apps/shi_re/'];
            require 'templates/readme.php';
        `], {encoding: 'utf8', timeout: 10000});
        for (const section of ['rechnung-erstellen', 'rechnungsarchiv', 'storno-rechnung']) {
            await page.goto('http://shire.test/apps/shi_re/readme?document=user#h-' + section);
            await page.setContent(userDoc);
            await page.evaluate(() => {
                Element.prototype.scrollIntoView = function () { window.helpHeading = this.textContent; };
            });
            await page.addScriptTag({path: path.resolve('js/readme.js')});
            await page.waitForFunction(() => Boolean(window.helpHeading));
            assert.equal(await page.title(), 'Benutzeranleitung');
            assert.equal(await page.locator('.shire-readme-page > a, .shire-readme-page > h2').count(), 0);
            assert.equal(await page.evaluate(() => window.helpHeading), {
                'rechnung-erstellen': '## Rechnung erstellen',
                'rechnungsarchiv': '## Rechnungsarchiv',
                'storno-rechnung': '## Storno-Rechnung',
            }[section]);
            assert.ok(await page.locator('.shire-readme-fallback a[href="#rechnung-erstellen"]').count());
        }
        const adminDoc = execFileSync('php', ['-d', 'memory_limit=64M', '-d', 'max_execution_time=5', '-r', `
            function p($value) { echo htmlspecialchars((string)$value, ENT_QUOTES); }
            function print_unescaped($value) { echo $value; }
            $_ = ['document' => 'admin', 'readmeUrl' => '/nextcloud/index.php/apps/shi_re/readme', 'appUrl' => '/nextcloud/index.php/apps/shi_re/'];
            require 'templates/readme.php';
        `], {encoding: 'utf8', timeout: 10000});
        await page.goto('http://shire.test/nextcloud/index.php/apps/shi_re/readme?document=admin#systemvoraussetzungen');
        await page.setContent(adminDoc);
        await page.evaluate(() => {
            window.OCA = {Text: {async createEditor({el}) {
                el.innerHTML = '<h2 id="h-systemvoraussetzungen">Systemvoraussetzungen</h2><a href="../README.md#tests">README</a>';
                el.querySelector('h2').scrollIntoView = () => { window.anchorReached = true; };
                return {setReadOnly() {}};
            }}};
        });
        await page.addScriptTag({path: path.resolve('js/readme.js')});
        await page.waitForFunction(() => window.anchorReached === true);
        assert.equal(await page.title(), 'Administrationsanleitung');
        // Simulate a late title reset by the surrounding Nextcloud scripts.
        await page.evaluate(() => { document.title = 'Nextcloud'; });
        await page.waitForFunction(() => document.title === 'Administrationsanleitung');
        // Replacing the title element must also keep the document-specific title.
        await page.evaluate(() => {
            const title = document.createElement('title');
            title.textContent = 'Nextcloud';
            document.querySelector('title').replaceWith(title);
        });
        await page.waitForFunction(() => document.title === 'Administrationsanleitung');
        assert.equal(await page.locator('.shire-readme-rich a').getAttribute('href'),
            '/nextcloud/index.php/apps/shi_re/readme?document=readme#tests');
        assert.match(await page.locator('.shire-readme-fallback').textContent(), /ShiRE administrieren/);
        await page.evaluate(() => {
            window.anchorReached = false;
            window.location.hash = 'h-systemvoraussetzungen';
        });
        await page.waitForFunction(() => window.anchorReached === true);
        await page.evaluate(() => {
            window.anchorReached = false;
            window.dispatchEvent(new Event('shire-help-focus'));
        });
        await page.waitForFunction(() => window.anchorReached === true);
        // Umlaut chapters work with Text's real anchor shape and without Text.
        for (const [anchor, title] of [
            ['h-optionen-fur-die-nutzung', 'Optionen für die Nutzung'],
            ['h-struktur-und-ubernahme', 'Struktur und Übernahme'],
        ]) {
            await page.evaluate(({anchor, title}) => {
                const rich = document.querySelector('.shire-readme-rich');
                rich.innerHTML = '<h2></h2>';
                const heading = rich.querySelector('h2');
                heading.textContent = title;
                const target = document.createElement('a');
                target.id = anchor;
                target.className = 'heading-anchor';
                target.scrollIntoView = () => { window.umlautAnchorReached = anchor; };
                heading.prepend(target);
                window.location.hash = anchor;
            }, {anchor, title});
            await page.waitForFunction(anchor => window.umlautAnchorReached === anchor, anchor);
            await page.evaluate(() => {
                document.querySelector('.shire-readme-rich').hidden = true;
                document.querySelector('.shire-readme-fallback').hidden = false;
                Element.prototype.scrollIntoView = function () { window.fallbackHeading = this.textContent; };
                window.dispatchEvent(new Event('shire-help-focus'));
            });
            assert.equal(await page.evaluate(() => window.fallbackHeading), '##' + (title.startsWith('Struktur') ? '##' : '') + ' ' + title);
            await page.evaluate(() => {
                document.querySelector('.shire-readme-rich').hidden = false;
                document.querySelector('.shire-readme-fallback').hidden = true;
            });
        }
        assert.deepEqual(errors, []);
        console.log('Admin browser: defaults, immediate saves, failure rollback, conditional uploads system check rows and Nextcloud Text viewer integration passed.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
