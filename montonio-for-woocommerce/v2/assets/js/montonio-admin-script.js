(function($) {
	'use strict'; 

    // Reset default email tracking code text
    $(document).on('click', '.montonio-reset-email-tracking-code-text', function(e) {
        e.preventDefault();

        $('#montonio_email_tracking_code_text').val('Track your shipment:');
    });

    // Conditionaliy toggle shipping v1 & v2 field visibility
    function toggleShippingfields() {
        var selectedVal = $('#montonio_shipping_enable_v2').val();
        if (selectedVal === 'yes') {
            $('.montonio_shipping_v1_field').removeAttr('required').closest('tr').hide();
            $('.montonio_shipping_v2_field').closest('tr').show();
        } else {
            $('.montonio_shipping_v2_field').closest('tr').hide();
            $('.montonio_shipping_v1_field').closest('tr').show();
            $('.montonio_shipping_v1_field--required').prop('required', true);
        }
    }

    toggleShippingfields();

    $(document).on('change', '#montonio_shipping_enable_v2', function() {
        toggleShippingfields();
    });


    // Conditionaliy toggle order prefix id field visibility
    function togglePrefixfield() {
        var selectedVal = $('#woocommerce_wc_montonio_api_merchant_reference_type').val();
        if (selectedVal === 'add_prefix') {
            $('#woocommerce_wc_montonio_api_order_prefix').closest('tr').show();
        } else {
            $('#woocommerce_wc_montonio_api_order_prefix').closest('tr').hide();
        }
    }

    togglePrefixfield();

    $(document).on('change', '#woocommerce_wc_montonio_api_merchant_reference_type', function() {
        togglePrefixfield();
    });


    // Add loader when settings are saved
    var currentUrl = window.location.href;
    if (currentUrl.indexOf('tab=montonio_shipping') !== -1) {
        $('button.woocommerce-save-button').on('click', function() {
            $(this).css('pointer-events', 'none');
            
            if ($('#montonio_shipping_enabled').is(':checked')) {
                $(this).after('<div class="montonio-options-loader">Syncing pickup points, please wait!</div>');
            }
        });
      
    }
   

})(jQuery);