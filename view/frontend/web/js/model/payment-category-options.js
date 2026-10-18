define(['ko'], function (ko) {
    'use strict';

    var TIMEOUT_MS = 10000;
    var CATEGORIES = ['CARD', 'INSTALLMENT', 'SCAN_TO_PAY', 'MOBILE_BANKING', 'EWALLET_APP'];
    var config = window.checkoutConfig.payment.reservepay || { categories: [] };

    /**
     * null while the SDK is asked, then the options to show by category.
     *
     * @type {ko.Observable<null|{byCategory: Object<string, {logos: Array, more: number}>}>}
     */
    var state = ko.observable(null);

    // Mirrors getAvailablePaymentCategories in @reservepaytech/browser-sdk-core; used only when the SDK can't answer.
    function availableCategories(settings) {
        var methods = settings.payment_methods || [];
        var upper = function (value) {
            return typeof value === 'string' ? value.toUpperCase() : value;
        };

        return CATEGORIES.filter(function (category) {
            if (category === 'INSTALLMENT') {
                return !!settings.interest_bearer && methods.some(function (method) {
                    return upper(method.name) === 'INSTALLMENT';
                });
            }
            return methods.some(function (method) {
                return upper(method.category) === category &&
                    !(category === 'CARD' && upper(method.name) === 'INSTALLMENT');
            });
        });
    }

    function settle(options) {
        if (state() !== null) {
            return;
        }
        if (!options || !options.length) {
            var categories = config.settings ? availableCategories(config.settings) : [];

            options = (categories.length ? categories : ['CARD']).map(function (category) {
                return { category: category, logos: [], more: 0 };
            });
        }
        var byCategory = {};
        options.forEach(function (option) {
            byCategory[option.category] = option;
        });
        state({ byCategory: byCategory });
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
