(function () {
    'use strict';

    const NS = window.MyXRechnung;

    /*
     * Startansicht der App.
     *
     * Enthält nur den Button:
     *
     *   Neue Rechnung
     */
    NS.showStart = function () {
        NS.stopHeartbeat();
        NS.initLayout();

        const input = NS.inputArea();
        const output = NS.outputArea();

        input.className = 'myx-start';
        output.className = '';

        input.appendChild(NS.button('Neue Rechnung', NS.startInvoice, 'myx-primary'));

        NS.log('Bereit.');
    };
})();
