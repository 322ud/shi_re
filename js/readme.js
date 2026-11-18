(() => {
    const editors = new WeakMap();
    const pending = new WeakMap();
    const documentImages = new WeakMap();

    const slug = text => text.toLowerCase().replace(/[^\p{L}\p{N}_\s-]/gu, '').replace(/ /g, '-');
    const localLink = (viewer, href) => {
        if (!href || /^(?:[a-z][a-z0-9+.-]*:|\/\/|\/|#)/i.test(href)) return href;
        const source = new URL(viewer.dataset.document || 'README.md', 'https://documentation.invalid/');
        const target = new URL(href, source);
        if (target.pathname.startsWith('/img/')) {
            const imagePath = target.pathname.slice('/img/'.length);
            return typeof window.OC?.imagePath === 'function'
                ? window.OC.imagePath('shi_re', imagePath)
                : 'https://raw.githubusercontent.com/322ud/shi_re/HEAD' + target.pathname;
        }
        const documents = {'/README.md': 'readme', '/docs/admin.md': 'admin', '/docs/user.md': 'user', '/docs/developer.md': 'developer'};
        if (!documents[target.pathname]) {
            return 'https://github.com/322ud/shi_re/blob/HEAD' + target.pathname + target.search + target.hash;
        }
        if (!viewer.dataset.documentUrl) return href;
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
                    if (href === match[2] && !href.startsWith('#')) continue;
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
                const picture = documentImages.get(viewer)?.get(href);
                if (picture) {
                    const img = document.createElement('img');
                    img.src = picture.src;
                    img.alt = picture.alt;
                    img.className = 'shire-document-image';
                    img.addEventListener('error', () => {
                        link.textContent = picture.alt + ' – Bild konnte nicht geladen werden (öffnen)';
                    }, {once: true});
                    link.href = picture.src;
                    link.replaceChildren(img);
                    return;
                }
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

        // Text's attachment loader needs a file context. App screenshots are
        // static assets, so render them as native images via temporary links.
        const pictures = new Map();
        documentImages.set(viewer, pictures);
        const markdown = fallback.textContent.replace(/!\[([^\]]*)\]\(([^)]+)\)/g, (match, alt, href) => {
            const source = new URL(viewer.dataset.document || 'README.md', 'https://documentation.invalid/');
            const target = new URL(href, source);
            if (target.origin !== source.origin || !/^\/img\/[^/]+\.png$/i.test(target.pathname)) return match;
            const marker = '#shire-document-image-' + pictures.size;
            pictures.set(marker, {alt, src: localLink(viewer, href)});
            return '[' + alt + '](' + marker + ')';
        });
        try {
            const editor = await createEditor({
                el: richContent,
                // Resolve against the Markdown document before Text can resolve links
                // against the administration page's URL.
                content: markdown.replace(/(\[[^\]]+\]\()([^)]+)(\))/g,
                    (_, start, href, end) => start + localLink(viewer, href) + end),
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
        if (!open) return;
        open.addEventListener('click', event => {
            if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            window.open(open.href, '_blank', 'popup,width=960,height=800,noopener,noreferrer');
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
