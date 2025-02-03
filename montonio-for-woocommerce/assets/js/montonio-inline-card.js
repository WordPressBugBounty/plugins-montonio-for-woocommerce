jQuery(document).ready(function($) {
	'use strict'; 

    let form = $('form.checkout'),
        params = wc_montonio_inline_cc,
        stripePublicKey = null,
        stripeClientSecret = null,
        uuid = null,
        embeddedPayment = '',
        isCompleted = false;


    $(document).on('updated_checkout', function(){        
        if ($('input[value="wc_montonio_card"]').is(':checked')) {
            setTimeout(function() { 
                initializeOrder();
            }, 200);
        }
    });

    $(document).on( 'change', 'input[value="wc_montonio_card"]', function() {
        initializeOrder();
    });

    function initializeOrder() {
        if ($('#montonio-card-form').hasClass('paymentInitilized')) {
            return false;
        }

        $('#montonio-card-form').addClass('loading').block({
            message: null,
            overlayCSS: {
                background: 'transparent',
                opacity: 0.6
            }
        });

        let data = {
            'action': 'get_payment_intent',
            'method': 'cardPayments',
            'sandbox_mode': params.sandbox_mode
        };

        $.post(woocommerce_params.ajax_url, data, function(response) {       
            if (response.success === true) {
                stripePublicKey = response.data.stripePublicKey;
                stripeClientSecret = response.data.stripeClientSecret;
                uuid = response.data.uuid;

                initializePayment();
            } else {
                $('#montonio-card-form').removeClass('loading').unblock();
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
            targetId: 'montonio-card-form',
        });

        $('input[name="montonio_card_payment_intent_uuid"]').val(uuid);
        $('#montonio-card-form').addClass('paymentInitilized').removeClass('loading').unblock();

        embeddedPayment.on('change', event => { 
            isCompleted = event.isCompleted;
        });
    }

    form.on('checkout_place_order', function() {
        if ($('input[value="wc_montonio_card"]').is(':checked') && !$('#montonio-card-form').is(':empty')) {
            $('body').addClass('wc-montonio-cc-processing');

            if(isCompleted == false) {
                $('body').removeClass('wc-montonio-cc-processing');
                $.scroll_to_notices( $('#payment_method_wc_montonio_card') );
                return false;
            }
        }
    });

    $(document).ajaxComplete( function() {
        if ($('input[value="wc_montonio_card"]').is(':checked') && $('body').hasClass('wc-montonio-cc-processing') && !$('#montonio-card-form').is(':empty')) {
            window.stop();
        }
    });

    form.on('checkout_place_order_success', function() {       
        if ($('input[value="wc_montonio_card"]').is(':checked') && !$('#montonio-card-form').is(':empty')) {
            confirmPayment();
        }
    });

    async function confirmPayment() {
        try {
            const result = await embeddedPayment.confirmPayment(params.sandbox_mode === 'yes');

            window.location.replace(result.returnUrl);
        } catch (error) {
            window.location.replace(encodeURI(params.return_url + '&error-message=' + error.message));
        }
    }
});