define([
    'jquery',
    'ko',
    'mage/translate',
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/action/select-payment-method',
    'Magento_Checkout/js/checkout-data',
    'Reservepay_Payment/js/model/payment-category-options',
    'mage/url'
], function ($, ko, $t, Component, selectPaymentMethodAction, checkoutData, categoryOptions, url) {
    'use strict';

    var FALLBACK_CATEGORY = 'CARD';

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
            if (state === null) {
                return false;
            }
            return state.fallback ? this.category === FALLBACK_CATEGORY : !!state.byCategory[this.category];
        },

        isFallback: function () {
            var state = categoryOptions();

            return state !== null && state.fallback && this.category === FALLBACK_CATEGORY;
        },

        getTitle: function () {
            return this.isFallback() ? $t('Pay with Reservepay') : this._super();
        },

        /**
         * @returns {{logos: Array<{symbol: string, name: string, src: string}>, more: number}}
         */
        getOption: function () {
            var state = categoryOptions();

            return (state && state.byCategory[this.category]) || { logos: [], more: 0 };
        },

        getData: function () {
            var data = this._super();

            if (this.isFallback()) {
                data['additional_data'] = { 'reservepay_fallback': '1' };
            }
            return data;
        },

        afterPlaceOrder: function () {
            $.mage.redirect(url.build(this.redirectUrl));
            return false;
        }
    });
});
