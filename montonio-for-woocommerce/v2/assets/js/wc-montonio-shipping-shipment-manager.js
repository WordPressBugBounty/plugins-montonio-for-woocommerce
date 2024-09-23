(function($){
    'use strict';

    var shippingPanel = $('.montonio-shipping-panel');
    var shipmentStatus = '';

    // Create or update shippment in Montonio
    $(document).on('click', '#montonio-shipping-send-shipment', function(e) {        
        if (!wcMontonioShippingShipmentData || !wcMontonioShippingShipmentData.orderId) {
            if (wp && wp.data && wp.data.dispatch) {
                wp.data.dispatch("core/notices").createNotice(
                    "error",
                    "Montonio: Missing wcMontonioShippingShipmentData", // Text string to display.
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
                        'Montonio: Shipment created/updated successfully',
                    );
                }
            },
            error: function(response) {
                if (wp && wp.data && wp.data.dispatch) {
                    wp.data.dispatch('core/notices').createNotice(
                        'error',
                        'Montonio: Shipment creation/update failed',
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
                    $('.montonio-shipping-panel-wrappper').html(response.panel);

                    if (wp && wp.data && wp.data.dispatch) {
                        wp.data.dispatch('core/notices').createNotice(
                            'success',
                            'Montonio: Shipment status updated',
                        );
                    }
                }

                if (shipmentStatus === 'registered' || shipmentStatus === 'registrationFailed') {
                    clearInterval(window.shipmentRegisteredInterval);
                }
            },
            error: function(response) {
                clearInterval(window.shipmentRegisteredInterval);
            }
        });
    }

})(jQuery);
