(() => {
    const editors = new WeakMap();

    const initializeReadme = async (viewer) => {
        if (!viewer || editors.has(viewer)) {
            return editors.get(viewer) || null;
        }

        const richContent = viewer.querySelector('.shire-readme-rich');
        const fallback = viewer.querySelector('.shire-readme-fallback');
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

    function init() {
        document.querySelectorAll('[data-shire-readme]').forEach(viewer => {
            if (!viewer.closest('dialog')) initializeReadme(viewer);
        });
        const open = document.querySelector('.shire-readme-link');
        const dialog = document.getElementById('shire-readme-dialog');
        const close = document.getElementById('shire-readme-close');
        if (!open || !dialog || !close) return;
        let ready;
        open.addEventListener('click', event => {
            event.preventDefault();
            dialog.showModal();
            close.focus();
            ready ||= initializeReadme(dialog.querySelector('[data-shire-readme]'));
        });
        close.addEventListener('click', () => dialog.close());
        dialog.addEventListener('click', event => {
            const bounds = dialog.getBoundingClientRect();
            if (event.target === dialog && (event.clientX < bounds.left || event.clientX > bounds.right
                || event.clientY < bounds.top || event.clientY > bounds.bottom)) dialog.close();
        });
        dialog.addEventListener('close', () => open.focus());
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once: true});
    else init();

    window.OCA = window.OCA || {};
    window.OCA.ShiRE = {
        ...window.OCA.ShiRE,
        initializeReadme,
    };
})();
