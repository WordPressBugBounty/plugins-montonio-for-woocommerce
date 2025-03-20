<?php

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Montonio_Shipping_Order handles Montonio shipping related order actions
 * @since 7.0.0
 */
class WC_Montonio_Shipping_Order {

    /**
     * The constructor for the WC_Montonio_Shipping_Order class
     * @since 7.0.0
     */
    public function __construct() {
        if ( WC_Montonio_Helper::is_hpos_enabled() ) {
            // Add shipping method filter
            add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'add_orders_filter' ) );
            add_filter( 'woocommerce_orders_table_query_clauses', array( $this, 'hpos_output_filter_results' ), 10, 3 );
            add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'modify_order_columns' ), 10, 2 );

            // Add shipment status column
            add_filter( 'woocommerce_shop_order_list_table_columns', array( $this, 'add_shipping_status_column' ), 20 );
            add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'display_shipping_status_column_content' ), 20, 2 );        
        } else {
            // Add shipping method filter
            add_action( 'restrict_manage_posts', array( $this, 'add_orders_filter' ) );
            add_filter( 'posts_where', array( $this, 'output_filter_results' ), 10, 2 );
            add_action( 'manage_shop_order_posts_custom_column', array( $this, 'modify_order_columns' ), 10, 2 );

            // Add shipment status column
            add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_shipping_status_column' ), 20 );
            add_action( 'manage_shop_order_posts_custom_column', array( $this, 'display_shipping_status_column_content' ), 20, 2 );
        }

        // Add custom order status
        add_action( 'init', array( $this, 'add_custom_order_status' ) );
        add_filter( 'wc_order_statuses', array( $this, 'add_custom_order_status_to_order_statuses' ) );
        add_filter( 'woocommerce_admin_order_actions', array( $this, 'add_custom_status_order_action' ), 10, 2);

        // Hide metadata fields from order view
        add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hide_custom_order_itemmeta' ) );

        // Add Montonio shipping panel in orde page
        add_action( 'woocommerce_admin_order_data_after_shipping_address', array( $this, 'add_order_shipping_panel' ) );

        // Add pickup point select in order view
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_required_admin_scripts' ), 15 );
        add_action( 'wp_ajax_get_country_select', array( $this, 'get_country_select' ) );
        add_action( 'wp_ajax_get_pickup_point_select', array( $this, 'get_pickup_point_select' ) );
        add_action( 'wp_ajax_process_selected_pickup_point', array( $this, 'process_selected_pickup_point' ) );
    }

    /**
     * Enqueue required admin scripts
     * @since 7.0.0
     */
    public function enqueue_required_admin_scripts() {
        wp_enqueue_script( 'montonio-shipping-pickup-points-admin' );
    }

    /**
     * Add shipping methods dropdown as a filter option for order list
     * @since 7.0.0
     * @return void
     */
    public function add_orders_filter() {
        if ( ( ! isset( $_GET['post_type'] ) || $_GET['post_type'] !== 'shop_order' ) && ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'wc-orders' ) ) {
            return;
        }

        $selected_method  = ! empty( $_GET['montonio_shipping_method'] ) ? sanitize_text_field( $_GET['montonio_shipping_method'] ) : '';
        $shipping_methods = WC()->shipping()->get_shipping_methods();

        echo '<select id="montonio_shipping_method" name="montonio_shipping_method">';
        echo '<option value="">' . __( 'All shipping methods', 'montonio-for-woocommerce' ) . '</option>';

        foreach ( $shipping_methods as $id => $shipping_method ) {
            $selected = ( $selected_method == $id ) ? ' selected' : null;

            echo '<option value="' . $id . '"' . $selected . '>' . $shipping_method->get_method_title() . '</option>';
        }

        echo '</select>';
    }

    /**
     * Filter orders list by selected shipping method for classic orders
     *
     * @since 7.0.0
     * @param string $where The existing WHERE clauses of the query
     * @param WP_Query $query The current WP_Query object
     * @return string The modified WHERE clauses
     */
    public function output_filter_results( $where, $query ) {
        if ( ! is_admin() ) {
            return $where;
        }

        $method = isset( $_GET['montonio_shipping_method'] ) ? sanitize_text_field( $_GET['montonio_shipping_method'] ) : false;

        if ( isset( $query->query['post_type'] ) && $query->query['post_type'] == 'shop_order' && ! empty( $method ) ) {
            global $wpdb;
            $where .= $wpdb->prepare(
                "AND ID IN (
                    SELECT order_id
                    FROM {$wpdb->prefix}woocommerce_order_itemmeta m
                    LEFT JOIN {$wpdb->prefix}woocommerce_order_items i
                    ON i.order_item_id = m.order_item_id
                    WHERE meta_key = 'method_id' and meta_value = %s
                )",
                $method
            );
        }

        return $where;
    }

    /**
     * Filter orders list by selected shipping method for HPOS
     *
     * @since 7.0.0
     * @param array $pieces The existing query clauses
     * @param WP_Query $query The current WP_Query object
     * @param array $query_vars The query variables
     * @return array The modified query clauses
     */
    public function hpos_output_filter_results( $pieces, $query, $query_vars ) {
        $method = isset( $_GET['montonio_shipping_method'] ) ? sanitize_text_field( $_GET['montonio_shipping_method'] ) : false;

        if ( isset( $_GET['page'] ) && $_GET['page'] == 'wc-orders' && ! empty( $method ) ) {
            global $wpdb;
            $pieces['where'] .= $wpdb->prepare(
                " AND {$wpdb->prefix}wc_orders.id IN (
                    SELECT order_id
                    FROM {$wpdb->prefix}woocommerce_order_itemmeta m
                    LEFT JOIN {$wpdb->prefix}woocommerce_order_items i
                    ON i.order_item_id = m.order_item_id
                    WHERE meta_key = 'method_id' and meta_value = %s
                )",
                $method
            );
        }

        return $pieces;
    }

    /**
     * Add tracking codes to shipping address column
     *
     * @since 7.0.0
     * @param string $column The column name
     * @param int $order_id The order ID
     * @return void
     */
    public function modify_order_columns( $column, $order_id ) {
        $order = wc_get_order( $order_id );
        if ( empty( $order ) ) {
            return;
        }

        $shipping_method = WC_Montonio_Shipping_Helper::get_chosen_montonio_shipping_method_for_order( $order );
        if ( empty( $shipping_method ) ) {
            return;
        }

        if ( $column === 'shipping_address' ) {
            if ( $shipping_method->get_meta( 'tracking_codes' ) ) {
                echo __( 'Tracking code(s)', 'montonio-for-woocommerce' ) . ':<br />' . $shipping_method->get_meta( 'tracking_codes' );
                return;
            }

            $date_paid = $order->get_date_paid();

            if ( empty( $date_paid ) ) {
                return;
            }

            if ( time() - $date_paid->getTimestamp() > 5 * 60 ) {
                echo '<span style="color:sandybrown">' . __( 'Unexpected error - Check status in Montonio Partner System', 'montonio-for-woocommerce' ) . '</span><br />';
            } else {
                echo '<span style="color:orange">' . __( 'Waiting for tracking codes from Montonio', 'montonio-for-woocommerce' ) . '</span><br />';
            }
        }
    }

    /**
     * Add shipping status column to order list
     *
     * @since 8.1.0
     * @param array $columns Current order list columns.
     * @return array Modified columns.
     */
    public function add_shipping_status_column( $columns ) {
        $new_columns = array();
    
        // Insert our column after the 'order_status' column
        foreach ( $columns as $column_name => $column_info ) {
            $new_columns[$column_name] = $column_info;
    
            if ( $column_name === 'order_status' ) {
                $new_columns['montonio_shipping_status'] = __( 'Shipment status', 'montonio-for-woocommerce' );
            }
        }
    
        return $new_columns;
    }
    
    /**
     * Display shipping status column content
     *
     * @since 8.1.0
     * @param string $column_name Column identifier.
     * @param mixed $order_or_order_id WC_Order object or order ID.
     * @return void
     */
    public function display_shipping_status_column_content( $column_name, $order_or_order_id ) {
        $order = is_numeric( $order_or_order_id ) ? wc_get_order( $order_or_order_id ) : $order_or_order_id;
        
        if ( $column_name === 'montonio_shipping_status' && $order ) {
            $status = $order->get_meta( '_wc_montonio_shipping_shipment_status' );
    
            if ( ! empty( $status ) ) {
                $formatted_status = preg_replace( '/(?<!^)([A-Z])/', ' $1', $status );
                $formatted_status = ucfirst( strtolower( $formatted_status ) );
    
                echo '<mark class="order-status montonio-shippment-status montonio-shippment-status--' . esc_html( $status ) . '"><span>' . esc_html( $formatted_status ) . '</span></mark>';
            } else {
                echo '<span class="na">–</span>';
            }
        }
    }

    /**
     * Register custom order status
     *
     * @since 7.0.0
     * @return void
     */
    public function add_custom_order_status() {
        register_post_status( 'wc-mon-label-printed', array(
            'label'                     => _x( 'Label printed', 'Order status', 'montonio-for-woocommerce' ),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Label printed (%s)', 'Label printed (%s)', 'montonio-for-woocommerce' )
        ) );
    }

    /**
     * Add custom order status to order statuses
     *
     * @since 7.0.0
     * @param array $order_statuses The existing order statuses
     * @return array The modified order statuses
     */
    public function add_custom_order_status_to_order_statuses( $order_statuses ) {
        $order_statuses['wc-mon-label-printed'] = _x( 'Label printed', 'Order status', 'montonio-for-woocommerce' );
        return $order_statuses;
    }

    /**
     * Add order actions for 'Label printed' status
     *
     * @since 7.0.2
     * @param array $actions The existing actions for the order.
     * @param WC_Order $order The current order object.
     * @return array The modified actions array including the custom 'Complete' action.
     */
    public function add_custom_status_order_action( $actions, $order ) {
        if ( $order->has_status( 'mon-label-printed' ) ) {
            $actions['complete'] = array(
                'url'    => wp_nonce_url( admin_url( 'admin-ajax.php?action=woocommerce_mark_order_status&status=completed&order_id=' . $order->get_id() ), 'woocommerce-mark-order-status' ),
                'name'   => __( 'Complete', 'woocommerce' ),
                'action' => 'complete',
            );
        }
    
        return $actions;
    }

    /**
     * Hide custom order itemmeta fields from order view
     *
     * @since 7.0.0
     * @param array $hidden_order_itemmeta The existing hidden order itemmeta fields
     * @return array The modified hidden order itemmeta fields
     */
    public function hide_custom_order_itemmeta( $hidden_order_itemmeta ) {
        $add_to_hidden = [
            'shipping_method_identifier',
            'provider_name',
            'type',
            'type_v2',
            'method_class_name',
            'tracking_codes',
            'instance_id'
        ];

        return array_merge( $hidden_order_itemmeta, $add_to_hidden );
    }

    /**
     * Add shipping panel in order page
     *
     * @since 7.0.0
     * @param WC_Order $order The order object
     * @return void
     */
    public function add_order_shipping_panel( $order ) {
        wp_enqueue_script( 'wc-montonio-shipping-label-printing' );

        wp_localize_script(
            'wc-montonio-shipping-label-printing',
            'wcMontonioShippingLabelPrintingData',
            array(
                'orderId'                   => $order->get_id(),
                'getLabelFileUrl'           => esc_url_raw( rest_url( 'montonio/shipping/v2/labels' ) ),
                'createLabelsUrl'           => esc_url_raw( rest_url( 'montonio/shipping/v2/labels/create' ) ),
                'markLabelsAsDownloadedUrl' => esc_url_raw( rest_url( 'montonio/shipping/v2/labels/mark-as-downloaded' ) ),
                'nonce'                     => wp_create_nonce( 'wp_rest' )
            )
        );

        wp_enqueue_script( 'wc-montonio-shipping-shipment-manager' );

        wp_localize_script(
            'wc-montonio-shipping-shipment-manager',
            'wcMontonioShippingShipmentData',
            array(
                'orderId'         => $order->get_id(),
                'shippingRestUrl' => esc_url_raw( rest_url( 'montonio/shipping/v2' ) ),
                'nonce'           => wp_create_nonce( 'wp_rest' )
            )
        );

        echo '<div class="montonio-shipping-panel-wrappper">';

        wc_get_template(
            'admin-order-shipping-panel.php',
            [
                'order' => $order
            ],
            '',
            WC_MONTONIO_PLUGIN_PATH . '/templates/'
        );

        echo '</div>';
    }

    /**
     * Get shipping panel content to update it dynamically
     *
     * @since 7.0.0
     * @param WC_Order $order The order object
     * @return void
     */
    public static function get_order_shipping_panel_content( $order ) {
        ob_start();
        wc_get_template(
            'admin-order-shipping-panel.php',
            [
                'order' => $order
            ],
            '',
            WC_MONTONIO_PLUGIN_PATH . '/templates/'
        );
        $content = ob_get_clean();

        return $content;
    }

    /**
     * Country select for chosen shipping method
     *
     * @since 7.0.0
     * @return void
     */
    public function get_country_select() {
        if ( ! isset( $_POST['shipping_method_id'] ) ) {
            wp_send_json_error();
        }

        $shipping_method_id       = sanitize_text_field( $_POST['shipping_method_id'] );
        $shipping_method_instance = WC_Montonio_Shipping_Helper::create_shipping_method_instance( $shipping_method_id );
        $carrier                  = $shipping_method_instance->provider_name;
        $type                     = $shipping_method_instance->type_v2;

        $country_availability = WC_Montonio_Shipping_Item_Manager::get_shipping_method_countries( $carrier, $type );

        if ( ! empty( $country_availability ) ) {
            $wc_countries  = new WC_Countries();
            $country_names = $wc_countries->__get( 'countries' );

            $country_select = '<select name="montonio_carrier_country" class="montonio_carrier_country" data-carrier="' . $carrier . '" data-type="' . $type . '">';
            $country_select .= '<option value="">' . __( 'Select a destination country', 'montonio-for-woocommerce' ) . '</option>';

            foreach ( $country_availability as $country ) {
                $country_select .= '<option value="' . $country . '">';
                $country_select .= $country_names[strtoupper( $country )];
                $country_select .= '</option>';
            }

            $country_select .= '</select>';
        } else {
            wp_send_json_error(); // TODO: Fix error message in JS
        }

        wp_send_json_success( $country_select );
    }

    /**
     * Pickup point select for chosen shipping method
     *
     * @since 7.0.0
     * @return void
     */
    public function get_pickup_point_select() {
        if ( ! isset( $_POST['country'] ) || ! isset( $_POST['carrier'] ) || ! isset( $_POST['type'] ) ) {
            wp_send_json_error();
        }

        $country = sanitize_text_field( $_POST['country'] );
        $carrier = sanitize_text_field( $_POST['carrier'] );
        $type    = sanitize_text_field( $_POST['type'] );

        $shipping_method_items = WC_Montonio_Shipping_Item_Manager::fetch_and_group_pickup_points( $country, $carrier, $type );

        if ( empty( $shipping_method_items ) ) {
            wp_send_json_error();
        }

        $include_address = get_option( 'montonio_shipping_show_address' );

        $pickup_point_select = '<select name="montonio_carrier_pickup_point" class="montonio_carrier_pickup_point">';
        $pickup_point_select .= '<option value="">' . __( 'Select pickup point', 'montonio-for-woocommerce' ) . '</option>';

        foreach ( $shipping_method_items as $locality => $items ) {
            $pickup_point_select .= '<optgroup label="' . esc_attr( $locality ) . '">';

            foreach ( $items as $item ) {
                $pickup_point_select .= '<option value="' . esc_attr( $item['id'] ) . '">';
                $pickup_point_select .= esc_html( $item['name'] );

                if ( $include_address === 'yes' && ! empty( $item['address'] ) ) {
                    $pickup_point_select .= ' - ' . esc_html( $item['address'] );
                }

                $pickup_point_select .= '</option>';
            }
            $pickup_point_select .= '</optgroup>';
        }

        $pickup_point_select .= '</select>';

        wp_send_json_success( $pickup_point_select );
    }

    /**
     * Process chosen shipping method data
     *
     * @since 7.0.0
     * @return void
     */
    public function process_selected_pickup_point() {
        if ( ! isset( $_POST['order_id'] ) || ! isset( $_POST['country'] ) || ! isset( $_POST['carrier'] ) || ! isset( $_POST['type'] ) ) {
            wp_send_json_error();
        }

        $order_id = sanitize_text_field( $_POST['order_id'] );
        $country  = sanitize_text_field( $_POST['country'] );
        $carrier  = sanitize_text_field( $_POST['carrier'] );
        $type     = sanitize_text_field( $_POST['type'] );

        $order = wc_get_order( $order_id );

        if ( in_array( $type, ['parcelMachine', 'postOffice', 'parcelShop'] ) ) {
            // Set method type
            $type = 'pickupPoint';

            // Get chosen pickup
            $shipping_method_item_id = isset( $_POST['pickup_point_id'] ) ? sanitize_text_field( $_POST['pickup_point_id'] ) : null;

            if ( empty( $shipping_method_item_id ) ) {
                wp_send_json_error();
            }

            // Get pickup point info
            $shipping_method_item = WC_Montonio_Shipping_Item_Manager::get_shipping_method_item( $shipping_method_item_id );

            // Update order meta data and shipping address
            $order->update_meta_data( '_montonio_pickup_point_name', $shipping_method_item->item_name );

            if ( method_exists( $order, 'set_shipping' ) ) {
                $order->set_shipping(
                    [
                        'address_1' => $shipping_method_item->item_name,
                        'address_2' => '',
                        'city'      => $shipping_method_item->locality,
                        'state'     => '',
                        'postcode'  => $shipping_method_item->postal_code,
                        'country'   => strtoupper( $shipping_method_item->country_code )
                    ]
                );
            } else {
                $order->update_meta_data( '_shipping_address_1', $shipping_method_item->item_name );
                $order->update_meta_data( '_shipping_address_2', '' );
                $order->update_meta_data( '_shipping_city', $shipping_method_item->locality );
                $order->update_meta_data( '_shipping_postcode', $shipping_method_item->postal_code );
            }
        } else {
            $shipping_method_item_id = WC_Montonio_Shipping_Item_Manager::get_courier_id( $country, $carrier );
        }

        $order->update_meta_data( '_montonio_pickup_point_uuid', $shipping_method_item_id );
        $order->update_meta_data( '_wc_montonio_shipping_method_type', $type );

        $order->save();

        wp_send_json_success( $shipping_method_item );
    }

    /**
     * Add shipment tracking codes to order
     *
     * @since 7.0.0
     * @param object $payload The payload data
     * @return WP_REST_Response The response object
     */
    public static function add_tracking_codes( $payload ) {
        if ( empty( $payload ) ) {
            return new WP_REST_Response( ['message' => 'Response data missing'], 400 );
        }

        $order_id = WC_Montonio_Helper::get_order_id_by_meta_data( $payload->shipmentId, '_wc_montonio_shipping_shipment_id' );
        $order    = wc_get_order( $order_id );

        // Verify that the meta data is correct with what we just searched for
        if ( empty( $order ) || $order->get_meta( '_wc_montonio_shipping_shipment_id', true ) !== $payload->shipmentId ) {
            WC_Montonio_Logger::log( __( 'add_tracking_codes: Order not found.', 'montonio-for-woocommerce' ) );
            return new WP_REST_Response( ['message' => 'Order not found'], 400 );
        }

        $shipping_method = WC_Montonio_Shipping_Helper::get_chosen_montonio_shipping_method_for_order( $order );
        if ( empty( $shipping_method ) ) {
            return new WP_REST_Response( ['message' => 'Order not using Montonio shipping method'], 400 );
        }

        $tracking_links = '';

        foreach ( $payload->data->parcels as $parcel ) {
            $parcel_id    = sanitize_text_field( $parcel->carrierParcelId );
            $tracking_url = sanitize_text_field( $parcel->trackingLink );

            if ( ! empty( $tracking_url ) ) {
                $tracking_links .= '<a href="' .  esc_url( $tracking_url ) . '" target="_blank">' . esc_html( $parcel_id ) . '</a><br>';
            }
        }

        if ( ! empty( $tracking_links ) ) {
            $order->add_order_note( __( '<strong>Shipment created.</strong><br>Tracking codes: ', 'montonio-for-woocommerce' ) . $tracking_links );
            $shipping_method->update_meta_data( 'tracking_codes', $tracking_links );
            $shipping_method->save_meta_data();

            $order->update_meta_data( '_montonio_tracking_info', $tracking_links );
        }

        $order->update_meta_data( '_wc_montonio_shipping_shipment_status', 'registered' );
        $order->save_meta_data();

        return new WP_REST_Response( ['message' => 'Tracking codes processed'], 200 );
    }

    /**
     * Handle 'shipment.registrationFailed' webhook
     *
     * @since 7.0.0
     * @param object $payload The payload data
     * @return WP_REST_Response The response object
     */
    public static function handle_registration_failed_webhook( $payload ) {
        if ( empty( $payload ) ) {
            return new WP_REST_Response( ['message' => 'Response data missing'], 400 );
        }

        $order_id = WC_Montonio_Helper::get_order_id_by_meta_data( $payload->shipmentId, '_wc_montonio_shipping_shipment_id' );
        $order    = wc_get_order( $order_id );

        // Verify that the meta data is correct with what we just searched for
        if ( empty( $order ) || $order->get_meta( '_wc_montonio_shipping_shipment_id', true ) !== $payload->shipmentId ) {
            WC_Montonio_Logger::log( 'handle_registration_failed_webhook: Order not found.' );
            return new WP_REST_Response( ['message' => 'Order not found'], 400 );
        }

        // Recursive function to traverse the nested errors and collect messages and descriptions
        function collect_error_messages( $errors, &$messages, &$seen_messages, $depth = 0, $max_depth = 5 ) {
            if ( $depth > $max_depth ) {
                return;
            }

            foreach ( $errors as $error ) {
                if ( isset( $error->message ) && ! in_array( $error->message, $seen_messages ) ) {
                    $sanitized_message = sanitize_text_field( $error->message );
                    $messages[]        = $sanitized_message;
                    $seen_messages[]   = $sanitized_message;
                }
                if ( isset( $error->description ) && ! in_array( $error->description, $seen_messages ) ) {
                    $sanitized_description = sanitize_text_field( $error->description );
                    $messages[]            = $sanitized_description;
                    $seen_messages[]       = $sanitized_description;
                }
                if ( isset( $error->cause ) ) {
                    if ( is_array( $error->cause ) ) {
                        collect_error_messages( $error->cause, $messages, $seen_messages, $depth + 1, $max_depth );
                    } else {
                        collect_error_messages( [$error->cause], $messages, $seen_messages, $depth + 1, $max_depth );
                    }
                }
            }
        }

        $messages      = [];
        $seen_messages = [];

        if ( ! empty( $payload->data->errors ) ) {
            collect_error_messages( $payload->data->errors, $messages, $seen_messages );
        }

        $message = '<strong>' . __( 'Shipment registration failed.', 'montonio-for-woocommerce' ) . '</strong>';
        if ( ! empty( $messages ) ) {
            $message .= '<br>' . implode( '<br>', $messages );
        }

        $order->add_order_note( $message );
        $order->update_meta_data( '_wc_montonio_shipping_shipment_status', 'registrationFailed' );
        $order->save_meta_data();

        return new WP_REST_Response( ['message' => 'Shipment registration failed message added to order'], 200 );
    }

    /**
     * Handle 'shipment.statusUpdate' webhook
     *
     * @since 8.1.0
     * @param object $payload The payload data
     * @return WP_REST_Response The response object
     */
    public static function handle_status_update_webhook( $payload ) {
        if ( empty( $payload ) ) {
            return new WP_REST_Response( ['message' => 'Response data missing'], 400 );
        }

        $order_id = WC_Montonio_Helper::get_order_id_by_meta_data( $payload->shipmentId, '_wc_montonio_shipping_shipment_id' );
        $order    = wc_get_order( $order_id );

        // Verify that the meta data is correct with what we just searched for
        if ( empty( $order ) || $order->get_meta( '_wc_montonio_shipping_shipment_id', true ) !== $payload->shipmentId ) {
            WC_Montonio_Logger::log( __( 'add_tracking_codes: Order not found.', 'montonio-for-woocommerce' ) );
            return new WP_REST_Response( ['message' => 'Order not found'], 400 );
        }

        $status = sanitize_text_field( $payload->data->status );
  
        if ( ! empty( $status ) ) {
            $order->update_meta_data( '_wc_montonio_shipping_shipment_status', $status );
            $order->save_meta_data();
        }

        return new WP_REST_Response( ['message' => 'Shipment status update processed'], 200 );
    } 
}

new WC_Montonio_Shipping_Order();
