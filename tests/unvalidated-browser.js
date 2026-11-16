'use strict';
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
(async () => {
    const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless: true});
    try {
        for (const pdfEnabled of [false, true]) {
            for (const choice of ['accept-close', 'accept-new', 'cancel-close', 'cancel-new', ...(pdfEnabled ? ['pdf'] : [])]) {
                const page = await browser.newPage();
                page.setDefaultTimeout(5000);
                const errors = [];
                page.on('pageerror', error => errors.push(error.message));
                await page.route('http://shire.test/**', route => route.fulfill({contentType: 'text/html', body: '<div id="myx-input"></div><div id="myx-output"></div>'}));
                await page.goto('http://shire.test/');
                await page.evaluate(() => {
                    window.OC = {config: {}, getLanguage: () => 'de', getLocale: () => 'de_DE', webroot: '', appswebroots: {shi_re: '/apps/shi_re'}, currentUser: 'test'};
                    window._oc_config = {};
                    window.ShiRE = {};
                });
                for (const file of ['js/dom.js', 'js/calc.js', 'js/draft.js', 'js/views/form.js', 'js/views/create-xml-invoice.js', 'js/validation-actions.js']) {
                    await page.addScriptTag({path: path.resolve(file)});
                }
                await page.addStyleTag({path: path.resolve('css/validation-actions.css')});
                await page.evaluate(pdfEnabled => {
                    const ns = window.ShiRE;
                    window.calls = [];
                    ns.state = {validationActive: false, pdfStatus: {enabled: pdfEnabled}, windowId: 'window', typeCode: '380', ramID: '26 200',
                        seller: {}, invoiceData: {buyer: {}, items: [{name: 'Leistung', quantity: 1, unitPrice: 100}], vatRate: 19, expenses: []}};
                    ns.stopHeartbeat = () => {};
                    ns.startInvoice = async () => { ns.releasePdfDownload(); window.destination = 'new'; };
                    ns.closeApp = () => { ns.releasePdfDownload(); window.destination = 'close'; };
                    ns.downloadSavedInvoice = async filename => { window.downloaded = filename; };
                    ns.route = path => path;
                    ns.post = async (path, body) => {
                        calls.push({path, body});
                        if (path.endsWith('/createXmlInvoice')) return {xmlInvoiceFile: 'en16931.xml', xmlHash: 'hash', ramID: '26 200'};
                        if (path.endsWith('/validateXmlInvoice')) return {skipped: true, valid: null, validationToken: 'token', xmlHash: 'hash'};
                        if (path.endsWith('/review')) return {filename: '26 200.xml'};
                        if (path === '/pdf/status') return {status: {enabled: pdfEnabled, templateVersion: 'v1'}};
                        if (path.endsWith('/cancel')) return {};
                        throw Error('Unexpected call: ' + path);
                    };
                    window.fetch = async (path, options) => {
                        calls.push({path, body: JSON.parse(options.body)});
                        return {ok: true, headers: {get: () => 'application/pdf'}, blob: async () => new Blob(['%PDF-1.7'], {type: 'application/pdf'})};
                    };
                    ns.showInvoiceForm();
                }, pdfEnabled);
                await page.getByRole('button', {name: 'Rechnung erstellen', exact: true}).click();
                await page.getByRole('menuitem', {name: 'XML speichern und ShiRE schließen', exact: true}).waitFor({state: 'visible'});
                const labels = (await page.getByRole('menuitem').allTextContents()).map(text => text.trim());
                assert.deepEqual(labels, [...(pdfEnabled ? ['PDF-Rechnung erstellen'] : []), 'XML speichern und ShiRE schließen', 'XML speichern und neue Rechnung starten']);
                if (choice.startsWith('cancel-')) {
                    await page.keyboard.press('Escape');
                    await page.getByRole('button', {name: 'abbrechen', exact: true}).click();
                }
                const label = {'accept-close': 'XML speichern und ShiRE schließen', 'accept-new': 'XML speichern und neue Rechnung starten',
                    'cancel-close': 'ShiRE schließen', 'cancel-new': 'neue Rechnung starten', pdf: 'PDF-Rechnung erstellen'}[choice];
                await page.getByRole('menuitem', {name: label, exact: true}).click();
                if (choice === 'pdf') await page.getByRole('button', {name: 'PDF-Rechnung speichern', exact: true}).waitFor();
                else await page.waitForFunction(() => window.destination);
                const calls = await page.evaluate(() => window.calls);
                if (choice.startsWith('cancel-')) assert.deepEqual(calls.map(call => call.path), ['/en16931/cancel']);
                else {
                    assert.deepEqual(calls.slice(0, 3).map(call => call.path), ['/en16931/createXmlInvoice', '/en16931/validateXmlInvoice', '/en16931/review']);
                    assert.equal(calls[2].body.accept, true);
                    assert.equal(calls[2].body.pdf, choice === 'pdf');
                    assert.equal(calls[2].body.validationToken, 'token');
                }
                if (choice === 'pdf') assert.equal(calls.at(-1).path, '/en16931/downloadPdfInvoice');
                else assert.equal(await page.evaluate(() => window.destination), choice.endsWith('new') ? 'new' : 'close');
                if (choice.startsWith('accept-')) assert.equal(await page.evaluate(() => window.downloaded), '26 200.xml');
                assert.equal(await page.getByRole('button', {name: 'Bericht als PDF speichern', exact: true}).count(), 0);
                assert.deepEqual(errors, []);
                await page.close();
            }
        }
        console.log('Unvalidated invoice menus passed: conditional PDF, XML save, cancellation, destinations and no report.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
