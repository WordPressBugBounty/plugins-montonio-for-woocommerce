jQuery(document).ready(function($) {
    'use strict'; 

    const { __, _x, _n, _nx } = wp.i18n;
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
                    'error',
                    __('Montonio: Missing wcMontonioShippingLabelPrintingData', 'montonio-for-woocommerce')
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
                    'error',
                    __('Montonio: Missing wcMontonioShippingLabelPrintingData', 'montonio-for-woocommerce')
                );
            }
            return;
        }

        if (wp && wp.data && wp.data.dispatch) {
            wp.data.dispatch("core/notices").createNotice(
                'info',
                __('Montonio: Started downloading Shipping labels', 'montonio-for-woocommerce')
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
                                'error',
                                __('Montonio: Unable to start polling for labels', 'montonio-for-woocommerce')
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
                        'error',
                        __('Montonio: Failed to print labels', 'montonio-for-woocommerce'),
                    );
                } else {
                    alert(__('Montonio: Failed to print labels', 'montonio-for-woocommerce'));
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
                    'error',
                    __('Montonio: Missing wcMontonioShippingLabelPrintingData', 'montonio-for-woocommerce'),
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
                            'success',
                            __('Montonio: Labels downloaded. Refresh the browser for updated order statuses', 'montonio-for-woocommerce'),
                        );
                    } else {
                        alert(__('Montonio: Labels downloaded. Refresh the browser for updated order statuses', 'montonio-for-woocommerce'));
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
                        'error',
                        __('Montonio: Failed to print labels', 'montonio-for-woocommerce'),
                    );
                } else {
                    alert(__('Montonio: Failed to print labels', 'montonio-for-woocommerce'));
                }
            }
        });
    }
});
