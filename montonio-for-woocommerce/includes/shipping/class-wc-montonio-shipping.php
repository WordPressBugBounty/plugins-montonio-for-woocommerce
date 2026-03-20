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
     * @since 7.0.0
     */
    protected function __construct() {
        $this->load_dependencies();
        $this->register_hooks();
    }

    private function load_dependencies() {
        require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/class-wc-montonio-shipping-api.php';
        require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/class-wc-montonio-shipping-rate.php';
        require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/class-wc-montonio-shipping-item-manager.php';
        require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/webhooks/class-wc-montonio-shipping-webhooks.php';
        require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/class-wc-montonio-shipping-helper.php';
        require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/class-wc-montonio-shipping-sync.php';
        WC_Montonio_Shipping_Sync::init();
        
        require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/class-wc-montonio-shipping-route-setup.php';
        require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/class-wc-montonio-shipping-route-setup-view.php';

        if ( 'yes' === get_option( 'montonio_shipping_enabled' ) ) {
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/class-wc-montonio-shipping-address-helper.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/class-wc-montonio-shipping-product.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/class-wc-montonio-shipping-order.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/class-wc-montonio-shipping-shipment-manager.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/class-wc-montonio-shipping-pickup-points-search.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/label-printing/class-wc-montonio-shipping-label-printing.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/class-wc-montonio-shipping-rest.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/checkout/class-wc-montonio-shipping-classic-checkout.php';
            
            // Shipping methods
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/class-montonio-shipping-method.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/dpd/class-montonio-dpd-parcel-machines.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/dpd/class-montonio-dpd-parcel-shops.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/dpd/class-montonio-dpd-courier.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/smartpost/class-montonio-smartpost-parcel-machines.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/smartpost/class-montonio-smartpost-post-offices.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/smartpost/class-montonio-smartpost-courier.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/omniva/class-montonio-omniva-parcel-machines.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/omniva/class-montonio-omniva-post-offices.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/omniva/class-montonio-omniva-courier.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/venipak/class-montonio-venipak-parcel-machines.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/venipak/class-montonio-venipak-parcel-shops.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/venipak/class-montonio-venipak-courier.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/unisend/class-montonio-unisend-parcel-machines.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/unisend/class-montonio-unisend-courier.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/latvian-post/class-montonio-latvian-post-parcel-machines.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/latvian-post/class-montonio-latvian-post-courier.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/inpost/class-montonio-inpost-parcel-machines.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/orlen/class-montonio-orlen-parcel-machines.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/montonio-international-shipping/class-montonio-international-shipping-pickup-points.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/montonio-international-shipping/class-montonio-international-shipping-courier.php';
        }
    }

    private function register_hooks() {
        // Save and process shipping configuration when Montonio Shipping settings are submitted
        add_action( 'woocommerce_update_options_montonio_shipping', array( $this, 'process_shipping_options' ) );

        // Show any pending admin notices (runs late to ensure all notices are registered first)
        add_action( 'admin_notices', array( $this, 'display_admin_notices' ), 999 );

        if ( 'yes' === get_option( 'montonio_shipping_enabled' ) ) {
            // Register Montonio shipping methods with WooCommerce
            add_filter( 'woocommerce_shipping_methods', array( $this, 'add_shipping_methods' ), 5 );

            // Load frontend scripts for pickup point / carrier selection at checkout
            add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ), 20 );

            // Hide Cash on Delivery option when the selected shipping method doesn't support it
            add_filter( 'woocommerce_available_payment_gateways', array( $this, 'disable_cod_for_shipping_method' ) );

            // Attach Montonio shipping data (e.g. pickup point) to the order on checkout submission
            add_action( 'woocommerce_checkout_create_order', array( $this, 'handle_montonio_shipping_checkout' ), 10, 2 );

            // Swap Montonio-specific placeholders with their actual values in outgoing emails
            add_filter( 'woocommerce_email_format_string', array( $this, 'replace_email_placeholders' ), 10, 2 );
        }
    }


    /**
     * Add custom shipping methods to WooCommerce.
     *
     * @param array $methods The existing shipping methods.
     * @return array The updated array of shipping methods.
     */
    public function add_shipping_methods( $methods ) {
        $methods['montonio_international_shipping_pickup_points'] = 'Montonio_International_Shipping_Pickup_Points';
        $methods['montonio_international_shipping_courier']       = 'Montonio_International_Shipping_Courier';
        $methods['montonio_omniva_parcel_machines']               = 'Montonio_Omniva_Parcel_Machines';
        $methods['montonio_omniva_post_offices']                  = 'Montonio_Omniva_Post_Offices';
        $methods['montonio_omniva_courier']                       = 'Montonio_Omniva_Courier';
        $methods['montonio_dpd_parcel_machines']                  = 'Montonio_DPD_Parcel_Machines';
        $methods['montonio_dpd_parcel_shops']                     = 'Montonio_DPD_Parcel_Shops';
        $methods['montonio_dpd_courier']                          = 'Montonio_DPD_Courier';
        $methods['montonio_venipak_parcel_machines']              = 'Montonio_Venipak_Parcel_Machines';
        $methods['montonio_venipak_post_offices']                 = 'Montonio_Venipak_Parcel_Shops';
        $methods['montonio_venipak_courier']                      = 'Montonio_Venipak_Courier';
        $methods['montonio_itella_parcel_machines']               = 'Montonio_Smartpost_Parcel_Machines';
        $methods['montonio_itella_post_offices']                  = 'Montonio_Smartpost_Post_Offices';
        $methods['montonio_itella_courier']                       = 'Montonio_Smartpost_Courier';
        $methods['montonio_unisend_parcel_machines']              = 'Montonio_Unisend_Parcel_Machines';
        $methods['montonio_unisend_courier']                      = 'Montonio_Unisend_Courier';
        $methods['montonio_latvian_post_parcel_machines']         = 'Montonio_Latvian_Post_Parcel_Machines';
        $methods['montonio_latvian_post_courier']                 = 'Montonio_Latvian_Post_Courier';
        $methods['montonio_inpost_parcel_machines']               = 'Montonio_Inpost_Parcel_Machines';
        $methods['montonio_orlen_parcel_machines']                = 'Montonio_Orlen_Parcel_Machines';

        return $methods;
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
            if ( 'select2' === get_option( 'montonio_shipping_dropdown_type' ) ) {
                wp_enqueue_style( 'montonio-pickup-points' );

                if ( ! wp_script_is( 'selectWoo', 'registered' ) ) {
                    wp_register_script( 'selectWoo', WC()->plugin_url() . '/assets/js/selectWoo/selectWoo.full.min.js', array( 'jquery' ), false, true );
                }

                wp_enqueue_script( 'montonio-shipping-pickup-points-legacy' );
            } elseif ( 'choices' === get_option( 'montonio_shipping_dropdown_type' ) ) {
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
            update_option( 'montonio_shipping_enabled', 'no' );

            $this->add_admin_notice(
                sprintf(
                    __( 'Montonio Shipping requires API credentials. Please <a href="%s">add them here</a>.', 'montonio-for-woocommerce' ),
                    admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_montonio_api' )
                ),
                'error'
            );

            return;
        }

        $result = WC_Montonio_Shipping_Sync::sync();

        if ( is_wp_error( $result ) ) {
            $this->add_admin_notice(
                '<strong>' . __( 'Shipping data sync failed.', 'montonio-for-woocommerce' ) . '</strong><br>' . esc_html( $result->get_error_message() ),
                'error'
            );
        } else {
            $this->add_admin_notice(
                '<strong>Montonio Shipping.</strong> ' . __( 'Shipping method data synced successfully.', 'montonio-for-woocommerce' ),
                'success'
            );
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
            echo '<div class="montonio-notice montonio-notice--' . esc_attr( $notice['class'] ) . ' notice notice-' . esc_attr( $notice['class'] ) . '">';
            echo '	<p>' . wp_kses_post( $notice['message'] ) . '</p>';
            echo '</div>';
        }
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
