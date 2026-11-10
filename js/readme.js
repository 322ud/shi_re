(() => {
    const editors = new WeakMap();
    const pending = new WeakMap();

    const mountReadme = async (viewer) => {
        if (!viewer || editors.has(viewer)) {
            return editors.get(viewer) || null;
        }

        const richContent = viewer.querySelector('.shire-readme-rich');
        const fallback = viewer.querySelector('.shire-readme-fallback');
        // Text may register its editor after this script (deferred app assets).
        for (let attempt = 0; attempt < 100 && typeof window.OCA?.Text?.createEditor !== 'function'; attempt++) {
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        const createEditor = window.OCA?.Text?.createEditor;
        const accessibleLabel = richContent?.getAttribute('aria-label');

        if (!richContent || !fallback || typeof createEditor !== 'function') {
            return null;
        }

        const labelEditorInput = () => {
            const editorInput = richContent.querySelector('[role="textbox"]');

            if (!editorInput) {
                return false;
            }

            if (
                accessibleLabel
                && !editorInput.hasAttribute('aria-label')
                && !editorInput.hasAttribute('aria-labelledby')
                && !editorInput.hasAttribute('title')
            ) {
                editorInput.setAttribute('aria-label', accessibleLabel);
            }

            return true;
        };
        const editorInputObserver = new MutationObserver(() => {
            if (labelEditorInput()) {
                editorInputObserver.disconnect();
            }
        });

        editorInputObserver.observe(richContent, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['role'],
        });

        try {
            const editor = await createEditor({
                el: richContent,
                content: fallback.textContent,
                readOnly: true,
                autofocus: false,
            });

            editor.setReadOnly(true);

            if (labelEditorInput()) {
                editorInputObserver.disconnect();
            }

            editors.set(viewer, editor);
            richContent.hidden = false;
            fallback.hidden = true;
            return editor;
        } catch {
            editorInputObserver.disconnect();
            richContent.replaceChildren();
            richContent.hidden = true;
            fallback.hidden = false;
            return null;
        }
    };

    const initializeReadme = (viewer) => {
        if (!viewer) return Promise.resolve(null);
        if (pending.has(viewer)) return pending.get(viewer);
        const task = mountReadme(viewer).finally(() => pending.delete(viewer));
        pending.set(viewer, task);
        return task;
    };

    function init() {
        document.querySelectorAll('[data-shire-readme]').forEach(viewer => {
            if (!viewer.closest('.shire-readme-modal')) initializeReadme(viewer);
        });
        const open = document.querySelector('.shire-readme-link');
        const dialog = document.getElementById('shire-readme-dialog');
        const close = document.getElementById('shire-readme-close');
        if (!open || !dialog || !close) return;
        // Keep the overlay outside Nextcloud's app content layout and stacking context.
        document.body.append(dialog);
        const closeReadme = () => {
            dialog.hidden = true;
            open.focus();
        };
        open.addEventListener('click', event => {
            event.preventDefault();
            dialog.hidden = false;
            close.focus();
            initializeReadme(dialog.querySelector('[data-shire-readme]'));
        });
        close.addEventListener('click', closeReadme);
        dialog.addEventListener('click', event => {
            if (event.target === dialog) closeReadme();
        });
        document.addEventListener('keydown', event => {
            if (dialog.hidden) return;
            if (event.key === 'Escape') {
                event.preventDefault();
                closeReadme();
            } else if (event.key === 'Tab') {
                const controls = [...dialog.querySelectorAll('button, a[href], input, [tabindex="0"]')]
                    .filter(node => node.getClientRects().length && !node.disabled);
                const first = controls[0], last = controls[controls.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault(); last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault(); first.focus();
                }
            }
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once: true});
    else init();

    window.OCA = window.OCA || {};
    window.OCA.ShiRE = {
        ...window.OCA.ShiRE,
        initializeReadme,
    };
})();
