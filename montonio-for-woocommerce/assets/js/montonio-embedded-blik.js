jQuery(document).ready(function ($) {
    'use strict';

    var form = $('form.checkout');
    window.embeddedPayment = null;
    var paymentIntentUuid = null;

    $(document).on('updated_checkout', function () {
        if ($('input[value="wc_montonio_blik"]').is(':checked')) {
            setTimeout(function () {
                createEmbeddedBlikForm();
            }, 200);
        }
    });

    $(document).on('change', 'input[value="wc_montonio_blik"]', function () {
        createEmbeddedBlikForm();
    });

    window.addEventListener('hashchange', onHashChange);

    function createEmbeddedBlikForm() {
        var targetElement = $('#montonio-blik-form');
        if (targetElement.hasClass('payment-form-initialized')) {
            return false;
        }

        targetElement.addClass('loading').block({
            message: null,
            overlayCSS: {
                background: 'transparent',
                opacity: 0.6,
            },
        });

        if (typeof Montonio === 'undefined' || typeof Montonio.Checkout === 'undefined') {
            console.error('Montonio SDK not loaded');
            return;
        }

        window.embeddedPayment = new Montonio.Checkout.Blik({
            locale: wc_montonio_embedded_blik.locale,
            environment: wc_montonio_embedded_blik.sandbox_mode === 'yes' ? 'sandbox' : 'production',
            targetElement: targetElement.get(0),
        });

        window.embeddedPayment.render();
        targetElement.addClass('payment-form-initialized').removeClass('loading').unblock();
    }

    form.on('checkout_place_order', function (e) {
        // Check if the Montonio Blik payment method is selected and the form is not empty
        if ($('input[value="wc_montonio_blik"]').is(':checked') && !$('#montonio-blik-form').is(':empty')) {

            window.embeddedPayment.showModal();

            // Return the validation promise
            return window.embeddedPayment
                .validate()
                .then(function (isValid) {
                    if (!isValid) {
                        // If validation fails, prevent form submission
                        e.preventDefault();
                        return false; // Prevent submission
                    }

                    return true; // Allow submission
                })
                .catch(function (error) {
                    // Handle validation errors (e.g., timeout)
                    e.preventDefault();
                    return false; // Prevent submission
                });
        }

        return true;
    });

    $(document).ajaxComplete(function (event, xhr, settings) {
        if ($('input[value="wc_montonio_blik"]').is(':checked')) {           
            var response = xhr.responseJSON;
            if (response.result !== 'success') {
                window.embeddedPayment.closeModal();
                window.embeddedPayment.reset();
            }
        }
    });

    function onHashChange() {
        if ($('input[value="wc_montonio_blik"]').is(':checked')) {
            var hash = window.location.hash.match(/^#confirm-pi-([0-9a-f-]+)$/i);

            if ( ! hash ) {
                return;
            }

            paymentIntentUuid = hash[1];

            window.location.hash = 'processing';

            waitForPaymentCompletion();
        }
    }


    async function waitForPaymentCompletion() {
        try {
            const response = await window.embeddedPayment.waitForPayment(paymentIntentUuid);
            window.location.replace(response.merchantReturnUrl);
        } catch (error) {
            console.log('waitForPaymentCompletion error', error);
            window.location.replace(encodeURI(wc_montonio_embedded_blik.return_url + '&error-message=' + error.message));
        }
    }
});