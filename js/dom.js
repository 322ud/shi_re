(function () {
    'use strict';

    const NS = window.MyXRechnung;

    NS.route = function (path) {
        if (typeof OC !== 'undefined' && OC.generateUrl) {
            return OC.generateUrl('/apps/' + NS.APP_ID + path);
        }
        return '/apps/' + NS.APP_ID + path;
    };

    NS.clear = function (el) {
        while (el && el.firstChild) {
            el.removeChild(el.firstChild);
        }
    };

    NS.root = function () {
        return document.getElementById('myx-root');
    };

    NS.inputArea = function () {
        return document.getElementById('myx-input');
    };

    NS.outputArea = function () {
        return document.getElementById('myx-output');
    };

    NS.log = function (text) {
        const out = NS.outputArea();
        if (!out) return;

        const p = document.createElement('div');
        p.textContent = text;
        out.appendChild(p);
        out.scrollTop = out.scrollHeight;
    };

    NS.make = function (tag, attrs = {}, text = null) {
        const el = document.createElement(tag);

        for (const [key, value] of Object.entries(attrs)) {
            if (key === 'class') el.className = value;
            else if (key === 'for') el.htmlFor = value;
            else if (key === 'value') el.value = value;
            else el.setAttribute(key, value);
        }

        if (text !== null) el.textContent = text;
        return el;
    };

    NS.button = function (text, handler, className = '') {
        const btn = NS.make('button', { type: 'button', class: className }, text);

        btn.addEventListener('click', async function () {
            try {
                await handler();
            } catch (e) {
                NS.log('Fehler: ' + e.message);
            }
        });

        return btn;
    };

    NS.field = function (labelText, name, value = '', type = 'text') {
        const wrap = NS.make('label', { class: 'myx-field' });
        wrap.appendChild(NS.make('span', {}, labelText));

        const inp = NS.make('input', {
            type: type,
            name: name,
            value: value,
        });

        wrap.appendChild(inp);
        return wrap;
    };

    NS.numberField = function (labelText, name, value = '') {
        const wrap = NS.make('label', { class: 'myx-field' });
        wrap.appendChild(NS.make('span', {}, labelText));

        const inp = NS.make('input', {
            type: 'number',
            step: '0.01',
            min: '0',
            name: name,
            value: value,
        });

        wrap.appendChild(inp);
        return wrap;
    };

    NS.amountField = function (labelText, name, value = '') {
        const wrap = NS.make('label', {
            class: 'myx-field myx-currency-field',
        });

        wrap.appendChild(NS.make('span', {}, labelText));

        const box = NS.make('div', {
            class: 'myx-currency-input-wrap',
        });

        const inp = NS.make('input', {
            type: 'text',
            inputmode: 'decimal',
            name: name,
            value: NS.formatAmount(value),
                            class: 'myx-money-input',
        });

        inp.addEventListener('blur', function () {
            inp.value = NS.formatAmount(inp.value);
        });

        const euro = NS.make('span', {
            class: 'myx-currency-symbol',
        });

        euro.textContent = '€';

        box.appendChild(inp);
        box.appendChild(euro);

        wrap.appendChild(box);

        return wrap;
    };

    NS.decimalField = function (labelText, name, value = '') {
        const wrap = NS.make('label', {
            class: 'myx-field',
        });

        wrap.appendChild(NS.make('span', {}, labelText));

        const inp = NS.make('input', {
            type: 'text',
            inputmode: 'decimal',
            name: name,
            value: NS.formatAmount(value),
        });

        inp.addEventListener('blur', function () {
            inp.value = NS.formatAmount(inp.value);
        });

        wrap.appendChild(inp);

        return wrap;
    };

    NS.readonlyAmount = function (labelText, className) {
        const wrap = NS.make('label', { class: 'myx-field myx-amount' });
        wrap.appendChild(NS.make('span', {}, labelText));

        const out = NS.make('output', { class: className }, '0,00 €');
        wrap.appendChild(out);

        return wrap;
    };

    NS.initLayout = function () {
        const r = NS.root();
        NS.clear(r);

        const input = NS.make('section', { id: 'myx-input' });
        const output = NS.make('section', { id: 'myx-output' });

        const icon = NS.make('div', { id: 'myx-bg-icon' });
        input.appendChild(icon);

        r.appendChild(input);
        r.appendChild(output);
    };
})();
