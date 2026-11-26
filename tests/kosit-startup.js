'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
(async () => {
    for (const [status, shouldInstall] of [
        [{installed: null, admin: true}, false],
        [{installed: '0.5.1', enabled: true, validation_enabled: false, admin: true, auto_update: true, update_available: true}, false],
        [{installed: null, admin: false}, false],
        [{installed: null, admin: true, check_error: 'offline'}, false],
        [{installed: '0.5.1', enabled: true, admin: true, auto_update: true, update_available: true}, true],
        [{installed: '0.5.1', enabled: true, admin: false, auto_update: true, update_available: true}, true],
        [{installed: '0.5.1', enabled: true, admin: true, update_available: true, latest: '0.5.2'}, false],
    ]) {
        const calls = [];
        let starts = 0;
        const ns = {
            state: {},
            setMessageAreaVisible(visible) { this.state.messageAreaVisible = visible; },
            root: () => ({}), initLayout() {}, log() {}, stopHeartbeat() {},
            startInvoice: async () => { starts++; },
            post: async (path, data) => { calls.push(data?.action === 'auto_update' ? 'auto_update' : path); return {status: path === '/pdf/status'
                ? {enabled: false, message: 'PDF-Modul ist deaktiviert.'} : status}; },
        };
        vm.runInNewContext(fs.readFileSync(require.resolve('../js/main.js'), 'utf8'), {
            window: {ShiRE: ns, addEventListener() {}}, document: {readyState: 'complete'},
        });
        await new Promise(setImmediate);
        assert.equal(starts, 1, 'Invoice remains usable if release check fails');
        assert.equal(calls.includes('auto_update'), shouldInstall);
        assert.equal(calls[0], '/validator/status');
        assert.ok(calls.includes('/pdf/status'), 'PDF dependencies checked at startup');
        assert.equal(ns.state.pdfStatus.enabled, false);
        assert.equal(ns.state.messageAreaVisible, status.message_area !== false);
        assert.equal(ns.state.validationActive, status.validation_active ?? Boolean(status.installed && status.enabled && status.validation_enabled !== false));
    }
    for (const outcome of ['deferred', 'updated', 'disabled']) {
        const logs = [];
        let starts = 0;
        const ns = {
            state: {}, root: () => ({}), initLayout() {}, setMessageAreaVisible() {}, stopHeartbeat() {},
            log: text => logs.push(text), startInvoice: async () => { starts++; },
            post: async (path, data) => data?.action === 'auto_update' ? {update: outcome} : {
                status: path === '/pdf/status' ? {enabled: true} : {
                    installed: '1.0.2', latest: '1.0.4', enabled: true,
                    validation_enabled: true, auto_update: true, update_available: true, admin: false,
                },
            },
        };
        vm.runInNewContext(fs.readFileSync(require.resolve('../js/main.js'), 'utf8'), {
            window: {ShiRE: ns, addEventListener() {}}, document: {readyState: 'complete'},
        });
        await new Promise(setImmediate);
        assert.equal(starts, 1);
        assert.ok(logs.includes('KoSIT-App: Release 1.0.4 verfügbar.'));
        if (outcome === 'deferred') assert.ok(logs.includes('Rechnungserstellung aktiv, Update wird zurückgestellt.'));
        assert.equal(logs.includes('INValidator wurde aktualisiert.'), outcome === 'updated');
    }
    console.log('Startup release-check and administratively authorized installation tests passed.');
})().catch(error => { console.error(error); process.exit(1); });
