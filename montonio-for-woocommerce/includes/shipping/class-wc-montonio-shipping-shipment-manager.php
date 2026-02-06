<?php
defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Montonio_Shipping_Shipment_Manager for handling Montonio Shipping V2 shipment creation
 * @since 7.0.0
 */
class WC_Montonio_Shipping_Shipment_Manager extends Montonio_Singleton {
    /**
     * The constructor for the Montonio Shipping Create Shipment class.
     *
     * @since 7.0.0
     */
    public function __construct() {
        // Create a shipment when order moves to processing status
        add_action( 'woocommerce_order_status_processing', array( $this, 'create_shipment_when_payment_complete' ), 10, 2 );
    }

    /**
     * Create new a shipment whenever the payment is complete.
     *
     * @since 7.0.0
     * @param int $order_id The ID of the order.
     * @param WC_Order $order The WooCommerce order object.
     * @return void
     */
    public function create_shipment_when_payment_complete( $order_id, $order ) {
        if ( empty( $order ) ) {
            WC_Montonio_Logger::log( 'Shipment creation failed. Order object is empty.' );
            return;
        }

        if ( ! apply_filters( 'wc_montonio_create_shipment_on_processing', true, $order_id, $order ) ) {
            return;
        }

        // Check if order has Montonio shipping method and no tracking code has already been generated
        $shipping_method = WC_Montonio_Shipping_Helper::get_chosen_montonio_shipping_method_for_order( $order );

        if ( empty( $shipping_method ) || $shipping_method->get_meta( 'tracking_codes' ) ) {
            return;
        }

        $this->create_shipment( $order );
    }

    /**
     * Creates a shipment for a given order using Montonio Shipping API.
     *
     * @since 7.0.0
     * @param WC_Order $order The WooCommerce order object.
     * @return string|null The API response on successful shipment creation, or null on failure.
     */
    public function create_shipment( $order ) {
        try {
            $data                      = $this->get_shipment_data( $order );
            $data['merchantReference'] = (string) apply_filters( 'wc_montonio_merchant_reference_display', $order->get_order_number(), $order );

            $montonio_order_uuid = $order->get_meta( '_montonio_uuid' );

            if ( ! empty( $montonio_order_uuid ) ) {
                $data['montonioOrderUuid'] = (string) $montonio_order_uuid;
            }

            $shipping_api = new WC_Montonio_Shipping_API();
            $response     = $shipping_api->create_shipment( $data );

            WC_Montonio_Logger::log( 'Create shipment response: ' . $response );
            $decoded_response = json_decode( $response );

            $order->update_meta_data( '_wc_montonio_shipping_shipment_id', $decoded_response->id );
            $order->update_meta_data( '_wc_montonio_shipping_shipment_status', $decoded_response->status );
            $order->update_meta_data( '_wc_montonio_shipping_shipment_status_reason', '' );
            $order->save_meta_data();

            return $response;
        } catch ( Exception $e ) {
            $decoded_response = json_decode( $e->getMessage(), true );
            $note             = '<strong>' . __( 'Shipment creation failed.', 'montonio-for-woocommerce' ) . '</strong>';

            $error_reason = $this->extract_shipment_api_error_reason( $decoded_response, $e );

            $note .= '<br>' . $error_reason;

            $order->add_order_note( $note );

            $order->update_meta_data( '_wc_montonio_shipping_shipment_status', 'creationFailed' );
            $order->update_meta_data( '_wc_montonio_shipping_shipment_status_reason', $note );
            $order->save_meta_data();

            WC_Montonio_Logger::log( 'Shipment creation failed. Response: ' . $e->getMessage() );

            return;
        }
    }

