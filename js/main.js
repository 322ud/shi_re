(function () {
    'use strict';

    const NS = window.MyXRechnung;

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
                'Die Rechnung konnte nicht geöffnet werden.'
            )
        );

        input.appendChild(
            NS.button('Erneut versuchen', startSafely, 'myx-primary')
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
        await startSafely();
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
