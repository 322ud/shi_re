(function () {
    'use strict';
    const NS = window.ShiRE;
    const paths = new Map([
        ['/en16931/createXmlInvoice', 'Rechnungsdaten werden geprüft …'],
        ['/en16931/validateXmlInvoice', 'XML-Rechnung wird vorbereitet …'],
        ['/en16931/review', 'Rechnung wird verarbeitet …'],
        ['/en16931/cancel', 'Rechnung wird verworfen …'],
        ['/en16931/downloadPdfInvoice', 'PDF-Werkzeuge und Vorlagen werden geprüft …'],
    ]);
    NS.progressTime = () => new Intl.DateTimeFormat('de-DE', {
        timeZone: 'Europe/Berlin', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
    }).format(new Date());
    NS.trackProgress = async function (path, body, action) {
        if (!NS.state.detailedProgress || !paths.has(path)) return action();
        const bytes = crypto.getRandomValues(new Uint8Array(16));
        body.progressId = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
        const windowId = NS.state.windowId;
        const input = NS.inputArea();
        let busy = input.querySelector('.myx-busy:not([hidden])');
        const ownsBusy = !busy;
        if (!busy) {
            busy = NS.make('div', {class: 'myx-busy', role: 'status', 'aria-live': 'polite'});
            busy.appendChild(NS.make('span', {class: 'icon-loading-small', 'aria-hidden': 'true'}));
            busy.appendChild(NS.make('span'));
            input.appendChild(busy);
        }
        const label = busy.lastElementChild;
        label.textContent = '[' + NS.progressTime() + '] ' + paths.get(path);
        let seen = 0;
        NS.progressReported = false;
        let active = true;
        let poll = null;
        const consume = async () => {
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 3000);
            try {
                const response = await fetch(NS.route('/validator/status'), {
                    method: 'POST', signal: controller.signal,
                    headers: {'Content-Type': 'application/json', requesttoken: OC.requestToken},
                    body: JSON.stringify({action: 'progress', progressId: body.progressId, windowId}),
                });
                if (!response.ok) return;
                const data = await NS.readJsonResponse(response, '/validator/status');
                if (!active || NS.state.invoiceInterrupted || NS.state.windowId !== windowId) return;
                const snapshot = data.progress;
                if (!snapshot) return;
                for (const event of snapshot.events.slice(seen)) NS.log('[' + event.time + '] ' + event.text);
                seen = snapshot.events.length;
                NS.progressReported = seen > 0;
                if (snapshot.current && busy.isConnected) {
                    label.textContent = '[' + snapshot.current.time + '] ' + snapshot.current.text;
                }
            } catch (error) {
                // The invoice operation and its normal error reporting remain authoritative.
            } finally {
                clearTimeout(timeout);
            }
        };
        const timer = setInterval(() => {
            if (poll || NS.state.invoiceInterrupted) return;
            poll = consume().finally(() => { poll = null; });
        }, 500);
        try {
            return await action();
        } finally {
            clearInterval(timer);
            if (poll) await poll;
            await consume(); // Collect short steps and the final completion after the main response.
            active = false;
            if (ownsBusy) busy.remove();
        }
    };
})();
