define(['Reservepay_Payment/js/payment-category-options'], function (loadCategoryOptions) {
    'use strict';

    function methodsCell(option) {
        var cell = document.createElement('td');

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
        if (option.more > 0) {
            cell.appendChild(document.createTextNode(' +' + option.more));
        }
        return cell;
    }

    return function (config, element) {
        loadCategoryOptions(config, { maxLogos: Infinity, locale: config.locale }).then(function (options) {
            var table = element.querySelector('[data-role="categories"]');

            element.querySelector('[data-role="checking"]').hidden = true;
            if (!options) {
                element.querySelector('[data-role="fallback"]').hidden = false;
                return;
            }
            options.forEach(function (option) {
                var row = document.createElement('tr');
                var label = document.createElement('td');

                label.textContent = option.label || option.category;
                label.style.whiteSpace = 'nowrap';
                row.appendChild(label);
                row.appendChild(methodsCell(option));
                table.tBodies[0].appendChild(row);
            });
            table.hidden = false;
        });
    };
});
