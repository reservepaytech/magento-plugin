define(
    [
        'jquery',
        'Magento_Checkout/js/view/payment/default',
        'mage/url'
    ],
    function ($, Component, url) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'Reservepay_Payment/paymentmethod',
                redirectAfterPlaceOrder: false,
                redirectUrl: 'reservepay/payment/form'
            },

            placeOrder: function (data, event) {
                var self = this;

                if (event) {
                    event.preventDefault();
                }

                if (this.validate() &&
                    this.isPlaceOrderActionAllowed()
                ) {
                    this.isPlaceOrderActionAllowed(false);

                    this.getPlaceOrderDeferredObject()
                        .done(function (orderId) {
                            $.mage.redirect(
                                url.build(self.redirectUrl + '/order_id/' + orderId)
                            );
                        })
                        .always(function () {
                            self.isPlaceOrderActionAllowed(true);
                        });

                    return true;
                }

                return false;
            }
        });
    }
);
