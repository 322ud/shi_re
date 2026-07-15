(function () {
    'use strict';

    const NS = window.MyXRechnung;

    NS.money = function (value) {
        return Number(value || 0).toFixed(2);
    };

    NS.todayISO = function () {
        return new Date().toISOString().slice(0, 10);
    };

    NS.todayDE = function () {
        return new Date().toLocaleDateString('de-DE');
    };

    NS.calculateTotals = function (data) {
        const itemNet = data.items.reduce(function (sum, item) {
            return sum + Number(item.quantity || 0) * Number(item.unitPrice || 0);
        }, 0);

        const expensesNet = data.expenses.reduce(function (sum, item) {
            return sum + Number(item.quantity || 0) * Number(item.unitPrice || 0);
        }, 0);

        const vat = itemNet * Number(data.vatRate || 0) / 100;
        const grand = itemNet + vat + expensesNet;

        return {
            itemNet: itemNet,
            expensesNet: expensesNet,
            vat: vat,
            grand: grand,
        };
    };
})();