    /**
     * Updates an existing shipment for a given order using Montonio Shipping API.
     *
     * @since 7.0.2
     * @param WC_Order $order The WooCommerce order object.
     * @return string|null The API response on successful shipment update, or null on failure.
     */
    public function update_shipment( $order ) {
        $shipment_id = $order->get_meta( '_wc_montonio_shipping_shipment_id' );

        if ( empty( $shipment_id ) ) {
            WC_Montonio_Logger::log( 'Shipment update failed. Missing shipment ID.' );
            return;
        }

        try {
            $data         = $this->get_shipment_data( $order, 'update' );
            $shipping_api = new WC_Montonio_Shipping_API();
            $response     = $shipping_api->update_shipment( $shipment_id, $data );

            WC_Montonio_Logger::log( 'Update shipment response: ' . $response );
            $decoded_response = json_decode( $response );

            $order->update_meta_data( '_wc_montonio_shipping_shipment_status', $decoded_response->status );
            $order->update_meta_data( '_wc_montonio_shipping_shipment_status_reason', '' );
            $order->save_meta_data();

            return $response;
        } catch ( Exception $e ) {
            $decoded_response = json_decode( $e->getMessage(), true );
            $note             = '<strong>' . __( 'Shipment update failed.', 'montonio-for-woocommerce' ) . '</strong>';

            $error_reason = $this->extract_shipment_api_error_reason( $decoded_response, $e );

            $note .= '<br>' . $error_reason;

            $order->add_order_note( $note );

            $order->update_meta_data( '_wc_montonio_shipping_shipment_status_reason', $note );
            $order->save_meta_data();

            WC_Montonio_Logger::log( 'Shipment update failed. Response: ' . $e->getMessage() );

            return;
        }
    }

