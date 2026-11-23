'use strict';
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
(async () => {
    const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless: true});
    try {
        for (const validationActive of [false, true]) {
        for (const pdfEnabled of [false, true]) {
            for (const choice of (pdfEnabled ? ['pdf-close', 'pdf-new'] : ['accept-close', 'accept-new'])) {
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
                await page.evaluate(({pdfEnabled, validationActive}) => {
                    const ns = window.ShiRE;
                    window.calls = [];
                    ns.state = {validationActive, pdfStatus: {enabled: pdfEnabled}, windowId: 'window', typeCode: '380', ramID: '26 200',
                        seller: {}, invoiceData: {buyer: {}, items: [{name: 'Leistung', quantity: 1, unitPrice: 100}], vatRate: 19, expenses: []}};
                    ns.stopHeartbeat = () => {};
                    ns.startInvoice = async () => { ns.releasePdfDownload(); window.destination = 'new'; };
                    ns.closeApp = () => { ns.releasePdfDownload(); window.destination = 'close'; };
                    ns.downloadSavedInvoice = async filename => { window.downloaded = filename; };
                    ns.route = path => path;
                    ns.post = async (path, body) => {
                        calls.push({path, body});
                        if (path.endsWith('/createXmlInvoice')) return {xmlInvoiceFile: 'tmp.xml', xmlHash: 'hash', ramID: '26 200'};
                        if (path.endsWith('/validateXmlInvoice')) return {skipped: !validationActive, valid: validationActive ? true : null, html: validationActive ? '<p>Bericht</p>' : null, validationToken: 'token', xmlHash: 'hash'};
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
                }, {pdfEnabled, validationActive});
                for (const cancellation of [false, true]) {
                    for (const optionalSteps of [false, true]) {
                        await page.evaluate(({cancellation, optionalSteps}) => {
                            const ns = window.ShiRE;
                            ns.state.cancellationFilename = cancellation ? 'original.xml' : null;
                            ns.state.invoiceData.archivedTotals = {vat: -19, grand: -119};
                            ns.state.pdfStatus.xmlEmbedding = optionalSteps;
                            ns.state.pdfStatus.pdfaVerification = optionalSteps;
                            ns.showInvoiceForm();
                        }, {cancellation, optionalSteps});
                        const expected = ['erzeugt eine XML-Rechnung'];
                        if (validationActive) expected.push('validiert die XML-Rechnung auf das XRechnung-Szenario');
                        if (pdfEnabled) {
                            expected.push('erzeugt eine PDF-Rechnung');
                            if (optionalSteps) expected.push('bindet die XML-Rechnung in die PDF-Rechnung ein',
                                'prüft die PDF-Rechnung auf den Standard PDF/A-3');
                        }
                        assert.deepEqual(await page.locator('.myx-creation-steps li').allTextContents(), expected);
                        assert.equal(await page.locator('.myx-primary + .myx-creation-steps').count(), 1);
                        assert.equal(await page.locator('.myx-creation-steps + .myx-saved-invoices').count(), 1);
                    }
                }
                await page.evaluate(() => {
                    window.ShiRE.state.cancellationFilename = null;
                    window.ShiRE.showInvoiceForm();
                });
                const downloadEvent = pdfEnabled ? page.waitForEvent('download') : null;
                await page.getByRole('button', {name: 'Rechnung erstellen', exact: true}).click();
                if (pdfEnabled) {
                    const download = await downloadEvent;
                    assert.equal(download.suggestedFilename(), '26 200.pdf');
                    assert.equal(await download.failure(), null);
                } else {
                    await page.waitForFunction(() => window.downloaded === '26 200.xml');
                }
                await page.getByRole('button', {name: 'erstelle neue Rechnung', exact: true}).waitFor();
                assert.deepEqual(await page.locator('.myx-review-actions button').allTextContents(), ['erstelle neue Rechnung', 'ShiRE schließen']);
                assert.equal(await page.evaluate(() => window.destination), undefined);
                await page.getByRole('button', {name: choice.endsWith('new') ? 'erstelle neue Rechnung' : 'ShiRE schließen', exact: true}).click();
                await page.waitForFunction(() => window.destination);
                const calls = await page.evaluate(() => window.calls);
                if (choice.startsWith('cancel-')) assert.deepEqual(calls.map(call => call.path), ['/en16931/cancel']);
                else {
                    assert.deepEqual(calls.slice(0, 3).map(call => call.path), ['/en16931/createXmlInvoice', '/en16931/validateXmlInvoice', '/en16931/review']);
                    assert.equal(calls[2].body.accept, true);
                    assert.equal(calls[2].body.pdf, choice.startsWith('pdf-'));
                    assert.equal(calls[2].body.validationToken, 'token');
                }
                if (choice.startsWith('pdf-')) assert.equal(calls.at(-1).path, '/en16931/downloadPdfInvoice');
                assert.equal(await page.evaluate(() => window.destination), choice.endsWith('new') ? 'new' : 'close');
                if (choice.startsWith('accept-')) assert.equal(await page.evaluate(() => window.downloaded), '26 200.xml');
                assert.equal(await page.getByRole('button', {name: 'Bericht als PDF speichern', exact: true}).count(), 0);
                assert.deepEqual(errors, []);
                await page.close();
            }
        }
        }
        console.log('Invoice completion passed: automatic XML/PDF downloads with and without validation, two final buttons and destinations.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
