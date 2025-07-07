jQuery(document).ready(function($) {
    'use strict';

    $(document).on('updated_checkout', function(){
        initPickupPointDropdown();
        customCheckoutCompatibility();
    });

    function waitForDropdownElement(callback, maxAttempts = 3) {
        if ($('.montonio-shipping-pickup-point-dropdown').length && !$('.montonio-shipping-pickup-point-dropdown').hasClass('montonio-shipping-pickup-point-dropdown--initialized')) {
            let shippingMethod = $('.montonio-shipping-pickup-point-dropdown').data('shipping-method');
            callback(shippingMethod);
        } else if (maxAttempts > 0) {
            setTimeout(function() {
                waitForDropdownElement(callback, maxAttempts - 1);
            }, 200);
        } else {
            return;
        }
    }

    function initPickupPointDropdown() {  
        waitForDropdownElement(function(shippingMethod) {
            if (typeof Montonio !== 'undefined' && Montonio.Checkout && Montonio.Checkout.ShippingDropdown) {
                if (window.montonioShippingDropdown) {
                    window.montonioShippingDropdown = null;
                }
        
                window.montonioShippingDropdown = new Montonio.Checkout.ShippingDropdown({
                    shippingMethod: shippingMethod,
                    targetId: 'montonio-shipping-pickup-point-dropdown',
                    shouldInjectCSS: true,
                });
        
                window.montonioShippingDropdown.init();

                $('.montonio-shipping-pickup-point-dropdown').addClass('montonio-shipping-pickup-point-dropdown--initialized');
            }
        });
    }

    function customCheckoutCompatibility() {
        if ($('.montonio-shipping-pickup-point-dropdown').length) {
                $('.montonio_pickup_point_value').val('');
                
                setTimeout(function() {
                    if ($('form[name="checkout"] [name="montonio_pickup_point"]').length == 0) {
                        $('form[name="checkout"]').prepend('<input type="hidden" class="montonio_pickup_point_value" name="montonio_pickup_point" value="">');
                
                        $(document).on('change', '.montonio-shipping-pickup-point-dropdown', function() {
                            $('.montonio_pickup_point_value').val($(this).val());
                        });
                    }
                }, 500);
        } else {
            $('form[name="checkout"] .montonio_pickup_point_value').remove();
        }
    }

    $(document).on('change', '.montonio-shipping-pickup-point-dropdown', function(){
        var selected_pickup_point = $(this).val();

        $('.montonio-shipping-pickup-point-dropdown').not(this).val(selected_pickup_point);
    });

});    