(function($){
    'use strict';

    // Add pickup point dropdown in order view
    $(document).on('click', 'a.edit-order-item', function() {
        $('.shipping_method').trigger('change');
    });

    $(document).on('change', '.shipping_method', function() {
        let shippingMethodId = $(this).find(':selected').val(),
            data = {
                'action': 'get_country_select',
                'shipping_method_id': shippingMethodId,
                'nonce': montonio_shipping_pickup_points_admin_params.nonce
            };

        $('.montonio_carrier_country').remove();
        $('.montonio_carrier_pickup_point').selectWoo('destroy');    
        $('.montonio_carrier_pickup_point').remove();        
        
        if (shippingMethodId.includes('montonio_')) {
            $.post(woocommerce_admin_meta_boxes.ajax_url, data, function(response) {     
                if (response.success === true) {
                    $('.shipping_method').after(response.data);
                } else {
                    $('.shipping_method').after('<div class="montonio_carrier_country">Sorry, we couldn\'t load pickup point list. Resave <a href="admin.php?page=wc-settings&tab=montonio_shipping" target="_blank">Montonio shipping settings</a> to resync pickup point list.</a></div>');
                }
            });
        }
    });

    $(document).on('change', '.montonio_carrier_country', function() {
        let country = $(this).find(':selected').val(),
            carrier = $(this).data('carrier'),
            type = $(this).data('type'),
            data = {
                'action': 'get_pickup_point_select',
                'country': country,
                'carrier': carrier,
                'type': type,
                'nonce': montonio_shipping_pickup_points_admin_params.nonce
            };
        
        $('.montonio_carrier_pickup_point').selectWoo('destroy');    
        $('.montonio_carrier_pickup_point').remove();    
        
        if (type != 'courier') {
            $.post(woocommerce_admin_meta_boxes.ajax_url, data, function(response) {     
                if (response.success === true) {
                    $('.montonio_carrier_country').after(response.data);
                    $('.montonio_carrier_pickup_point').selectWoo({
                        width: '100%',
                    });
                } else {
                    $('.montonio_carrier_country').after('<div class="montonio_carrier_country">Sorry, we couldn\'t load pickup point list. Resave <a href="admin.php?page=wc-settings&tab=montonio_shipping" target="_blank">Montonio shipping settings</a> to resync pickup point list.</a></div>');
                }
            });
        }
    });

    $(document).on('items_saved', function() {
        let shippingMethodId = $('.shipping_method').find(':selected').val(),
            pickupPointId = $('.montonio_carrier_pickup_point').find(':selected').val(),
            country = $('.montonio_carrier_country').find(':selected').val(),
            type = $('.montonio_carrier_country').data('type'),
            carrier = $('.montonio_carrier_country').data('carrier'),
            data = {
                'action': 'process_selected_pickup_point',
                'order_id': woocommerce_admin_meta_boxes.post_id,
                'pickup_point_id': pickupPointId,
                'country': country,
                'carrier': carrier,
                'type': type,
                'nonce': montonio_shipping_pickup_points_admin_params.nonce
            };

        if (shippingMethodId.includes('montonio_')) {
            $.post(woocommerce_admin_meta_boxes.ajax_url, data, function(response) {   
                if (response.success === true) {
                    if (type != 'courier') {
                        $('#_shipping_address_1').val(response.data.item_name);
                        $('#_shipping_city').val(response.data.locality);
                        $('#_shipping_country').val(response.data.country_code).selectWoo();
                        $('#_shipping_address_2, #_shipping_postcode, #_shipping_state').val('');

                        // Update address preview
                        $('.order_data_column:has(.montonio-shipping-panel-wrapper)')
                        .find('.address p:first-child')
                        .html(function(index, oldHtml) {
                            var parts = oldHtml.split('<br>', 1);
                            var name = parts[0];
                            return name + '<br>' + response.data.item_name + '<br>' + $('#_shipping_country option:selected').text();
                        });

                    }
                } else {
                    if (wp && wp.data && wp.data.dispatch) {
                        wp.data.dispatch('core/notices').createNotice(
                            'error',
                            'Sorry, we couldn\'t save the selected shipping method data.',
                        );
                    } else {
                        alert('Sorry, we couldn\'t save the selected shipping method data.');
                    }
                }
            });
        }
    });

})(jQuery);