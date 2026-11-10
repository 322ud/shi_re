'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const source = fs.readFileSync(require.resolve('../js/dom.js'), 'utf8');
for (const root of ['', '/nextcloud']) {
    for (const rewritten of [true, false]) {
        const ns = {APP_ID: 'shi_re'};
        vm.runInNewContext(source, {
            window: {ShiRE: ns},
            OC: {generateUrl: path => root + (rewritten ? '' : '/index.php') + path},
        });
        for (const path of ['/pdf/invoices', '/en16931/review', '/en16931/downloadPdfInvoice', '/pdf/saved']) {
            assert.equal(ns.route(path), root + '/index.php/apps/shi_re' + path);
        }
    }
}
const ns = {APP_ID: 'shi_re'};
vm.runInNewContext(source, {window: {ShiRE: ns}});
assert.equal(ns.route('/pdf/invoices'), '/index.php/apps/shi_re/pdf/invoices');
console.log('Routes passed: rewritten URLs, existing index.php, subdirectories and fallback.');
