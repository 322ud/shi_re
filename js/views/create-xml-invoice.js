(function () {
    'use strict';

    const NS = window.ShiRE;

    NS.createXmlInvoice = async function () {
        const invoiceData = NS.collectFormData();

        const data = await NS.post('/en16931/createXmlInvoice', {
            invoiceData: invoiceData,
        });


        NS.state.xmlInvoiceFile = data.xmlInvoiceFile || null;
        NS.state.xmlHash = data.xmlHash;
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
        NS.state.validationToken = null;
        NS.releasePdfDownload();
        const input = NS.inputArea();
        NS.clear(input);
        input.className = 'myx-xml-validation-result';
        const busy = NS.make('div', {class: 'myx-busy', role: 'status', 'aria-live': 'polite'});
        busy.appendChild(NS.make('span', {class: 'icon-loading-small', 'aria-hidden': 'true'}));
        const busyText = NS.make('span', {}, 'XRechnung wird mit KoSIT geprüft …');
        busy.appendChild(busyText);
        input.appendChild(busy);
        let data;
        try {
            data = await NS.post('/en16931/validateXmlInvoice');
            NS.state.validationToken = NS.state.invoiceInterrupted ? null : (data.validationToken || null);
        } catch (error) {
            NS.log('KoSIT-Prüfung fehlgeschlagen: ' + error.message);
            data = {valid: false, xmlHash: NS.state.xmlHash};
        } finally {
            busy.hidden = true;
        }
        NS.log(data.valid ? 'XRechnung (XML) ist valide' : 'XRechnung (XML) ist nicht valide');
        if (data.html && !NS.state.invoiceInterrupted) {
            const frame = NS.make('iframe', {
                title: 'KoSIT-Prüfbericht',
                // Keep the print document outside the visible invoice flow. Scripts remain forbidden.
                sandbox: 'allow-same-origin allow-modals',
                style: 'position:fixed;width:0;height:0;border:0;visibility:hidden;',
                'aria-hidden': 'true', tabindex: '-1',
            });
            frame.srcdoc = data.html;
            NS.validationReportFrame = frame;
            document.body.appendChild(frame);
        }
        if (NS.state.invoiceInterrupted) return;
        let pending = false;
        let menu = null;
        async function choose(choice) {
            if (pending) return;
            pending = true;
            if (menu) menu.setBusy(true);
            busy.hidden = false;
            busyText.textContent = choice.startsWith('cancel-') ? 'Rechnung wird verworfen …' : 'XML wird angenommen …';
            let finished = false;
            try {
                const accepted = choice === 'pdf' || choice.startsWith('accept-');
                const result = await NS.post('/en16931/review', {xmlHash: data.xmlHash, accept: accepted,
                    validationToken: NS.state.validationToken, pdf: choice === 'pdf'});
                finished = true;
                if (accepted) NS.log('XML-Rechnung archiviert');
                NS.stopHeartbeat();
                if (menu) menu.destroy();
                if (choice === 'pdf') {
                    await NS.showAcceptedPdf(result.filename, data.xmlHash);
                } else {
                    NS.state.validationToken = null;
                    if (accepted) await NS.downloadSavedInvoice(result.filename);
                    if (choice.endsWith('-new')) await NS.startInvoice();
                    else NS.closeApp();
                }
            } catch (error) {
                NS.log(error.message);
                // Acceptance cannot be undone. Never offer the acceptance menu again afterwards.
                if (finished) NS.showFinishedInvoice();
            } finally {
                busy.hidden = true;
                if (!finished) { if (menu) menu.setBusy(false); pending = false; }
            }
            return finished;
        }
        if (data.valid === true && NS.state.pdfStatus?.enabled && NS.state.validationToken) {
            if (await choose('pdf')) return;
            if (NS.state.invoiceInterrupted) return;
        }
        const host = NS.make('div');
        input.appendChild(host);
        menu = NS.mountReviewActions(host, data.valid === true, choose,
            NS.validationReportFrame ? () => { if (!pending) NS.saveValidationReport(); } : null);
    };

    NS.saveValidationReport = function () {
        const frame = NS.validationReportFrame;
        if (!frame) return;
        try {
            frame.contentWindow.focus();
            frame.contentWindow.print();
        } catch (error) {
            NS.log('Bericht konnte nicht gedruckt werden: ' + error.message);
        }
    };

    NS.closeApp = function () {
        NS.stopHeartbeat();
        NS.releasePdfDownload();
        window.location.assign(OC.generateUrl('/'));
    };

    NS.showFinishedInvoice = function (savePdf) {
        const input = NS.inputArea();
        NS.clear(input);
        const host = NS.make('div');
        input.appendChild(host);
        NS.finishedActions = NS.mountFinishedActions(host, NS.startInvoice, NS.closeApp, savePdf,
            NS.validationReportFrame ? NS.saveValidationReport : null);
    };

    NS.showAcceptedPdf = async function (filename, xmlHash) {
        const input = NS.inputArea();
        NS.clear(input);
        const busy = NS.make('div', {class: 'myx-busy', role: 'status'});
        busy.appendChild(NS.make('span', {class: 'icon-loading-small', 'aria-hidden': 'true'}));
        busy.appendChild(NS.make('span', {}, 'PDF-Rechnung wird aus der archivierten XML erstellt …'));
        input.appendChild(busy);
        try {
            const {status} = await NS.post('/pdf/status');
            if (!status.enabled) throw new Error(status.message);
            const response = await fetch(NS.route('/en16931/downloadPdfInvoice'), {
                method: 'POST',
                headers: {'Content-Type': 'application/json', requesttoken: OC.requestToken},
                body: JSON.stringify({filename, xmlHash, templateVersion: status.templateVersion,
                    windowId: NS.state.windowId, validationToken: NS.state.validationToken}),
            });
            const type = (response.headers.get('Content-Type') || '').split(';')[0].trim().toLowerCase();
            if (!response.ok || response.redirected || type !== 'application/pdf') {
                let message = 'PDF-Erstellung fehlgeschlagen (HTTP ' + response.status + ').';
                if (type === 'application/json') message = (await NS.readJsonResponse(response, '/en16931/downloadPdfInvoice')).error || message;
                throw new Error(message);
            }
            NS.state.pdfDownloadUrl = URL.createObjectURL(await response.blob());
            NS.log('PDF-Rechnung erstellt');
            NS.log('PDF-Rechnung archiviert');
            NS.showFinishedInvoice(function () {
                const link = NS.make('a', {href: NS.state.pdfDownloadUrl, download: filename.replace(/\.xml$/, '.pdf')});
                document.body.appendChild(link);
                link.click();
                link.remove();
            });
            input.appendChild(NS.make('iframe', {src: NS.state.pdfDownloadUrl, title: 'PDF-Rechnung', class: 'myx-validation-frame'}));
        } catch (error) {
            NS.showFinishedInvoice();
            NS.log('XML ist archiviert. PDF: ' + error.message);
        } finally {
            NS.state.validationToken = null;
        }
    };

    NS.releasePdfDownload = function () {
        if (NS.validationReportFrame) {
            NS.validationReportFrame.remove();
            NS.validationReportFrame = null;
        }
        if (NS.finishedActions) {
            NS.finishedActions.$destroy();
            NS.finishedActions = null;
        }
        if (NS.state.pdfDownloadUrl) {
            URL.revokeObjectURL(NS.state.pdfDownloadUrl);
            NS.state.pdfDownloadUrl = null;
        }
    };
    window.addEventListener('pagehide', function () {
        NS.state.invoiceInterrupted = true;
        NS.state.validationToken = null;
        NS.releasePdfDownload();
    });

})();
