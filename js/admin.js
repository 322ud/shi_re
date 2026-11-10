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
            showValidatorStatus(status);
            if (status.check_error) throw new Error(status.check_error);
            if (!status.installed) {
                output.textContent = 'Aktuelle Validator-App wird installiert …';
                const installed = await post('/admin/validator/install');
                showValidatorStatus(installed.status);
                output.textContent = 'Validator-App installiert. Seite neu laden. Java und Prüfszenarien können in der KoSIT-Administration eingerichtet werden.';
            } else {
                output.textContent = status.update_available
                    ? 'Release ' + status.latest + ' verfügbar. Bitte über den administrativen Release-Deploymentweg aktualisieren.'
                    : 'Installierte Validator-App ist aktuell.';
                if (!status.enabled) output.textContent += ' Die App ist deaktiviert; bitte in der App-Verwaltung aktivieren.';
            }
        });
        const pdfOutput = document.getElementById('myx-pdf-status');
        function showCheck(key, check) {
            const row = root.querySelector('[data-check="' + key + '"]');
            if (!row) return;
            const state = row.querySelector('.shire-state');
            state.textContent = check.ok ? '✓' : '✕';
            state.className = 'shire-state shire-state--' + (check.ok ? 'available' : 'missing');
            state.setAttribute('aria-label', check.ok ? 'Bereit' : 'Nicht bereit');
            row.querySelector('[data-version]').textContent = check.version || '—';
            row.querySelector('[data-detail]').textContent = check.detail || '';
        }
        function showValidatorStatus(status) {
            showCheck('validator', {ok: Boolean(status.installed && status.enabled && !status.check_error),
                version: status.installed, detail: status.check_error || (!status.enabled ? 'INValidator fehlt oder ist deaktiviert.'
                    : status.update_available ? 'Update auf ' + status.latest + ' verfügbar.' : '')});
        }
        function showPdfStatus(status) {
            Object.entries(status.checks || {}).forEach(([key, check]) => showCheck(key, check));
            pdfOutput.textContent = status.enabled ? 'PDF-Modul ist aktiv.'
                : 'PDF-Modul ist deaktiviert. ' + (status.errors || []).join(' ');
        }
        bind('myx-check-system', async () => {
            const results = await Promise.allSettled([
                post('/validator/status').then(data => showValidatorStatus(data.status)),
                post('/admin/pdf/status').then(data => showPdfStatus(data.status)),
            ]);
            const failures = results.filter(result => result.status === 'rejected');
            output.textContent = failures.length ? failures.map(result => result.reason.message).join(' ') : 'Systemprüfung abgeschlossen.';
        });
        root.addEventListener('click', async event => {
            const help = event.target.closest('[data-readme-help]');
            if (!help) return;
            event.preventDefault();
            await window.OCA?.ShiRE?.initializeReadme(root.querySelector('[data-shire-readme]'));
            const rich = root.querySelector('.shire-readme-rich');
            if (rich.hidden) {
                const target = [...root.querySelectorAll('[data-readme-heading]')]
                    .find(node => node.dataset.readmeHeading === help.dataset.readmeHelp);
                (target || document.getElementById('shire-admin-readme-title')).scrollIntoView({block: 'center'});
                return;
            }
            const normalize = (text) => text.replace(/[\u200B-\u200D\uFEFF]/g, '')
                .replace(/^\s*#{1,6}\s*/, '').replace(/\s+/g, ' ').trim();
            const findHeading = () => {
                const content = rich.querySelector('.ProseMirror') || rich;
                return [...content.querySelectorAll('h1, h2, h3, h4, h5, h6')].find((element) => {
                    const heading = element.cloneNode(true);
                    heading.querySelectorAll('button, [aria-hidden="true"]').forEach((decoration) => decoration.remove());
                    return normalize(heading.textContent) === normalize(help.dataset.readmeHelp);
                });
            };
            let heading = findHeading();
            if (!heading) {
                // Nextcloud Text can mount its headings after createEditor has resolved.
                heading = await new Promise((resolve) => {
                    const finish = (element) => {
                        observer.disconnect();
                        clearTimeout(timeout);
                        resolve(element);
                    };
                    const observer = new MutationObserver(() => {
                        const element = findHeading();
                        if (element) {
                            finish(element);
                        }
                    });
                    const timeout = setTimeout(() => finish(null), 2000);
                    observer.observe(rich, { childList: true, subtree: true, characterData: true });
                });
            }
            // A missing anchor must never replace the rendered Markdown with raw source.
            (heading || rich).scrollIntoView({ block: 'center' });
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
                    showCheck('templates', {ok: false, detail: 'prüfung fehlgeschlagen'});
                    throw new Error(data.error || 'Upload fehlgeschlagen.');
                }
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
