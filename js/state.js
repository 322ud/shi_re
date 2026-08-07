(function () {
    'use strict';

    window.MyXRechnung = window.MyXRechnung || {};

    const NS = window.MyXRechnung;

    NS.APP_ID = 'my_xrechnung';
    NS.HEARTBEAT_MS = 30000;

    NS.state = {
        windowId: getWindowId(),
        ramID: null,
        sellerName: null,
        todayText: null,
        xmlInvoiceFile: null,
        invoiceData: null,
        heartbeatTimer: null,
    };

    function getWindowId() {
        let id = sessionStorage.getItem('my_xrechnung.windowId');

        if (!id) {
            id = crypto.randomUUID();
            sessionStorage.setItem('my_xrechnung.windowId', id);
        }

        return id;
    }

    NS.resetStateAfterClose = function () {
        sessionStorage.removeItem('my_xrechnung.windowId');

        NS.state.windowId = getWindowId();
        NS.state.ramID = null;
        NS.state.sellerName = null;
        NS.state.todayText = null;
        NS.state.xmlInvoiceFile = null;
        NS.state.invoiceData = null;
    };
})();
