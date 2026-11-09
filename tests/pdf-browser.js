'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');

class Element {
    constructor(tag, attributes = {}, text = '') {
        this.tag = tag;
        this.attributes = attributes;
        this.textContent = text;
        this.children = [];
        this.isConnected = true;
    }
    appendChild(child) { this.children.push(child); }
}

(async () => {
    for (const scenario of ['valid', 'popup-blocked', 'invalid', 'disabled', 'changed', 'login', 'navigated']) {
        const input = new Element('div');
        const actions = [];
        const calls = [];
        const revoked = [];
        const logs = [];
        const opened = [];
        const pdfWindow = {opener: {}};
        let blobs = 0;
        const ns = {
            state: {pdfDownloadUrl: 'blob:previous'},
            inputArea: () => input,
            clear: element => { element.children = []; },
            make: (tag, attributes, text) => {
                const element = new Element(tag, attributes, text);
                if (attributes?.class === 'myx-actions') actions.push(element);
                return element;
            },
            button: text => new Element('button', {}, text),
            log: message => logs.push(message),
            route: path => path,
            post: async path => {
                calls.push(path);
                if (path === '/pdf/status') return {status: {enabled: scenario !== 'disabled', templateVersion: 'v1', message: 'deaktiviert'}};
                return {valid: scenario !== 'invalid', xmlHash: 'hash', html: '<p>report</p>'};
            },
        };
        const listeners = {};
        vm.runInNewContext(fs.readFileSync(require.resolve('../js/views/create-xml-invoice.js'), 'utf8'), {
            window: {
                MyXRechnung: ns,
                addEventListener: (name, fn) => { listeners[name] = fn; },
                open: (url, target) => {
                    opened.push({url, target});
                    return scenario === 'popup-blocked' ? null : pdfWindow;
                },
            },
            OC: {requestToken: 'csrf'},
            URL: {createObjectURL: () => { blobs++; return 'blob:pdf'; }, revokeObjectURL: url => revoked.push(url)},
            fetch: async (path, options) => {
                calls.push(path);
                assert.deepEqual(JSON.parse(options.body), {xmlHash: 'hash', templateVersion: 'v1'});
                assert.equal(options.headers.requesttoken, 'csrf');
                if (scenario === 'navigated') actions[0].isConnected = false;
                return {
                    ok: scenario !== 'changed', status: scenario === 'changed' ? 409 : 200,
                    redirected: scenario === 'login',
                    headers: {get: name => name === 'Content-Type'
                        ? (scenario === 'changed' ? 'application/json' : 'application/pdf') : '26%20200.pdf'},
                    blob: async () => ({}), json: async () => ({error: 'erneut validieren'}),
                };
            },
        });
        await ns.showXmlValidation();
        assert.ok(revoked.includes('blob:previous'), 'Previous invoice blob released');
        const links = actions[0].children.filter(child => child.tag === 'a');
        const success = scenario === 'valid' || scenario === 'popup-blocked';
        assert.equal(links.length, 0, 'No PDF save button');
        assert.equal(blobs, success ? 1 : 0);
        assert.equal(opened.length, success ? 1 : 0, 'Open only after successful PDF response');
        assert.equal(logs[0], 'XRechnung (XML) wird geprüft');
        assert.equal(logs[1], scenario === 'invalid' ? 'XRechnung (XML) ist nicht valide' : 'XRechnung (XML) ist valide');
        if (scenario === 'invalid') assert.ok(!calls.includes('/pdf/status'), 'No PDF for invalid XML');
        if (scenario === 'disabled') assert.ok(!calls.includes('/en16931/downloadPdfInvoice'), 'Disabled module does not generate');
        if (scenario === 'changed') assert.ok(logs.includes('PDF: erneut validieren'));
        if (success) {
            assert.deepEqual(opened[0], {url: 'blob:pdf', target: '_blank'});
            assert.ok(logs.includes('PDF-Rechnung wird erstellt …'));
            assert.ok(logs.includes('PDF-Rechnung ist bereit.'));
            assert.ok(!input.children.some(child => child.tag === 'p'), 'Status messages appear in log only');
            if (scenario === 'popup-blocked') {
                assert.ok(input.children.some(child => child.tag === 'iframe' && child.attributes.src === 'blob:pdf'), 'Blocked popup opens embedded PDF');
            } else {
                assert.equal(pdfWindow.opener, null);
            }
            listeners.pagehide();
            assert.ok(revoked.includes('blob:pdf'));
            assert.equal(ns.state.pdfDownloadUrl, null);
        }
    }
    console.log('PDF browser tests passed: valid/invalid XML, disabled module, stale XML, login redirect, navigation and blob cleanup.');
})().catch(error => { console.error(error); process.exit(1); });
