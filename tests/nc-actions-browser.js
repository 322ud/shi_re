'use strict';
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
(async () => {
    const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless: true, args: ['--no-sandbox']});
    try {
        const page = await browser.newPage();
        page.setDefaultTimeout(5000);
        const errors = [];
        page.on('pageerror', error => (errors.push(error.message), console.error(error.message)));
        await page.route('http://myx.test/**', route => route.fulfill({contentType: 'text/html', body: '<html lang="de"><body><div id="content"><div id="mount"></div></div></body></html>'}));
        await page.goto('http://myx.test/');
        await page.evaluate(() => {
            window.OC = {config: {}, getLanguage: () => 'de', getLocale: () => 'de_DE', webroot: '', appswebroots: {shi_re: '/apps/shi_re'}, currentUser: 'test'};
            window._oc_config = {};
            window.ShiRE = {};
        });
        await page.addStyleTag({path: path.resolve('css/validation-actions.css')});
        await page.addStyleTag({path: path.resolve('css/style.css')});
        await page.addScriptTag({path: path.resolve('js/validation-actions.js')});
        await page.evaluate(() => {
            window.choices = [];
            window.controls = window.ShiRE.mountReviewActions(document.getElementById('mount'), true,
                choice => window.choices.push(choice), () => window.choices.push('report'), {pdfEnabled: true});
        });
        await page.getByRole('button', {name: 'PDF-Erstellung erneut starten', exact: true}).click();
        assert.deepEqual(await page.evaluate(() => window.choices), ['pdf']);
        await page.keyboard.press('Escape');
        await page.getByRole('button', {name: 'abbrechen', exact: true}).click();
        await page.getByRole('menuitem', {name: 'erstelle neue Rechnung', exact: true}).click();
        await page.keyboard.press('Escape');
        await page.getByRole('button', {name: 'Bericht als PDF speichern', exact: true}).click();
        assert.deepEqual(await page.evaluate(() => window.choices), ['pdf', 'cancel-new', 'report']);
        await page.evaluate(() => window.controls.setBusy(true));
        assert.equal(await page.getByRole('button', {name: 'Bericht als PDF speichern', exact: true}).isDisabled(), true);
        await page.evaluate(() => {
            window.controls.destroy();
            const mount = document.createElement('div'); document.body.appendChild(mount);
            window.ShiRE.mountReviewActions(mount, false, choice => window.choices.push(choice), () => {});
        });
        assert.equal(await page.getByRole('button', {name: 'speichern…', exact: true}).count(), 0);
        assert.equal(await page.getByRole('button', {name: 'abbrechen', exact: true}).count(), 1);
        await page.getByRole('button', {name: 'abbrechen', exact: true}).click();
        await page.getByRole('menuitem', {name: 'ShiRE schließen', exact: true}).waitFor({state: 'visible'});
        assert.deepEqual((await page.getByRole('menuitem').allTextContents()).map(text => text.trim()), ['ShiRE schließen', 'erstelle neue Rechnung']);
        await page.getByRole('menuitem', {name: 'ShiRE schließen', exact: true}).click();
        await page.keyboard.press('Escape');
        await page.getByRole('button', {name: 'abbrechen', exact: true}).click();
        await page.getByRole('menuitem', {name: 'erstelle neue Rechnung', exact: true}).click();
        await page.keyboard.press('Escape');
        assert.deepEqual(await page.evaluate(() => window.choices.slice(-2)), ['cancel-close', 'cancel-new']);

        await page.evaluate(() => {
            document.body.replaceChildren();
            const mount = document.createElement('div'); document.body.appendChild(mount);
            window.ShiRE.mountReviewActions(mount, true, () => {}, null, {pdfEnabled: false});
        });
        await page.getByRole('button', {name: 'speichern…', exact: true}).click();
        await page.getByRole('menuitem', {name: '… und ShiRE schließen', exact: true}).waitFor();
        assert.equal(await page.getByRole('menuitem', {name: 'in PDF einbinden', exact: true}).count(), 0);
        await page.keyboard.press('Escape');

        await page.evaluate(() => {
            document.body.replaceChildren();
            const mount = document.createElement('div'); document.body.appendChild(mount);
            window.ShiRE.mountFinishedActions(mount, () => window.choices.push('new'), () => window.choices.push('close'));
        });
        assert.deepEqual(await page.locator('button').allTextContents(), ['erstelle neue Rechnung', 'ShiRE schließen']);
        await page.getByRole('button', {name: 'erstelle neue Rechnung', exact: true}).click();
        await page.getByRole('button', {name: 'ShiRE schließen', exact: true}).click();
        assert.deepEqual(await page.evaluate(() => window.choices.slice(-2)), ['new', 'close']);
        assert.deepEqual(errors, []);
        console.log('Real Chromium NcActions test passed: menus, actions, disabled state and invalid XML.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
