(function () {
    'use strict';

    const NS = window.ShiRE;

    async function startSafely() {
        try {
            await NS.startInvoice();
        } catch (error) {
            renderStartError(error);
        }
    }

    function renderStartError(error) {
        const input = NS.inputArea();

        NS.clear(input);
        NS.clear(NS.outputArea());

        input.appendChild(
            NS.make(
                'p',
                { class: 'myx-error' },
                'Das Erstellen einer neuen Rechnung ist derzeit nicht möglich: Temporäre Dateien aus einem früheren Versuch wurden noch nicht gelöscht - dies kann ein paar Minuten dauern. Wenn Du diese Seite dauerhaft nicht laden kannst, wende Dich an den Administrator dieser Seite.'
            )
        );

        if (error.code === 'active_draft' && error.deletionToken) {
            const deleteButton = NS.button('aktiven Rechnungsentwurf löschen', async function () {
                const confirmation = window.prompt("bestätige mit 'ja'!");
                if (confirmation !== 'ja') {
                    return;
                }

                deleteButton.disabled = true;
                try {
                    await NS.post('/en16931/deleteActiveDraft', {
                        confirmation: confirmation,
                        deletionToken: error.deletionToken,
                    });
                    NS.stopHeartbeat();
                    await startSafely();
                } finally {
                    deleteButton.disabled = false;
                }
            });
            input.appendChild(deleteButton);
        }

        NS.log('Fehler: ' + (
            error instanceof Error
                ? error.message
                : String(error)
        ));
    }

    async function bootstrap() {
        const root = NS.root();

        if (!root) {
            return;
        }

        NS.initLayout();
        const notices = [];
        NS.log('KoSIT-App: aktuelles GitHub-Release wird geprüft …');
        try {
            const {status} = await NS.post('/validator/status');
            if (status.check_error) notices.push(status.check_error);
            if (!status.installed || status.validation_enabled === false) {
                // Installation is explicitly requested in the admin settings.
            } else if (!status.enabled) {
                notices.push('KoSIT-App ist deaktiviert. Bitte durch einen Administrator aktivieren lassen.');
            } else if (status.update_available) {
                if (status.auto_update && status.admin && !status.check_error) {
                    NS.log('INValidator wird aktualisiert …');
                    await NS.post('/admin/validator/install');
                    notices.push('INValidator wurde aktualisiert.');
                } else notices.push('KoSIT-App: Release ' + status.latest + ' verfügbar. Bitte administrativ aktualisieren.');
            }
        } catch (error) {
            notices.push('KoSIT-Einrichtung: ' + error.message);
        }
        try {
            const {status} = await NS.post('/pdf/status');
            NS.state.pdfStatus = status;
            if (!status.enabled) notices.push(status.message);
        } catch (error) {
            NS.state.pdfStatus = {enabled: false};
            notices.push('PDF-Modul konnte nicht geprüft werden: ' + error.message);
        }
        await startSafely();
        notices.forEach(NS.log);
    }

    window.addEventListener('pagehide', function () {
        NS.stopHeartbeat();
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            void bootstrap();
        }, { once: true });
    } else {
        void bootstrap();
    }
})();
