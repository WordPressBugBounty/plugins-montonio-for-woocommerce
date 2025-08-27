<?php
defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;

/**
 * Manages WooCommerce blocks for Montonio payment gateway plugin.
 *
 * This class handles the integration of Montonio payment and shipping methods
 * with WooCommerce Blocks, including registration of payment method blocks,
 * shipping blocks, and custom API endpoints.
 *
 * @since 7.1.0
 */
class WC_Montonio_Blocks_Manager {
    const IDENTIFIER = 'montonio-for-woocommerce';

    /**
     * Constructor.
     *
     * @since 7.1.0
     */
    public function __construct() {
        add_action( 'woocommerce_blocks_loaded', array( $this, 'register_blocks' ) );
        add_action( 'woocommerce_blocks_loaded', array( $this, 'register_store_api_endpoint_data' ) );
        add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'update_order_meta_data' ), 10, 2 );
    }

    /**
     * Register blocks and payment methods.
     *
     * @since 7.1.0
     * @return void
     */
    public function register_blocks() {
        if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
            return;
        }

        if ( ! interface_exists( 'Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface' ) ) {
            return;
        }

        $this->include_block_files();
        $this->register_payment_methods();

        if ( get_option( 'montonio_shipping_enabled' ) === 'yes' ) {
            $this->register_shipping_dropdown();
        }
    }

    /**
     * Include necessary block files.
     *
     * @since 7.1.0
     * @return void
     */
    private function include_block_files() {
        $files = array(
            'abstract-montonio-payment-method-block.php',
            'class-wc-montonio-payments-block.php',
            'class-wc-montonio-card-block.php',
            'class-wc-montonio-blik-block.php',
            'class-wc-montonio-bnpl-block.php',
            'class-wc-montonio-hire-purchase-block.php',
            'class-wc-montonio-shipping-dropdown-block.php'
        );

        foreach ( $files as $file ) {
            require_once WC_MONTONIO_PLUGIN_PATH . '/blocks/' . $file;
        }
    }

    /**
     * Register payment method blocks.
     *
     * @since 7.1.0
     * @return void
     */
    private function register_payment_methods() {
        add_action( 'woocommerce_blocks_payment_method_type_registration', function ( $registry ) {
            $methods = array(
                'WC_Montonio_Payments_Block',
                'WC_Montonio_BNPL_Block',
                'WC_Montonio_Card_Block',
                'WC_Montonio_Blik_Block',
                'WC_Montonio_Hire_Purchase_Block'
            );

            foreach ( $methods as $method ) {
                $registry->register( new $method() );
            }
        } );
    }

    /**
     * Register shipping dropdown block.
     *
     * @since 7.1.0
     * @return void
     */
    private function register_shipping_dropdown() {
        add_action( 'woocommerce_blocks_checkout_block_registration', function ( $registry ) {
            $registry->register( new WC_Montonio_Shipping_Checkout_Dropdown_Block() );
        } );
    }

    /**
     * Register custom data for the Store API endpoint.
     *
     * @since 7.1.0
     * @return void
     */
    public function register_store_api_endpoint_data() {
        if ( function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
            woocommerce_store_api_register_endpoint_data(
                array(
                    'endpoint'        => CheckoutSchema::IDENTIFIER,
                    'namespace'       => self::IDENTIFIER,
                    'data_callback'   => array( $this, 'data_callback' ),
                    'schema_callback' => array( $this, 'schema_callback' ),
                    'schema_type'     => ARRAY_A
                )
            );
        }
    }

    /**
     * Callback for custom data.
     *
     * @since 7.1.0
     * @return array
     */
    public function data_callback() {
        return array(
            'selected_pickup_point' => ''
        );
    }

    /**
     * Callback for custom data schema.
     *
     * @since 7.1.0
     * @return array
     */
    public function schema_callback() {
        return array(
            'selected_pickup_point' => array(
                'description' => __( 'Selected Pickup Point', 'montonio-for-woocommerce' ),
                'type'        => array( 'string', 'null' ),
                'readonly'    => true
            )
        );
    }

    /**
     * Update order meta data with selected pickup point.
     *
     * @since 7.1.0
     * @param WC_Order $order The order object.
     * @param array $request The request data.
     * @return void
     */
    public function update_order_meta_data( $order, $request ) {
        $data = isset( $request['extensions'][self::IDENTIFIER] ) ? $request['extensions'][self::IDENTIFIER] : array();

        $handler = WC_Montonio_Shipping::get_instance();
        $handler->update_order_meta( $order, isset( $data['selected_pickup_point'] ) ? $data['selected_pickup_point'] : null );
    }
}
new WC_Montonio_Blocks_Manager();