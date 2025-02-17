<?php

defined( 'ABSPATH' ) || exit;

/**
 * Class for Montonio Shipping
 * @since 7.0.0
 */
class WC_Montonio_Shipping extends Montonio_Singleton {
    /**
     * Notices to be displayed in the admin
     *
     * @since 7.0.0
     * @var array
     */
    protected $admin_notices = array();

    /**
     * The constructor for the Montonio Shipping class
     *
     * @since 7.0.1 - Add woocommerce_shipping_zone_method_added action
     * @since 7.0.0
     */
    protected function __construct() {
        require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/class-wc-montonio-shipping-api.php';
        require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/class-wc-montonio-shipping-item-manager.php';
        require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/webhooks/class-wc-montonio-shipping-webhooks.php';
        require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/class-wc-montonio-shipping-helper.php';

        if ( get_option( 'montonio_shipping_enabled' ) === 'yes' ) {
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/class-wc-montonio-shipping-address-helper.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/class-wc-montonio-shipping-product.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/class-wc-montonio-shipping-order.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/class-wc-montonio-shipping-shipment-manager.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/label-printing/class-wc-montonio-shipping-label-printing.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/class-wc-montonio-shipping-rest.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/checkout/class-wc-montonio-shipping-classic-checkout.php';

            // Update order data when order is created
            add_action( 'woocommerce_checkout_create_order', array( $this, 'handle_montonio_shipping_checkout' ), 10, 2 );

            // Replace email placeholder(s) with relevant data
            add_filter( 'woocommerce_email_format_string', array( $this, 'replace_email_placeholders' ), 10, 2 );

            // Periodically sync Shipping Method Items in the background
            add_action( 'wp_loaded', array( $this, 'maybe_sync_shipping_method_items' ) );
        }

        // Perform various actions when options are saved in Montonio Shipping
        add_action( 'woocommerce_update_options_montonio_shipping', array( $this, 'process_shipping_options' ) );

        add_action( 'woocommerce_shipping_zone_method_added', array( $this, 'sync_shipping_methods_ajax' ) );
        add_action( 'wc_montonio_shipping_sync_shipping_method_items', array( $this, 'sync_shipping_methods' ) );

        // Admin notices
        add_action( 'admin_notices', array( $this, 'display_admin_notices' ), 999 );

        add_filter( 'montonio_ota_sync', array( $this, 'sync_shipping_methods_ota' ), 20, 1 );
    }

    /**
     * Handle Montonio shipping checkout
     *
     * @since 7.1.0
     * @param WC_Order $order The order object
     * @param array $data The data that is being passed to the order
     * @return void
     */
    public function handle_montonio_shipping_checkout( $order, $data ) {
        $shipping_method_item_id = isset( $_POST['montonio_pickup_point'] ) ? sanitize_text_field( $_POST['montonio_pickup_point'] ) : null;

        $this->update_order_meta( $order, $shipping_method_item_id );
    }

    /**
     * Update order meta data
     *
     * @since 7.0.0
     * @param WC_Order $order The order object
     * @param array $data The data that is being passed to the order
     * @return void
     */
    public function update_order_meta( $order, $shipping_method_item_id = null ) {
        $shipping_method = WC_Montonio_Shipping_Helper::get_chosen_montonio_shipping_method_for_order( $order );

        if ( empty( $shipping_method ) ) {
            return;
        }

        $shipping_method_instance = WC_Montonio_Shipping_Helper::create_shipping_method_instance( $shipping_method->get_method_id(), $shipping_method->get_instance_id() );
        $carrier                  = $shipping_method_instance->provider_name;
        $method_type              = $shipping_method_instance->type_v2;

        if ( in_array( $method_type, array( 'parcelMachine', 'postOffice', 'parcelShop' ) ) ) {
            $method_type = 'pickupPoint';

            // Check if pickup point is set
            if ( empty( $shipping_method_item_id ) ) {
                return;
            }

            // Get pickup point info
            $shipping_method_item = WC_Montonio_Shipping_Item_Manager::get_shipping_method_item( $shipping_method_item_id );

            // Update order meta data and shipping address
            $order->update_meta_data( '_montonio_pickup_point_name', $shipping_method_item->item_name );

            if ( method_exists( $order, 'set_shipping' ) ) {
                $order->set_shipping(
                    array(
                        'address_1' => $shipping_method_item->item_name,
                        'address_2' => '',
                        'city'      => $shipping_method_item->locality,
                        'state'     => '',
                        'postcode'  => $shipping_method_item->postal_code,
                        'country'   => strtoupper( $shipping_method_item->country_code )
                    )
                );
            } else {
                $order->update_meta_data( '_shipping_address_1', $shipping_method_item->item_name );
                $order->update_meta_data( '_shipping_address_2', '' );
                $order->update_meta_data( '_shipping_city', $shipping_method_item->locality );
                $order->update_meta_data( '_shipping_postcode', $shipping_method_item->postal_code );
            }
        } else {
            $shipping_method_item_id = WC_Montonio_Shipping_Item_Manager::get_courier_id( WC_Montonio_Shipping_Helper::get_customer_shipping_country(), $carrier );
        }

        $order->update_meta_data( '_montonio_pickup_point_uuid', $shipping_method_item_id );
        $order->update_meta_data( '_wc_montonio_shipping_method_type', $method_type );
        $order->save();
    }

