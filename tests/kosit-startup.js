'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
(async () => {
    for (const [status, shouldInstall] of [
        [{installed: null, admin: true}, true],
        [{installed: null, admin: false}, false],
        [{installed: null, admin: true, check_error: 'offline'}, false],
        [{installed: '0.5.1', enabled: true, admin: true, auto_update: true, update_available: true}, true],
        [{installed: '0.5.1', enabled: true, admin: false, auto_update: true, update_available: true}, false],
        [{installed: '0.5.1', enabled: true, admin: true, update_available: true, latest: '0.5.2'}, false],
    ]) {
        const calls = [];
        let starts = 0;
        const ns = {
            state: {},
            root: () => ({}), initLayout() {}, log() {}, stopHeartbeat() {},
            startInvoice: async () => { starts++; },
            post: async (path) => { calls.push(path); return {status: path === '/pdf/status'
                ? {enabled: false, message: 'PDF-Modul ist deaktiviert.'} : status}; },
        };
        vm.runInNewContext(fs.readFileSync(require.resolve('../js/main.js'), 'utf8'), {
            window: {ShiRE: ns, addEventListener() {}}, document: {readyState: 'complete'},
        });
        await new Promise(setImmediate);
        assert.equal(starts, 1, 'Invoice remains usable if release check fails');
        assert.equal(calls.includes('/admin/validator/install'), shouldInstall);
        assert.equal(calls[0], '/validator/status');
        assert.ok(calls.includes('/pdf/status'), 'PDF dependencies checked at startup');
        assert.equal(ns.state.pdfStatus.enabled, false);
    }
    console.log('Startup release-check and admin-only installation tests passed.');
})().catch(error => { console.error(error); process.exit(1); });
