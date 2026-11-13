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
            ns.showInvoiceForm();
        });
        assert.equal(await page.evaluate(() => document.activeElement.name), 'buyer.reference');
        assert.equal(await page.evaluate(() => window.ShiRE.defaultInvoiceData({}).items[0].quantity), 0);
        const lines = await page.locator('.myx-seller div').allTextContents();
        assert.ok(lines.includes('IBAN: DE123, USt-ID: DE987654321'));
        assert.equal(await page.locator('.myx-vat-text').textContent(), 'MwSt (19 %)');
        assert.equal(await page.locator('.myx-grand-total-spacer').textContent(), 'Summe');
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
        const xml = fs.readFileSync(path.resolve('config/en16931.xml'), 'utf8');
        const vatId = await page.evaluate(xml => window.ShiRE.parseArchivedInvoice(xml).seller.vatId, xml);
        assert.ok(vatId && xml.includes(vatId), 'Archive reads VAT registration with VA scheme');
        assert.deepEqual(errors, []);
        console.log('Invoice form browser: seller VAT ID, red empty inputs and zero hours, forward/reverse tab order and archived VAT ID passed.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
