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

        NS.stopHeartbeat();

        NS.state.xmlInvoiceFile = data.xmlInvoiceFile || null;
        NS.state.ramID = data.ramID || null;

        NS.showXmlInvoice();
    };

    NS.showXmlInvoice = function () {
        const input = NS.inputArea();
        NS.clear(input);
        input.className = 'myx-xml-invoice-result';

        input.appendChild(NS.make('h1', {}, 'XML-Rechnung erstellt'));

        input.appendChild(NS.make(
            'p',
            {},
            'Die XML-Rechnung wurde erstellt und gespeichert.'
        ));

        if (NS.state.xmlInvoiceFile) {
            input.appendChild(NS.make(
                'p',
                {},
                'Datei: ' + NS.state.xmlInvoiceFile
            ));
        }

        if (NS.state.ramID) {
            input.appendChild(NS.make(
                'p',
                {},
                'Rechnungsnummer: ' + NS.state.ramID
            ));
        }

        const actions = NS.make('div', { class: 'myx-actions' });
        actions.appendChild(NS.button('Zurück', NS.showInvoiceForm));
        input.appendChild(actions);
    };
})();
