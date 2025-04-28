jQuery(document).ready(function($) {
    'use strict'; 

    const { __, _x, _n, _nx } = wp.i18n;
    var labelPrintingInterval = null;
    var shippingPanel = $('.montonio-shipping-panel');
    
    $(document).on('click', '.wc-action-button-montonio_print_label', function(event) {
        event.preventDefault();

        var order_id = $(this).attr('href').replace('#', '');

        var data = {
            order_ids: [order_id]
        };
        
        createMontonioShippingLabels(data);
    });

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
    
        createMontonioShippingLabels(data);
    });

    // This is used in the order details page
    $(document).on('click', '#montonio-shipping-print-label', function(event) {
        if (!wcMontonioShippingLabelPrintingData || !wcMontonioShippingLabelPrintingData.orderId) {
            showNotice('error', __('Montonio: Failed to print labels, missing wcMontonioShippingLabelPrintingData', 'montonio-for-woocommerce'));

            return;
        }

        event.preventDefault();

        var data = {
            order_ids: [wcMontonioShippingLabelPrintingData.orderId]
        };

        createMontonioShippingLabels(data);
        
    });

    function createMontonioShippingLabels(data) {
        if (!wcMontonioShippingLabelPrintingData || !wcMontonioShippingLabelPrintingData.restUrl) {
            showNotice('error', __('Montonio: Failed to print labels, missing wcMontonioShippingLabelPrintingData', 'montonio-for-woocommerce'));

            return;
        }

        showNotice('info', __('Montonio: Started downloading Shipping labels', 'montonio-for-woocommerce'));

        shippingPanel.addClass('montonio-shipping-panel--loading');

        $.ajax({
            url: wcMontonioShippingLabelPrintingData.restUrl + '/labels/create',
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
                            pollMontonioShippingLabels();
                        }, 1000);
                    } else {
                        showNotice('error', __('Montonio: Unable to start polling for labels', 'montonio-for-woocommerce'));
                    }
                }
            },
            error: function(response) {
                console.error(response);
                shippingPanel.removeClass('montonio-shipping-panel--loading');

                showNotice('error', __('Montonio: Failed to print labels', 'montonio-for-woocommerce'));
            }
        });
    }

    function saveLatestLabelFileIdToSession(labelFileId) {
        sessionStorage.setItem('wc_montonio_shipping_latest_label_file_id', labelFileId);
    }

    function getLatestLabelFileIdFromSession() {
        return sessionStorage.getItem('wc_montonio_shipping_latest_label_file_id');
    }

    function pollMontonioShippingLabels() {
        $.ajax({
            url: wcMontonioShippingLabelPrintingData.restUrl + '/labels?label_file_id=' + getLatestLabelFileIdFromSession(),
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

                    shippingPanel.removeClass('montonio-shipping-panel--loading');
                    clearInterval(labelPrintingInterval);
                    labelPrintingInterval = null;

                    showNotice('success',  __('Montonio: Labels downloaded. Refresh the browser for updated order statuses', 'montonio-for-woocommerce'));
                } else if (response && response.data && response.data.status === 'failed') {
                    shippingPanel.removeClass('montonio-shipping-panel--loading');
                    clearInterval(labelPrintingInterval);
                    labelPrintingInterval = null;

                    showNotice('error', __('Montonio: Failed to print labels', 'montonio-for-woocommerce'));
                }
            },
            error: function(response) {
                console.error(response);
                shippingPanel.removeClass('montonio-shipping-panel--loading');
                clearInterval(labelPrintingInterval);
                labelPrintingInterval = null;

                showNotice('error', __('Montonio: Failed to print labels', 'montonio-for-woocommerce'));
            }
        });
    }

    function showNotice(type, message) {
        if (wp && wp.data && wp.data.dispatch) {
            wp.data.dispatch('core/notices').createNotice(
                type,
                message
            );
        } else {
            alert(message);
        }
    }
});
