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

        if ( 'yes' === get_option( 'montonio_shipping_enabled' ) ) {
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/class-wc-montonio-shipping-address-helper.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/class-wc-montonio-shipping-product.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/class-wc-montonio-shipping-order.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/class-wc-montonio-shipping-shipment-manager.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/class-wc-montonio-shipping-pickup-points-search.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/label-printing/class-wc-montonio-shipping-label-printing.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/class-wc-montonio-shipping-rest.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/checkout/class-wc-montonio-shipping-classic-checkout.php';

            // Add shipping method items selection scripts
            add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ), 20 );

            // Disable Cash on Delivery for unsupported courier methods
            add_filter( 'woocommerce_available_payment_gateways', array( $this, 'disable_cod_for_shipping_method' ) );

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

        // Admin notices
        add_action( 'admin_notices', array( $this, 'display_admin_notices' ), 999 );

        add_filter( 'montonio_ota_sync', array( $this, 'sync_shipping_methods_ota' ), 20, 1 );
    }

    /**
     * Enqueues the Montonio SDK script.
     *
     * @since 9.1.1 Added pickup point search script
     * @since 7.0.1 - Removed sync of shipping method items in here
     * @since 7.0.0
     * @return null
     */
    public function enqueue_scripts() {
        if ( ! is_checkout() ) {
            return;
        }

        if ( ! WC_Montonio_Helper::is_checkout_block() ) {
            if ( get_option( 'montonio_shipping_dropdown_type' ) === 'select2' ) {
                wp_enqueue_style( 'montonio-pickup-points' );

                if ( ! wp_script_is( 'selectWoo', 'registered' ) ) {
                    wp_register_script( 'selectWoo', WC()->plugin_url() . '/assets/js/selectWoo/selectWoo.full.min.js', array( 'jquery' ), false, true );
                }

                wp_enqueue_script( 'montonio-shipping-pickup-points-legacy' );
            } else {
                wp_enqueue_script( 'montonio-shipping-pickup-points' );
            }
        }

        wp_enqueue_script( 'montonio-shipping-pickup-points-search' );

        wp_localize_script( 'montonio-shipping-pickup-points-search', 'wc_montonio_pickup_points_search', array(
            'ajax_url'     => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'montonio_pickup_nonce' ),
            'show_address' => get_option( 'montonio_shipping_show_address' ),
            'loading_text' => __( 'Searching pickup points...', 'montonio-for-woocommerce' ),
            'error_text'   => __( 'Failed to load pickup points. Please try again.', 'montonio-for-woocommerce' ),
            'no_results'   => __( 'No pickup points found', 'montonio-for-woocommerce' )
        ) );
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
        $shipping_method_item_id = isset( $_POST['montonio_pickup_point'] ) ? sanitize_text_field( wp_unslash( $_POST['montonio_pickup_point'] ) ) : null;

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

        $carrier = $shipping_method->get_meta( 'carrier_code' );
        $type    = $shipping_method->get_meta( 'type_v2' );

        if ( in_array( $type, array( 'parcelMachine', 'postOffice', 'parcelShop' ) ) ) {
            // Handle pickup point methods
            if ( empty( $shipping_method_item_id ) ) {
                return;
            }

            $pickup_point = WC_Montonio_Shipping_Item_Manager::get_shipping_method_item( $shipping_method_item_id );

            if ( empty( $pickup_point ) ) {
                return;
            }

            $order->update_meta_data( '_montonio_pickup_point_name', $pickup_point->item_name ?? '' );

            if ( method_exists( $order, 'set_shipping' ) ) {
                $shipping_data = array(
                    'address_1' => $pickup_point->item_name ?? '',
                    'address_2' => '',
                    'city'      => $pickup_point->locality ?? '',
                    'postcode'  => $pickup_point->postal_code ?? '',
                    'country'   => strtoupper( $pickup_point->country_code ?? '' )
                );

                if ( ! WC_Montonio_Helper::is_checkout_block() ) {
                    $shipping_data['state'] = '';
                }

                $order->set_shipping( $shipping_data );
            } else {
                $order->update_meta_data( '_shipping_address_1', $pickup_point->item_name ?? '' );
                $order->update_meta_data( '_shipping_address_2', '' );
                $order->update_meta_data( '_shipping_city', $pickup_point->locality ?? '' );
                $order->update_meta_data( '_shipping_postcode', $pickup_point->postal_code ?? '' );
            }

            $carrier_assigned_id = $pickup_point->carrier_assigned_id ?? '';
            $type                = 'pickupPoint';
        } else {
            // Handle courier methods
            $shipping_method_item_id = WC_Montonio_Shipping_Item_Manager::get_courier_id( WC_Montonio_Shipping_Helper::get_customer_shipping_country(), $carrier );

            if ( empty( $shipping_method_item_id ) ) {
                return;
            }

            $courier_item = WC_Montonio_Shipping_Item_Manager::get_shipping_method_item( $shipping_method_item_id );

            if ( empty( $courier_item ) ) {
                return;
            }

            $carrier_assigned_id = $courier_item->carrier_assigned_id ?? '';
        }

        $order->update_meta_data( '_montonio_pickup_point_uuid', $shipping_method_item_id );
        $order->update_meta_data( '_wc_montonio_shipping_method_type', $type );
        $order->update_meta_data( '_wc_montonio_carrier_pickup_point_id', $carrier_assigned_id );
        $order->save();
    }

    /**
     * Attempt to sync shipping method items if 24 hours have passed since the last sync.
     *
     * @since 7.0.1
     */
    public function maybe_sync_shipping_method_items() {
        if ( ! WC_Montonio_Shipping_Helper::is_time_to_sync_shipping_method_items() ) {
            return;
        }

        update_option( 'montonio_shipping_sync_timestamp', time(), 'no' );

        $this->sync_shipping_methods_ajax();
    }

    /**
     * Perform actions when Montonio Shipping settings are saved
     *
     * @since 7.0.0
     * @return void
     */
    public function process_shipping_options() {
        if ( 'yes' !== get_option( 'montonio_shipping_enabled' ) ) {
            return;
        }

        if ( ! WC_Montonio_Helper::has_api_keys() ) {
            $this->add_admin_notice(
                sprintf(
                    __( 'Montonio Shipping requires API credentials. Please <a href="%s">add them here</a>.', 'montonio-for-woocommerce' ),
                    admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_montonio_api' )
                ),
                'error'
            );

            return;
        }

        $this->sync_shipping_methods();
    }

    /**
     * Make an AJAX request to sync shipping methods. This way
     *
     * @return void
     */
    public function sync_shipping_methods_ajax() {
        if ( ! WC_Montonio_Helper::has_api_keys() ) {
            return;
        }

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
     * @since 9.1.3 - Use new methods in WC_Montonio_Shipping_Item_Manager
     * @since 9.3.1 - Added concurrency lock
     * @return true|WP_Error True on success, WP_Error on failure or if sync already in progress
     */
    public function sync_shipping_methods() {
        $lock_manager = new Montonio_Lock_Manager();

        if ( ! $lock_manager->acquire_lock( 'montonio_shipping_method_items_sync' ) ) {
            return new WP_Error( 'wc_montonio_shipping_sync_locked', 'Sync already in progress.' );
        }

        update_option( 'montonio_shipping_sync_timestamp', time(), 'no' );

        try {
            WC_Montonio_Shipping_Item_Manager::initialize_temp_table();

            $courier_services_synced = false;
            $pickup_point_carriers   = array();

            $shipping_api     = new WC_Montonio_Shipping_API();
            $shipping_methods = json_decode( $shipping_api->get_shipping_methods(), true );

            foreach ( $shipping_methods['countries'] as $country ) {
                if ( empty( $country['carriers'] ) ) {
                    continue;
                }

                foreach ( $country['carriers'] as $carrier ) {
                    $carrier_code = $carrier['carrierCode'];
                    foreach ( $carrier['shippingMethods'] as $method ) {
                        if ( 'courier' === $method['type'] ) {
                            if ( false === $courier_services_synced ) {
                                $courier_services_synced = true;
                                WC_Montonio_Shipping_Item_Manager::import_shipping_items_to_temp( 'courier' );
                            }

                            continue;
                        }

                        if ( in_array( $carrier_code, $pickup_point_carriers ) ) {
                            continue;
                        }

                        $pickup_point_carriers[] = $carrier_code;
                        WC_Montonio_Shipping_Item_Manager::import_shipping_items_to_temp(
                            'pickupPoints',
                            $carrier_code,
                            null
                        );
                    }
                }
            }

            WC_Montonio_Shipping_Item_Manager::replace_main_table_with_temp();

            $this->add_admin_notice( __( 'Montonio Shipping: Pickup point sync successful!', 'montonio-for-woocommerce' ), 'success' );

            return true;
        } catch ( Exception $e ) {
            WC_Montonio_Shipping_Item_Manager::remove_temp_table();
            WC_Montonio_Logger::log( 'Shipping method sync failed. Response: ' . $e->getMessage() );
            $this->add_admin_notice( __( 'Montonio API response: ', 'montonio-for-woocommerce' ) . $e->getMessage(), 'error' );

            return new WP_Error( 'wc_montonio_shipping_sync_error', $e->getMessage(), array( 'status' => 500 ) );
        } finally {
            $lock_manager->release_lock( 'montonio_shipping_method_items_sync' );
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
                $tracking_title = get_option( 'montonio_email_tracking_code_text' );

                if ( empty( $tracking_title ) || $tracking_title === 'Track your shipment:' ) {
                    $tracking_title = __( 'Track your shipment:', 'montonio-for-woocommerce' );
                }

                $tracking_info = $tracking_links ? $tracking_title . ' ' . $tracking_links : '';
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

            $status_report['sync_results'][] = array(
                'status'  => 'success',
                'message' => 'Shipping method sync successful'
            );
        } catch ( Exception $e ) {
            $status_report['sync_results'][] = array(
                'status'  => 'error',
                'message' => 'Shipping method sync failed: ' . $e->getMessage()
            );
        }

        return $status_report;
    }

    /**
     * Disable Cash on Delivery payment method for Montonio courier shipping methods that do not support it
     *
     * @since 9.1.4
     * @param array $available_gateways The available payment gateways
     * @return array
     */
    public function disable_cod_for_shipping_method( $available_gateways ) {
        if ( is_admin() ) {
            return $available_gateways;
        }

        // Check if COD gateway exists
        if ( ! isset( $available_gateways['cod'] ) ) {
            return $available_gateways;
        }

        $shipping_method = WC_Montonio_Shipping_Helper::get_chosen_montonio_shipping_method_at_checkout();

        if ( empty( $shipping_method ) ) {
            return $available_gateways;
        }

        $carrier = $shipping_method->carrier_code;
        $type    = $shipping_method->type;

        if ( 'courier' !== $type ) {
            return $available_gateways;
        }

        $additional_services = WC_Montonio_Shipping_Item_Manager::get_additional_services( WC_Montonio_Shipping_Helper::get_customer_shipping_country(), $carrier, 'courier' );

        $supports_cod = in_array( 'cod', array_column( $additional_services, 'code' ), true );

        if ( ! $supports_cod ) {
            unset( $available_gateways['cod'] );
        }

        return $available_gateways;
    }
}

WC_Montonio_Shipping::get_instance();
