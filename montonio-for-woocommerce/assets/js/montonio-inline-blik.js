jQuery(document).ready(function($) {
	'use strict'; 

    let form = $('form.checkout'),
        params = wc_montonio_inline_blik,
        stripePublicKey = null,
        stripeClientSecret = null,
        uuid = null,
        embeddedPayment = '',
        isCompleted = false;

    $(document).on('updated_checkout', function(){        
        if ($('input[value="wc_montonio_blik"]').is(':checked')) {
            setTimeout(function() { 
                initializeOrder();
            }, 200);
        }
    });

    $(document).on( 'change', 'input[value="wc_montonio_blik"]', function() {
        initializeOrder();
    });

    window.addEventListener('hashchange', onHashChange);

    function initializeOrder() {
        if ($('#montonio-blik-form').hasClass('paymentInitilized')) {
            return false;
        }

        $('#montonio-blik-form').addClass('loading').block({
            message: null,
            overlayCSS: {
                background: 'transparent',
                opacity: 0.6
            }
        });

        let data = {
            'action': 'get_payment_intent',
            'method': 'blik',
            'sandbox_mode': params.test_mode,
            'nonce': params.nonce,
        };

        $.post(woocommerce_params.ajax_url, data, function(response) {        
            if (response.success === true) {
                stripePublicKey = response.data.stripePublicKey;
                stripeClientSecret = response.data.stripeClientSecret;
                uuid = response.data.uuid;

                initializePayment();
            } else {
                if (response.data && typeof response.data === 'object' && response.data.reload) {
                    // Refresh the page
                    setTimeout(function() {
                        window.location.reload();
                    }, 2000);

                    return;
                }

                $('#montonio-blik-form').removeClass('loading').unblock();
            }
        });
    }

    async function initializePayment() {    
        if (typeof Montonio === 'undefined' || typeof Montonio.Checkout === 'undefined') {
            console.error('Montonio SDK not loaded');
            return;
        }
         
        embeddedPayment = await Montonio.Checkout.EmbeddedPayments.initializePayment({
            stripePublicKey: stripePublicKey,
            stripeClientSecret: stripeClientSecret,
            paymentIntentUuid: uuid, 
            locale: params.locale,
            country: form.find('[name=billing_country]').val(),
            targetId: 'montonio-blik-form',
        });

        $('input[name="montonio_blik_payment_intent_uuid"]').val(uuid);
        $('#montonio-blik-form').addClass('paymentInitilized').removeClass('loading').unblock();

        embeddedPayment.on('change', event => { 
            isCompleted = event.isCompleted;
        });
    }

    form.on('checkout_place_order', function() {
        if ($('input[value="wc_montonio_blik"]').is(':checked') && !$('#montonio-blik-form').is(':empty')) {
            if(isCompleted == false) {
                $.scroll_to_notices( $('#payment_method_wc_montonio_blik') );
                return false;
            }
        }
    });

    function onHashChange() {
        if ($('input[value="wc_montonio_blik"]').is(':checked')) {
            var hash = window.location.hash.match(/^#confirm-pi-([0-9a-f-]+)$/i);

            if ( ! hash ) {
                return;
            }

            window.location.hash = 'processing';

            confirmPayment();
        }
    }

    async function confirmPayment() {
        try {
            const result = await embeddedPayment.confirmPayment(params.test_mode === 'yes');

            window.location.replace(result.returnUrl);
        } catch (error) {
            window.location.replace(encodeURI(params.return_url + '&error-message=' + error.message));
        }
    }
});