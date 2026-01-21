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

            // initObservable: function () {

            //     this._super()
            //         .observe([
            //             'transactionResult'
            //         ]);
            //     return this;
            // },

            // getCode: function() {
            //     return 'reservepay_payment';
            // },

            // getData: function() {
            //     return {
            //         'method': (this.item && this.item.method) ? this.item.method : this.getCode(),
            //         'additional_data': {
            //             'transaction_result': this.transactionResult()
            //         }
            //     };
            // },

            // getTransactionResults: function() {
            //     var results = (window.checkoutConfig && window.checkoutConfig.payment && window.checkoutConfig.payment.reservepay_payment && window.checkoutConfig.payment.reservepay_payment.transactionResults) || {};
            //     return _.map(results, function(value, key) {
            //         return {
            //             'value': key,
            //             'transaction_result': value
            //         };
            //     });
            // },

            afterPlaceOrder: function () {
                // window.location.replace(url.build(this.redirectUrl));
                console.log('Redirecting to Reservepay payment form via $.mage.redirect: ' + url.build(this.redirectUrl));
                $.mage.redirect(url.build(this.redirectUrl));
                // var redirectUrl = url.build('reservepay/payment/form');
                // console.log('afterPlaceOrder called. current location: ' + window.location.href);
                // console.log('Redirecting to Reservepay payment form: ' + redirectUrl);

                // // Ensure absolute URL for some environments
                // if (redirectUrl && redirectUrl.indexOf('/') === 0) {
                //     redirectUrl = window.location.origin + redirectUrl;
                //     console.log('Normalized redirectUrl to absolute:', redirectUrl);
                // }

                // try {
                //     window.location.replace(redirectUrl);
                // } catch (e) {
                //     console.error('Redirect replace() failed, will fallback', e);
                //     // Try assign after a short delay to let Magento finish any UI work
                //     setTimeout(function() {
                //         try {
                //             window.location.href = redirectUrl;
                //         } catch (e2) {
                //             console.error('Fallback href assignment failed', e2);
                //         }
                //     }, 150);
                // }
                // // Additional fallbacks for iframe/parent contexts or blocked replace():
                // setTimeout(function() {
                //     try {
                //         if (window.top && window.top !== window) {
                //             console.log('Attempting redirect via window.top');
                //             window.top.location.replace(redirectUrl);
                //             return;
                //         }
                //     } catch (etop) {
                //         console.warn('window.top redirect failed', etop);
                //     }

                //     try {
                //         if (window.parent && window.parent !== window) {
                //             console.log('Attempting redirect via window.parent');
                //             window.parent.location.replace(redirectUrl);
                //             return;
                //         }
                //     } catch (eparent) {
                //         console.warn('window.parent redirect failed', eparent);
                //     }

                //     try {
                //         console.log('Final fallback: assign href and open');
                //         window.location.href = redirectUrl;
                //         window.open(redirectUrl, '_self');
                //     } catch (efinal) {
                //         console.error('All redirect fallbacks failed', efinal);
                //     }
                // }, 300);
            }
        });
    }
);