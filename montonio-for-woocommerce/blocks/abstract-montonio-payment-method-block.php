<?php
defined('ABSPATH') || exit;

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
     * Montonio API settings.
     * 
     * @var array
     */
    protected $api_settings = [];

    /**
     * The payment method settings.
     *
     * @var array
     */
    protected $settings = [];

    /**
     * Constructor.
     *
     * @since 7.1.0
     * @param string $name The name of the payment method.
     */
    public function __construct( $name ) {
        $this->name = $name;

        // Convert the name to kebab-case for the script slug
        $this->name_slug = strtolower(str_replace( '_', '-', $name ) );
    }

    /**
     * Initializes the settings for the plugin.
     *
     * @since 7.1.0
     * @return void
     */
    public function initialize() {
        $this->settings = get_option( 'woocommerce_' . $this->name . '_settings', array() );
        $this->api_settings = get_option( 'woocommerce_wc_montonio_api_settings', array() );
    }

    /**
     * Checks if the payment method is active or not.
     *
     * @since 7.1.0
     * @return boolean
     */
    public function is_active() {
        return $this->get_setting( 'enabled' ) === 'yes';
    }

    /**
     * Gets the payment method script handles.
     *
     * @since 7.1.0
     * @return array
     */
    public function get_payment_method_script_handles() {
        $handle            = $this->name_slug . '-block';
        $script_url        = WC_MONTONIO_PLUGIN_URL . '/blocks/build/' . $this->name_slug . '/index.js';
        $script_asset_path = WC_MONTONIO_PLUGIN_PATH . '/blocks/build/' . $this->name_slug . 'index.asset.php';
        $script_asset      = file_exists( $script_asset_path )
            ? require $script_asset_path
            : array(
                'dependencies' => array(),
                'version'      => WC_MONTONIO_PLUGIN_VERSION,
            );

        $script_asset['dependencies'][] = 'montonio-sdk';    

        wp_register_script( 'montonio-sdk', 'https://public.montonio.com/assets/montonio-js/2.x/montonio.bundle.js', array(), WC_MONTONIO_PLUGIN_VERSION, true );

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