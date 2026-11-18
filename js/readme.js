(() => {
    const editors = new WeakMap();
    const pending = new WeakMap();

    const slug = text => text.toLowerCase().replace(/[^\p{L}\p{N}_\s-]/gu, '').replace(/ /g, '-');
    const localLink = (viewer, href) => {
        if (!href || /^(?:[a-z][a-z0-9+.-]*:|\/\/|\/|#)/i.test(href)) return href;
        const source = new URL(viewer.dataset.document || 'README.md', 'https://documentation.invalid/');
        const target = new URL(href, source);
        const documents = {'/README.md': 'readme', '/docs/admin.md': 'admin'};
        if (!documents[target.pathname] || !viewer.dataset.documentUrl) return href;
        const destination = new URL(viewer.dataset.documentUrl, window.location.href);
        destination.searchParams.set('document', documents[target.pathname]);
        destination.hash = target.hash;
        return destination.pathname + destination.search + destination.hash;
    };
    const prepareNavigation = viewer => {
        const rich = viewer.querySelector('.shire-readme-rich');
        const fallback = viewer.querySelector('.shire-readme-fallback');
        // Keep Markdown source intact for Text while making fallback links usable.
        if (fallback && !fallback.dataset.linked) {
            fallback.dataset.linked = 'true';
            const walker = document.createTreeWalker(fallback, NodeFilter.SHOW_TEXT);
            const nodes = [];
            while (walker.nextNode()) nodes.push(walker.currentNode);
            for (const node of nodes) {
                const pattern = /\[([^\]]+)\]\(([^)]+)\)/g;
                let match, offset = 0;
                const fragment = document.createDocumentFragment();
                while ((match = pattern.exec(node.data))) {
                    const href = localLink(viewer, match[2]);
                    if (href === match[2]) continue;
                    fragment.append(node.data.slice(offset, match.index));
                    const link = document.createElement('a');
                    link.href = href;
                    link.textContent = match[0];
                    fragment.append(link);
                    offset = pattern.lastIndex;
                }
                if (offset) {
                    fragment.append(node.data.slice(offset));
                    node.replaceWith(fragment);
                }
            }
        }
        const update = () => {
            rich?.querySelectorAll('a[href]').forEach(link => {
                const href = link.getAttribute('href');
                const local = localLink(viewer, href);
                if (local !== href) link.setAttribute('href', local);
            });
        };
        const scroll = () => {
            let hash;
            try { hash = decodeURIComponent(window.location.hash.slice(1)); } catch { return; }
            if (!hash || viewer.closest('.shire-readme-modal')) return;
            const root = rich && !rich.hidden ? rich : fallback;
            const headings = root?.querySelectorAll('h1,h2,h3,h4,h5,h6,[data-readme-heading]') || [];
            const counts = new Map();
            for (const heading of headings) {
                const copy = heading.cloneNode(true);
                copy.querySelectorAll('button,a').forEach(node => node.remove());
                const base = slug(heading.dataset.readmeHeading || copy.textContent.trim());
                const count = counts.get(base) || 0;
                counts.set(base, count + 1);
                if ((count ? `${base}-${count}` : base) === hash) {
                    heading.scrollIntoView({block: 'start'});
                    break;
                }
            }
        };
        update();
        scroll();
        if (!viewer.navigationObserver) {
            viewer.navigationObserver = new MutationObserver(() => { update(); scroll(); });
            viewer.navigationObserver.observe(rich, {childList: true, subtree: true});
            window.addEventListener('hashchange', scroll);
        }
    };

    const mountReadme = async (viewer) => {
        if (!viewer || editors.has(viewer)) {
            return editors.get(viewer) || null;
        }

        prepareNavigation(viewer);
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
            prepareNavigation(viewer);
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
