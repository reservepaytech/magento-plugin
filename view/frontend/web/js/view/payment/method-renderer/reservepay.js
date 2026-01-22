define(
    [
        'jquery',
        'Magento_Checkout/js/view/payment/default',
        'mage/url',
        'Magento_Checkout/js/action/place-order'
    ],
    function ($, Component, url, placeOrderAction) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'Reservepay_Payment/paymentmethod',
                redirectAfterPlaceOrder: false,
                redirectUrl: 'reservepay/payment/form'
            },

            afterPlaceOrder: function () {
                $.mage.redirect(url.build(this.redirectUrl));
            }
        });
    }
);