    /**
     * Prepares shipment data.
     *
     * @since 7.0.1 Utilizes WC_Montonio_Shipping_Address_Helper for consolidated shipping address fields.
     * @param WC_Order $order The WooCommerce order object.
     * @param string $type Request type - 'create' for new shipment and 'update' for updating.
     * @return array The formatted shipment data ready for API submission.
     */
    public function get_shipment_data( $order, $type = 'create' ) {
        $shipping_method = WC_Montonio_Shipping_Helper::get_chosen_montonio_shipping_method_for_order( $order );

        if ( empty( $shipping_method ) ) {
            return;
        }

        $method_type = $order->get_meta( '_wc_montonio_shipping_method_type' );
        $method_id   = $order->get_meta( '_montonio_pickup_point_uuid' );

        if ( empty( $method_type ) || empty( $method_id ) ) {
            throw new Exception( 'Missing method type or method item ID' );
        }

        $address_helper = WC_Montonio_Shipping_Address_Helper::get_instance();
        $address_data   = $address_helper->standardize_address_data( array(
            'billing_first_name'        => (string) $order->get_billing_first_name(),
            'billing_last_name'         => (string) $order->get_billing_last_name(),
            'billing_company'           => (string) $order->get_billing_company(),
            'billing_street_address_1'  => (string) $order->get_billing_address_1(),
            'billing_street_address_2'  => (string) $order->get_billing_address_2(),
            'billing_locality'          => (string) $order->get_billing_city(),
            'billing_region'            => (string) $order->get_billing_state(),
            'billing_postal_code'       => (string) $order->get_billing_postcode(),
            'billing_country'           => (string) $order->get_billing_country(),
            'billing_email'             => (string) $order->get_billing_email(),
            'billing_phone_number'      => (string) $order->get_billing_phone(),
            'shipping_first_name'       => (string) $order->get_shipping_first_name(),
            'shipping_last_name'        => (string) $order->get_shipping_last_name(),
            'shipping_company'          => (string) $order->get_shipping_company(),
            'shipping_street_address_1' => (string) $order->get_shipping_address_1(),
            'shipping_street_address_2' => (string) $order->get_shipping_address_2(),
            'shipping_locality'         => (string) $order->get_shipping_city(),
            'shipping_region'           => (string) $order->get_shipping_state(),
            'shipping_postal_code'      => (string) $order->get_shipping_postcode(),
            'shipping_country'          => (string) $order->get_shipping_country(),
            'shipping_phone_number'     => method_exists( $order, 'get_shipping_phone' ) ? (string) $order->get_shipping_phone() : null
        ) );

        $data = array(
            'receiver'       => array(
                'name'        => (string) trim( $address_data['first_name'] . ' ' . $address_data['last_name'] ),
                'companyName' => (string) $address_data['company'],
                'country'     => (string) $address_data['country'],
                'phoneNumber' => (string) $address_data['phone_number'],
                'email'       => (string) $address_data['email']
            ),
            'metadata'       => array(
                'platform'        => 'wordpress ' . get_bloginfo( 'version' ) . ' woocommerce ' . WC()->version,
                'platformVersion' => WC_MONTONIO_PLUGIN_VERSION,
                'context'         => array(
                    'woocommerceVersion' => (string) WC()->version,
                    'storeUrl'           => (string) get_site_url(),
                    'orderId'            => (int) $order->get_id(),
                    'orderKey'           => (string) $order->get_order_key(),
                    'billingEmail'      => (string) $order->get_billing_email()
                )
            ),
            'shippingMethod' => array(
                'type' => (string) $method_type,
                'id'   => (string) $method_id
            )
        );

        // Check if payment method is COD and add additional services
        if ( 'cod' === $order->get_payment_method() ) {
            $data['shippingMethod']['additionalServices'] = array(
                array(
                    'code'   => 'cod',
                    'params' => array(
                        'amount' => (float) wc_format_decimal( $order->get_total(), 2 )
                    )
                )
            );
        }

        if ( 'create' === $type ) {
            $data['orderData'] = array(
                'orderTotal'    => (float) wc_format_decimal( $order->get_total(), 2 ),
                'orderSubtotal' => (float) wc_format_decimal( $order->get_subtotal(), 2 ),
                'shippingCost'  => (float) wc_format_decimal( $order->get_shipping_total(), 2 ),
                'taxTotal'      => (float) wc_format_decimal( $order->get_total_tax(), 2 ),
                'productsTax'   => (float) wc_format_decimal( $order->get_total_tax() - $order->get_shipping_tax(), 2 ),
                'shippingTax'   => (float) wc_format_decimal( $order->get_shipping_tax(), 2 ),
                'currency'      => (string) $order->get_currency()
            );

            $data['orderComment']    = (string) sanitize_text_field( $order->get_customer_note() );
            $data['notificationUrl'] = esc_url_raw( rest_url( 'montonio/shipping/v2/webhook' ) );
        }

        if ( $method_type == 'courier' ) {
            $data['receiver']['streetAddress'] = $address_data['street_address_1'] . ' ' . $address_data['street_address_2'];
            $data['receiver']['postalCode']    = $address_data['postal_code'];
            $data['receiver']['locality']      = $address_data['locality'];
            $data['receiver']['region']        = $address_data['region'];
        }

        $parcels  = array();
        $products = array();

        foreach ( $order->get_items() as $item ) {
            $product    = $item->get_product();
            $product_id = $product->get_id();
            $sku        = ! empty( $product->get_sku() ) ? $product->get_sku() : 'woomon_' . $product_id;
            $barcode    = method_exists( $product, 'get_global_unique_id' ) ? $product->get_global_unique_id() : null;
            $name       = $product->get_name();
            $price      = wc_get_price_including_tax( $product );
            $quantity   = $item->get_quantity();

            // Get raw dimensions
            $dimensions = array(
                'weight' => $product->get_weight(),
                'length' => $product->get_length(),
                'width'  => $product->get_width(),
                'height' => $product->get_height(),
            );

            // Apply fallbacks if applicable
            $dimensions = $this->maybe_apply_dimension_fallbacks( $dimensions, $shipping_method );

            // Convert to metric
            $weight = WC_Montonio_Helper::convert_to_kg( $dimensions['weight'] );
            $length = WC_Montonio_Helper::convert_to_meters( $dimensions['length'] );
            $width  = WC_Montonio_Helper::convert_to_meters( $dimensions['width'] );
            $height = WC_Montonio_Helper::convert_to_meters( $dimensions['height'] );

            if ( $product->get_meta( '_montonio_separate_label' ) == 'yes' ) {
                for ( $i = 0; $i < $quantity; $i++ ) {
                    $parcels[] = array(
                        'weight' => $weight > 0 ? $weight : 1,
                        'length' => $length,
                        'width'  => $width,
                        'height' => $height
                    );
                }
            } else {
                if ( array_key_exists( 'combined', $parcels ) ) {
                    $parcels['combined']['weight'] += $weight * $quantity;
                    $parcels['combined']['length'] = max( $parcels['combined']['length'], $length );
                    $parcels['combined']['width']  = max( $parcels['combined']['width'], $width );
                    $parcels['combined']['height'] = max( $parcels['combined']['height'], $height );
                } else {
                    $parcels['combined'] = array(
                        'weight' => $weight * $quantity,
                        'length' => $length,
                        'width'  => $width,
                        'height' => $height
                    );
                }
            }

            // Check for duplicate SKUs
            $duplicate_index = array_search( $sku, array_column( $products, 'sku' ) );

            if ( false !== $duplicate_index ) {
                // SKU duplicate found, check if product IDs match
                if ( isset( $product_ids[$duplicate_index] ) && $product_ids[$duplicate_index] === $product_id ) {
                    // Same product (based on item ID), just increase quantity
                    $products[$duplicate_index]['quantity'] += (float) $quantity;
                    continue;
                }
            }

            $product_data = array(
                'sku'             => (string) $sku,
                'name'            => (string) $name,
                'quantity'        => (float) $quantity,
                'barcode'         => (string) $barcode,
                'price'           => (float) wc_format_decimal( $price, 2 ),
                'currency'        => (string) $order->get_currency(),
                'imageUrl'        => (string) wp_get_attachment_url( $product->get_image_id() ),
                'storeProductUrl' => (string) $product->get_permalink()
            );

            $product_ids[] = $product_id;
            $products[]    = $product_data;
        }

        // For combined parcel, if it exists and weight is 0, set to 1
        if ( array_key_exists( 'combined', $parcels ) && $parcels['combined']['weight'] <= 0 ) {
            $parcels['combined']['weight'] = 1;
        }

        // Only add products array if all products were valid
        if ( 'create' === $type && ! empty( $products ) ) {
            $data['products'] = $products;
        }

        $data['parcels'] = array_values( $parcels );

        $data = apply_filters( 'wc_montonio_before_shipping_data_submission', $data, $order );
        WC_Montonio_Logger::log( 'Create shipment payload: ' . json_encode( $data ) );

        return $data;
    }

