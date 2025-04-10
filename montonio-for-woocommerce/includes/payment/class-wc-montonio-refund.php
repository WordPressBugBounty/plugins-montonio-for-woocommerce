<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Montonio_Refund {
    public function __construct() {
        // Add custom order status
        add_filter( 'woocommerce_register_shop_order_post_statuses', array( $this, 'add_custom_order_status' ) );
        add_filter( 'wc_order_statuses', array( $this, 'add_custom_order_status_to_order_statuses' ) );
    }

    /**
     * Initiates refund and return true/false as result
     *
     * @param string $order_id order id.
     * @param string $amount refund amount.
     * @param string $reason reason of refund.
     * @return bool
     */
    public static function init_refund( $order_id, $sandbox_mode, $amount, $reason ) {
        if ( 0 >= $amount ) {
            return false;
        }

        try {
            $order           = wc_get_order( $order_id );
            $idempotency_key = $order->get_order_key() . time();
            $order_uuid      = $order->get_meta( '_montonio_uuid' );
            $refunds         = $order->get_refunds();
            $refund          = $refunds[0] ?? false;

            if ( empty( $refund ) ) {
                throw new Exception( __( 'No refund record found. Please refund this order manually in Montonio Partner System.', 'montonio-for-woocommerce' ) );
            }

            if ( empty( $order_uuid ) ) {
                WC_Montonio_Logger::log( 'Failed to initiate refund due to missing UUID. Order ID: ' . $order_id );

                throw new Exception( __( 'Montonio order UUID missing! Please refund this order manually in Montonio Partner System.', 'montonio-for-woocommerce' ) );
            }

            // Create new Montonio API instance
            $montonio_api = new WC_Montonio_API( $sandbox_mode );
            $response     = $montonio_api->create_refund_request( $order_uuid, $amount, $idempotency_key );
            $response     = json_decode( $response );

            $status = sanitize_text_field( $response->status );
            $uuid   = sanitize_text_field( $response->uuid );

            if ( 'REJECTED' === $status ) {
                throw new Exception( __( 'Refund rejected. Refund ID: ', 'montonio-for-woocommerce' ) . $uuid );
            }

            $refund_id        = $refund->get_id();
            $existing_refunds = self::get_order_refunds( $order );

            $existing_refunds[$uuid] = array(
                'wc_refund_id' => $refund_id,
                'status'       => $status
            );

            $order->update_meta_data( '_montonio_refunds', $existing_refunds );
            $order->save();

            if ( 'SUCCESSFUL' === $status ) {
                /* translators: 1) refund amount 2) refund UUID */
                $message = sprintf( __( '<strong>Refund of %1$s processed succesfully.</strong><br>Refund ID: %2$s', 'montonio-for-woocommerce' ), wc_price( $amount ), $uuid );
                $order->add_order_note( $message );
            } else {
                /* translators: 1) refund amount 2) refund UUID */
                $message = sprintf( __( '<strong>Refund of %1$s pending.</strong><br>Refund ID: %2$s', 'montonio-for-woocommerce' ), wc_price( $amount ), $uuid );
                $order->add_order_note( $message );
            }

            return true;
        } catch ( Exception $e ) {
            $message = $e->getMessage();

            if ( $message && substr( $message, 0, 1 ) === '{' ) {
                $message = json_decode( $message, true );

                if ( json_last_error() === JSON_ERROR_NONE && isset( $message['message'] ) ) {
                    $message = $message['message'];
                }
            }

            if ( is_array( $message ) ) {
                $message = implode( '; ', $message );
            }

            WC_Montonio_Logger::log( 'Refund failed: ' . $message );

            $order->add_order_note( __( '<strong>Refund failed.</strong><br>', 'montonio-for-woocommerce' ) . $message );

            return new WP_Error( 'error', $message );
        }
    }

    /**
     * Register custom order status
     *
     * @since 8.1.0
     * @return void
     */
    public function add_custom_order_status( $order_statuses ) {
        $order_statuses['wc-mon-part-refund'] = array(
            'label'                     => _x( 'Partially refunded', 'Order status', 'montonio-for-woocommerce' ),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            /* translators: %s: number of orders */
            'label_count'               => _n_noop( 'Partially refunded (%s)', 'Partially refunded (%s)', 'montonio-for-woocommerce' )
        );

        return $order_statuses;
    }

    /**
     * Add custom order status to order statuses
     *
     * @since 8.1.0
     * @param array $order_statuses The existing order statuses
     * @return array The modified order statuses
     */
    public function add_custom_order_status_to_order_statuses( $order_statuses ) {
        $order_statuses['wc-mon-part-refund'] = _x( 'Partially refunded', 'Order status', 'montonio-for-woocommerce' );
        return $order_statuses;
    }

    /**
     * Retrieves the refund history for a specified WooCommerce order
     *
     * @since 8.1.0
     * @param \WC_Order $order The WooCommerce order object to retrieve refunds from
     * @return array
     */
    public static function get_order_refunds( $order ) {
        if ( empty( $order ) ) {
            return array();
        }

        $refunds = $order->get_meta( '_montonio_refunds', true );
        return is_array( $refunds ) ? $refunds : array();
    }
}
new WC_Montonio_Refund();
