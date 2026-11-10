'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');

(async () => {
    let response;
    const ns = {state: {windowId: 'window'}, route: path => path};
    vm.runInNewContext(fs.readFileSync(require.resolve('../js/api.js'), 'utf8'), {
        window: {MyXRechnung: ns},
        fetch: async () => response,
    });
    for (const body of ['', '<html>Gateway timeout</html>', '{']) {
        response = new Response(body, {status: 502});
        await assert.rejects(ns.post('/en16931/review'), /keine gültige JSON-Antwort.*HTTP 502, \/en16931\/review/);
    }
    for (const body of ['null', '[]', 'true']) {
        response = new Response(body);
        await assert.rejects(ns.post('/pdf/status'), /Unerwartete Serverantwort.*HTTP 200/);
    }
    response = new Response('{"success":false,"error":"Entwurf gesperrt","code":"locked","deletionToken":"token"}', {status: 409});
    await assert.rejects(ns.post('/en16931/review'), error => {
        assert.equal(error.message, 'Entwurf gesperrt');
        assert.equal(error.code, 'locked');
        assert.equal(error.deletionToken, 'token');
        return true;
    });
    response = new Response('{"success":true,"filename":"26 200.xml"}');
    assert.equal((await ns.post('/en16931/review')).filename, '26 200.xml');
    const logs = [];
    let finished = 0;
    Object.assign(ns, {
        inputArea: () => ({appendChild() {}}), clear() {},
        make: () => ({appendChild() {}}), log: message => logs.push(message),
        post: async () => ({status: {enabled: true, templateVersion: 'v1'}}),
    });
    vm.runInNewContext(fs.readFileSync(require.resolve('../js/views/create-xml-invoice.js'), 'utf8'), {
        window: {MyXRechnung: ns, addEventListener() {}},
        OC: {requestToken: 'csrf'}, fetch: async () => response,
    });
    ns.showFinishedInvoice = () => { finished++; };
    response = new Response('', {status: 500, headers: {'Content-Type': 'application/json'}});
    await ns.showAcceptedPdf('26 200.xml', 'hash');
    assert.equal(finished, 1);
    assert.match(logs[0], /XML ist archiviert.*HTTP 500, \/en16931\/downloadPdfInvoice/);
    console.log('API response tests passed, including empty PDF errors after XML acceptance.');
})().catch(error => { console.error(error); process.exit(1); });
