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
        for (const file of ['js/dom.js', 'js/calc.js', 'js/views/form.js']) {
            await page.addScriptTag({path: path.resolve(file)});
        }
        await page.addStyleTag({path: path.resolve('css/style.css')});
        await page.evaluate(() => {
            const ns = window.ShiRE;
            ns.state = {typeCode: '380', ramID: '2026-1', todayText: '12.11.2026',
                seller: {name: 'Aussteller', iban: 'DE123', vatId: 'DE987654321'},
                invoiceData: {buyer: {}, items: [{name: 'Leistung', quantity: 1, unitPrice: 100}], vatRate: 19, expenses: []}};
            ns.createXmlInvoice = () => {};
            ns.showInvoiceForm();
        });
        const lines = await page.locator('.myx-seller div').allTextContents();
        assert.equal(lines[lines.indexOf('IBAN: DE123') + 1], 'USt-ID: DE987654321');
        const fields = ['buyer.reference', 'item.description', 'item.quantity'];
        for (const name of fields) {
            assert.equal(await page.locator(`[name="${name}"]`).evaluate(el => getComputedStyle(el).backgroundColor), 'rgb(252, 228, 236)');
        }
        await page.locator('[name="buyer.reference"]').focus();
        for (const name of fields.slice(1)) {
            await page.keyboard.press('Tab');
            assert.equal(await page.evaluate(() => document.activeElement.name), name);
        }
        await page.keyboard.press('Tab');
        assert.equal(await page.evaluate(() => document.activeElement.textContent), 'Rechnung erstellen');
        for (const name of fields.slice().reverse()) {
            await page.keyboard.press('Shift+Tab');
            assert.equal(await page.evaluate(() => document.activeElement.name), name);
        }
        const xml = fs.readFileSync(path.resolve('config/en16931.xml'), 'utf8');
        const vatId = await page.evaluate(xml => window.ShiRE.parseArchivedInvoice(xml).seller.vatId, xml);
        assert.ok(vatId && xml.includes(vatId), 'Archive reads VAT registration with VA scheme');
        assert.deepEqual(errors, []);
        console.log('Invoice form browser: seller VAT ID, pink inputs, forward/reverse tab order and archived VAT ID passed.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
