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
        NS.releasePdfDownload();
        const input = NS.inputArea();
        NS.clear(input);
        input.className = 'myx-xml-validation-result';

        NS.log('XRechnung (XML) wird geprüft');
        const data = await NS.post('/en16931/validateXmlInvoice');

        NS.log(
            data.valid
            ? 'XRechnung (XML) ist valide'
            : 'XRechnung (XML) ist nicht valide'
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
        if (data.valid === true) {
            NS.log('PDF-Verfügbarkeit wird geprüft …');
            try {
                const {status} = await NS.post('/pdf/status');
                if (!status.enabled) {
                    NS.log(status.message);
                    return;
                }
                if (!actions.isConnected) return;
                NS.log('PDF-Rechnung wird erstellt …');
                const response = await fetch(NS.route('/en16931/downloadPdfInvoice'), {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', requesttoken: OC.requestToken},
                    body: JSON.stringify({xmlHash: data.xmlHash, templateVersion: status.templateVersion}),
                });
                const type = (response.headers.get('Content-Type') || '').split(';')[0].trim().toLowerCase();
                if (!response.ok || response.redirected || type !== 'application/pdf') {
                    let message = 'PDF-Download fehlgeschlagen (HTTP ' + response.status + ').';
                    if (type === 'application/json') {
                        const error = await response.json();
                        message = error.error || message;
                    }
                    throw new Error(message);
                }
                const blob = await response.blob();
                // Während der Erzeugung kann bereits eine neue Rechnung geöffnet worden sein.
                if (!actions.isConnected) return;
                const url = URL.createObjectURL(blob);
                NS.state.pdfDownloadUrl = url;
                NS.log('PDF-Rechnung ist bereit.');
                // Browser können neue Tabs nach einer asynchronen Anfrage blockieren.
                const pdfWindow = window.open(url, '_blank');
                if (pdfWindow) {
                    pdfWindow.opener = null;
                } else {
                    input.appendChild(NS.make('iframe', {
                        src: url,
                        title: 'PDF-Rechnung',
                        class: 'myx-validation-frame',
                    }));
                }
            } catch (error) {
                NS.log('PDF: ' + error.message);
            }
        }
    };

    NS.releasePdfDownload = function () {
        if (NS.state.pdfDownloadUrl) {
            URL.revokeObjectURL(NS.state.pdfDownloadUrl);
            NS.state.pdfDownloadUrl = null;
        }
    };
    window.addEventListener('pagehide', NS.releasePdfDownload);

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
