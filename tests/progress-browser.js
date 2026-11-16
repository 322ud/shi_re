'use strict';
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
(async () => {
    const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless: true});
    try {
        const page = await browser.newPage();
        page.setDefaultTimeout(6000);
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto('about:blank');
        await page.setContent('<div id="myx-input"></div><div id="myx-output"></div>');
        await page.evaluate(() => {
            window.ShiRE = {state: {detailedProgress: true, windowId: 'window'}};
            window.OC = {requestToken: 'test'};
        });
        for (const file of ['js/dom.js', 'js/api.js', 'js/progress.js']) await page.addScriptTag({path: path.resolve(file)});
        await page.evaluate(() => {
            ShiRE.route = path => path;
            window.snapshot = {current: {time: '11:01:02', text: 'XML wird mit INValidator geprüft …'}, events: []};
            window.polls = 0;
            window.fetch = async (url, options) => {
                const body = JSON.parse(options.body);
                if (url === '/progress/status') {
                    window.polls++;
                    if (body.progressId !== window.operation.progressId || body.windowId !== 'window') throw Error('Wrong operation');
                    return new Response(JSON.stringify({success: true, progress: window.snapshot}), {headers: {'Content-Type': 'application/json'}});
                }
                window.operation = body;
                return new Promise(resolve => { window.finishRequest = resolve; });
            };
            window.task = ShiRE.post('/en16931/validateXmlInvoice').then(() => { window.finished = true; });
        });
        await page.waitForFunction(() => document.querySelector('.myx-busy').textContent === '[11:01:02] XML wird mit INValidator geprüft …');
        assert.equal(await page.evaluate(() => Boolean(window.finished)), false, 'Live progress precedes operation response');
        await page.evaluate(() => {
            snapshot = {current: {time: '11:01:05', text: 'XML-Rechnung wird archiviert …'}, events: [
                {time: '11:01:04', text: 'INValidator-Prüfung abgeschlossen: XML valide.'},
            ]};
        });
        await page.waitForFunction(() => document.querySelector('.myx-busy').textContent.startsWith('[11:01:05]'));
        assert.equal(await page.locator('#myx-output').textContent(), '[11:01:04] INValidator-Prüfung abgeschlossen: XML valide.');
        await page.evaluate(() => {
            snapshot.events.push({time: '11:01:06', text: 'XML-Rechnung archiviert: 26 200.xml'});
            snapshot.current = null;
            finishRequest(new Response(JSON.stringify({success: true})));
            return task;
        });
        assert.equal(await page.locator('#myx-output > div').count(), 2, 'Final drain preserves short completions without duplicates');
        assert.equal(await page.locator('.myx-busy').count(), 0);
        const polls = await page.evaluate(() => window.polls);
        await page.evaluate(async () => {
            ShiRE.state.detailedProgress = false;
            window.fetch = async (url, options) => {
                if (url === '/progress/status' || JSON.parse(options.body).progressId) throw Error('Disabled progress made request');
                return new Response(JSON.stringify({success: true}));
            };
            await ShiRE.post('/en16931/createXmlInvoice');
        });
        assert.equal(await page.evaluate(() => window.polls), polls);
        await page.evaluate(() => {
            ShiRE.state.detailedProgress = true;
            ShiRE.log('Abschluss.');
        });
        assert.match(await page.locator('#myx-output > div').last().textContent(), /^\[\d{2}:\d{2}:\d{2}\] Abschluss\.$/);
        assert.deepEqual(errors, []);
        console.log('Progress browser: live steps before response, server timestamps, final drain, no duplicates and disabled mode passed.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
