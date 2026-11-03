(function () {
    'use strict';

    const NS = window.MyXRechnung;

    function headers() {
        const h = {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
        };

        if (typeof OC !== 'undefined' && OC.requestToken) {
            h.requesttoken = OC.requestToken;
        }

        return h;
    }

    NS.post = async function (path, payload = {}) {
        payload.windowId = NS.state.windowId;

        const response = await fetch(NS.route(path), {
            method: 'POST',
            headers: headers(),
                                     body: JSON.stringify(payload),
        });

        const data = await response.json();

        if (!response.ok || data.success === false) {
            const error = new Error(data.error || 'Anfrage fehlgeschlagen.');
            error.code = data.code;
            error.deletionToken = data.deletionToken;
            throw error;
        }

        return data;
    };

    NS.startHeartbeat = function () {
        NS.stopHeartbeat();

        NS.state.heartbeatTimer = window.setInterval(async function () {
            if (!NS.state.ramID) return;

            try {
                await NS.post('/en16931/ping');
            } catch (e) {
                NS.stopHeartbeat();
                NS.log('Fehler: Entwurf nicht mehr aktiv. ' + e.message);
            }
        }, NS.HEARTBEAT_MS);
    };

    NS.stopHeartbeat = function () {
        if (NS.state.heartbeatTimer) {
            window.clearInterval(NS.state.heartbeatTimer);
            NS.state.heartbeatTimer = null;
        }
    };
})();
