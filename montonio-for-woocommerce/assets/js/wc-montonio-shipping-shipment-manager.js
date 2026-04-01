(function($) {
    'use strict';

    const { __, _x, _n, _nx } = wp.i18n;
    const panelWrapper = $('.montonio-shipping-panel-wrapper');
    let shipmentStatus = '';
    let shipmentStatusInterval = null;
    
    // Validate required data
    function validateShipmentData() {
        if (!wcMontonioShippingShipmentData || !wcMontonioShippingShipmentData.orderId) {
            showNotice('error', __('Montonio: Missing wcMontonioShippingShipmentData', 'montonio-for-woocommerce'));
            return false;
        }
        return true;
    }
    
    // Helper function for showing notices
    function showNotice(type, message) {
        if (wp?.data?.dispatch) { 
            wp.data.dispatch('core/notices').createNotice(type, message);
        }
    }
    
    // Helper function for AJAX requests with common settings
    function makeAjaxRequest(endpoint, data = {}, method = 'POST') {
        return $.ajax({
            url: wcMontonioShippingShipmentData.shippingRestUrl + endpoint,
            type: method,
            data: { ...data, order_id: wcMontonioShippingShipmentData.orderId },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcMontonioShippingShipmentData.nonce);
            }
        });
    }
    
    // Clear any existing interval
    function clearStatusInterval() {
        if (shipmentStatusInterval) {
            clearInterval(shipmentStatusInterval);
            shipmentStatusInterval = null;
        }
    }
    
    // Start status polling
    function startStatusPolling() {
        clearStatusInterval(); // Prevent multiple intervals
        shipmentStatusInterval = setInterval(updateShippingPanelContent, 1000);
    }
    
    // Event handlers
    $(document).on('click', '#montonio-shipping-create-shipment', function(e) {
        e.preventDefault();
        handleShipment('create');
    });

    $(document).on('click', '#montonio-shipping-update-shipment', function(e) {
        e.preventDefault();
        handleShipment('update');
    });

    $(document).on('click', '#montonio-shipping-sync-shipment', function(e) {
        e.preventDefault();
        syncShipment();
    });

    function handleShipment(type) {
        if (!validateShipmentData()) {
            return;
        }
        
        $('.montonio-shipping-panel').addClass('montonio-shipping-panel--loading');

        makeAjaxRequest(`/shipment/${type}`)
            .done(function(response) {
                showNotice('success', __('Montonio: Shipment created/updated successfully.', 'montonio-for-woocommerce'));
            })
            .fail(function(response) {
                const errorMessage = response.responseJSON?.message || __('Montonio: Shipment creation/update failed.', 'montonio-for-woocommerce');
                showNotice('error', errorMessage);
            })
            .always(function() {
                $('.montonio-shipping-panel').removeClass('montonio-shipping-panel--loading');
                shipmentStatus = '';
                startStatusPolling();
            });
    }

    function updateShippingPanelContent() {
        if (!validateShipmentData()) {
            clearStatusInterval();
            return;
        }

        makeAjaxRequest('/shipment/update-panel', { status: shipmentStatus })
            .done(function(response) {
                if (!response || typeof response.status === 'undefined') {
                    console.warn('Montonio: Invalid response format');
                    return;
                }
                
                // Only update if status changed
                if (shipmentStatus !== response.status) {
                    shipmentStatus = response.status;
                    
                    if (response.panel) {
                        panelWrapper.html(response.panel);
                    }

                    showNotice('success', __('Montonio: Shipment info updated.', 'montonio-for-woocommerce'));
                }

                // Stop polling if no longer pending
                if (shipmentStatus !== 'pending') {
                    clearStatusInterval();
                }
            })
            .fail(function(xhr, status, error) {
                console.error('Montonio: Failed to update panel content:', error);
                clearStatusInterval();
            });
    }

    function syncShipment(type) {
        if (!validateShipmentData()) {
            return;
        }
        
        $('.montonio-shipping-panel').addClass('montonio-shipping-panel--loading');

        makeAjaxRequest('/shipment/sync', {}, 'GET')
            .done(function(response) {
                showNotice('success', __('Montonio: Shipment details synced successfully.', 'montonio-for-woocommerce'));
            })
            .fail(function(response) {
                const errorMessage = response.responseJSON?.message || __('Montonio: Failed to sync shipment details.', 'montonio-for-woocommerce');
                showNotice('error', errorMessage);
            })
            .always(function() {
                $('.montonio-shipping-panel').removeClass('montonio-shipping-panel--loading');
                shipmentStatus = '';
                updateShippingPanelContent();
            });
    }
    
    // Cleanup on page unload
    $(window).on('beforeunload', function() {
        clearStatusInterval();
    });
})(jQuery);