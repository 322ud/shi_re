(() => {
    // Render the bundled documentation directly, independently of Nextcloud Text.
    // Build DOM nodes rather than interpreting Markdown as HTML.
    function renderMarkdown(target, markdown) {
        function inline(parent, text) {
            const pattern = /(`[^`]+`|\*\*[^*]+\*\*|\[[^\]]+\]\([^\s)]+\))/g;
            let offset = 0;
            for (const match of text.matchAll(pattern)) {
                parent.append(document.createTextNode(text.slice(offset, match.index)));
                const token = match[0];
                const node = document.createElement(token.startsWith('`') ? 'code' : token.startsWith('**') ? 'strong' : 'a');
                if (node.tagName === 'A') {
                    const parts = /^\[([^\]]+)\]\(([^)]+)\)$/.exec(token);
                    node.textContent = parts[1];
                    if (/^(https?:\/\/|#)/i.test(parts[2])) node.setAttribute('href', parts[2]);
                } else node.textContent = token.slice(node.tagName === 'CODE' ? 1 : 2, node.tagName === 'CODE' ? -1 : -2);
                parent.append(node);
                offset = match.index + token.length;
            }
            parent.append(document.createTextNode(text.slice(offset)));
        }
        target.replaceChildren();
        let paragraph = null, list = null, code = null;
        for (const line of markdown.split('\n')) {
            if (line.startsWith('```')) {
                if (code) code = null;
                else {
                    const pre = document.createElement('pre');
                    code = document.createElement('code');
                    pre.append(code); target.append(pre);
                }
                paragraph = list = null;
                continue;
            }
            if (code) { code.append(document.createTextNode(line + '\n')); continue; }
            if (!line.trim()) { paragraph = list = null; continue; }
            const heading = /^(#{1,6})\s+(.+)$/.exec(line);
            const item = /^\s*(?:([-*])|\d+\.)\s+(.+)$/.exec(line);
            if (heading) {
                const node = document.createElement('h' + heading[1].length);
                inline(node, heading[2]); target.append(node); paragraph = list = null;
            } else if (item) {
                const type = item[1] ? 'UL' : 'OL';
                if (!list || list.tagName !== type) { list = document.createElement(type); target.append(list); }
                const node = document.createElement('li'); inline(node, item[2]); list.append(node); paragraph = null;
            } else {
                if (!paragraph) { paragraph = document.createElement('p'); target.append(paragraph); }
                else paragraph.append(document.createTextNode(' '));
                inline(paragraph, line); list = null;
            }
        }
    }

    const initializeReadme = (viewer) => {
        if (!viewer) return;
        const richContent = viewer.querySelector('.shire-readme-rich');
        const fallback = viewer.querySelector('.shire-readme-fallback');
        if (!richContent || !fallback) return;
        renderMarkdown(richContent, fallback.textContent);
        richContent.hidden = false;
        fallback.hidden = true;
    };

    function init() {
        document.querySelectorAll('[data-shire-readme]').forEach(initializeReadme);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once: true});
    else init();

    window.OCA = window.OCA || {};
    window.OCA.ShiRE = {
        ...window.OCA.ShiRE,
        initializeReadme,
    };
})();
