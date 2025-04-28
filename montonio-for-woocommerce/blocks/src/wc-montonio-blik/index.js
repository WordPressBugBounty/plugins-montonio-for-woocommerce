import { useEffect, useState, RawHTML } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

const { registerPaymentMethod } = wc.wcBlocksRegistry;
const { getSetting } = wc.wcSettings;
const { decodeEntities } = wp.htmlEntities;
const { applyFilters, addFilter  } = wp.hooks;

const canMakePayment = () => {
	return applyFilters('wc_montonio_blik_block_enabled', true, settings);
};

const settings = getSetting('wc_montonio_blik_data', {});
const title = applyFilters('wc_montonio_blik_block_title', decodeEntities(settings.title || __( 'BLIK', 'montonio-for-woocommerce' )), settings );

const addSandboxModeMessage = (description, settings) => {
    if (settings.sandboxMode === 'yes') {
        const sandboxMessage = 
            '<strong>' + __('TEST MODE ENABLED!', 'montonio-for-woocommerce') + '</strong><br>' +
            __('When test mode is enabled, payment providers do not process payments.', 'montonio-for-woocommerce') + '<br>';
        
        description = sandboxMessage + description;
    }
    return description;
};
addFilter('wc_montonio_blik_block_description', 'montonio-for-woocommerce', addSandboxModeMessage);

const description = applyFilters('wc_montonio_blik_block_description', decodeEntities(settings.description), settings );

const renderHTML = (html) => {
    return <RawHTML>{html}</RawHTML>;
};

const MontonioBlikCheckout = ({ setEmbeddedPayment }) => {
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState(null);


    const initializePayment = async () => {
        setIsLoading(true);
        setError(null);
      
        try {
            // Check if Montonio SDK is loaded
            if (typeof Montonio === 'undefined' || typeof Montonio.Checkout === 'undefined') {
                throw new Error('Montonio SDK is not loaded');
            }

            const targetElement = document.getElementById('montonio-blik-form');
            
            if (!targetElement) {
                throw new Error('Target element not found');
            }

            // Create and initialize the embedded payment form
            const embeddedPayment = new Montonio.Checkout.Blik({
                locale: settings.locale || 'en',
                environment: settings.sandboxMode === 'yes' ? 'sandbox' : 'production',
                targetElement: targetElement,
            });

            // Render the form
            await embeddedPayment.render();

            // Update parent state
            setEmbeddedPayment(embeddedPayment);
        } catch (error) {
            console.error('Error in initializePayment:', error);
            setError(error.message || 'Failed to initialize payment form');
        } finally {
            setIsLoading(false);
        }
    };


    // Effect to handle initialization
    useEffect(() => {
        const targetElement = document.getElementById('montonio-blik-form');
        // Only initialize if not already initialized and form element exists and is empty
        if (targetElement && !targetElement.hasChildNodes()) {
            initializePayment();
        }
    }, []);

    return (
        <div 
            id="montonio-blik-form-wrapper" 
            className={isLoading ? 'loading' : ''}
            style={isLoading ? { opacity: 0.6 } : {}}
        >
            {isLoading && <div className="montonio-loader">{__('Loading...', 'montonio-for-woocommerce')}</div>}
            {error && <div className="montonio-error">{error}</div>}
            <div id="montonio-blik-form"></div>
        </div>
    );
};

const Content = ({ eventRegistration }) => {
    if (settings.inlineCheckout !== 'yes') {
        return description ? <p className="montonio-payment-block-description">{renderHTML(description)}</p> : null;
    }

    const { onPaymentSetup, onCheckoutSuccess, onCheckoutFail } = eventRegistration;
    const [embeddedPayment, setEmbeddedPayment] = useState(null);
    const { createErrorNotice, removeNotice } = useDispatch('core/notices');

    useEffect(() => {
        const unsubscribePaymentSetup = onPaymentSetup(async () => {
            removeNotice('wc-montonio-blik-error', 'wc/checkout');

            // Show modal before validation
            if (embeddedPayment.showModal) {
                embeddedPayment.showModal();
            }

            // Perform validation
            try {
                const isValid = await embeddedPayment.validate();

                if (!isValid) {
                    embeddedPayment?.closeModal?.();
                    
                    return {
                        type: 'error',
                        message: __('Please enter a valid 6-digit BLIK code.', 'montonio-for-woocommerce'),
                    };
                }

                return {
                    type: 'success',
                    meta: {
                        paymentMethodData: {
                            montonio_blik_code: document.getElementById('montonio_blik_code')?.value || '',
                        },
                    },
                };

            } catch (error) {
                embeddedPayment?.closeModal?.();
                embeddedPayment?.reset?.();
                
                const errorMessage = error.message || __('An error occurred during payment processing. Please try again.', 'montonio-for-woocommerce');

                return {
                    type: 'error',
                    message: errorMessage,
                };
            }
        });

        const unsubscribeCheckoutSuccess = onCheckoutSuccess(async (checkoutSuccessData) => {
            try {
                const { processingResponse } = checkoutSuccessData;
                const paymentIntentUuid = processingResponse.paymentDetails?.payment_intent_uuid;
        
                if (processingResponse.paymentStatus === 'success' && paymentIntentUuid) {
                    // Wait for payment completion
                    const paymentResult = await embeddedPayment.waitForPayment(paymentIntentUuid);
                    
                    if (paymentResult?.merchantReturnUrl) {
                        return {
                            type: 'success',
                            redirectUrl: paymentResult.merchantReturnUrl
                        };
                    }
                } else {
                    throw new Error(__('Payment intent not found in the response.', 'montonio-for-woocommerce'));
                }
            } catch (error) {
                embeddedPayment?.closeModal?.();
                embeddedPayment?.reset?.();
        
                const errorMessage = error.message || __('An error occurred during payment processing. Please try again.', 'montonio-for-woocommerce');
                
                createErrorNotice(errorMessage, {
                    context: 'wc/checkout',
                });
        
                return {
                    type: 'error',
                    message: errorMessage,
                    retry: true
                };
            }
        });

        const unsubscribeCheckoutFail = onCheckoutFail((checkoutFailData) => {
            embeddedPayment?.closeModal?.();
            embeddedPayment?.reset?.();

            const { processingResponse } = checkoutFailData;
            const errorMessage = processingResponse.paymentDetails?.message;

            return {
                type: 'error',
                message: errorMessage,
                retry: true,
                messageContext: 'wc/checkout' 
            };
        });

        return () => {
            unsubscribePaymentSetup();
            unsubscribeCheckoutSuccess();
            unsubscribeCheckoutFail();
        };
    }, [onPaymentSetup, onCheckoutSuccess, onCheckoutFail, embeddedPayment, createErrorNotice, removeNotice]);

    return (
        <>
            {description && <p className="montonio-payment-block-description">{renderHTML(description)}</p>}
            <MontonioBlikCheckout 
                setEmbeddedPayment={setEmbeddedPayment}
            />
        </>
    );
};

const Icon = () => {
    return <span><img src={settings.iconurl} alt="Montonio BLIK" /></span>;
};

const Label = () => {
    return (
        <span>
            {title}
            <Icon />
        </span>
    );
};

/**
 * Montonio Payments method config.
 */
const MontonioBlikBlockOptions = {
	name: 'wc_montonio_blik',
	label: <Label />,
	content: <Content />,
	edit: <Content />,
	canMakePayment: canMakePayment,
	ariaLabel: title
};
registerPaymentMethod(MontonioBlikBlockOptions);