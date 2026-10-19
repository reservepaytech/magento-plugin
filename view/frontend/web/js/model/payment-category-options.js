define(['ko', 'Reservepay_Payment/js/payment-category-options'], function (ko, loadCategoryOptions) {
    'use strict';

    var config = window.checkoutConfig.payment.reservepay || { categories: [] };

    /**
     * null while the SDK is asked, then the options to show by category.
     *
     * @type {ko.Observable<null|{byCategory: Object<string, {logos: Array, more: number}>}>}
     */
    var state = ko.observable(null);

    if (config.categories.length) {
        loadCategoryOptions(config).then(function (result) {
            var byCategory = {};

            result.options.forEach(function (option) {
                byCategory[option.category] = option;
            });
            state({ byCategory: byCategory });
        });
    }

    return state;
});
