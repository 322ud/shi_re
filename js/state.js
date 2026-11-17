(function () {
    'use strict';

    window.ShiRE = window.ShiRE || {};

    const NS = window.ShiRE;

    NS.APP_ID = 'shi_re';
    NS.HEARTBEAT_MS = 30000;

    NS.state = {
        windowId: getWindowId(),
        validationToken: null,
        ramID: null,
        seller: null,
        typeCode: null,
        todayText: null,
        xmlInvoiceFile: null,
        invoiceData: null,
        heartbeatTimer: null,
    };

    function getWindowId() {
        let id = sessionStorage.getItem('shi_re.windowId');

        if (!id) {
            id = crypto.randomUUID();
            sessionStorage.setItem('shi_re.windowId', id);
        }

        return id;
    }

    NS.resetStateAfterClose = function () {
        sessionStorage.removeItem('shi_re.windowId');

        NS.state.windowId = getWindowId();
        NS.state.validationToken = null;
        NS.state.ramID = null;
        NS.state.seller = null;
        NS.state.typeCode = null;
        NS.state.todayText = null;
        NS.state.xmlInvoiceFile = null;
        NS.state.invoiceData = null;
        NS.state.archiveDraft = null;
        delete NS.state.archiveFilename;
        delete NS.state.cancellationFilename;
    };
})();
