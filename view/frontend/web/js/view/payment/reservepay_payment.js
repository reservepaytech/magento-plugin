define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    var config = window.checkoutConfig.payment.reservepay || { categories: [] };

    config.categories.forEach(function (category) {
        rendererList.push({
            type: category.code,
            component: 'Reservepay_Payment/js/view/payment/method-renderer/reservepay'
        });
    });

    return Component.extend({});
});
