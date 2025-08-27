jQuery(document).ready(function($) {
	'use strict'; 

    // Check if Montonio library is loaded at initialization
    if (typeof window.Montonio === 'undefined') {
        console.error('Montonio library not loaded');
        return;
    }

    const { MontonioCheckout } = window.Montonio;

    let form = $('form.checkout'),
        params = wc_montonio_embedded_card,
        montonioCheckout = null,
        sessionPromise = null; // Promise that resolves when session is ready

    $(document).on('updated_checkout', function(){        
        if ($('input[value="wc_montonio_card"]').is(':checked')) {
            setTimeout(function() { 
                handleCardPaymentSelection();
            }, 200);
        }
    });

    $(document).on( 'change', 'input[value="wc_montonio_card"]', function() {
        handleCardPaymentSelection();
    });

    window.addEventListener('hashchange', onHashChange);

    // Create the Montonio Session in the background already on page load
    sessionPromise = createMontonioSession();

    // Function to create Montonio session via AJAX (returns a Promise)
    function createMontonioSession() {
        return new Promise((resolve, reject) => {
            $.ajax({
                url: woocommerce_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_session_uuid',
                    sandbox_mode: params.test_mode,
                    nonce: params.nonce
                },
                success: function(response) {
                    if (response.success && response.data.uuid) {
                        resolve(response.data);
                    } else {
                        console.error('Error fetching session UUID:', response);
                        reject(new Error('Session creation failed'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', error);
                    reject(new Error('AJAX request failed: ' + error));
                }
            });
        });
    }

    // Handle when card payment method is selected
    async function handleCardPaymentSelection() {
        if ($('#montonio-card-form').hasClass('checkoutInitilized')) {
            return;
        }

        // Show loading state
        $('#montonio-card-form').addClass('loading').block({
            message: null,
            overlayCSS: {
                background: 'transparent',
                opacity: 0.6
            }
        });

        try {
            // Wait for the session to be ready (either already resolved or still pending)
            const sessionData = await sessionPromise;
            
            if (sessionData && sessionData.uuid) {
                // Initialize Montonio checkout with the session UUID
                await initializeMontonioCheckout(sessionData.uuid);
            } else {
                console.error('Session UUID not available');
                $('#montonio-card-form').removeClass('loading').unblock();
            }
        } catch (error) {
            console.error('Error handling card payment selection:', error);
            $('#montonio-card-form').removeClass('loading').unblock();
        }
    }

    // Function to initialize Montonio checkout
    async function initializeMontonioCheckout(uuid) {
        try {
            const checkoutOptions = {
                sessionUuid: uuid,
                environment: params.test_mode === 'yes' ? 'sandbox' : 'production',
                locale: params.locale
            };

            // Assign to the global variable instead of const
            montonioCheckout = new MontonioCheckout(checkoutOptions);
            
            // Initialize the checkout component
            await montonioCheckout.initialize('#montonio-card-form');

            $('input[name="montonio_card_payment_session_uuid"]').val(uuid);
            $('#montonio-card-form').addClass('checkoutInitilized').removeClass('loading').unblock();            
        } catch (error) {
            console.error('Error initializing Montonio checkout:', error);
            $('#montonio-card-form').removeClass('loading').unblock();
        }
    }

    form.on('checkout_place_order_wc_montonio_card', function() {
        if (!montonioCheckout.isValid) {
            validateForm();
            return false;
        }

        return true;
    });
    
    async function onHashChange() {
        if ($('input[value="wc_montonio_card"]').is(':checked')) {
            var hash = window.location.hash.match(/^#confirm-session-([0-9a-f-]+)$/i);

            if (!hash) {
                return;
            }

            window.location.hash = 'processing';

            const isValid = await validateForm();

            if (!isValid) {
                $.scroll_to_notices( $('#payment_method_wc_montonio_card') );
                form.removeClass( 'processing' ).unblock();
                return;
            }

            submitPayment();
        }
    }

    async function validateForm() {
        try {
            await montonioCheckout.validateOrReject();
            return true;
        } catch (error) {
            console.error(error);
            return false;
        }
    }

    async function submitPayment() {
        try {
            const result = await montonioCheckout.submitPayment();
            window.location.href = result.returnUrl;
        } catch (error) {
            console.error(error);

            if (error.displayedInPaymentComponent === true) {
                $.scroll_to_notices( $('#payment_method_wc_montonio_card') );
                form.removeClass( 'processing' ).unblock();
            } else {
                window.location.href = encodeURI(params.return_url + '&error-message=' + error.message);
            }
        }
    }
});