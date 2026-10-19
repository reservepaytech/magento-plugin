define([], function () {
    'use strict';

    var TIMEOUT_MS = 10000;
    var sdkLoaded = null;

    // Mirrors getAvailablePaymentCategories in @reservepaytech/browser-sdk-core; used only when the SDK can't answer.
    function availableCategories(settings, categories) {
        var methods = settings.payment_methods || [];
        var upper = function (value) {
            return typeof value === 'string' ? value.toUpperCase() : value;
        };

        return categories.filter(function (category) {
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

    // The first category alone when nothing is known, so a configured store never shows zero options.
    function fallback(config) {
        var all = config.categories.map(function (entry) {
            return entry.category;
        });
        var categories = config.settings ? availableCategories(config.settings, all) : [];

        return {
            fromSdk: false,
            options: (categories.length ? categories : all.slice(0, 1)).map(function (category) {
                return { category: category, logos: [], more: 0 };
            })
        };
    }

    function loadSdk(url) {
        if (window.Reservepay) {
            return Promise.resolve();
        }
        sdkLoaded = sdkLoaded || new Promise(function (resolve, reject) {
            var script = document.createElement('script');

            script.src = url;
            script.async = true;
            script.onload = resolve;
            script.onerror = reject;
            document.head.appendChild(script);
        });
        return sdkLoaded;
    }

    function ask(config, maxLogos) {
        return loadSdk(config.sdkUrl).then(function () {
            var sdk = window.Reservepay;
            var options = {};

            // A deployed SDK older than the helper still pays, through its own chooser.
            if (!sdk || typeof sdk.retrievePaymentCategoryOptions !== 'function') {
                return null;
            }
            if (config.paymentAssetsUrl) {
                options.paymentAssetsUrl = config.paymentAssetsUrl;
            }
            if (maxLogos !== undefined) {
                options.maxLogos = maxLogos;
            }
            return sdk.retrievePaymentCategoryOptions(config.settings, options);
        });
    }

    /**
     * The categories checkout offers for these installation settings, with the SDK's logos, or the local rule's
     * categories without logos when the SDK can't answer. No settings: the first category alone, without loading the SDK.
     *
     * @param {{categories: Array<{category: string}>, settings: ?Object, sdkUrl: string, paymentAssetsUrl: ?string}} config
     *     categories: Model\PaymentCategories::CODES, in its order, default first
     * @param {number} [maxLogos] logos per category, the SDK's default (3, then "more") when left out
     * @returns {Promise<{fromSdk: boolean, options: Array<{category: string, logos: Array, more: number}>}>}
     */
    return function (config, maxLogos) {
        if (!config.settings) {
            return Promise.resolve(fallback(config));
        }
        return new Promise(function (resolve) {
            var settle = function (options) {
                resolve(options && options.length ? { fromSdk: true, options: options } : fallback(config));
            };

            setTimeout(settle, TIMEOUT_MS);
            ask(config, maxLogos).then(settle, function () {
                settle(null);
            });
        });
    };
});
