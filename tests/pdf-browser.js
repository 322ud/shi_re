'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');

class Element {
    constructor(tag, attributes = {}, text = '') {
        Object.assign(this, {tag, attributes, textContent: text, children: [], isConnected: true});
        this.contentWindow = {focus() {}, print: () => { this.printed = true; }};
    }
    remove() { this.isConnected = false; }
    appendChild(child) { this.children.push(child); }
    click() { this.clicked = true; }
    addEventListener(name, handler) { (this.events ||= {})[name] = handler; }
}
(async () => {
    for (const choice of ['cancel-close', 'cancel-new', 'accept-stay', 'pdf']) {
        for (const valid of [true, false]) {
            if (valid === choice.startsWith('cancel')) continue;
            const input = new Element('div');
            const body = new Element('body');
            let finishedShown = false;
            const calls = [], logs = [];
            const events = {};
            let menu, choose, report, downloaded, destination, stopped = false;
            const ns = {
                state: {pdfDownloadUrl: null, windowId: 'window', pdfStatus: {enabled: choice === 'pdf' || !valid}}, inputArea: () => input,
                clear: element => { element.children = []; },
                make: (tag, attrs, text) => new Element(tag, attrs, text),
                button: text => new Element('button', {}, text),
                log: message => logs.push(message), route: path => path,
                stopHeartbeat: () => { stopped = true; },
                startInvoice: async () => { destination = 'new'; },
                downloadSavedInvoice: async name => { downloaded = name; },
                mountFinishedActions(host, start, close) { finishedShown = true; },
                mountReviewActions: (host, isValid, callback, print) => {
                    assert.equal(isValid, valid);
                    choose = callback; report = print;
                    menu = {busy: false, destroyed: false, setBusy(value) { this.busy = value; }, destroy() { this.destroyed = true; }};
                    return menu;
                },
                post: async (path, payload) => {
                    calls.push(path);
                    if (path.endsWith('validateXmlInvoice')) return {valid, html: '<p>report</p>', xmlHash: 'hash', validationToken: valid ? 'token' : null};
                    if (path === '/pdf/status') return {status: {enabled: true, templateVersion: 'v1'}};
                    assert.equal(path, '/en16931/review');
                    assert.equal(payload.accept, !choice.startsWith('cancel'));
                    assert.equal(payload.xmlHash, 'hash');
                    assert.equal(payload.validationToken, valid ? 'token' : null);
                    assert.equal(payload.pdf, choice === 'pdf');
                    if (valid) assert.equal(menu, undefined, 'Automatic completion has no intermediate actions');
                    else assert.equal(menu.busy, true);
                    return {filename: payload.accept ? '26 200.xml' : null};
                },
            };
            vm.runInNewContext(fs.readFileSync(require.resolve('../js/views/create-xml-invoice.js'), 'utf8'), {
                window: {ShiRE: ns, setTimeout() {}, addEventListener(name, handler) { events[name] = handler; }, location: {assign: () => { destination = 'close'; }}},
                document: {body},
                OC: {requestToken: 'csrf', generateUrl: path => path},
                URL: {createObjectURL: () => 'blob:pdf', revokeObjectURL() {}},
                fetch: async (path, options) => {
                    assert.equal(menu, undefined, 'No decision menu before automatic PDF creation');
                    assert.deepEqual(JSON.parse(options.body), {filename: '26 200.xml', xmlHash: 'hash', templateVersion: 'v1', windowId: 'window', validationToken: 'token'});
                    assert.equal(options.headers.requesttoken, 'csrf');
                    calls.push(path);
                    return {ok: true, headers: {get: () => 'application/pdf'}, blob: async () => ({})};
                },
            });
            await ns.showXmlValidation();
            if (!valid) {
                assert.deepEqual(calls, ['/en16931/validateXmlInvoice']);
                report();
                assert.ok(body.children.find(child => child.tag === 'iframe').printed);
                await choose(choice);
                assert.equal(menu.destroyed, true);
            } else {
                assert.equal(menu, undefined);
                assert.equal(finishedShown, true);
                assert.deepEqual(calls, choice === 'pdf'
                    ? ['/en16931/validateXmlInvoice', '/en16931/review', '/pdf/status', '/en16931/downloadPdfInvoice']
                    : ['/en16931/validateXmlInvoice', '/en16931/review']);
                if (choice === 'pdf') assert.ok(body.children.find(child => child.tag === 'a' && child.clicked));
            }
            assert.equal(stopped, true);
            ns.state.validationToken = 'leftover';
            events.pagehide();
            assert.equal(ns.state.invoiceInterrupted, true);
            assert.equal(ns.validationReportFrame, null, 'Report released when leaving');
            assert.equal(ns.state.validationToken, null);
            if (choice === 'pdf') {
                assert.ok(calls.includes('/en16931/downloadPdfInvoice'));
                const preview = input.children.find(child => child.tag === 'iframe' && child.attributes.src === 'blob:pdf');
                assert.ok(preview);
                preview.events.load();
                assert.ok(logs.includes('PDF-Rechnung zur Anzeige und zum Speichern bereit.'));
                assert.ok(!logs.some(message => message.startsWith('PDF-Erstellung fehlgeschlagen')));
            } else {
                assert.equal(destination, choice === 'accept-stay' ? undefined : choice.endsWith('new') ? 'new' : 'close');
                assert.equal(downloaded, choice.startsWith('accept') ? '26 200.xml' : undefined);
            }
        }
    }
    const storage = new Map();
    const loadState = () => {
        const window = {};
        vm.runInNewContext(fs.readFileSync(require.resolve('../js/state.js'), 'utf8'), {
            window, crypto: {randomUUID: () => 'window'},
            sessionStorage: {getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value)},
        });
        return window.ShiRE.state;
    };
    const beforeReload = loadState();
    beforeReload.validationToken = 'secret';
    const afterReload = loadState();
    assert.equal(afterReload.windowId, beforeReload.windowId);
    assert.equal(afterReload.validationToken, null, 'Reload cannot restore validation token');
    console.log('Review browser tests passed: explicit acceptance, cancellation, printing, destinations and archive-based PDF.');
})().catch(error => { console.error(error); process.exit(1); });
