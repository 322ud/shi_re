(function () {
    'use strict';
    function init() {
        const root = document.getElementById('myx-admin');
        if (!root) return;
        const output = document.getElementById('myx-admin-result');
        const templateForm = document.getElementById('myx-pdf-templates');
        function refreshFileNames() {
            templateForm.querySelectorAll('.shire-file-picker').forEach(picker => {
                const input = picker.querySelector('input[type=file]');
                picker.querySelector('[data-file-name]').textContent = input.files?.[0]?.name || 'Datei auswählen';
            });
        }
        templateForm.addEventListener('change', refreshFileNames);
        // A reset event fires before the browser clears the native file inputs.
        templateForm.addEventListener('reset', () => setTimeout(refreshFileNames, 0));
        refreshFileNames();

        async function post(path, body = {}) {
            const response = await fetch(OC.generateUrl('/apps/shi_re' + path), {
                method: 'POST',
                headers: {'Content-Type': 'application/json', requesttoken: OC.requestToken},
                body: JSON.stringify(body),
            });
            let data;
            try {
                data = await response.json();
            } catch (error) {
                throw new Error('Der Server hat keine gültige JSON-Antwort geliefert (HTTP ' + response.status
                    + ', ' + path + '). Bitte das Nextcloud-Serverprotokoll prüfen.');
            }
            if (response.redirected || !data || typeof data !== 'object' || Array.isArray(data)) {
                throw new Error('Unerwartete Serverantwort (HTTP ' + response.status + ', ' + path + ').');
            }
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
        const clearArchive = document.getElementById('myx-clear-archive');
        clearArchive.addEventListener('click', async () => {
            const confirmation = window.prompt('Wirklich? bestätige mit "ja"!');
            if (confirmation !== 'ja') return;
            const result = document.getElementById('myx-clear-archive-result');
            clearArchive.disabled = true;
            result.textContent = 'Archiv wird geleert …';
            try {
                await post('/admin/pdf/settings', {action: 'clear_archive', confirmation});
                result.textContent = 'Archiv geleert.';
                document.getElementById('myx-archive-status').textContent = 'leer';
                root.querySelector('[data-check="archive"]').hidden = true;
            } catch (error) {
                result.textContent = error.message;
            } finally {
                clearArchive.disabled = false;
            }
        });
        function bindSwitch(id, path, key, onSaved = () => {}, toStoredValue = checked => checked) {
            const input = document.getElementById(id);
            input.addEventListener('change', async () => {
                const checked = input.checked;
                input.disabled = true;
                output.textContent = 'Wird gespeichert …';
                try {
                    const data = await post(path, {[key]: toStoredValue(checked)});
                    onSaved(data);
                    output.textContent = 'Einstellung gespeichert.';
                } catch (error) {
                    input.checked = !checked;
                    output.textContent = error.message;
                } finally {
                    input.disabled = false;
                    refreshSettings();
                }
            });
        }
        bindSwitch('myx-message-area', '/admin/validator/settings', 'message_area');
        bindSwitch('myx-allow-draft-deletion', '/admin/draft/settings', 'allow_draft_deletion');
        bindSwitch('myx-hide-validator', '/admin/validator/settings', 'hide_navigation', () => {}, checked => !checked);
        bindSwitch('myx-auto-update', '/admin/validator/settings', 'auto_update', data => showValidatorStatus(data.status));
        bindSwitch('myx-validation-enabled', '/admin/validator/settings', 'validation_enabled', data => showValidatorStatus(data.status));
        bindSwitch('myx-xml-embedding', '/admin/pdf/settings', 'xml_embedding', data => showPdfStatus(data.status));
        bindSwitch('myx-pdf-validation', '/admin/pdf/settings', 'pdf_validation', data => showPdfStatus(data.status));
        bindSwitch('myx-pdf-enabled', '/admin/pdf/settings', 'pdf_enabled', data => showPdfStatus(data.status));
        bindSwitch('myx-custom-templates', '/admin/pdf/settings', 'custom_templates', data => {
            showPdfStatus(data.status);
        });
        bind('myx-discard-templates', async () => {
            const data = await post('/admin/pdf/settings', {action: 'discard_templates'});
            document.getElementById('myx-custom-templates').checked = false;
            document.getElementById('myx-pdf-templates').reset();
            showPdfStatus(data.status);
            output.textContent = 'Eigene Vorlagen verworfen. Standardvorlagen werden verwendet.';
        });
        const pdfOutput = document.getElementById('myx-pdf-status');
        let pdfStatus = JSON.parse(pdfOutput.dataset.status);
        let validatorStatus = {installed: !document.getElementById('myx-validation-enabled').closest('label').hidden, enabled: root.dataset.validatorEnabled === 'true'};
        function refreshSettings() {
            root.querySelectorAll('[data-setting]').forEach(row => {
                const input = row.querySelector('input');
                const id = input.id;
                const visibility = ['myx-hide-validator', 'myx-message-area', 'myx-allow-draft-deletion'].includes(id);
                const visible = input.checked;
                if (!input.closest('label').hidden) {
                    row.querySelector('[data-setting-state]').textContent = id === 'myx-custom-templates' ? 'installiert'
                        : visibility ? (visible ? 'eingeblendet' : 'ausgeblendet') : (input.checked ? 'aktiv' : 'inaktiv');
                }
                document.getElementById(id + '-label').textContent = visibility ? (visible ? 'ausblenden' : 'einblenden') : (input.checked ? 'deaktivieren' : 'aktivieren');
            });
            const active = validatorStatus.installed && validatorStatus.enabled && document.getElementById('myx-validation-enabled').checked;
            root.querySelector('[data-setting="myx-hide-validator"]').hidden = !active;
            const autoUpdate = document.getElementById('myx-auto-update');
            autoUpdate.parentElement.hidden = !validatorStatus.installed;
            document.getElementById('myx-auto-update-label').textContent = autoUpdate.checked
                ? 'nicht automatisch aktualisieren' : 'automatisch aktualisieren';
        }
        function setInstalled(id, installed) {
            const input = document.getElementById(id);
            input.closest('label').hidden = !installed;
            if (!installed) input.closest('tr').querySelector('[data-setting-state]').textContent = 'nicht installiert';
        }
        function showCheck(key, check) {
            const row = root.querySelector('[data-check="' + key + '"]');
            if (row && key !== 'templates') row.querySelector('[data-version]').textContent = check.version || 'nicht installiert';
        }
        function showValidatorStatus(status) {
            validatorStatus = status;
            showCheck('validator', {version: status.installed});
            if (typeof status.validation_enabled === 'boolean') document.getElementById('myx-validation-enabled').checked = status.validation_enabled;
            if (typeof status.auto_update === 'boolean') document.getElementById('myx-auto-update').checked = status.auto_update;
            setInstalled('myx-validation-enabled', Boolean(status.installed));
            refreshSettings();
        }
        function showPdfStatus(status) {
            pdfStatus = status;
            const pdfActive = Boolean(status.checks?.libreoffice?.ok && (status.pdf_enabled ?? true));
            root.querySelectorAll('[data-pdf-template]').forEach(row => {
                row.hidden = !pdfActive;
                row.querySelectorAll('input[type=file]').forEach(input => {
                    input.disabled = !pdfActive;
                    if (!pdfActive) input.value = '';
                });
            });
            refreshFileNames();
            document.getElementById('myx-template-pdf-help').hidden = !pdfActive;
            document.getElementById('myx-template-xml-help').hidden = pdfActive;
            const templates = status.checks?.templates;
            if (templates) {
                const list = document.getElementById('myx-template-files');
                list.replaceChildren();
                if (templates.version) {
                    Object.entries({odt: 'XRechnung (ODT)', xml: 'XRechnung (XML)', mapping: 'XRechnung (Mapping)', storno_odt: 'Storno (ODT)', storno_xml: 'Storno (XML)', storno_mapping: 'Storno (Mapping)'}).forEach(([key, label]) => {
                        const file = templates.files?.[key];
                        if (!file || file.source === 'default' || (!pdfActive && !['xml', 'storno_xml'].includes(key))) return;
                        const entry = document.createElement('li');
                        entry.textContent = label + ': ' + (file?.name || 'Dateiname nicht erfasst')
                            + (file?.source === 'default' ? ' — mitgelieferte Standardvorlage'
                                : ' — Upload-Datum: ' + (file?.date || 'nicht erfasst'));
                        list.appendChild(entry);
                    });
                }
            }
            Object.entries(status.checks || {}).forEach(([key, check]) => showCheck(key, check));
            const installed = Boolean(templates?.version);
            root.querySelector('[data-check="templates"]').hidden = !installed;
            document.getElementById('myx-install-templates').hidden = installed;
            setInstalled('myx-custom-templates', installed);
            document.getElementById('myx-template-install-state').textContent = installed ? 'installiert' : 'nicht installiert';
            if (!installed) document.getElementById('myx-replace-templates').open = false;
            document.getElementById('myx-template-panel').hidden = !installed || !document.getElementById('myx-replace-templates').open;
            document.querySelector('#myx-replace-templates summary').textContent = installed ? 'ersetzen' : 'Vorlagen installieren';
            const libreofficeInstalled = Boolean(status.checks?.libreoffice?.ok);
            const pdfSwitch = document.getElementById('myx-pdf-enabled');
            pdfSwitch.closest('tr').hidden = !libreofficeInstalled;
            pdfSwitch.closest('label').hidden = false;
            pdfSwitch.checked = libreofficeInstalled && (status.pdf_enabled ?? true);
            for (const [id, available, enabled] of [
                ['myx-xml-embedding', Boolean(status.checks?.mustang?.ok && status.checks?.java?.ok), status.xmlEmbedding],
                ['myx-pdf-validation', Boolean(status.checks?.verapdf?.ok), status.pdfaVerification],
            ]) {
                const input = document.getElementById(id);
                const usable = pdfSwitch.checked && available;
                input.closest('tr').hidden = !usable;
                input.closest('label').hidden = false;
                input.checked = usable && (enabled ?? true);
            }
            pdfOutput.textContent = templates?.error && document.getElementById('myx-custom-templates').checked
                ? 'Eigene Vorlagen sind für die aktuelle Konfiguration nicht verwendbar. Standardvorlagen werden verwendet. ' + templates.error
                : '';
            refreshSettings();
        }
        document.getElementById('myx-replace-templates').addEventListener('toggle', event => {
            document.getElementById('myx-template-panel').hidden = !event.target.open;
        });
        showPdfStatus(pdfStatus);
        document.getElementById('myx-install-templates').addEventListener('click', () => {
            root.querySelector('[data-check="templates"]').hidden = false;
            document.getElementById('myx-template-panel').hidden = false;
            document.getElementById('myx-replace-templates').open = true;
            document.getElementById('shire-components-title').focus();
        });
        bind('myx-check-system', async () => {
            const results = await Promise.allSettled([
                post('/validator/status').then(data => showValidatorStatus(data.status)),
                post('/admin/pdf/status').then(data => showPdfStatus(data.status)),
            ]);
            const failures = results.filter(result => result.status === 'rejected');
            output.textContent = failures.length ? failures.map(result => result.reason.message).join(' ') : 'Systemprüfung abgeschlossen.';
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
                if (!response.ok || !data.success) {
                    if (data.status) showPdfStatus(data.status);
                    throw new Error(data.error || 'Upload fehlgeschlagen.');
                }
                this.reset();
                showPdfStatus(data.status);
            } catch (error) {
                output.textContent = error.message;
            } finally { button.disabled = false; }
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once: true});
    else init();
})();
