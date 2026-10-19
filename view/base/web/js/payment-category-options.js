define([], function () {
    'use strict';

    var TIMEOUT_MS = 10000;
    var sdkLoaded = null;

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

    function ask(config, options) {
        return loadSdk(config.sdkUrl).then(function () {
            var sdk = window.Reservepay;
            var sdkOptions = {};

            // A deployed SDK older than the helper still pays, through its own chooser.
            if (!sdk || typeof sdk.retrievePaymentCategoryOptions !== 'function') {
                return null;
            }
            if (config.paymentAssetsUrl) {
                sdkOptions.paymentAssetsUrl = config.paymentAssetsUrl;
            }
            if (options.maxLogos !== undefined) {
                sdkOptions.maxLogos = options.maxLogos;
            }
            if (options.locale) {
                sdkOptions.locale = options.locale;
            }
            return sdk.retrievePaymentCategoryOptions(config.settings, sdkOptions);
        });
    }

    /**
     * The installation's payment categories as the SDK groups them, or null when it can't answer: no settings (the SDK
     * is not loaded then), the SDK fails to load or takes over 10 s, it lacks the helper, or it finds none.
     *
     * @param {{settings: ?Object, sdkUrl: string, paymentAssetsUrl: ?string}} config
     * @param {{maxLogos: (number|undefined), locale: (string|undefined)}} [options]
     * @returns {Promise<?Array<{category: string, label: string, logos: Array<{symbol: string, name: string, src: string}>, more: number}>>}
     */
    return function (config, options) {
        if (!config.settings) {
            return Promise.resolve(null);
        }
        return new Promise(function (resolve) {
            var settle = function (categories) {
                resolve(Array.isArray(categories) && categories.length ? categories : null);
            };

            setTimeout(settle, TIMEOUT_MS);
            ask(config, options || {}).then(settle, function () {
                settle(null);
            });
        });
    };
});
