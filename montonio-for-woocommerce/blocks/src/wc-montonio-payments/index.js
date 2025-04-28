import { __ } from '@wordpress/i18n';
import { useEffect, RawHTML } from '@wordpress/element';
import { useSelect } from '@wordpress/data';

const { registerPaymentMethod } = wc.wcBlocksRegistry;
const { getSetting } = wc.wcSettings;
const { decodeEntities } = wp.htmlEntities;
const { applyFilters, addFilter } = wp.hooks;

const canMakePayment = () => {
    return applyFilters('wc_montonio_payments_block_enabled', true, settings);
};

const settings = getSetting('wc_montonio_payments_data', {});
const title = applyFilters('wc_montonio_payments_block_title', decodeEntities(settings.title || __('Pay with your bank', 'montonio-for-woocommerce')), settings);

const addSandboxModeMessage = (description, settings) => {
    if (settings.sandboxMode === 'yes') {
        const sandboxMessage = 
            '<strong>' + __('TEST MODE ENABLED!', 'montonio-for-woocommerce') + '</strong><br>' +
            __('When test mode is enabled, payment providers do not process payments.', 'montonio-for-woocommerce') + '<br>';
        
        description = sandboxMessage + description;
    }
    return description;
};
addFilter('wc_montonio_payments_block_description', 'montonio-for-woocommerce', addSandboxModeMessage);

const description = applyFilters('wc_montonio_payments_block_description', decodeEntities(settings.description), settings);

const renderHTML = (html) => {
    return <RawHTML>{html}</RawHTML>;
};

const MontonioCheckout = ({ defaultRegion }) => {
    useEffect(() => {
        if (typeof Montonio !== 'undefined' && Montonio.Checkout && Montonio.Checkout.PaymentInitiation) {
            window.onMontonioLoaded = function() {
                var checkout = Montonio.Checkout.PaymentInitiation.create({
                    accessKey: settings.accessKey,
                    storeSetupData: settings.storeSetupData,
                    currency: settings.currency,
                    targetId: 'montonio-pis-widget-container',
                    defaultRegion: defaultRegion,
					regions: settings.regions,
                    regionNames: settings.regionNames,
                    inputName: 'montonio_payments_preselected_bank',
                    regionInputName: 'montonio_payments_preferred_country',
                    displayAsList: settings.handleStyle === 'list'
                });
                checkout.init();
            };
            
            window.onMontonioLoaded();
        }
    }, []);

    useEffect(() => {
        if (settings.preselectCountry === 'billing' && settings.availableCountries.includes(defaultRegion)) {
            const selectElement = document.querySelector('.montonio-bank-select-class');
            if (selectElement) {
                selectElement.value = defaultRegion;
                selectElement.dispatchEvent(new Event('change'));
            }
        }
    }, [defaultRegion]);

    return <div id="montonio-pis-widget-container"></div>;
};

const Content = ({ eventRegistration }) => {
    const { onPaymentSetup } = eventRegistration;

    const defaultRegion = useSelect((select) => {
        if (settings.preselectCountry === 'billing') {
            const cart = select('wc/store/cart');
            return cart.getCartData().billingAddress.country || settings.defaultRegion;
        }
        return settings.defaultRegion;
    });

    useEffect(() => {
        const unsubscribePaymentSetup = onPaymentSetup(() => {
            const preferredBank = document.querySelector('input[name="montonio_payments_preselected_bank"]')?.value || '';
            const preferredCountry = document.querySelector('input[name="montonio_payments_preferred_country"]')?.value || defaultRegion;

            return {
                type: 'success',
                meta: {
                    paymentMethodData: {
                        montonio_payments_preselected_bank: preferredBank,
                        montonio_payments_preferred_country: preferredCountry,
                    },
                },
            };
        });

        return () => unsubscribePaymentSetup();
    }, [onPaymentSetup, defaultRegion]);

    return (
		<>
			{description && <p className="montonio-payment-block-description">{renderHTML(description)}</p>}
            {settings.handleStyle !== 'hidden' && <MontonioCheckout defaultRegion={defaultRegion} />}
		</>
	);
};

const Icon = () => {
    return <span><img src={settings.iconurl} /></span>;
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
const MontonioPaymentsBlockOptions = {
    name: 'wc_montonio_payments',
    label: <Label />,
    content: <Content />,
    edit: <Content />,
    canMakePayment: canMakePayment,
    ariaLabel: title
};

registerPaymentMethod(MontonioPaymentsBlockOptions);