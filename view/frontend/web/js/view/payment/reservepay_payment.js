define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'reservepay_payment',
                component: 'Reservepay_Payment/js/view/payment/method-renderer/reservepay'
            }
        );
        return Component.extend({});
    }
);