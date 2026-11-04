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
        if (data.valid === true) {
            const downloadButton = NS.button('XRechnung (XML) speichern', async function () {
                downloadButton.disabled = true;
                try {
                    await NS.downloadXmlInvoice(data.xmlHash);
                } finally {
                    downloadButton.disabled = false;
                }
            }, 'myx-primary');
            actions.appendChild(downloadButton);
        }
        actions.appendChild(NS.button('weitere Rechnung erstellen', NS.startInvoice, 'myx-primary'));
        input.appendChild(actions);
    };

    NS.downloadXmlInvoice = async function (xmlHash) {
        const response = await fetch(NS.route('/en16931/validateXmlInvoice'), {
            method: 'POST',
            headers: {
                'Accept': 'application/xml, application/json',
                'Content-Type': 'application/json',
                requesttoken: OC.requestToken,
            },
            body: JSON.stringify({download: true, xmlHash: xmlHash}),
        });
        const contentType = (response.headers.get('Content-Type') || '').split(';')[0].trim().toLowerCase();
        const isXml = contentType === 'application/xml' || contentType === 'text/xml';
        if (!response.ok || response.redirected || !isXml) {
            let message = '';
            if (contentType === 'application/json' || contentType.endsWith('+json')) {
                try {
                    const error = await response.json();
                    message = error.error || error.message || '';
                } catch (_) {
                    // Auch eine defekte JSON-Fehlerantwort soll den HTTP-Status nicht verdecken.
                }
            }
            if (!message) {
                if (response.redirected || response.status === 401) {
                    message = 'Bitte die Seite neu laden und gegebenenfalls erneut anmelden.';
                } else if (response.status === 404) {
                    message = 'Die Download-Adresse wurde nicht gefunden. Deployment und Nextcloud-Routen prüfen.';
                } else if (response.status === 403 || response.status === 412) {
                    message = 'Zugriff oder Sicherheitsprüfung fehlgeschlagen. Bitte die Seite neu laden.';
                } else {
                    message = 'Der Server hat keine XML-Datei geliefert. Bitte das Nextcloud-Serverprotokoll prüfen.';
                }
            }
            throw new Error('XML-Download fehlgeschlagen (HTTP ' + response.status + '): ' + message);
        }
        const url = URL.createObjectURL(await response.blob());
        const link = NS.make('a', {href: url, download: 'en16931.xml'});
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    };
})();
