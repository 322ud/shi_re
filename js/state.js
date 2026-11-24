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
            // randomUUID is only exposed in secure contexts; local HTTP test hosts
            // still support cryptographically secure getRandomValues.
            const bytes = crypto.getRandomValues(new Uint8Array(16));
            bytes[6] = (bytes[6] & 0x0f) | 0x40;
            bytes[8] = (bytes[8] & 0x3f) | 0x80;
            const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
            id = [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16),
                hex.slice(16, 20), hex.slice(20)].join('-');
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
