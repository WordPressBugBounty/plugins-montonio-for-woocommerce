import { useEffect, RawHTML } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';

const { registerPaymentMethod } = wc.wcBlocksRegistry;
const { getSetting } = wc.wcSettings;
const { decodeEntities } = wp.htmlEntities;
const { applyFilters, addFilter  } = wp.hooks;

const canMakePayment = () => {
	return applyFilters('wc_montonio_bnpl_block_enabled', true, settings);
};

const settings = getSetting('wc_montonio_bnpl_data', {});
const title = applyFilters('wc_montonio_bnpl_block_title', decodeEntities(settings.title || __('Pay Later', 'montonio-for-woocommerce')), settings );

const addSandboxModeMessage = (description, settings) => {
    if (settings.sandboxMode === 'yes') {
        const sandboxMessage = 
            '<strong>' + __('TEST MODE ENABLED!', 'montonio-for-woocommerce') + '</strong><br>' +
            __('When test mode is enabled, payment providers do not process payments.', 'montonio-for-woocommerce') + '<br>';
        
        description = sandboxMessage + description;
    }
    return description;
};
addFilter('wc_montonio_bnpl_block_description', 'montonio-for-woocommerce', addSandboxModeMessage);

const description = applyFilters('wc_montonio_bnpl_block_description', decodeEntities(settings.description), settings );

const renderHTML = (html) => {
    return <RawHTML>{html}</RawHTML>;
};

const formatPrice = (price, currencyData) => {
    const numericPrice = parseInt(price) / (10 ** currencyData.currency_minor_unit);
    return numericPrice.toFixed(currencyData.currency_minor_unit).replace('.', currencyData.currency_decimal_separator);
};

const MontonioBnplCheckout = () => {
	const cartTotals = useSelect((select) => {
        const store = select('wc/store/cart');
        return store ? store.getCartTotals() : {};
    });

	const payNextMonth = __( 'Pay next month', 'montonio-for-woocommerce' );
	const payInTwoParts = __( 'Pay in two parts', 'montonio-for-woocommerce' );
	const payInThreeParts = __( 'Pay in three parts', 'montonio-for-woocommerce' );
	const addMoreMessage = __( 'Add #amount to the cart to make this option available', 'montonio-for-woocommerce' );
	const overMaxAmountMessage = __( 'Cart total exceeds maximum limit for this option', 'montonio-for-woocommerce' );;
    const grandTotal = formatPrice(cartTotals.total_price, cartTotals);

	useEffect(() => {
		// Check if Montonio is loaded and then call the provided JS
		if (typeof Montonio !== 'undefined' && Montonio.Checkout && Montonio.Checkout.Bnpl) {
			var checkout = new Montonio.Checkout.Bnpl({
				targetId: "montonio-bnpl-widget-container",
				shouldInjectCSS: true,
				hideOptionIfNotAvailable: false,
				bnplOptions: [{
						period: '1',
						title: payNextMonth,
						min: 30,
						max: 800
					},
					{
						period: '2',
						title: payInTwoParts,
						min: 75,
						max: 2500
					},
					{
						period: '3',
						title: payInThreeParts,
						min: 85,
						max: 2500
					}
				],
				addMoreMessage: addMoreMessage,
				grandTotal: grandTotal,
				overMaxAmountMessage: overMaxAmountMessage
			});
			checkout.init();
		}
	}, []);

	return <div id="montonio-bnpl-widget-container"></div>;
};

const Content = ({ eventRegistration }) => {
	const { onPaymentSetup } = eventRegistration;

    useEffect(() => {
        const unsubscribePaymentSetup = onPaymentSetup(() => {
            const bnplPeriod = document.querySelector('input[name="montonio_bnpl_period"]')?.value || 1;

            return {
                type: 'success',
                meta: {
                    paymentMethodData: {
                        montonio_bnpl_period: bnplPeriod,
                    },
                },
            };
        });

        return () => unsubscribePaymentSetup();
    }, [onPaymentSetup]);

	return (
        <>
            {description && <p className="montonio-payment-block-description">{renderHTML(description)}</p>}
            <MontonioBnplCheckout />
        </>
    );
}

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
const MontonioBnplBlockOptions = {
	name: 'wc_montonio_bnpl',
	label: <Label />,
	content: <Content />,
	edit: <Content />,
	canMakePayment: canMakePayment,
	ariaLabel: title
};
registerPaymentMethod(MontonioBnplBlockOptions);