'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
class Element {
    constructor(tag, attrs = {}, text = '') { Object.assign(this, {tag, attrs, text, children: [], listeners: {}}); }
    appendChild(child) { this.children.push(child); }
    setAttribute(key, value) { this.attrs[key] = value; }
    addEventListener(key, handler) { this.listeners[key] = handler; }
    focus() { this.focused = true; }
}
(async () => {
    let invoices = [], fail = false, calls = 0;
    const ns = {
        make: (tag, attrs, text) => new Element(tag, attrs, text),
        button: (text, handler) => Object.assign(new Element('button', {}, text), {click: handler}),
        clear: element => { element.children = []; },
        post: async path => {
            assert.equal(path, '/pdf/invoices'); calls++;
            if (fail) throw new Error('offline');
            return {invoices};
        },
    };
    vm.runInNewContext(fs.readFileSync(require.resolve('../js/views/form.js'), 'utf8'), {window: {ShiRE: ns}});
    const wrapper = ns.savedInvoiceDropdown();
    const [button, menu] = wrapper.children;
    assert.equal(button.text, 'Archiv');
    assert.equal(menu.hidden, true);
    await button.click();
    assert.equal(menu.children[0].text, 'Keine Rechnungen vorhanden.');
    await button.click();
    invoices = [{filename: 'new.pdf'}, {filename: 'new.xml'}, {filename: 'old.pdf'}];
    await button.click();
    assert.equal(calls, 2, 'Refresh on reopening');
    assert.deepEqual(menu.children.map(entry => entry.text), ['new.pdf', 'new.xml', 'old.pdf']);
    let downloaded;
    ns.downloadSavedInvoice = async name => { downloaded = name; };
    await menu.children[0].click();
    assert.equal(downloaded, 'new.pdf');
    assert.equal(menu.children[0].disabled, false);
    wrapper.listeners.keydown({key: 'Escape'});
    assert.equal(menu.hidden, true);
    assert.equal(button.attrs['aria-expanded'], 'false');
    assert.equal(button.focused, true);
    fail = true;
    await assert.rejects(button.click(), /offline/);
    assert.match(menu.children[0].text, /konnten nicht geladen/);
    console.log('Saved invoice dropdown tests passed: empty, refresh, order, selection, Escape and errors.');
})().catch(error => { console.error(error); process.exit(1); });
