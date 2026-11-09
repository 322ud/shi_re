(function () {
    'use strict';

    const NS = window.MyXRechnung;

    NS.startInvoice = async function () {
        NS.releasePdfDownload();
        NS.clear(NS.inputArea());
        NS.clear(NS.outputArea());

        const data = await NS.post('/en16931/draft');
        const seed = data.seed || {};

        NS.state.xmlInvoiceFile = null;
        NS.state.ramID = data.ramID || null;
        NS.state.seller = seed.seller || {};
        NS.state.typeCode = String(seed.typeCode ?? '').trim();
        NS.state.todayText = NS.todayDE();

        NS.state.invoiceData = NS.defaultInvoiceData(seed);

        NS.startHeartbeat();
        NS.showInvoiceForm();
    };

    NS.defaultInvoiceData = function (seed) {
        const buyer = seed.buyer || {};

        return {
            buyer: {
                name: buyer.name || '',
                street: buyer.address || '',
                postcode: buyer.postcode || '',
                city: buyer.city || '',
                country: buyer.country || 'DE',
                reference: '',
            },

            items: [{
                name: seed.firstItem?.name || '',
                description: '',
                quantity: 1,
                unitPrice: NS.parseAmount(seed.firstItem?.unitPrice || 0),
            }],

            vatRate: NS.parseAmount(seed.firstItem?.vatRate || 19),
            expenses: [],
            due: 'sofort',
            dueDate: NS.state.todayText,
        };
    };

    NS.cancelInvoice = async function () {
        await NS.post('/en16931/cancel');

        NS.stopHeartbeat();

        NS.clear(NS.outputArea());
        NS.log('Entwurf verworfen.');

        NS.resetStateAfterClose();
        NS.startInvoice();
    };
})();
