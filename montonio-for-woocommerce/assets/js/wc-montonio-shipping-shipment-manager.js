(function($){
    'use strict';

    const { __, _x, _n, _nx } = wp.i18n;
    var shippingPanel = $('.montonio-shipping-panel');
    var shipmentStatus = '';

    // Create or update shippment in Montonio
    $(document).on('click', '#montonio-shipping-send-shipment', function(e) {        
        if (!wcMontonioShippingShipmentData || !wcMontonioShippingShipmentData.orderId) {
            if (wp && wp.data && wp.data.dispatch) {
                wp.data.dispatch("core/notices").createNotice(
                    'error',
                    'Montonio: Missing wcMontonioShippingShipmentData',
                );
            }
            return;
        }
        $('.montonio-shipping-panel').addClass('montonio-shipping-panel--loading');

        var type = $(this).data('type');
        var data = {
            order_id: wcMontonioShippingShipmentData.orderId
        };

        $.ajax({
            url: wcMontonioShippingShipmentData.shippingRestUrl + '/shipment/' + type,
            type: 'POST',
            data: data,
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcMontonioShippingShipmentData.nonce);
            },
            complete: function() {
                $('.montonio-shipping-panel').removeClass('montonio-shipping-panel--loading');

                shipmentStatus = '';

                window.shipmentRegisteredInterval = setInterval(function() {
                    updateShippingPanelContent();
                }, 1000);
            },
            success: function(response) {
                if (wp && wp.data && wp.data.dispatch) {
                    wp.data.dispatch('core/notices').createNotice(
                        'success',
                        __('Montonio: Shipment created/updated successfully.', 'montonio-for-woocommerce'),
                    );
                }
            },
            error: function(response) {
                if (wp && wp.data && wp.data.dispatch) {
                    wp.data.dispatch('core/notices').createNotice(
                        'error',
                        __('Montonio: Shipment creation/update failed.', 'montonio-for-woocommerce'),
                    );
                }
            }
        });
    });

    function updateShippingPanelContent() {
        var data = {
            order_id: wcMontonioShippingShipmentData.orderId,
            status: shipmentStatus
        };

        $.ajax({
            url: wcMontonioShippingShipmentData.shippingRestUrl + '/shipment/update-panel',
            type: 'POST',
            data: data,
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcMontonioShippingShipmentData.nonce);
            },
            success: function(response) {
                if (shipmentStatus !== response.status) {
                    shipmentStatus = response.status;
                    $('.montonio-shipping-panel-wrapper').html(response.panel);

                    if (wp && wp.data && wp.data.dispatch) {
                        wp.data.dispatch('core/notices').createNotice(
                            'success',
                            __('Montonio: Shipment status updated.', 'montonio-for-woocommerce'),
                        );
                    }
                }

                if (shipmentStatus !== 'pending') {
                    clearInterval(window.shipmentRegisteredInterval);
                }
            },
            error: function(response) {
                clearInterval(window.shipmentRegisteredInterval);
            }
        });
    }

})(jQuery);
