jQuery(document).ready(function($) {
	'use strict'; 

    // This is used in the orders list page
    $(document).on('click', '#doaction', function(event) {
        if ($('#bulk-action-selector-top').val() !== 'montonio_print_labels') {
            return;
        }

        var formId = $(this).closest('form').attr('id');

        if (formId == 'wc-orders-filter') {
            var orderIds = $('#wc-orders-filter').serializeArray()
            .filter(param => { return param.name === 'id[]' })
            .map(param => { return param.value });

        } else {
            var orderIds = $('#posts-filter').serializeArray()
            .filter(param => { return param.name === 'post[]' })
            .map(param => { return param.value });

        }

        if (orderIds.length === 0) {
            return;
        }

        event.preventDefault();

        var data = {
            orderIds: orderIds
        };

        createLabels(data);
    });

    // This is used in the order details page
    $(document).on('submit', function(e) {
        var orderActionSelect = $('select[name="wc_order_action"]');
        if (!orderActionSelect.length) {
            console.error('Montonio: Missing order action select');
            return;
        }

        var selectAction = orderActionSelect.val();
        if (selectAction === 'montonio_print_labels') {
            console.log('Montonio: Printing labels');
            if (!montonioPrintLabelsData) {
                console.error('Montonio: Missing montonioPrintLabelsData');

                if (wp && wp.data && wp.data.dispatch) {
                    wp.data.dispatch("core/notices").createNotice(
                        "error",
                        "Montonio: Missing montonioPrintLabelsData", // Text string to display.
                    );
                }
                return;
            }

            e.preventDefault();

            if (!montonioPrintLabelsData.orderId) {
                console.error('Montonio: Missing orderId');
                if (wp && wp.data && wp.data.dispatch) {
                    wp.data.dispatch("core/notices").createNotice(
                        "error",
                        "Montonio: Missing orderId", // Text string to display.
                    );
                }
                return;
            }

            var data = {
                orderIds: [montonioPrintLabelsData.orderId]
            };

            createLabels(data);
        }
    })


    function createLabels(data) {
        if (!montonioPrintLabelsData || !montonioPrintLabelsData.createLabelsUrl) {
            if (wp && wp.data && wp.data.dispatch) {
                wp.data.dispatch("core/notices").createNotice(
                    "error",
                    "Montonio: Missing montonioPrintLabelsData", // Text string to display.
                );
            }
            return;
        }

        if (wp && wp.data && wp.data.dispatch) {
            wp.data.dispatch("core/notices").createNotice(
                "info",
                "Montonio: Started downloading Shipping labels", // Text string to display.
            );
        }
        $.post(montonioPrintLabelsData.createLabelsUrl, data, function(response) {

            if (response.data && response.data.url) {
                var anchor = document.createElement("a");
                anchor.href = response.data.url;
                anchor.download = 'labels.pdf';

                document.body.appendChild(anchor);
                anchor.click();
                document.body.removeChild(anchor);

                if (wp && wp.data && wp.data.dispatch) {
                    wp.data.dispatch("core/notices").createNotice(
                        "success",
                        "Montonio: Labels downloaded. Refresh the browser for updated order statuses", // Text string to display.
                    );
                } else {
                    alert("Montonio: Labels downloaded. Refresh the browser for updated order statuses");
                }
            }
        }).fail(function(response) {
            console.error(response)
            if (wp && wp.data && wp.data.dispatch) {
                wp.data.dispatch("core/notices").createNotice(
                    "error",
                    "Montonio: Failed to print labels", // Text string to display.
                );
            } else {
                alert("Montonio: Failed to print labels");
            }
        })
    }

});
