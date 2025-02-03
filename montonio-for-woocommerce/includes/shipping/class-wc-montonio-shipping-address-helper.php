<?php

defined( 'ABSPATH' ) || exit;

/**
 * WC_Montonio_Shipping_Address_Helper class to manage and validate shipment data.
 *
 * @since 7.0.1
 */
class WC_Montonio_Shipping_Address_Helper extends Montonio_Singleton {
    /**
     * Attributes grouped by their type.
     *
     * @since 7.0.1
     * @var array
     */
    private $attributes;

    /**
     * Constructor to initialize attributes.
     *
     * @since 7.0.1
     */
    protected function __construct() {
        $this->attributes = [
            'address' => ['street_address_1', 'street_address_2', 'locality', 'region', 'postal_code', 'country'],
            'name'    => ['first_name', 'last_name'],
            'phone'   => ['phone_country', 'phone_number'],
            'other'   => ['company', 'email']
        ];
    }

    /**
     * Replace shipping fields with billing if invalid and return consolidated shipping address fields.
     *
     * @since 7.0.1
     * @param array $data Partial order data.
     * @return array Updated order data with consolidated shipping address fields.
     * 
     * @example <code>
     * $data = [
     *     'billing_first_name' => 'John',
     *     'billing_last_name' => 'Doe',
     *     'billing_street_address_1' => 'Kai 1',
     *     'billing_locality' => 'Tallinn',
     *     'billing_region' => 'Harjumaa',
     *     'billing_postal_code' => '10111',
     *     'billing_country' => 'EE',
     *     'billing_phone_country' => '372',
     *     'billing_phone_number' => '5555555',
     *     'billing_email' => 'john.doe@example.com',
     *     'shipping_first_name' => '',
     *     'shipping_last_name' => '',
     *     'shipping_street_address_1' => '',
     *     'shipping_locality' => '',
     *     'shipping_region' => '',
     *     'shipping_postal_code' => '',
     *     'shipping_country' => '',
     *     'shipping_phone_country' => '',
     *     'shipping_phone_number' => '',
     *     'shipping_email' => 'myshippingemail@example.com',
     * ];
     *
     * $helper = WC_Montonio_Shipping_Address_Helper::get_instance();
     * $updated_data = $helper->standardize_address_data($data);
     * print_r($updated_data);
     *
     * // Output:
     * // Array (
     * //     [first_name] => John
     * //     [last_name] => Doe
     * //     [street_address_1] => Kai 1
     * //     [street_address_2] => null
     * //     [locality] => Tallinn
     * //     [region] => Harjumaa
     * //     [postal_code] => 10111
     * //     [country] => EE
     * //     [phone_country] => 372
     * //     [phone_number] => 5555555
     * //     [company] => null
     * //     [email] => myshippingemail@example.com
     * // )
     * </code>
     */
    public function standardize_address_data( $data ) {
        $consolidated_data = [];

        foreach ( $this->attributes as $group => $attributes ) {
            $use_shipping = $this->is_any_shipping_field_valid( $attributes, $data, $group );

            foreach ( $attributes as $attribute ) {
                $prefix = $use_shipping ? 'shipping_' : 'billing_';
                if ( $group === 'other' ) {
                    $consolidated_data[$attribute] = $this->is_valid( $data['shipping_' . $attribute] ?? null ) ? $data['shipping_' . $attribute] : ( $data['billing_' . $attribute] ?? null );
                } else {
                    $consolidated_data[$attribute] = $data[$prefix . $attribute] ?? null;
                }
            }
        }

        return $consolidated_data;
    }

    /**
     * Check if any shipping field is valid.
     *
     * @since 7.0.1
     * @param array $attributes Attributes to check.
     * @param array $data Order data.
     * @param string $group Attribute group type.
     * @return bool True if any shipping field is valid, false otherwise.
     */
    private function is_any_shipping_field_valid( $attributes, $data, $group ) {
        foreach ( $attributes as $attribute ) {
            // Skip 'country' check if the group is 'address'
            if ( $group === 'address' && $attribute === 'country' ) {
                continue;
            }

            if ( $this->is_valid( $data['shipping_' . $attribute] ?? null ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a value is valid.
     *
     * @since 7.0.1
     * @param mixed $item Item to check.
     * @return bool True if valid, false otherwise.
     */
    private function is_valid( $item ) {
        return isset( $item ) && ! empty( $item );
    }
}