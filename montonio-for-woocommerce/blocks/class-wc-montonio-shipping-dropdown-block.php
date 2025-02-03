<?php

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

defined( 'ABSPATH' ) || exit;

class WC_Montonio_Shipping_Checkout_Dropdown_Block implements IntegrationInterface {

    /**
	 * The name of the integration.
	 *
	 * @return string
	 */
    public function get_name() {
        return 'wc-montonio-shipping-dropdown';
    }

    /**
	 * When called invokes any initialization/setup for the integration.
	 */
    public function initialize() {
        $this->register_block_frontend_scripts();
		$this->register_block_editor_scripts();
    }

    /**
	 * Register scripts for delivery date block editor.
	 *
	 * @return void
	 */
	public function register_block_editor_scripts() {
		$script_url        = WC_MONTONIO_PLUGIN_URL . '/blocks/build/wc-montonio-shipping-dropdown/index.js';
		$script_asset_path = WC_MONTONIO_PLUGIN_PATH . '/blocks/build/wc-montonio-shipping-dropdown/index.asset.php';
		$script_asset      = file_exists( $script_asset_path )
			? require $script_asset_path
			: array(
				'dependencies' => array(),
				'version'      => WC_MONTONIO_PLUGIN_VERSION,
			);

		wp_register_script(
			'wc-montonio-shipping-dropdown-backend',
			$script_url,
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);
	}

	/**
	 * Register scripts for frontend block.
	 *
	 * @return void
	 */
	public function register_block_frontend_scripts() {
		$script_url        = WC_MONTONIO_PLUGIN_URL . '/blocks/build/wc-montonio-shipping-dropdown/view.js';
		$script_asset_path = WC_MONTONIO_PLUGIN_PATH . '/blocks/build/wc-montonio-shipping-dropdown/view.asset.php';

		$script_asset = file_exists( $script_asset_path )
			? require $script_asset_path
			: array(
				'dependencies' => array(),
				'version'      => WC_MONTONIO_PLUGIN_VERSION,
			);
		
		$script_asset['dependencies'][] = 'montonio-sdk';

		wp_register_script( 'montonio-sdk', 'https://public.montonio.com/assets/montonio-js/2.x/montonio.bundle.js', array(), WC_MONTONIO_PLUGIN_VERSION, true );

		wp_register_script(
			'wc-montonio-shipping-dropdown-block',
			$script_url,
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);

		wp_set_script_translations(
			'wc-montonio-shipping-dropdown-block',
			'montonio-for-woocommerce',
			WC_MONTONIO_PLUGIN_PATH . '/languages'
		);
	}

    /**
	 * Returns an array of script handles to enqueue in the frontend context.
	 *
	 * @return array
	 */
	public function get_script_handles() {
		return [ 'wc-montonio-shipping-dropdown-block' ];
	}

    /**
	 * Returns an array of script handles to enqueue in the editor context.
	 *
	 * @return array
	 */
    public function get_editor_script_handles() {
        return [ 'wc-montonio-shipping-dropdown-backend' ];
    }

    /**
	 * An array of key, value pairs of data made available to the block on the client side.
	 *
	 * @return array
	 */
    public function get_script_data() {
        if ( is_admin() ) {
            return [];
        }

        $api_keys = WC_Montonio_Helper::get_api_keys();

        return [
			'accessKey'                 => $api_keys['access_key'],
			'includeAddress'            => get_option( 'montonio_shipping_show_address' ),
			'getShippingMethodItemsUrl' => esc_url_raw( rest_url( 'montonio/shipping/v2/get-shipping-method-items' ) ),
			'nonce'                     => wp_create_nonce( 'wp_rest' )
        ];
    }
}