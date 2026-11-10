(function () {
    'use strict';
    function init() {
        const root = document.getElementById('myx-admin');
        if (!root) return;
        const output = document.getElementById('myx-admin-result');
        async function post(path, body = {}) {
            const response = await fetch(OC.generateUrl('/apps/shi_re' + path), {
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
        const pdfOutput = document.getElementById('myx-pdf-status');
        const veraPdfOutput = document.getElementById('myx-verapdf-status');
        function showPdfStatus(status) {
            const veraPdfVersion = (status.versions || {}).verapdf || '';
            veraPdfOutput.hidden = !veraPdfVersion;
            veraPdfOutput.querySelector('strong').textContent = veraPdfVersion;
            pdfOutput.textContent = status.enabled ? 'PDF-Modul ist aktiv.'
                : 'PDF-Modul ist deaktiviert. ' + (status.errors || []).join(' ');
            if (status.enabled && !status.pdfaVerification) {
                pdfOutput.textContent += ' Zusätzliche veraPDF-Prüfung ist nicht eingerichtet.';
            }
        }
        document.getElementById('myx-pdf-check').addEventListener('click', async function () {
            this.disabled = true;
            pdfOutput.textContent = 'Verfügbarkeit wird geprüft …';
            try { showPdfStatus((await post('/admin/pdf/status')).status); }
            catch (error) { pdfOutput.textContent = error.message; }
            finally { this.disabled = false; }
        });
        document.getElementById('myx-pdf-templates').addEventListener('submit', async function (event) {
            event.preventDefault();
            const button = document.getElementById('myx-pdf-upload');
            button.disabled = true;
            pdfOutput.textContent = 'Vorlagensatz wird geprüft …';
            try {
                const response = await fetch(OC.generateUrl('/apps/shi_re/admin/pdf/templates'), {
                    method: 'POST', headers: {requesttoken: OC.requestToken}, body: new FormData(this),
                });
                const data = await response.json();
                if (!response.ok || !data.success) throw new Error(data.error || 'Upload fehlgeschlagen.');
                this.reset();
                showPdfStatus(data.status);
            } catch (error) {
                pdfOutput.textContent = 'PDF-Modul ist deaktiviert. ' + error.message;
            } finally { button.disabled = false; }
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once: true});
    else init();
})();
