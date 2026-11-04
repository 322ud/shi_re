(function () {
    'use strict';

    const NS = window.MyXRechnung;

    NS.createXmlInvoice = async function () {
        const invoiceData = NS.collectFormData();

        const data = await NS.post('/en16931/createXmlInvoice', {
            invoiceData: invoiceData,
        });

        NS.stopHeartbeat();

        NS.state.xmlInvoiceFile = data.xmlInvoiceFile || null;
        NS.state.ramID = data.ramID || null;



        NS.log(
            'XML-Rechnung erstellt: Datei ' +
            NS.state.xmlInvoiceFile +
            ', Rechnungsnummer ' +
            NS.state.ramID
        );

        await NS.showXmlValidation();
    };

    NS.showXmlValidation = async function () {
        const input = NS.inputArea();
        NS.clear(input);
        input.className = 'myx-xml-validation-result';

        const data = await NS.post('/en16931/validateXmlInvoice');

        NS.log(
            data.valid
            ? 'XML-Datei ist valide'
            : 'XML-Datei ist nicht valide'
        );

        const frame = NS.make('iframe', {
            class: 'myx-validation-frame',
            sandbox: '',
        });

        frame.srcdoc = data.html || '<p>Keine Validator-Ausgabe vorhanden.</p>';
        input.appendChild(frame);

        const actions = NS.make('div', { class: 'myx-actions' });
        actions.appendChild(NS.button('Zurück', NS.showInvoiceForm));
        input.appendChild(actions);
    };
})();
