<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Montonio_API_Settings extends WC_Settings_API {

    public function __construct() {
        $this->id = 'wc_montonio_api';

        add_action( 'woocommerce_update_options_checkout_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_montonio_settings_checkout_' . $this->id, array( $this, 'admin_options' ) );

        $this->init_form_fields();
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'title'              => array(
                'type'        => 'title',
                'title'       => __( 'Add API Keys', 'montonio-for-woocommerce' ),
                'description' => __( 'Live and Sandbox API keys can be obtained at <a target="_blank" href="https://partner.montonio.com">Montonio Partner System</a>', 'montonio-for-woocommerce' )
            ),
            'live_title'         => array(
                'type'        => 'title',
                'title'       => __( 'Live keys', 'montonio-for-woocommerce' ),
                'description' => __( 'Use live keys to receive real payments from your customers.', 'montonio-for-woocommerce' ),
            ),
            'access_key'         => array(
                'title'       => __( 'Access Key', 'montonio-for-woocommerce' ),
                'type'        => 'text',
                'description' => '',
                'desc_tip'    => true
            ),
            'secret_key'         => array(
                'title'       => __( 'Secret Key', 'montonio-for-woocommerce' ),
                'type'        => 'password',
                'description' => '',
                'desc_tip'    => true
            ),
            'sandbox_title'       => array(
                'type'        => 'title',
                'title'       => __( 'Sandbox keys for testing', 'montonio-for-woocommerce' ),
                'description' => __( 'Use sandbox keys to test our services.', 'montonio-for-woocommerce' )
            ),
            'sandbox_access_key' => array(
                'title'       => __( 'Access Key', 'montonio-for-woocommerce' ),
                'type'        => 'text',
                'description' => '',
                'desc_tip'    => true
            ),
            'sandbox_secret_key' => array(
                'title'       => __( 'Secret Key', 'montonio-for-woocommerce' ),
                'type'        => 'password',
                'description' => '',
                'desc_tip'    => true
            ),
            'general_title'              => array(
                'type'  => 'title',
                'title' => __( 'General settings', 'montonio-for-woocommerce' ),
            ),
            'merchant_reference_type' => array(
                'title'       => __( 'Merchant reference type', 'montonio-for-woocommerce' ),
                'type'        => 'select',
                'description' => __( '<strong>Use order ID:</strong> Uses the default WooCommere order ID.<br><br><strong>Use order number:</strong> Allows you to use a custom order number. This option is useful if you have a custom order numbering system in place.<br><br><strong>Add prefix:</strong> Allows you to add a custom prefix to the default order ID.', 'montonio-for-woocommerce' ),
                'options'     => array(
                    'order_id'     =>  __( 'Use order ID', 'montonio-for-woocommerce' ),
                    'order_number' => __( 'Use order number', 'montonio-for-woocommerce' ),
                    'add_prefix'   => __( 'Add custom prefix', 'montonio-for-woocommerce' ),
                ),
            ),
            'order_prefix' => array(
                'title'       => __( 'Order ID prefix', 'montonio-for-woocommerce' ),
                'type'        => 'text',
                'description' => '',
            ),
        );
    }

    public function admin_options() {
        WC_Montonio_Display_Admin_Options::display_options(
            __( 'API Settings', 'montonio-for-woocommerce' ),
            $this->generate_settings_html( array(), false ),
            $this->id
        );
    }

}
new WC_Montonio_API_Settings();