(function($) {
	'use strict'; 

    // Reset default email tracking code text
    $(document).on('click', '.montonio-reset-email-tracking-code-text', function(e) {
        e.preventDefault();

        $('#montonio_email_tracking_code_text').val('Track your shipment:');
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

    // Move the p.submit element inside .montonio-options__content
    if ($('.montonio-options').next('p.submit').length > 0) {
        $('.montonio-options').next('p.submit')
        .appendTo('.montonio-options .montonio-options__container .montonio-options__content')
        .find('button.woocommerce-save-button')
        .removeClass('components-button is-primary')
        .addClass('montonio-button');
    }

    // Hide the Montonio banner when the dismiss button or close button is clicked
	$('.montonio-banner__close').on( 'click', function(e) {
		e.preventDefault();

        var bannerId = $(this).closest('.montonio-banner').attr('id');
        
        $(this).closest('.montonio-banner').addClass('hidden');

        updateBannerVisibility(bannerId);
	});

    function updateBannerVisibility(bannerId) {
        $.post(
			ajaxurl,
			{
				action: 'update_montonio_banner_visibility',
				id: bannerId,
				_ajax_nonce: $( '#' + bannerId + ' #_wpnonce').val()
			},
			function() {
                console.log('Banner visibility updated');
			}
		);
    }


})(jQuery);