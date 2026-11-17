'use strict';
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
(async () => {
    const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless: true});
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.setContent('<div id="myx-input"></div><div id="myx-output"></div>');
        await page.evaluate(() => { window.ShiRE = {}; });
        for (const file of ['js/dom.js', 'js/calc.js', 'js/draft.js', 'js/views/form.js']) {
            await page.addScriptTag({path: path.resolve(file)});
        }
        await page.addStyleTag({path: path.resolve('css/style.css')});
        await page.evaluate(() => {
            const ns = window.ShiRE;
            ns.state = {typeCode: '380', ramID: '2026-1', todayText: '12.11.2026',
                seller: {name: 'Aussteller', iban: 'DE123', vatId: 'DE987654321'},
                invoiceData: {buyer: {}, items: [{name: 'Leistung', quantity: 0, unitPrice: 100}], vatRate: 19, expenses: []}};
            ns.createXmlInvoice = () => {};
            ns.closeApp = () => { window.closeAppCalled = true; };
            ns.post = async path => {
                window.cancelPath = path;
                if (window.cancelFails) throw new Error('Entwurf konnte nicht gelöscht werden.');
            };
            ns.showInvoiceForm();
        });
        assert.equal(await page.evaluate(() => document.activeElement.name), 'buyer.reference');
        assert.equal(await page.evaluate(() => window.ShiRE.defaultInvoiceData({}).items[0].quantity), 0);
        const lines = await page.locator('.myx-seller div').allTextContents();
        assert.ok(lines.includes('IBAN: DE123, USt-ID: DE987654321'));
        assert.equal(await page.locator('.myx-vat-text').textContent(), 'MwSt (19 %)');
        assert.equal(await page.locator('.myx-grand-total-spacer').textContent(), 'Summe');
        const closeButton = page.getByRole('button', {name: 'ShiRE schließen', exact: true});
        assert.equal(await closeButton.isVisible(), true);
        const archiveBounds = await page.getByRole('button', {name: 'Archiv', exact: true}).boundingBox();
        const closeBounds = await closeButton.boundingBox();
        assert.ok(closeBounds.x >= archiveBounds.x + archiveBounds.width);
        assert.equal(closeBounds.y, archiveBounds.y);
        await page.evaluate(() => { window.cancelFails = true; });
        await closeButton.click();
        assert.equal(await page.evaluate(() => Boolean(window.closeAppCalled)), false);
        assert.equal(await closeButton.isEnabled(), true);
        await page.evaluate(() => { window.cancelFails = false; });
        await closeButton.click();
        assert.equal(await page.evaluate(() => window.cancelPath), '/en16931/cancel');
        assert.equal(await page.evaluate(() => window.closeAppCalled), true);
        const fields = ['buyer.reference', 'item.description', 'item.quantity'];
        const background = field => field.evaluate(el => getComputedStyle(el).backgroundColor);
        const color = field => field.evaluate(el => getComputedStyle(el).color);
        assert.equal(await color(page.locator('[name="item.quantity"]')), 'rgb(204, 0, 0)', 'Zero hours are red');
        for (const name of fields) {
            const field = page.locator(`[name="${name}"]`);
            await field.fill('');
            assert.equal(await color(field), 'rgb(204, 0, 0)', 'Empty field text is red');
            assert.notEqual(await background(field), 'rgb(252, 228, 236)', 'No pink background');
            assert.equal(await field.evaluate(el => getComputedStyle(el, '::placeholder').color), 'rgb(204, 0, 0)');
            await field.fill(name === 'item.quantity' ? '2' : 'Eingabe');
            assert.notEqual(await color(field), 'rgb(204, 0, 0)', 'Filled field stays neutral');
            await field.fill('');
            assert.equal(await color(field), 'rgb(204, 0, 0)', 'Clearing restores red');
        }
        await page.locator('[name="buyer.reference"]').focus();
        for (const name of ['item.description']) {
            await page.keyboard.press('Tab');
            assert.equal(await page.evaluate(() => document.activeElement.name), name);
        }
        await page.keyboard.press('Tab');
        assert.equal(await page.evaluate(() => document.activeElement.textContent), 'Rechnung erstellen');
        for (const name of ['item.description', 'buyer.reference']) {
            await page.keyboard.press('Shift+Tab');
            assert.equal(await page.evaluate(() => document.activeElement.name), name);
        }
        const xml = fs.readFileSync(path.resolve('config/XRechnung.xml'), 'utf8');
        const vatId = await page.evaluate(xml => window.ShiRE.parseArchivedInvoice(xml).seller.vatId, xml);
        assert.ok(vatId && xml.includes(vatId), 'Archive reads VAT registration with VA scheme');
        const cancellationXml = fs.readFileSync(path.resolve('config/Storno.xml'), 'utf8');
        await page.evaluate(xml => {
            const ns = window.ShiRE;
            Object.assign(ns.state, ns.parseArchivedInvoice(xml));
            ns.state.ramID = '26 202';
            ns.state.todayText = '17.11.2026';
            ns.state.cancellationFilename = '26 201.xml';
            ns.state.invoiceData.items[0].description = 'Vollständige Stornierung zu 26 201';
            ns.state.validationActive = false;
            ns.createXmlInvoice = () => { window.cancellationCreated = true; };
            ns.showInvoiceForm();
        }, cancellationXml);
        assert.equal(await page.locator('h1').textContent(), 'neue Storno-Rechnung Nr. 26 202 vom 17.11.2026');
        assert.equal(await page.locator('[name="item.quantity"]').count(), 0);
        assert.deepEqual(await page.locator('.myx-actions button:visible').allTextContents(), ['Storno-Rechnung erstellen', 'abbrechen']);
        assert.equal(await page.locator('#myx-invoice-form input:not([readonly]), #myx-invoice-form textarea:not([readonly])').count(), 0);
        assert.equal(await page.locator('[name="item.description"]').inputValue(), 'Vollständige Stornierung zu 26 201');
        assert.equal(await page.locator('.myx-grand-total').textContent(), '-119,00 €');
        await page.getByRole('button', {name: 'Storno-Rechnung erstellen', exact: true}).click();
        assert.equal(await page.evaluate(() => window.cancellationCreated), true);
        await page.evaluate(() => {
            window.closeAppCalled = false;
            window.ShiRE.stopHeartbeat = () => { window.heartbeatStopped = true; };
            window.ShiRE.startInvoice = async () => { window.newInvoiceStarted = true; };
        });
        await page.getByRole('button', {name: 'abbrechen', exact: true}).click();
        await page.evaluate(() => { window.cancelFails = true; });
        await page.getByRole('button', {name: 'neue Rechnung erstellen', exact: true}).click();
        assert.equal(await page.evaluate(() => Boolean(window.newInvoiceStarted)), false);
        assert.equal(await page.getByRole('button', {name: 'neue Rechnung erstellen', exact: true}).isEnabled(), true);
        await page.evaluate(() => { window.cancelFails = false; });
        await page.getByRole('button', {name: 'neue Rechnung erstellen', exact: true}).click();
        assert.equal(await page.evaluate(() => window.newInvoiceStarted && window.heartbeatStopped), true);
        assert.equal(await page.evaluate(() => window.cancelPath), '/en16931/cancel');
        await page.getByRole('button', {name: 'ShiRE schließen', exact: true}).click();
        assert.equal(await page.evaluate(() => window.closeAppCalled), true);

        assert.deepEqual(errors, []);
        console.log('Invoice form browser: seller VAT ID, red empty inputs and zero hours, forward/reverse tab order and archived VAT ID passed.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
