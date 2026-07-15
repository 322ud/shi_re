(function () {
    'use strict';

    const NS = window.MyXRechnung;

    function bootstrap() {
        const r = NS.root();

        if (!r) {
            return;
        }

        NS.clear(r);
        r.appendChild(NS.make('section', { id: 'myx-input' }));
        r.appendChild(NS.make('section', { id: 'myx-output' }));

        NS.showStart();
    }

    window.addEventListener('pagehide', function () {
        NS.stopHeartbeat();
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrap);
    } else {
        bootstrap();
    }
})();
