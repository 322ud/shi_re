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
        function bindSwitch(id, path, key, onSaved = () => {}) {
            const input = document.getElementById(id);
            input.addEventListener('change', async () => {
                const checked = input.checked;
                input.disabled = true;
                output.textContent = 'Wird gespeichert …';
                try {
                    const data = await post(path, {[key]: checked});
                    onSaved(data);
                    output.textContent = 'Einstellung gespeichert.';
                } catch (error) {
                    input.checked = !checked;
                    output.textContent = error.message;
                } finally {
                    input.closest('label').querySelector('[data-switch-value]').textContent = input.checked ? 'ja' : 'nein';
                    input.disabled = false;
                }
            });
        }
        bindSwitch('myx-allow-draft-deletion', '/admin/draft/settings', 'allow_draft_deletion');
        bindSwitch('myx-hide-validator', '/admin/validator/settings', 'hide_navigation');
        bindSwitch('myx-auto-update', '/admin/validator/settings', 'auto_update');
        bindSwitch('myx-custom-templates', '/admin/pdf/settings', 'custom_templates', data => {
            document.getElementById('myx-pdf-templates').hidden = !document.getElementById('myx-custom-templates').checked;
            showPdfStatus(data.status);
        });
        bind('myx-check-validator', async () => {
            const {status} = await post('/validator/status');
            document.getElementById('myx-validator-version').textContent = (status.installed || 'Nicht installiert') + (status.enabled ? '' : ' – deaktiviert');
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
            document.getElementById('myx-template-status').textContent = status.customTemplatesStatus;
            root.querySelectorAll('[data-pdf-version]').forEach(cell => {
                cell.textContent = (status.versions || {})[cell.dataset.pdfVersion] || 'Nicht ermittelt';
            });
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
