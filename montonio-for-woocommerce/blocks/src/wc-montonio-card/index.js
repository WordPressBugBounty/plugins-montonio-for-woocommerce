
import { useEffect, useState, useCallback, RawHTML } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

const { registerPaymentMethod } = wc.wcBlocksRegistry;
const { getSetting } = wc.wcSettings;
const { decodeEntities } = wp.htmlEntities;
const { applyFilters, addFilter } = wp.hooks;

const canMakePayment = () => {
    return applyFilters('wc_montonio_card_block_enabled', true, settings);
};

const settings = getSetting('wc_montonio_card_data', {});
const title = applyFilters('wc_montonio_card_block_title', decodeEntities(settings.title || __('Card Payment', 'montonio-for-woocommerce')), settings );

const addSandboxModeMessage = (description, settings) => {
    if (settings.sandboxMode === 'yes') {
        const sandboxMessage = 
            '<strong>' + __('TEST MODE ENABLED!', 'montonio-for-woocommerce') + '</strong><br>' +
            __('When test mode is enabled, payment providers do not process payments.', 'montonio-for-woocommerce') + '<br>';
        
        description = sandboxMessage + description;
    }
    return description;
};
addFilter('wc_montonio_card_block_description', 'montonio-for-woocommerce', addSandboxModeMessage);

const description = applyFilters('wc_montonio_card_block_description', decodeEntities(settings.description), settings );

const renderHTML = (html) => {
    return <RawHTML>{html}</RawHTML>;
};

const MontonioCardCheckout = ({ setIsFormCompleted, setEmbeddedPayment }) => {

    const [isLoading, setIsLoading] = useState(false);
    const [isInitialized, setIsInitialized] = useState(false);
    const [error, setError] = useState(null);

    const billingCountry = useSelect((select) => {
        const cart = select('wc/store/cart');
        const customerData = cart.getCustomerData();
        return customerData.billingAddress.country || customerData.shippingAddress.country;
    });

    const initializeOrder = useCallback(async () => {
        setIsLoading(true);
        setError(null);

        if (window.cardPaymentsIntentData) {
            await initializePayment();
            setIsInitialized(true);
            setIsLoading(false);
            return;
        }

        const data = new FormData();
        data.append('action', 'get_payment_intent');
        data.append('method', 'cardPayments');
        data.append('sandbox_mode', settings.sandboxMode);
        data.append('nonce', settings.nonce);

        try {
            const response = await fetch(woocommerce_params.ajax_url, {
                method: 'POST',
                credentials: 'same-origin',
                body: data,
            });

            const result = await response.json();

            if (result.success) {
                window.cardPaymentsIntentData = result.data;

                await initializePayment();
                setIsInitialized(true);
            } else {
                throw new Error(result.data || 'Failed to initialize payment');
            }
        } catch (error) {
            console.error('Error initializing payment:', error);
            setError(error.message || 'Failed to initialize payment');
        } finally {
            setIsLoading(false);
        }
    }, []);

    const initializePayment = async () => {
        try {
            if (typeof Montonio === 'undefined' || typeof Montonio.Checkout === 'undefined') {
                throw new Error('Montonio SDK is not loaded');
            }

            const embedded = await Montonio.Checkout.EmbeddedPayments.initializePayment({
                stripePublicKey: window.cardPaymentsIntentData.stripePublicKey,
                stripeClientSecret: window.cardPaymentsIntentData.stripeClientSecret,
                paymentIntentUuid: window.cardPaymentsIntentData.uuid,
                locale: settings.locale || 'en',
                country: billingCountry || 'EE',
                targetId: 'montonio-card-form',
            });

            embedded.on('change', event => {
                setIsFormCompleted(event.isCompleted);
            });

            setEmbeddedPayment(embedded);
        } catch (error) {
            console.error('Error in initializePayment:', error);
        }
    };

    useEffect(() => {
        if (!isInitialized) {
            initializeOrder();
        }
    }, [isInitialized, initializeOrder]);

    return (
        <div 
            id="montonio-card-form-wrapper" 
            className={isLoading ? 'loading' : ''}
            style={isLoading ? { opacity: 0.6 } : {}}
        >
            {isLoading && <div className="montonio-loader">{__('Loading...', 'montonio-for-woocommerce')}</div>}
            {error && <div className="montonio-error">{error}</div>}
            <div id="montonio-card-form"></div>
        </div>
    );
};

const Content = ({ eventRegistration }) => {
    if (settings.inlineCheckout !== 'yes') {
        return description ? <p className="montonio-payment-block-description">{renderHTML(description)}</p> : null;
    }

    const { onPaymentSetup, onCheckoutSuccess } = eventRegistration;
    const [isFormCompleted, setIsFormCompleted] = useState(false);
    const [embeddedPayment, setEmbeddedPayment] = useState(null);
    const { createErrorNotice, removeNotice } = useDispatch('core/notices');
    const embeddedPaymentForm = document.getElementById('montonio-card-form');
    const [shouldReinitialize, setShouldReinitialize] = useState(false);

    useEffect(() => {
        const unsubscribePaymentSetup = onPaymentSetup(() => {
            removeNotice('wc-montonio-card-error', 'wc/checkout');
            
            if (!embeddedPaymentForm || embeddedPaymentForm.innerHTML.trim() === '' || isFormCompleted) {
                return {
                    type: 'success',
                    meta: {
                        paymentMethodData: {
                            montonio_card_payment_intent_uuid: window.cardPaymentsIntentData?.uuid || '',
                        },
                    },
                };
            }

            return {
                type: 'error',
                message: __('Please fill in the required fields for the payment method.', 'montonio-for-woocommerce'),
            };
        });

        const unsubscribeCheckoutSuccess = onCheckoutSuccess(async (checkoutSuccessData) => {
            if (!embeddedPaymentForm || embeddedPaymentForm.innerHTML.trim() === '') {
                return;
            }

            try {
                const result = await embeddedPayment.confirmPayment(settings.sandboxMode === 'yes');
                
                return {
                    type: 'success',
                    redirectUrl: result.returnUrl,
                };
            } catch (error) {
                console.log(error.message);
                const errorMessage = error.message || __('An error occurred during payment processing. Please try again.', 'montonio-for-woocommerce');
                
                createErrorNotice(errorMessage, {
                    context: 'wc/checkout',
                });

                // Clear the cardPaymentsIntentData and trigger reinitialization
                window.cardPaymentsIntentData = null;
                setShouldReinitialize(prev => !prev);

                return {
                    type: 'error',
                    message: errorMessage,
                    retry: true
                };
            }
        });

        return () => {
            unsubscribePaymentSetup();
            unsubscribeCheckoutSuccess();
        };
    }, [onPaymentSetup, onCheckoutSuccess, isFormCompleted, embeddedPayment, createErrorNotice, removeNotice]);

    return (
        <>
            {description && <p className="montonio-payment-block-description">{renderHTML(description)}</p>}
            <MontonioCardCheckout 
                setIsFormCompleted={setIsFormCompleted} 
                setEmbeddedPayment={setEmbeddedPayment}
                key={shouldReinitialize}
            />
        </>
    );
};

const Icon = () => {
    return <span><img src={settings.iconurl} alt="Montonio Card" /></span>;
};

const Label = () => {
    return (
        <span>
            {title}
            <Icon />
        </span>
    );
};

const MontonioCardBlockOptions = {
    name: 'wc_montonio_card',
    label: <Label />,
    content: <Content />,
    edit: <Content />,
    canMakePayment: canMakePayment,
    ariaLabel: title
};

registerPaymentMethod(MontonioCardBlockOptions);