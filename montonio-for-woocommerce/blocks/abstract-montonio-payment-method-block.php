<?php
defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

abstract class AbstractMontonioPaymentMethodBlock extends AbstractPaymentMethodType {
    /**
     * Payment method name. Matches gateway ID.
     *
     * @var string
     */
    protected $name;

    /**
     * Script slug for the payment method.
     *
     * @var string
     */
    protected $name_slug;

    /**
     * The payment method settings.
     *
     * @var array
     */
    protected $settings = array();

    /**
     * Constructor.
     *
     * @since 7.1.0
     * @param string $name The name of the payment method.
     */
    public function __construct( $name ) {
        $this->name = $name;

        // Convert the name to kebab-case for the script slug
        $this->name_slug = strtolower( str_replace( '_', '-', $name ) );
    }

    /**
     * Initializes the settings for the plugin.
     *
     * @since 7.1.0
     * @return void
     */
    public function initialize() {
        $this->settings = get_option( 'woocommerce_' . $this->name . '_settings', array() );
    }

    /**
     * Checks if the payment method is active or not.
     *
     * @since 7.1.0
     * @return boolean
     */
    public function is_active() {
        return 'yes' === $this->get_setting( 'enabled' );
    }

    /**
     * Gets the payment method script handles.
     *
     * @since 7.1.0
     * @return array
     */
    public function get_payment_method_script_handles() {
        $script_url        = WC_MONTONIO_PLUGIN_URL . '/blocks/build/' . $this->name_slug . '/index.js';
        $script_asset_path = WC_MONTONIO_PLUGIN_PATH . '/blocks/build/' . $this->name_slug . '/index.asset.php';
        $dependency        = 'montonio-js-legacy';

        if ( 'wc_montonio_card' === $this->name ) {
            $card_config    = WC_Montonio_Helper::get_payment_methods( 'cardPayments' );
            $card_processor = $card_config['processor'] ?? 'stripe';

            if ( 'adyen' !== $card_processor ) {
                $script_url        = WC_MONTONIO_PLUGIN_URL . '/blocks/build/' . $this->name_slug . '/index-legacy.js';
                $script_asset_path = WC_MONTONIO_PLUGIN_PATH . '/blocks/build/' . $this->name_slug . '/index-legacy.asset.php';
            } else {
                $dependency = 'montonio-js';
            }
        }

        $handle       = $this->name_slug . '-block';
        $script_asset = file_exists( $script_asset_path )
            ? require $script_asset_path
            : array(
                'dependencies' => array(),
                'version'      => WC_MONTONIO_PLUGIN_VERSION
            );

        $script_asset['dependencies'][] = $dependency;

        wp_register_script( 'montonio-js-legacy', 'https://public.montonio.com/assets/montonio-js/3.x/montonio.bundle.js', array(), WC_MONTONIO_PLUGIN_VERSION, true );
        wp_register_script( 'montonio-js', 'https://js.montonio.com/1.x.x/montonio.umd.js', array(), WC_MONTONIO_PLUGIN_VERSION, true );

        wp_register_script(
            $handle,
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        wp_set_script_translations(
            $handle,
            'montonio-for-woocommerce',
            WC_MONTONIO_PLUGIN_PATH . '/languages'
        );

        return array( $handle );
    }
}