    /**
     * Apply fallback dimensions and weight if needed for the shipping method.
     *
     * @param array  $dimensions Array with 'weight', 'length', 'width', 'height' keys.
     * @param object $shipping_method The shipping method object.
     * @return array The dimensions with fallbacks applied if applicable.
     */
    private function maybe_apply_dimension_fallbacks( $dimensions, $shipping_method ) {
        $shipping_method_id = $shipping_method->get_method_id();
        $apply_fallback     = false;

        // International shipping - always apply fallback
        if ( strpos( $shipping_method_id, 'montonio_international_shipping' ) === 0 ) {
            $apply_fallback = true;
        }

        // DPD methods - only if pricing_type is dynamic
        $dpd_methods = array(
            'montonio_dpd_parcel_machines',
            'montonio_dpd_parcel_shops',
            'montonio_dpd_courier'
        );

        if ( in_array( $shipping_method_id, $dpd_methods, true ) ) {
            $instance = WC_Montonio_Shipping_Helper::get_shipping_method_instance( $shipping_method->get_instance_id() );
            
            if ( 'dynamic' === $instance->get_option( 'pricing_type' ) ) {
                $apply_fallback = true;
            }
        }

        if ( ! $apply_fallback ) {
            return $dimensions;
        }

        $instance = $instance ?? WC_Montonio_Shipping_Helper::get_shipping_method_instance( $shipping_method->get_instance_id() );

        if ( empty( $dimensions['length'] ) || empty( $dimensions['width'] ) || empty( $dimensions['height'] ) ) {
            $dimensions['length'] = $instance->get_option( 'default_length', 0 );
            $dimensions['width']  = $instance->get_option( 'default_width', 0 );
            $dimensions['height'] = $instance->get_option( 'default_height', 0 );
        }

        if ( empty( $dimensions['weight'] ) ) {
            $dimensions['weight'] = $instance->get_option( 'default_weight', 0 );
        }

        return $dimensions;
    }

    /**
     * @param $decoded_response
     * @param Exception $e
     * @return mixed
     */
    private function extract_shipment_api_error_reason( $decoded_response, Exception $e ) {
        $error_reason = '';

        if ( json_last_error() === JSON_ERROR_NONE && ! empty( $decoded_response['message'] ) && ! empty( $decoded_response['error'] ) ) {
            if ( is_array( $decoded_response['message'] ) ) {
                $error_reason .= implode( '<br>', $decoded_response['message'] );
            } else {
                $error_reason .= $decoded_response['message'];
            }

            $error_reason .= '<br>' . $decoded_response['error'];
        } else {
            $error_reason .= $e->getMessage();
        }

        return $error_reason;
    }
}
WC_Montonio_Shipping_Shipment_Manager::get_instance();