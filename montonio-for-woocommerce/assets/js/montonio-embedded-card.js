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
        sessionPromise = null, // Promise that resolves when session is ready
        isInitializing = false;

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
        if (isInitializing || $('#montonio-card-form').hasClass('checkoutInitialized')) {
            return;
        }
        
        isInitializing = true;

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
    
            if (!sessionData || !sessionData.uuid) {
                throw new Error('Session UUID not available');
            }
            
            await initializeMontonioCheckout(sessionData.uuid);
        } catch (error) {
            console.error(error);
        } finally {
            isInitializing = false;
            $('#montonio-card-form').removeClass('loading').unblock();
        }
    }

    // Function to initialize Montonio checkout
    async function initializeMontonioCheckout(uuid) {
        const $submitBtn = form.find('button[type="submit"]');
        const $formSections = form.find('#customer_details, .shop_table');
     
        const checkoutOptions = {
            sessionUuid: uuid,
            environment: params.test_mode ? 'sandbox' : 'production',
            locale: params.locale,
            onSuccess: (result) => {
                window.location.href = result.returnUrl;
            },
            onError: (error) => {
                console.error(error);

                    if (error.displayedInPaymentComponent) {
                        $.scroll_to_notices($('#payment_method_wc_montonio_card'));
                        $submitBtn.prop('disabled', false).unblock();
                        $formSections.unblock();
                        form.removeClass('processing').unblock();
                    } else {
                        window.location.href = params.return_url + '&error-message=' + encodeURIComponent(error.message);
                    }
                },
                onActionRequired: () => {
                    form.removeClass('processing').addClass('action-required').unblock();
                    $formSections.block({
                        message: null,
                        overlayCSS: {
                            background: '#fff',
                            opacity: 0.6
                        }
                    });
                    $submitBtn.prop('disabled', true).block({ message: null });
                    $.scroll_to_notices($('#montonio-card-form'));
                }
            };

        // Assign to the global variable instead of const
        montonioCheckout = new MontonioCheckout(checkoutOptions);
        
        // Initialize the checkout component
        await montonioCheckout.initialize('#montonio-card-form');

        $('input[name="montonio_card_payment_session_uuid"]').val(uuid);
        $('#montonio-card-form').addClass('checkoutInitialized');
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
                return;
            }

            montonioCheckout.submitPayment();
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
});