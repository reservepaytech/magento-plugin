define([
    'jquery',
    'Magento_Checkout/js/view/payment/default',
    'mage/url'
], function ($, Component, url) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Reservepay_Payment/paymentmethod',
            redirectAfterPlaceOrder: false,
            redirectUrl: 'reservepay/payment/form'
        },

        /**
         * @returns {{logos: Array<{src: string, alt: string}>, more: number}}
         */
        getGroup: function () {
            var code = this.getCode();

            return window.checkoutConfig.payment.reservepay.groups.find(function (group) {
                return group.code === code;
            }) || { logos: [], more: 0 };
        },

        afterPlaceOrder: function () {
            $.mage.redirect(url.build(this.redirectUrl));
            return false;
        }
    });
});
