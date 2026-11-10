(function () {
    'use strict';

    const NS = window.ShiRE;

    NS.parseAmount = function (value) {
        if (value === null || value === undefined) {
            return 0;
        }

        const normalized = String(value)
        .replace('€', '')
        .replace(/\s/g, '')
        .replace(',', '.');

        const parsed = Number(normalized);
        return Number.isFinite(parsed) ? parsed : 0;
    };

    NS.formatAmount = function (value) {
        return NS.parseAmount(value).toFixed(2).replace('.', ',');
    };

    NS.money = function (value) {
        return NS.formatAmount(value) + ' €';
    };

    NS.todayISO = function () {
        return new Date().toISOString().slice(0, 10);
    };

    NS.todayDE = function () {
        return new Date().toLocaleDateString('de-DE');
    };

    NS.calculateTotals = function (data) {
        const itemNet = data.items.reduce(function (sum, item) {
            return sum + Math.round(Math.round(Number(item.quantity || 0) * 10) * Math.round(Number(item.unitPrice || 0) * 100) / 10) / 100;
        }, 0);

        const expensesNet = data.expenses.reduce(function (sum, item) {
            return sum + Math.round(Math.round(Number(item.quantity || 0) * 10) * Math.round(Number(item.unitPrice || 0) * 100) / 10) / 100;
        }, 0);

        const vat = Math.round(Math.round(itemNet * 100) * Math.round(Number(data.vatRate || 0) * 100) / 10000) / 100;
        const grand = itemNet + vat + expensesNet;

        return {
            itemNet: itemNet,
            expensesNet: expensesNet,
            vat: vat,
            grand: grand,
        };
    };
})();
