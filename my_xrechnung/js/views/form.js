(function () {
    'use strict';

    const NS = window.MyXRechnung;

    NS.showInvoiceForm = function () {
        const input = NS.inputArea();
        NS.clear(input);
        input.className = 'myx-form';

        input.appendChild(NS.make('div', { id: 'myx-bg-icon' }));

        input.appendChild(
            NS.make(
                'h1',
                {},
                'Neue Rechnung Nr. ' + NS.state.ramID + ' vom ' + NS.state.todayText
            )
        );

        const form = NS.make('form', { id: 'myx-invoice-form' });
        form.addEventListener('input', NS.recalculate);

        form.appendChild(NS.make('h2', {}, 'Rechnungsempfänger'));
        form.appendChild(NS.field('Name', 'buyer.name', NS.state.invoiceData.buyer.name));
        form.appendChild(NS.field('Hausadresse', 'buyer.street', NS.state.invoiceData.buyer.street));

        const addressLine = NS.make('div', { class: 'myx-address-line' });
        addressLine.appendChild(NS.field('PLZ', 'buyer.postcode', NS.state.invoiceData.buyer.postcode));
        addressLine.appendChild(NS.field('Ort', 'buyer.city', NS.state.invoiceData.buyer.city));
        addressLine.appendChild(document.createTextNode('/'));
        addressLine.appendChild(NS.field('Land', 'buyer.country', NS.state.invoiceData.buyer.country));
        form.appendChild(addressLine);

        const referenceLine = NS.make('div', { class: 'myx-reference-line' });
        referenceLine.appendChild(document.createTextNode('Aktenzeichen '));
        referenceLine.appendChild(NS.field('Aktenzeichen', 'buyer.reference', NS.state.invoiceData.buyer.reference));
        form.appendChild(referenceLine);

        form.appendChild(NS.make('h2', {}, 'Rechnungsposten'));

        const table = NS.make('div', { class: 'myx-posten-table' });

        table.appendChild(NS.postenHeaderRow());

        NS.state.invoiceData.items.forEach(function (item) {
            table.appendChild(NS.itemRow(item));
        });

        if (NS.state.invoiceData.items.length > 0) {
            table.appendChild(NS.vatRow());
        }

        table.appendChild(NS.postenButtonRow('Posten mit MwSt', function () {
            NS.collectFormData();
            NS.state.invoiceData.items.push({
                name: '',
                description: '',
                quantity: 1,
                unitPrice: 0,
            });
            NS.showInvoiceForm();
        }));

        NS.state.invoiceData.expenses.forEach(function (expense) {
            table.appendChild(NS.expenseRow(expense));
        });

        table.appendChild(NS.postenButtonRow('Posten ohne MwSt', function () {
            NS.collectFormData();
            NS.state.invoiceData.expenses.push({
                name: '',
                description: '',
                quantity: 1,
                unitPrice: 0,
            });
            NS.showInvoiceForm();
        }));

        table.appendChild(NS.grandTotalRow());

        form.appendChild(table);

        form.appendChild(NS.make('h2', {}, 'Fälligkeit'));

        const dueWrap = NS.make('div', { class: 'myx-due-row' });
        dueWrap.appendChild(NS.field('Fällig', 'due', NS.state.invoiceData.due));
        dueWrap.appendChild(NS.field('Datum', 'dueDate', NS.state.invoiceData.dueDate || NS.todayISO(), 'date'));
        form.appendChild(dueWrap);

        const actions = NS.make('div', { class: 'myx-actions' });
        actions.appendChild(NS.button('Rechnung erstellen', NS.createXmlInvoice, 'myx-primary'));
        actions.appendChild(NS.button('Abbrechen', NS.cancelInvoice));

        form.appendChild(actions);

        input.appendChild(form);

        NS.applyInputPlaceholders(form);
        NS.recalculate();
    };

    NS.postenHeaderRow = function () {
        const row = NS.make('div', { class: 'myx-row myx-header-row' });

        row.appendChild(NS.make('div', {}, 'Bezeichnung'));
        row.appendChild(NS.make('div', {}, 'Beschreibung'));
        row.appendChild(NS.make('div', {}, 'Menge'));
        row.appendChild(NS.make('div', {}, 'Einzelpreis'));
        row.appendChild(NS.make('div', {}, 'Summe'));

        return row;
    };

    NS.vatRow = function () {
        const row = NS.make('div', { class: 'myx-row myx-vat-table-row' });

        const text = NS.make('div', { class: 'myx-vat-text' });
        text.appendChild(
            document.createTextNode('zuzüglich der gesetzlichen Mehrwertsteuer von')
        );

        row.appendChild(text);
        row.appendChild(NS.numberField('MwSt', 'vatRate', NS.state.invoiceData.vatRate));
        row.appendChild(NS.make('div', { class: 'myx-vat-percent' }, '% in Höhe von'));
        row.appendChild(NS.readonlyAmount('Steuerbetrag', 'myx-vat-total'));

        return row;
    };

    NS.postenButtonRow = function (label, handler) {
        const row = NS.make('div', { class: 'myx-row myx-button-row' });
        const cell = NS.make('div', { class: 'myx-button-cell' });

        cell.appendChild(NS.button(label, handler));
        row.appendChild(cell);

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

    NS.itemRow = function (item) {
        const row = NS.make('div', { class: 'myx-row myx-item-row' });

        row.appendChild(NS.field('Bezeichnung', 'item.name', item.name));
        row.appendChild(NS.field('Beschreibung', 'item.description', item.description));
        row.appendChild(NS.decimalField('Menge', 'item.quantity', item.quantity));
        row.appendChild(NS.amountField('Einzelpreis', 'item.unitPrice', item.unitPrice));
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

        const itemRows = Array.from(document.querySelectorAll('.myx-item-row'));
        const expenseRows = Array.from(document.querySelectorAll('.myx-expense-row'));

        const items = itemRows.map(function (row) {
            return {
                name: row.querySelector('[name="item.name"]').value,
                description: row.querySelector('[name="item.description"]').value,
                quantity: NS.parseAmount(row.querySelector('[name="item.quantity"]').value),
                unitPrice: NS.parseAmount(row.querySelector('[name="item.unitPrice"]').value),
            };
        });

        const expenses = expenseRows.map(function (row) {
            return {
                name: row.querySelector('[name="expense.name"]').value,
                description: row.querySelector('[name="expense.description"]').value,
                quantity: Number(row.querySelector('[name="expense.quantity"]').value || 0),
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
            vatRate: fd.has('vatRate')
            ? Number(fd.get('vatRate') || 0)
            : Number(NS.state.invoiceData.vatRate || 19),
            expenses: expenses,
            due: fd.get('due') || 'sofort',
            dueDate: fd.get('dueDate') || '',
        };

        return NS.state.invoiceData;
    };

    NS.recalculate = function () {
        if (!document.getElementById('myx-invoice-form')) {
            return;
        }

        const data = NS.collectFormData();

        document.querySelectorAll('.myx-item-row').forEach(function (row) {
            const q = Number(row.querySelector('[name="item.quantity"]').value || 0);
            const p = NS.parseAmount(row.querySelector('[name="item.unitPrice"]').value);
            row.querySelector('.myx-line-net').textContent = NS.money(q * p);
        });

        document.querySelectorAll('.myx-expense-row').forEach(function (row) {
            const q = Number(row.querySelector('[name="expense.quantity"]').value || 0);
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
