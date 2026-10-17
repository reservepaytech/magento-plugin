define(['ko'], function (ko) {
    'use strict';

    var TIMEOUT_MS = 10000;
    var config = window.checkoutConfig.payment.reservepay || { categories: [] };

    /**
     * null while the SDK is asked. Then either the SDK's options by category, or fallback: true when the SDK could not
     * answer, and checkout offers one "Pay with Reservepay" option in the card slot instead.
     *
     * @type {ko.Observable<null|{fallback: boolean, byCategory: Object<string, {logos: Array, more: number}>}>}
     */
    var state = ko.observable(null);

    function settle(options) {
        if (state() !== null) {
            return;
        }
        var byCategory = {};
        (options || []).forEach(function (option) {
            byCategory[option.category] = option;
        });
        state({ fallback: !options || !options.length, byCategory: byCategory });
    }

    function ask() {
        var sdk = window.Reservepay;
        // A deployed SDK older than the helper still pays, through its own chooser.
        if (!sdk || typeof sdk.retrievePaymentCategoryOptions !== 'function') {
            settle(null);
            return;
        }
        sdk.retrievePaymentCategoryOptions(
            config.settings,
            config.paymentAssetsUrl ? { paymentAssetsUrl: config.paymentAssetsUrl } : undefined
        ).then(settle, function () {
            settle(null);
        });
    }

    if (config.categories.length) {
        if (!config.settings) {
            settle(null);
        } else if (window.Reservepay) {
            ask();
        } else {
            setTimeout(function () {
                settle(null);
            }, TIMEOUT_MS);
            var script = document.createElement('script');
            script.src = config.sdkUrl;
            script.async = true;
            script.onload = ask;
            script.onerror = function () {
                settle(null);
            };
            document.head.appendChild(script);
        }
    }

    return state;
});
