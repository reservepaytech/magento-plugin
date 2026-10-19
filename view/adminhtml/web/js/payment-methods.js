define(['Reservepay_Payment/js/payment-category-options'], function (loadCategoryOptions) {
    'use strict';

    function methodsCell(cell, option, fromSdk, text) {
        if (!fromSdk) {
            cell.textContent = text.noLogos;
            return;
        }
        option.logos.forEach(function (logo, index) {
            var item = document.createElement('span');
            var img = document.createElement('img');

            if (index > 0) {
                cell.appendChild(document.createTextNode(', '));
            }
            img.src = logo.src;
            img.alt = '';
            img.width = 24;
            img.height = 24;
            img.style.verticalAlign = 'middle';
            img.style.marginRight = '4px';
            item.style.whiteSpace = 'nowrap';
            item.appendChild(img);
            item.appendChild(document.createTextNode(logo.name));
            cell.appendChild(item);
        });
    }

    return function (config, table) {
        loadCategoryOptions(config, Infinity).then(function (result) {
            var byCategory = {};

            result.options.forEach(function (option) {
                byCategory[option.category] = option;
            });
            table.querySelectorAll('tr[data-category]').forEach(function (row) {
                var option = byCategory[row.getAttribute('data-category')];
                var checkout = row.querySelector('[data-role="checkout"]');
                var methods = row.querySelector('[data-role="methods"]');

                checkout.textContent = option ? config.text.shown : config.text.notOffered;
                checkout.style.whiteSpace = 'nowrap';
                methods.textContent = '';
                if (option) {
                    methodsCell(methods, option, result.fromSdk, config.text);
                }
            });
        });
    };
});
