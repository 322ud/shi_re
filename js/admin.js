(function () {
    'use strict';
    function init() {
        const root = document.getElementById('myx-admin');
        if (!root) return;
        const output = document.getElementById('myx-admin-result');
        async function post(path, body = {}) {
            const response = await fetch(OC.generateUrl('/apps/my_xrechnung' + path), {
                method: 'POST',
                headers: {'Content-Type': 'application/json', requesttoken: OC.requestToken},
                body: JSON.stringify(body),
            });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.error || 'Anfrage fehlgeschlagen.');
            return data;
        }
        function bind(id, action) {
            const button = document.getElementById(id);
            button.addEventListener('click', async () => {
                button.disabled = true;
                output.textContent = 'Bitte warten …';
                try { await action(); }
                catch (error) { output.textContent = error.message; }
                finally { button.disabled = false; }
            });
        }
        bind('myx-save-draft-settings', async () => {
            await post('/admin/draft/settings', {allow_draft_deletion: document.getElementById('myx-allow-draft-deletion').checked});
            output.textContent = 'Entwurfseinstellung gespeichert. Rechnungs-App neu laden.';
        });
        bind('myx-save-validator', async () => {
            await post('/admin/validator/settings', {hide_navigation: document.getElementById('myx-hide-validator').checked});
            output.textContent = 'Gespeichert. Die Navigation wird beim nächsten Seitenaufruf aktualisiert.';
        });
        bind('myx-check-validator', async () => {
            const {status} = await post('/validator/status');
            if (status.check_error) throw new Error(status.check_error);
            if (!status.installed) {
                output.textContent = 'Aktuelle Validator-App wird installiert …';
                await post('/admin/validator/install');
                output.textContent = 'Validator-App installiert. Seite neu laden. Java und Prüfszenarien können in der KoSIT-Administration eingerichtet werden.';
            } else {
                output.textContent = status.update_available
                    ? 'Release ' + status.latest + ' verfügbar. Bitte über den administrativen Release-Deploymentweg aktualisieren.'
                    : 'Installierte Validator-App ist aktuell.';
                if (!status.enabled) output.textContent += ' Die App ist deaktiviert; bitte in der App-Verwaltung aktivieren.';
            }
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once: true});
    else init();
})();
