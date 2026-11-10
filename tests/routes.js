'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const source = fs.readFileSync(require.resolve('../js/dom.js'), 'utf8');
for (const root of ['', '/nextcloud']) {
    for (const rewritten of [true, false]) {
        const ns = {APP_ID: 'my_xrechnung'};
        vm.runInNewContext(source, {
            window: {MyXRechnung: ns},
            OC: {generateUrl: path => root + (rewritten ? '' : '/index.php') + path},
        });
        for (const path of ['/pdf/invoices', '/en16931/review', '/en16931/downloadPdfInvoice', '/pdf/saved']) {
            assert.equal(ns.route(path), root + '/index.php/apps/my_xrechnung' + path);
        }
    }
}
const ns = {APP_ID: 'my_xrechnung'};
vm.runInNewContext(source, {window: {MyXRechnung: ns}});
assert.equal(ns.route('/pdf/invoices'), '/index.php/apps/my_xrechnung/pdf/invoices');
console.log('Routes passed: rewritten URLs, existing index.php, subdirectories and fallback.');
