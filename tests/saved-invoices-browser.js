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
    assert.deepEqual(menu.children.map(entry => entry.text), ['new']);
    const openArchivedInvoice = ns.openArchivedInvoice;
    let downloaded;
    ns.openArchivedInvoice = async name => { downloaded = name; };
    await menu.children[0].click();
    assert.equal(downloaded, 'new.xml');
    assert.equal(menu.children[0].disabled, false);
    wrapper.listeners.keydown({key: 'Escape'});
    assert.equal(menu.hidden, true);
    assert.equal(button.attrs['aria-expanded'], 'false');
    assert.equal(button.focused, true);
    fail = true;
    await assert.rejects(button.click(), /offline/);
    assert.match(menu.children[0].text, /konnten nicht geladen/);
    ns.openArchivedInvoice = openArchivedInvoice;
    const draft = {
        ramID: '2026-002', todayText: '12.11.2026', typeCode: '380', seller: {name: 'Aktueller Aussteller'},
        invoiceData: {
            buyer: {name: 'Entwurfsempfänger', reference: 'Entwurf'},
            items: [{name: 'Aktuelle Leistung', description: 'Entwurfstext', quantity: 1, unitPrice: 120}],
            vatRate: 19, expenses: [],
        },
    };
    const archived = {
        ramID: '2025-010', todayText: '01.12.2025', typeCode: '380', seller: {name: 'Alter Aussteller'},
        invoiceData: {
            buyer: {name: 'Archivempfänger', street: 'Altstraße 1', postcode: '12345', city: 'Berlin', country: 'DE', reference: 'AZ-123'},
            items: [{name: 'Alte Leistung', description: 'Archivbeschreibung', quantity: 2.5, unitPrice: 90}],
            vatRate: 7, expenses: [],
        },
    };
    ns.state = structuredClone(draft);
    let renders = 0, collections = 0;
    ns.showInvoiceForm = () => { renders++; };
    ns.collectFormData = () => { collections++; };
    ns.fetchSavedInvoice = async filename => {
        assert.equal(filename, '2025-010.xml');
        return {text: async () => '<invoice />'};
    };
    ns.parseArchivedInvoice = () => structuredClone(archived);
    await ns.openArchivedInvoice('2025-010.xml');
    assert.equal(ns.state.ramID, archived.ramID);
    assert.equal(collections, 1);
    ns.leaveArchive(false);
    assert.deepEqual(ns.state, {...draft, archiveDraft: null}, 'Back restores the complete draft');
    await ns.openArchivedInvoice('2025-010.xml');
    const [templateButton, templateMenu] = ns.archiveTemplateDropdown().children;
    assert.equal(templateMenu.hidden, true);
    templateButton.click();
    assert.equal(templateMenu.hidden, false);
    assert.equal(templateMenu.children[0].text, 'für neue Rechnung');
    assert.equal(templateMenu.children[1].text, 'für Storno-Rechnung');
    assert.equal(templateMenu.children[1].disabled, true);
    templateMenu.children[0].click();
    assert.equal(ns.state.ramID, draft.ramID, 'Template keeps the reserved new invoice number');
    assert.equal(ns.state.todayText, draft.todayText);
    assert.deepEqual(ns.state.seller, draft.seller);
    assert.equal(JSON.stringify(ns.state.invoiceData.buyer), JSON.stringify(archived.invoiceData.buyer));
    assert.equal(ns.state.invoiceData.items[0].description, 'Archivbeschreibung');
    assert.equal(ns.state.invoiceData.items[0].quantity, 2.5);
    assert.equal(ns.state.invoiceData.items[0].unitPrice, 120);
    assert.equal(ns.state.invoiceData.vatRate, 19);
    assert.equal(renders, 4);
    console.log('Saved invoice dropdown tests passed: empty, refresh, order, selection, Escape and errors.');
    console.log('Archive navigation tests passed: restore draft, copy recipient/reference/description/quantity, retain new number and current prices, disable cancellation.');
})().catch(error => { console.error(error); process.exit(1); });
