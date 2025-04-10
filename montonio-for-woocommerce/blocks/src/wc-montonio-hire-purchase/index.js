import { RawHTML } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const { registerPaymentMethod } = wc.wcBlocksRegistry;
const { getSetting } = wc.wcSettings;
const { decodeEntities } = wp.htmlEntities;
const { applyFilters, addFilter } = wp.hooks;

const canMakePayment = () => {
    return applyFilters('wc_montonio_card_block_enabled', true, settings);
};

const settings = getSetting('wc_montonio_hire_purchase_data', {});
const title = applyFilters('wc_montonio_hire_purchase_block_title', decodeEntities(settings.title || __('Financing', 'montonio-for-woocommerce')), settings );

const addSandboxModeMessage = (description, settings) => {
    if (settings.sandboxMode === 'yes') {
        const sandboxMessage = 
            '<strong>' + __('TEST MODE ENABLED!', 'montonio-for-woocommerce') + '</strong><br>' +
            __('When test mode is enabled, payment providers do not process payments.', 'montonio-for-woocommerce') + '<br>';
        
        description = sandboxMessage + description;
    }
    return description;
};
addFilter('wc_montonio_hire_purchase_block_description', 'montonio-for-woocommerce', addSandboxModeMessage);

const description = applyFilters('wc_montonio_hire_purchase_block_description', decodeEntities(settings.description), settings );

const renderHTML = (html) => {
    return <RawHTML>{html}</RawHTML>;
};

const Content = props => {
	return description ? <p className="montonio-payment-block-description">{renderHTML(description)}</p> : null;
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
const MontonioHirePurchaseBlockOptions = {
	name: 'wc_montonio_hire_purchase',
	label: <Label />,
	content: <Content />,
	edit: <Content />,
	canMakePayment: canMakePayment,
	ariaLabel: title
};
registerPaymentMethod(MontonioHirePurchaseBlockOptions);
