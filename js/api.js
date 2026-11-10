(function () {
    'use strict';

    const NS = window.ShiRE;

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

    NS.readJsonResponse = async function (response, path) {
        const context = ' (HTTP ' + response.status + ', ' + path + ').';
        let data;
        try {
            data = await response.json();
        } catch (error) {
            throw new Error('Der Server hat keine gültige JSON-Antwort geliefert' + context
                + ' Bitte das Serverprotokoll prüfen.');
        }
        if (response.redirected || !data || typeof data !== 'object' || Array.isArray(data)) {
            throw new Error('Unerwartete Serverantwort' + context);
        }
        return data;
    };

    NS.post = async function (path, payload = {}) {
        payload.windowId = NS.state.windowId;

        const response = await fetch(NS.route(path), {
            method: 'POST',
            headers: headers(),
            body: JSON.stringify(payload),
        });

        const data = await NS.readJsonResponse(response, path);

        if (!response.ok || data.success === false) {
            const error = new Error(data.error || 'Anfrage fehlgeschlagen (HTTP ' + response.status + ', ' + path + ').');
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
