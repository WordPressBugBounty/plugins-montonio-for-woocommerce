jQuery(document).ready(function($) {
    'use strict'; 

    var labelPrintingInterval = null;
    var shippingPanel = $('.montonio-shipping-panel');
    
    // This is used in the orders list page
    $(document).on('click', '#doaction', function(event) {
        if ($('#bulk-action-selector-top').val() !== 'wc_montonio_print_labels') {
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
            order_ids: orderIds
        };
    
        createMontonioShippingV2Labels(data);
    });

    // This is used in the order details page
    $(document).on('click', '#montonio-shipping-print-label', function(event) {
        if (!wcMontonioShippingLabelPrintingData || !wcMontonioShippingLabelPrintingData.orderId) {
            if (wp && wp.data && wp.data.dispatch) {
                wp.data.dispatch("core/notices").createNotice(
                    "error",
                    "Montonio: Missing wcMontonioShippingLabelPrintingData", // Text string to display.
                );
            }
            return;
        }

        event.preventDefault();

        var data = {
            order_ids: [wcMontonioShippingLabelPrintingData.orderId]
        };
        createMontonioShippingV2Labels(data);
        
    });

    function createMontonioShippingV2Labels(data) {
        if (!wcMontonioShippingLabelPrintingData || !wcMontonioShippingLabelPrintingData.createLabelsUrl) {
            if (wp && wp.data && wp.data.dispatch) {
                wp.data.dispatch("core/notices").createNotice(
                    "error",
                    "Montonio: Missing wcMontonioShippingLabelPrintingData", // Text string to display.
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

        shippingPanel.addClass('montonio-shipping-panel--loading');

        $.ajax({
            url: wcMontonioShippingLabelPrintingData.createLabelsUrl,
            type: 'POST',
            data: data,
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcMontonioShippingLabelPrintingData.nonce);
            },
            success: function(response) {
                if (response && response.data && response.data.id) {
                    saveLatestLabelFileIdToSession(response.data.id);
                    if (!labelPrintingInterval && getLatestLabelFileIdFromSession().length > 0) {
                        labelPrintingInterval = setInterval(function() {
                            pollMontonioShippingV2Labels();
                        }, 1000);
                    } else {
                        if (wp && wp.data && wp.data.dispatch) {
                            wp.data.dispatch("core/notices").createNotice(
                                "error",
                                "Montonio: Unable to start polling for labels", // Text string to display.
                            );
                        }
                    }
                }
            },
            error: function(response) {
                console.error(response);
                shippingPanel.removeClass('montonio-shipping-panel--loading');

                if (wp && wp.data && wp.data.dispatch) {
                    wp.data.dispatch("core/notices").createNotice(
                        "error",
                        "Montonio: Failed to print labels", // Text string to display.
                    );
                } else {
                    alert("Montonio: Failed to print labels");
                }
            }
        });
    }

    function saveLatestLabelFileIdToSession(labelFileId) {
        sessionStorage.setItem('wc_montonio_shipping_latest_label_file_id', labelFileId);
    }

    function getLatestLabelFileIdFromSession() {
        return sessionStorage.getItem('wc_montonio_shipping_latest_label_file_id');
    }

    function pollMontonioShippingV2Labels() {
        if (!wcMontonioShippingLabelPrintingData || !wcMontonioShippingLabelPrintingData.getLabelFileUrl) {
            shippingPanel.removeClass('montonio-shipping-panel--loading');

            if (wp && wp.data && wp.data.dispatch) {
                wp.data.dispatch("core/notices").createNotice(
                    "error",
                    "Montonio: Missing wcMontonioShippingLabelPrintingData", // Text string to display.
                );
            }
        }

        $.ajax({
            url: wcMontonioShippingLabelPrintingData.getLabelFileUrl + '?label_file_id=' + getLatestLabelFileIdFromSession(),
            type: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcMontonioShippingLabelPrintingData.nonce);
            },
            success: function(response) {
                if (response && response.data && response.data.labelFileUrl && labelPrintingInterval) {
                    var anchor = document.createElement("a");
                    anchor.href = response.data.labelFileUrl;
                    anchor.download = 'labels-' + response.data.id + '.pdf';

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

                    shippingPanel.removeClass('montonio-shipping-panel--loading');
                    clearInterval(labelPrintingInterval);
                    labelPrintingInterval = null;
                }
            },
            error: function(response) {
                console.error(response);
                shippingPanel.removeClass('montonio-shipping-panel--loading');

                if (wp && wp.data && wp.data.dispatch) {
                    wp.data.dispatch("core/notices").createNotice(
                        "error",
                        "Montonio: Failed to print labels", // Text string to display.
                    );
                } else {
                    alert("Montonio: Failed to print labels");
                }
            }
        });
    }
});
