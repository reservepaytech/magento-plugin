define([
    'jquery',
    'ko',
    'Magento_Checkout/js/view/payment/default',
    'Reservepay_Payment/js/payment-category-options',
    'mage/url'
], function ($, ko, Component, loadCategoryOptions, url) {
    'use strict';

    var MAX_LOGOS = 6;

    /** Shared by every renderer instance, so a reload of the payment list does not ask the SDK again. */
    var rowLogos = ko.observable({ logos: [], more: 0 });
    var asked = false;

    // The first logo of each category, then the second of each, and so on. A logo two categories share shows once.
    function roundRobin(options) {
        var logos = [];
        var seen = {};
        var depth = 0;
        var i;

        options.forEach(function (option) {
            depth = Math.max(depth, option.logos.length);
        });
        for (i = 0; i < depth; i++) {
            options.forEach(function (option) {
                var logo = option.logos[i];

                if (logo && !seen[logo.src]) {
                    seen[logo.src] = true;
                    logos.push(logo);
                }
            });
        }
        return { logos: logos.slice(0, MAX_LOGOS), more: Math.max(0, logos.length - MAX_LOGOS) };
    }

    function askOnce() {
        if (asked) {
            return;
        }
        asked = true;
        loadCategoryOptions(window.checkoutConfig.payment.reservepay || {}, { maxLogos: Infinity }).then(function (options) {
            if (options) {
                rowLogos(roundRobin(options));
            }
        });
    }

    return Component.extend({
        defaults: {
            template: 'Reservepay_Payment/paymentmethod',
            redirectAfterPlaceOrder: false,
            redirectUrl: 'reservepay/payment/form'
        },

        initialize: function () {
            this._super();
            this.rowLogos = rowLogos;
            askOnce();
            return this;
        },

        afterPlaceOrder: function () {
            $.mage.redirect(url.build(this.redirectUrl));
            return false;
        }
    });
});
