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
        try{
            $shipping_api = get_montonio_shipping_api();
            $data = $this->get_shipment_data( $order );
            $data['merchantReference'] = (string) apply_filters( 'wc_montonio_merchant_reference_display', $order->get_order_number(), $order );
            
            $montonio_order_uuid = $order->get_meta( '_montonio_uuid' );

            if ( ! empty( $montonio_order_uuid ) ) {
                $data['montonioOrderUuid'] = (string) $montonio_order_uuid;
            }
            
            $response = $shipping_api->create_shipment( $data );

            WC_Montonio_Logger::log( 'Create shipment response: ' . $response );
            $decoded_response = json_decode( $response );

            $order->update_meta_data( '_wc_montonio_shipping_shipment_id', $decoded_response->id );
            $order->update_meta_data( '_wc_montonio_shipping_shipment_status', $decoded_response->status );
            $order->save_meta_data();

            return $response;
        } catch ( Exception $e ) {
            $decoded_response = json_decode( $e->getMessage(), true );
            $note = '<strong>' . __( 'Shipment creation failed.', 'montonio-for-woocommerce' ) . '</strong>';
            
            if ( json_last_error() === JSON_ERROR_NONE && ! empty( $decoded_response['message'] ) && ! empty( $decoded_response['error'] ) ) {
                if ( is_array( $decoded_response['message'] ) ) {
                    $note .= '<br>' . implode( '<br>', $decoded_response['message'] );
                } else {
                    $note .= '<br>' . $decoded_response['message'];
                }
                $note .= '<br>' . $decoded_response['error'];
            } else {
                $note .= $e->getMessage();
            }

            $order->add_order_note( $note );
            $order->update_meta_data( '_wc_montonio_shipping_shipment_status', 'creationFailed' );
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

        try{
            $shipping_api = get_montonio_shipping_api();
            $data = $this->get_shipment_data( $order );
            $response = $shipping_api->update_shipment( $shipment_id, $data );

            WC_Montonio_Logger::log( 'Update shipment response: ' . $response );
            $decoded_response = json_decode( $response );

            $order->update_meta_data( '_wc_montonio_shipping_shipment_status', $decoded_response->status );
            $order->save_meta_data();

            return $response;
        } catch ( Exception $e ) {
            $decoded_response = json_decode( $e->getMessage(), true );
            $note = '<strong>' . __( 'Shipment update failed.', 'montonio-for-woocommerce' ) . '</strong>';
            
            if ( json_last_error() === JSON_ERROR_NONE && ! empty( $decoded_response['message'] ) && ! empty( $decoded_response['error'] ) ) {
                if ( is_array( $decoded_response['message'] ) ) {
                    $note .= '<br>' . implode( '<br>', $decoded_response['message'] );
                } else {
                    $note .= '<br>' . $decoded_response['message'];
                }
                $note .= '<br>' . $decoded_response['error'];
            } else {
                $note .= $e->getMessage();
            }

            $order->add_order_note( $note );
            $order->update_meta_data( '_wc_montonio_shipping_shipment_status', 'updateFailed' );
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
     * @return array The formatted shipment data ready for API submission.
     */
    public function get_shipment_data( $order ) {
        $method_type         = $order->get_meta( '_wc_montonio_shipping_method_type' );
        $method_id           = $order->get_meta( '_montonio_pickup_point_uuid' );

        if ( empty( $method_type ) || empty( $method_id ) ) {
            WC_Montonio_Logger::log( 'Shipment creation failed. Missing method type or method item ID.' );
            return;
        }

        $address_helper = WC_Montonio_Shipping_Address_Helper::get_instance();
            $address_data   = $address_helper->standardize_address_data( [
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
                'shipping_phone_number'     => method_exists( $order, 'get_shipping_phone' ) ? (string) $order->get_shipping_phone() : null,
            ] );

            $data = [
                'receiver'          => [
                    'name'        => trim( $address_data['first_name'] . ' ' . $address_data['last_name'] ),
                    'companyName' => $address_data['company'],
                    'country'     => $address_data['country'],
                    'phoneNumber' => $address_data['phone_number'],
                    'email'       => $address_data['email'],
                ],
                'metadata'       => [
                    'platform'        => 'wordpress ' . get_bloginfo('version') . ' woocommerce ' . WC()->version,
                    'platformVersion' => WC_MONTONIO_PLUGIN_VERSION
                ],
                'shippingMethod' => [
                    'type' => (string) $method_type,
                    'id'   => (string) $method_id
                ]
            ];

            if ( $method_type == 'courier' ) {
                $data['receiver']['streetAddress'] = $address_data['street_address_1'] . ' ' . $address_data['street_address_2'];
                $data['receiver']['postalCode']    = $address_data['postal_code'];
                $data['receiver']['locality']      = $address_data['locality'];
                $data['receiver']['region']        = $address_data['region'];
            }

            $parcels = [];

            foreach ( $order->get_items() as $item ) {
                $product = $item->get_product();
                $weight  = WC_Montonio_Helper::convert_to_kg( $product->get_weight() );

                if ( $product->get_meta( '_montonio_separate_label' ) == 'yes' ) {
                    for ( $i = 0; $i < $item->get_quantity(); $i++ ) {
                        $parcels[] = [
                            'weight' => $weight > 0 ? $weight : 1,
                            'length' => WC_Montonio_Helper::convert_to_meters( $product->get_length() ),
                            'width'  => WC_Montonio_Helper::convert_to_meters( $product->get_width() ),
                            'height' => WC_Montonio_Helper::convert_to_meters( $product->get_height() ),
                        ];
                    }
                } else {
                    if ( array_key_exists( 'combined', $parcels ) ) {
                        $parcels['combined']['weight'] += $weight * $item->get_quantity();
                    } else {
                        $parcels['combined']['weight'] = $weight * $item->get_quantity();
                    }
                }
            }

            // For combined parcel, if it exists and weight is 0, set to 1
            if ( array_key_exists( 'combined', $parcels ) && ! $parcels['combined']['weight'] > 0 ) {
                $parcels['combined']['weight'] = 1;
            }

            $data['parcels'] = array_values( $parcels );

            $data = apply_filters( 'wc_montonio_before_shipping_data_submission', $data, $order );
            WC_Montonio_Logger::log( 'Create shipment payload: ' . json_encode( $data ) );

            return $data;
    }

}
WC_Montonio_Shipping_Shipment_Manager::get_instance();