define([
    'jquery',
    'ko',
    'Magento_Checkout/js/view/payment/default',
    'Reservepay_Payment/js/payment-category-options',
    'mage/url'
], function ($, ko, Component, loadCategoryOptions, url) {
    'use strict';

    /** Shared by every renderer instance, so a reload of the payment list does not ask the SDK again. */
    var categories = ko.observableArray([]);
    var categoryLabels = ko.pureComputed(function () {
        return categories().map(function (category) {
            return category.label;
        }).join(', ');
    });
    var asked = false;

    function toCategory(option) {
        return { label: option.label || option.category, logos: option.logos };
    }

    function askOnce() {
        var config = window.checkoutConfig.payment.reservepay || {};

        if (asked) {
            return;
        }
        asked = true;
        loadCategoryOptions(config, { maxLogos: Infinity, locale: config.locale }).then(function (options) {
            if (options) {
                categories(options.map(toCategory));
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
            this.categories = categories;
            this.categoryLabels = categoryLabels;
            askOnce();
            return this;
        },

        afterPlaceOrder: function () {
            $.mage.redirect(url.build(this.redirectUrl));
            return false;
        }
    });
});
