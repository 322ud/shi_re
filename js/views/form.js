(function () {
    'use strict';

    const NS = window.ShiRE;

    NS.showInvoiceForm = function () {
        const input = NS.inputArea();
        NS.clear(input);
        input.className = 'myx-form';

        input.appendChild(NS.make('div', { id: 'myx-bg-icon' }));

        const archived = !!NS.state.archiveDraft;
        const supportedType = NS.state.typeCode === '380';
        if (!supportedType) {
            NS.log('Typecode [' + NS.state.typeCode + '] kann (noch) nicht verarbeitet werden.');
        }
        const firstItemName = NS.state.invoiceData.items[0]?.name || '';
        input.appendChild(
            NS.make(
                'h1',
                {},
                (archived ? 'archivierte Rechnung' : (supportedType ? 'Neue Rechnung' : 'Neues Dokument')) + ' "' + firstItemName + '" Nr. ' + NS.state.ramID + ' vom ' + NS.state.todayText
            )
        );

        // Recipient data belongs to an invoice, not to the browser user's address profile.
        const form = NS.make('form', { id: 'myx-invoice-form', autocomplete: 'off' });
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
        buyer.querySelectorAll('input').forEach(field => field.setAttribute('autocomplete', 'off'));
        parties.appendChild(buyer);

        const seller = NS.state.seller || {};
        const issuer = NS.make('section', { class: 'myx-seller' });
        issuer.appendChild(NS.make('h2', {}, 'Rechnungssteller'));
        [seller.name, seller.address, seller.address2, seller.address3,
            [seller.postcode, seller.city].filter(Boolean).join(' ') + (seller.country ? '/' + seller.country : ''),
            seller.iban ? 'IBAN: ' + seller.iban : '',
            seller.vatId ? 'USt-ID: ' + seller.vatId : '',
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
        if (archived) {
            actions.appendChild(NS.archiveTemplateDropdown());
            actions.appendChild(NS.button('zurück', () => NS.leaveArchive(false)));
            form.querySelectorAll('input, textarea').forEach(field => { field.readOnly = true; });
        } else {
            const createButton = NS.button('Rechnung erstellen', NS.createXmlInvoice, 'myx-primary');
            createButton.disabled = !supportedType;
            actions.appendChild(createButton);
            actions.appendChild(NS.savedInvoiceDropdown());
        }

        form.appendChild(actions);

        input.appendChild(form);
        if (!archived) {
            const order = [form.querySelector('[name="buyer.reference"]'),
                form.querySelector('[name="item.description"]'),
                form.querySelector('[name="item.quantity"]'),
                form.querySelector('.myx-primary')];
            form.addEventListener('keydown', event => {
                if (event.key !== 'Tab' || event.ctrlKey || event.altKey || event.metaKey) return;
                const index = order.indexOf(event.target);
                if (index < 0) return;
                const next = order[index + (event.shiftKey ? -1 : 1)];
                if (next && !next.disabled) {
                    event.preventDefault();
                    next.focus();
                }
            });
        }

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

    NS.savedInvoiceDropdown = function () {
        const wrapper = NS.make('div', {class: 'myx-saved-invoices'});
        const menu = NS.make('div', {class: 'myx-saved-menu', id: 'myx-saved-menu'});
        menu.hidden = true;
        const button = NS.button('Archiv', async function () {
            menu.hidden = !menu.hidden;
            button.setAttribute('aria-expanded', String(!menu.hidden));
            if (menu.hidden) return;
            NS.clear(menu);
            menu.appendChild(NS.make('p', {}, 'Rechnungen werden geladen …'));
            try {
                const data = await NS.post('/pdf/invoices');
                NS.clear(menu);
                const invoices = data.invoices.filter(invoice => invoice.filename.endsWith('.xml'));
                if (!invoices.length) menu.appendChild(NS.make('p', {}, 'Keine Rechnungen vorhanden.'));
                invoices.forEach(function (invoice) {
                    const entry = NS.button(invoice.filename.slice(0, -4), async function () {
                        entry.disabled = true;
                        try {
                            await NS.openArchivedInvoice(invoice.filename);
                        } finally {
                            entry.disabled = false;
                        }
                    });
                    menu.appendChild(entry);
                });
            } catch (error) {
                NS.clear(menu);
                menu.appendChild(NS.make('p', {}, 'Rechnungen konnten nicht geladen werden. Bitte erneut öffnen.'));
                throw error;
            }
        });
        button.setAttribute('aria-expanded', 'false');
        button.setAttribute('aria-controls', 'myx-saved-menu');
        wrapper.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                menu.hidden = true;
                button.setAttribute('aria-expanded', 'false');
                button.focus();
            }
        });
        wrapper.appendChild(button);
        wrapper.appendChild(menu);
        return wrapper;
    };

    NS.fetchSavedInvoice = async function (filename) {
        const response = await fetch(NS.route('/pdf/saved'), {
            method: 'POST',
            headers: {'Content-Type': 'application/json', requesttoken: OC.requestToken},
            body: JSON.stringify({filename: filename}),
        });
        const type = (response.headers.get('Content-Type') || '').split(';')[0].trim().toLowerCase();
        if (!response.ok || response.redirected || type !== (filename.endsWith('.xml') ? 'application/xml' : 'application/pdf')) {
            throw new Error('Archivdatei konnte nicht geladen werden (HTTP ' + response.status + ').');
        }
        return response;
    };

    NS.downloadSavedInvoice = async function (filename) {
        const response = await NS.fetchSavedInvoice(filename);
        const url = URL.createObjectURL(await response.blob());
        const link = NS.make('a', {href: url, download: filename});
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    };

    // Keep the active draft (including its reserved new number) while browsing the archive.
    NS.openArchivedInvoice = async function (filename) {
        const response = await NS.fetchSavedInvoice(filename);
        const archived = NS.parseArchivedInvoice(await response.text());
        NS.collectFormData();
        const keys = ['invoiceData', 'ramID', 'seller', 'typeCode', 'todayText'];
        NS.state.archiveDraft = Object.fromEntries(keys.map(key => [key, NS.state[key]]));
        Object.assign(NS.state, archived);
        NS.showInvoiceForm();
    };

    NS.leaveArchive = function (useTemplate) {
        const archived = NS.state.invoiceData;
        const draft = NS.state.archiveDraft;
        if (!draft) return;
        Object.assign(NS.state, draft);
        NS.state.archiveDraft = null;
        if (useTemplate) {
            NS.state.invoiceData.buyer = {...archived.buyer};
            NS.state.invoiceData.items[0].description = archived.items[0].description;
            NS.state.invoiceData.items[0].quantity = archived.items[0].quantity;
        }
        NS.showInvoiceForm();
    };

    NS.archiveTemplateDropdown = function () {
        const wrapper = NS.make('div', {class: 'myx-saved-invoices'});
        const menu = NS.make('div', {class: 'myx-saved-menu', id: 'myx-template-menu'});
        menu.hidden = true;
        const button = NS.button('als Vorlage verwenden...', function () {
            menu.hidden = !menu.hidden;
            button.setAttribute('aria-expanded', String(!menu.hidden));
        });
        button.setAttribute('aria-expanded', 'false');
        button.setAttribute('aria-controls', 'myx-template-menu');
        menu.appendChild(NS.button('für neue Rechnung', () => NS.leaveArchive(true)));
        const cancel = NS.button('für Storno-Rechnung', function () {});
        cancel.disabled = true;
        menu.appendChild(cancel);
        wrapper.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                menu.hidden = true;
                button.setAttribute('aria-expanded', 'false');
                button.focus();
            }
        });
        wrapper.appendChild(button);
        wrapper.appendChild(menu);
        return wrapper;
    };

    NS.parseArchivedInvoice = function (xml) {
        const doc = new DOMParser().parseFromString(xml, 'application/xml');
        const root = doc.documentElement;
        if (doc.querySelector('parsererror') || root.localName !== 'CrossIndustryInvoice') {
            throw new Error('Archivdatei enthält keine lesbare XML-Rechnung.');
        }
        // Resolve direct children by local name so XML namespace prefixes may vary.
        const node = (parent, path) => path.split('/').reduce((current, name) =>
            current && Array.from(current.children).find(child => child.localName === name), parent);
        const value = (parent, path) => node(parent, path)?.textContent || '';
        const documentNode = node(root, 'ExchangedDocument');
        const transaction = node(root, 'SupplyChainTradeTransaction');
        const agreement = node(transaction, 'ApplicableHeaderTradeAgreement');
        const buyer = node(agreement, 'BuyerTradeParty');
        const seller = node(agreement, 'SellerTradeParty');
        const item = node(transaction, 'IncludedSupplyChainTradeLineItem');
        const settlement = node(transaction, 'ApplicableHeaderTradeSettlement');
        if (!item || !value(documentNode, 'ID')) throw new Error('Archiv-Rechnungsdaten sind unvollständig.');
        const address = (party, field) => value(party, 'PostalTradeAddress/' + field);
        const date = value(documentNode, 'IssueDateTime/DateTimeString');
        return {
            ramID: value(documentNode, 'ID'),
            typeCode: value(documentNode, 'TypeCode'),
            todayText: date.replace(/^(\d{4})(\d{2})(\d{2})$/, '$3.$2.$1'),
            seller: {
                name: value(seller, 'Name'), address: address(seller, 'LineOne'),
                address2: address(seller, 'LineTwo'), address3: address(seller, 'LineThree'),
                postcode: address(seller, 'PostcodeCode'), city: address(seller, 'CityName'),
                country: address(seller, 'CountryID'),
                vatId: Array.from(seller?.children || [])
                    .filter(child => child.localName === 'SpecifiedTaxRegistration')
                    .map(child => node(child, 'ID')).find(id => id?.getAttribute('schemeID') === 'VA')?.textContent || '',
                iban: value(settlement, 'SpecifiedTradeSettlementPaymentMeans/PayeePartyCreditorFinancialAccount/IBANID'),
            },
            invoiceData: {
                buyer: {
                    name: value(buyer, 'Name'), street: address(buyer, 'LineOne'),
                    postcode: address(buyer, 'PostcodeCode'), city: address(buyer, 'CityName'),
                    country: address(buyer, 'CountryID'), reference: value(agreement, 'BuyerReference'),
                },
                items: [{
                    name: value(item, 'SpecifiedTradeProduct/Name'),
                    description: value(item, 'SpecifiedTradeProduct/Description'),
                    quantity: NS.parseAmount(value(item, 'SpecifiedLineTradeDelivery/BilledQuantity')),
                    unitPrice: NS.parseAmount(value(item, 'SpecifiedLineTradeAgreement/NetPriceProductTradePrice/ChargeAmount')),
                }],
                vatRate: NS.parseAmount(value(item, 'SpecifiedLineTradeSettlement/ApplicableTradeTax/RateApplicablePercent')),
                expenses: [],
            },
        };
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

        const data = NS.state.archiveDraft ? NS.state.invoiceData : NS.collectFormData();

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
