(function () {
    'use strict';

    const NS = window.MyXRechnung;

    NS.createXmlInvoice = async function () {
        const invoiceData = NS.collectFormData();
        const totals = NS.calculateTotals(invoiceData);

        const data = await NS.post('/en16931/createXmlInvoice', {
            invoiceData: invoiceData,
            totals: totals,
        });

        NS.state.xmlInvoiceCreated = data.xmlInvoiceUrl || null;

        NS.showXmlInvoice();
    };

    NS.showXmlInvoice = function () {
        const input = NS.inputArea();
        NS.clear(input);
        input.className = 'myx-xml-invoice-result';

        input.appendChild(NS.make('h1', {}, 'PDF-Vorschau'));

        if (NS.state.xmlInvoiceCreated) {
            input.appendChild(NS.make('iframe', {
                src: NS.state.xmlInvoiceCreated,
                class: 'myx-pdf-frame',
            }));
        } else {
            input.appendChild(NS.make(
                'p',
                { class: 'myx-message-warning' },
                'Noch keine PDF verfügbar'
            ));
        }

        const actions = NS.make('div', { class: 'myx-actions' });
        actions.appendChild(NS.button('Zurück', NS.showInvoiceForm));
        actions.appendChild(NS.button('Abbrechen', NS.cancelInvoice));
        input.appendChild(actions);
    };
})();
