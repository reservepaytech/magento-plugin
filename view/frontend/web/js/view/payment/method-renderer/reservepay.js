define([
    'jquery',
    'ko',
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/action/select-payment-method',
    'Magento_Checkout/js/checkout-data',
    'Reservepay_Payment/js/model/payment-category-options',
    'mage/url'
], function ($, ko, Component, selectPaymentMethodAction, checkoutData, categoryOptions, url) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Reservepay_Payment/paymentmethod',
            redirectAfterPlaceOrder: false,
            redirectUrl: 'reservepay/payment/form'
        },

        initialize: function () {
            var self = this;

            this._super();
            this.category = (window.checkoutConfig.payment.reservepay.categories.find(function (entry) {
                return entry.code === self.getCode();
            }) || {}).category;
            this.isVisible = ko.pureComputed(function () {
                return self.isVisibleIn(categoryOptions());
            });
            // A selection made before the SDK answered must not stay on a hidden option. Reads the state directly, not
            // isVisible, which may not have updated yet when this runs.
            ko.computed(function () {
                var state = categoryOptions();

                if (state !== null && !self.isVisibleIn(state) && self.isChecked() === self.getCode()) {
                    selectPaymentMethodAction(null);
                    checkoutData.setSelectedPaymentMethod(null);
                }
            });
            return this;
        },

        isVisibleIn: function (state) {
            return state !== null && !!state.byCategory[this.category];
        },

        /**
         * @returns {{logos: Array<{symbol: string, name: string, src: string}>, more: number}}
         */
        getOption: function () {
            var state = categoryOptions();

            return (state && state.byCategory[this.category]) || { logos: [], more: 0 };
        },

        afterPlaceOrder: function () {
            $.mage.redirect(url.build(this.redirectUrl));
            return false;
        }
    });
});