    /**
     * Attempt to sync shipping method items if 24 hours have passed since the last sync.
     *
     * @since 7.0.1
     */
    public function maybe_sync_shipping_method_items() {
        try {
            if ( WC_Montonio_Shipping_Helper::is_time_to_sync_shipping_method_items() ) {
                update_option( 'montonio_shipping_sync_timestamp', time(), 'no' );

                $lock_manager = new Montonio_Lock_Manager();
                $lock_exists  = $lock_manager->lock_exists( 'montonio_shipping_method_items_sync' );

                if ( ! $lock_exists ) {
                    $this->sync_shipping_methods_ajax();
                }
            }
        } catch ( Exception $e ) {
            WC_Montonio_Logger::log( 'Shipping method sync failed. Response: ' . $e->getMessage() );
        }
    }

    /**
     * Perform actions when Montonio Shipping settings are saved
     *
     * @since 7.0.0
     * @return void
     */
    public function process_shipping_options() {
        if ( get_option( 'montonio_shipping_enabled' ) === 'yes' ) {
            $api_settings = get_option( 'woocommerce_wc_montonio_api_settings' );

            if ( ! $api_settings ) {
                $this->add_admin_notice( __( 'Please add Montonio API keys!', 'montonio-for-woocommerce' ), 'error' );
                return;
            }

            $this->sync_shipping_methods();
        }
    }

    /**
     * Make an AJAX request to sync shipping methods. This way
     *
     * @return void
     */
    public function sync_shipping_methods_ajax() {
        $url   = esc_url_raw( rest_url( 'montonio/shipping/v2/sync-shipping-method-items' ) );
        $token = WC_Montonio_Helper::create_jwt_token( array(
            'hash' => md5( $url )
        ) );

        wp_remote_post( $url, array(
            'method'   => 'POST',
            'timeout'  => 0.01,
            'blocking' => false,
            'body'     => array(
                'token' => $token
            )
        ) );
    }

    /**
     * Sync shipping methods
     *
     * @since 7.0.0
     * @return void
     */
    public function sync_shipping_methods() {
        update_option( 'montonio_shipping_sync_timestamp', time(), 'no' );

        try {
            $courier_services_synced = false;
            $pickup_point_countries  = array();

            $shipping_api     = new WC_Montonio_Shipping_API();
            $shipping_methods = json_decode( $shipping_api->get_shipping_methods(), true );

            foreach ( $shipping_methods['countries'] as $country ) {
                if ( empty( $country['carriers'] ) ) {
                    continue;
                }

                $country_code = $country['countryCode'];

                foreach ( $country['carriers'] as $carrier ) {
                    foreach ( $carrier['shippingMethods'] as $method ) {
                        if ( 'courier' === $method['type'] ) {
                            if ( false === $courier_services_synced ) {
                                $courier_services_synced = true;
                                WC_Montonio_Shipping_Item_Manager::sync_method_items( 'courierServices' );
                            }

                            continue;
                        }

                        if ( in_array( $country_code, $pickup_point_countries ) ) {
                            continue;
                        }

                        $pickup_point_countries[] = $country_code;
                        WC_Montonio_Shipping_Item_Manager::sync_method_items(
                            'pickupPoints',
                            null,
                            $country_code
                        );
                    }
                }
            }

            $this->add_admin_notice( __( 'Montonio Shipping: Pickup point sync successful!', 'montonio-for-woocommerce' ), 'success' );
        } catch ( Exception $e ) {
            WC_Montonio_Logger::log( 'Shipping method sync failed. Response: ' . $e->getMessage() );
            $this->add_admin_notice( __( 'Montonio API response: ', 'montonio-for-woocommerce' ) . $e->getMessage(), 'error' );
        }
    }

    /**
     * Replace email placeholder with tracking info
     *
     * @since 7.0.0
     * @param string $string The email content string
     * @param WC_Email $email The email object
     * @return string The email content string with replaced placeholders
     */
    public function replace_email_placeholders( $string, $email ) {
        $order         = $email->object;
        $tracking_info = '';

        if ( ! empty( $order ) ) {
            $shipping_method = WC_Montonio_Shipping_Helper::get_chosen_montonio_shipping_method_for_order( $order );

            if ( ! empty( $shipping_method ) ) {
                $tracking_links = $shipping_method->get_meta( 'tracking_codes' );
                $tracking_title = __( get_option( 'montonio_email_tracking_code_text', __( 'Track your shipment:', 'montonio-for-woocommerce' ) ), 'montonio-for-woocommerce' );
                $tracking_info  = $tracking_links ? $tracking_title . ' ' . $tracking_links : '';
            }
        }

        return str_replace( '{montonio_tracking_info}', $tracking_info, $string );
    }

    /**
     * Display admin notices
     *
     * @since 7.0.0
     * @param string $message The message to display
     * @param string $class The type of notice
     * @return void
     */
    public function add_admin_notice( $message, $class ) {
        $this->admin_notices[] = array( 'message' => $message, 'class' => $class );
    }

    public function display_admin_notices() {
        foreach ( $this->admin_notices as $notice ) {
            echo '<div class="notice notice-' . esc_attr( $notice['class'] ) . '">';
            echo '	<p>' . wp_kses_post( $notice['message'] ) . '</p>';
            echo '</div>';
        }
    }

    /**
     * Sync shipping methods when an over-the-air trigger is received
     *
     * @since 7.1.2
     * @param array $status_report The status report of the OTA sync
     * @return void
     */
    public function sync_shipping_methods_ota( $status_report ) {
        try {
            $this->sync_shipping_methods();
            WC_Montonio_Helper::append_to_status_report( $status_report, 'success', 'Shipping method sync successful!' );
        } catch ( Exception $e ) {
            WC_Montonio_Helper::append_to_status_report( $status_report, 'error', 'Shipping method sync failed. Response: ' . $e->getMessage() );
        }

        return $status_report;
    }

}

WC_Montonio_Shipping::get_instance();
