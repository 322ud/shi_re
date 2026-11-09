(function () {
    'use strict';

    const NS = window.MyXRechnung;

    NS.showInvoiceForm = function () {
        const input = NS.inputArea();
        NS.clear(input);
        input.className = 'myx-form';

        input.appendChild(NS.make('div', { id: 'myx-bg-icon' }));

        const supportedType = NS.state.typeCode === '380';
        if (!supportedType) {
            NS.log('Typecode [' + NS.state.typeCode + '] kann (noch) nicht verarbeitet werden.');
        }
        const firstItemName = NS.state.invoiceData.items[0]?.name || '';
        input.appendChild(
            NS.make(
                'h1',
                {},
                (supportedType ? 'Neue Rechnung' : 'Neues Dokument') + ' "' + firstItemName + '" Nr. ' + NS.state.ramID + ' vom ' + NS.state.todayText
            )
        );

        const form = NS.make('form', { id: 'myx-invoice-form' });
        form.addEventListener('input', NS.recalculate);

        const parties = NS.make('div', { class: 'myx-parties' });
        const buyer = NS.make('section', { class: 'myx-buyer' });
        buyer.appendChild(NS.make('h2', {}, 'Rechnungsempfänger'));
        buyer.appendChild(NS.field('Name', 'buyer.name', NS.state.invoiceData.buyer.name));
        buyer.appendChild(NS.field('Hausadresse', 'buyer.street', NS.state.invoiceData.buyer.street));

        const addressLine = NS.make('div', { class: 'myx-address-line' });
        addressLine.appendChild(NS.field('PLZ', 'buyer.postcode', NS.state.invoiceData.buyer.postcode));
        addressLine.appendChild(NS.field('Ort', 'buyer.city', NS.state.invoiceData.buyer.city));
        const country = NS.field('Land', 'buyer.country', NS.state.invoiceData.buyer.country);
        country.classList.add('myx-country-field');
        addressLine.appendChild(country);
        buyer.appendChild(addressLine);
        parties.appendChild(buyer);

        const seller = NS.state.seller || {};
        const issuer = NS.make('section', { class: 'myx-seller' });
        issuer.appendChild(NS.make('h2', {}, 'Rechnungssteller'));
        [seller.name, seller.address, seller.address2, seller.address3,
            [seller.postcode, seller.city].filter(Boolean).join(' ') + (seller.country ? '/' + seller.country : ''),
            seller.iban ? 'IBAN: ' + seller.iban : '',
        ].filter(Boolean).forEach(function (line) {
            issuer.appendChild(NS.make('div', {}, line));
        });
        parties.appendChild(issuer);
        form.appendChild(parties);

        const referenceLine = NS.make('div', { class: 'myx-reference-line' });
        referenceLine.appendChild(NS.field('Aktenzeichen', 'buyer.reference', NS.state.invoiceData.buyer.reference));
        const hours = NS.decimalField('Stunden', 'item.quantity', NS.state.invoiceData.items[0]?.quantity ?? 1, 1);
        hours.classList.add('myx-hours-field');
        referenceLine.appendChild(hours);
        form.appendChild(referenceLine);

        const table = NS.make('div', { class: 'myx-posten-table' });

        table.appendChild(NS.postenHeaderRow());

        table.appendChild(NS.itemRow(NS.state.invoiceData.items[0] || {}));
        table.appendChild(NS.vatRow());

        table.appendChild(NS.grandTotalRow());

        form.appendChild(table);

        const actions = NS.make('div', { class: 'myx-actions' });
        const createButton = NS.button('Rechnung erstellen', NS.createXmlInvoice, 'myx-primary');
        createButton.disabled = !supportedType;
        actions.appendChild(createButton);

        form.appendChild(actions);

        input.appendChild(form);

        NS.applyInputPlaceholders(form);
        const description = form.querySelector('textarea[name="item.description"]');
        NS.resizeDescription(description);
        const observer = new ResizeObserver(function () {
            if (!description.isConnected) {
                observer.disconnect();
                return;
            }
            if (description.clientWidth !== previousWidth) {
                previousWidth = description.clientWidth;
                NS.resizeDescription(description);
            }
        });
        let previousWidth = description.clientWidth;
        observer.observe(description);
        NS.recalculate();
    };

    NS.postenHeaderRow = function () {
        const row = NS.make('div', { class: 'myx-row myx-header-row' });

        row.appendChild(NS.make('div'));
        row.appendChild(NS.make('div', {}, 'Summe'));

        return row;
    };

    NS.vatRow = function () {
        const row = NS.make('div', { class: 'myx-row myx-vat-table-row' });

        const text = NS.make('div', { class: 'myx-vat-text' },
            'zuzüglich der gesetzlichen Mehrwertsteuer von ' +
            NS.formatAmount(NS.state.invoiceData.vatRate) +
            ' % in Höhe von'
        );

        row.appendChild(text);
        row.appendChild(NS.readonlyAmount('Steuerbetrag', 'myx-vat-total'));

        return row;
    };

    NS.grandTotalRow = function () {
        const row = NS.make('div', { class: 'myx-row myx-grand-total-row' });

        row.appendChild(NS.make('div', { class: 'myx-grand-total-spacer' }, ''));
        row.appendChild(NS.readonlyAmount('Summe', 'myx-grand-total'));

        return row;
    };

    NS.applyInputPlaceholders = function (form) {
        form.querySelectorAll('.myx-field').forEach(function (field) {
            const label = field.querySelector('span');
            const input = field.querySelector('input, textarea, select');

            if (!label || !input) {
                return;
            }

            input.placeholder = label.textContent.trim();
        });
    };

    NS.resizeDescription = function (textarea) {
        textarea.style.setProperty('height', 'auto', 'important');
        textarea.style.setProperty('height', textarea.scrollHeight + 'px', 'important');
    };

    NS.itemRow = function (item) {
        const row = NS.make('div', { class: 'myx-row myx-item-row' });

        const description = NS.make('label', { class: 'myx-field' });
        description.appendChild(NS.make('span', {}, 'Beschreibung'));
        const textarea = NS.make('textarea', {
            name: 'item.description',
            rows: '1',
            class: 'myx-description',
            'aria-label': 'Beschreibung',
            value: item.description || '',
        });
        textarea.addEventListener('input', function () {
            NS.resizeDescription(textarea);
            const matches = Array.from(textarea.value.matchAll(/\(\s*(\d{1,2})(?:,(\d))?\s+h\s*\)/g));
            const hours = textarea.form?.querySelector('[name="item.quantity"]');
            if (hours && matches.length) {
                // Sum integer tenths to avoid floating-point rounding errors.
                const tenths = matches.reduce(function (sum, match) {
                    return sum + Number(match[1]) * 10 + Number(match[2] || 0);
                }, 0);
                hours.value = (tenths / 10).toFixed(1).replace('.', ',');
            }
        });
        description.appendChild(textarea);
        row.appendChild(description);
        row.appendChild(NS.readonlyAmount('Summe', 'myx-line-net'));

        return row;
    };

    NS.expenseRow = function (expense) {
        const row = NS.make('div', { class: 'myx-row myx-expense-row' });

        row.appendChild(NS.field('Bezeichnung', 'expense.name', expense.name));
        row.appendChild(NS.field('Beschreibung', 'expense.description', expense.description));
        row.appendChild(NS.numberField('Menge', 'expense.quantity', expense.quantity));
        row.appendChild(NS.amountField('Einzelpreis', 'expense.unitPrice', expense.unitPrice));
        row.appendChild(NS.readonlyAmount('Summe', 'myx-expense-net'));

        return row;
    };

    NS.collectFormData = function () {
        const form = document.getElementById('myx-invoice-form');

        if (!form) {
            return NS.state.invoiceData;
        }

        const fd = new FormData(form);

        const itemRows = Array.from(form.querySelectorAll('.myx-item-row')).slice(0, 1);
        const expenseRows = Array.from(document.querySelectorAll('.myx-expense-row'));

        const items = itemRows.map(function (row, index) {
            const previous = NS.state.invoiceData.items[index] || {};

            return {
                name: previous.name || '',
                description: row.querySelector('[name="item.description"]').value,
                quantity: Math.round(NS.parseAmount(fd.get('item.quantity')) * 10) / 10,
                unitPrice: NS.parseAmount(previous.unitPrice || 0),
            };
        });

        const expenses = expenseRows.map(function (row) {
            return {
                name: row.querySelector('[name="expense.name"]').value,
                description: row.querySelector('[name="expense.description"]').value,
                quantity: NS.parseAmount(row.querySelector('[name="expense.quantity"]').value || 0),
                unitPrice: NS.parseAmount(row.querySelector('[name="expense.unitPrice"]').value),
            };
        });

        NS.state.invoiceData = {
            buyer: {
                name: fd.get('buyer.name') || '',
                street: fd.get('buyer.street') || '',
                postcode: fd.get('buyer.postcode') || '',
                city: fd.get('buyer.city') || '',
                country: fd.get('buyer.country') || 'DE',
                reference: fd.get('buyer.reference') || '',
            },
            items: items,
            vatRate: NS.parseAmount(NS.state.invoiceData.vatRate || 19),
            expenses: expenses,
            due: NS.state.invoiceData.due || 'sofort',
            dueDate: NS.state.todayText,
        };

        return NS.state.invoiceData;
    };

    NS.recalculate = function () {
        if (!document.getElementById('myx-invoice-form')) {
            return;
        }

        const data = NS.collectFormData();

        document.querySelectorAll('.myx-item-row').forEach(function (row, index) {
            const item = data.items[index] || {}
            row.querySelector('.myx-line-net').textContent =
                NS.money(NS.calculateTotals({ items: [item], expenses: [], vatRate: 0 }).itemNet);
        });

        document.querySelectorAll('.myx-expense-row').forEach(function (row) {
            const q = NS.parseAmount(row.querySelector('[name="expense.quantity"]').value || 0);
            const p = NS.parseAmount(row.querySelector('[name="expense.unitPrice"]').value);
            row.querySelector('.myx-expense-net').textContent = NS.money(q * p);
        });

        const totals = NS.calculateTotals(data);

        const vat = document.querySelector('.myx-vat-total');
        const grand = document.querySelector('.myx-grand-total');

        if (vat) {
            vat.textContent = NS.money(totals.vat);
        }

        if (grand) {
            grand.textContent = NS.money(totals.grand);
        }
    };
})();
