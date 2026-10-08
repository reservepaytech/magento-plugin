define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    var config = window.checkoutConfig.payment.reservepay || { groups: [] };

    config.groups.forEach(function (group) {
        rendererList.push({
            type: group.code,
            component: 'Reservepay_Payment/js/view/payment/method-renderer/reservepay'
        });
    });

    return Component.extend({});
});
