<?php
defined( 'ABSPATH' ) or exit;

class WC_Montonio_Shipping_Settings extends WC_Settings_Page {

    /**
     * Constructor
     */
    public function __construct() {
        $this->id    = 'montonio_shipping';
        $this->label = __( 'Montonio Shipping', 'montonio-for-woocommerce' );

        parent::__construct();
    }

    /**
     * Edit settings page layout
     */
    public function output() {
        $settings = $this->get_settings();
        ob_start();
        WC_Admin_Settings::output_fields( $settings );
        $shipping_options = ob_get_contents();
        ob_end_clean();

        WC_Montonio_Display_Admin_Options::display_options(
            $this->label,
            $shipping_options,
            $this->id
        );
    }

    /**
     * Legacy support for Woocommerce 5.4 and earlier
     *
     * @return array
     */
    public function get_settings() {
        return $this->get_settings_for_default_section();
    }

    /**
     * Used when creating Montonio shipping settings tab
     *
     * @return array
     */
    public function get_settings_for_default_section() {
        $countries      = array( '' => '-- Choose country --' );
        $countries      = array_merge( $countries, ( new WC_Countries() )->get_countries() );
        $order_statuses = wc_get_order_statuses();

        if ( ! array_key_exists( 'wc-mon-label-printed', $order_statuses ) ) {
            $order_statuses['wc-mon-label-printed'] = __( 'Label printed', 'montonio-for-woocommerce' );
        }

        return array(
            array(
                'title'   => __( 'Enable/Disable', 'montonio-for-woocommerce' ),
                'desc'    => __( 'Enable Montonio Shipping', 'montonio-for-woocommerce' ),
                'type'    => 'checkbox',
                'default' => 'no',
                'id'      => 'montonio_shipping_enabled'
            ),
            array(
                'title'   => __( 'Sandbox Mode', 'montonio-for-woocommerce' ),
                'desc'    => __( 'Enable Sandbox Mode', 'montonio-for-woocommerce' ),
                'type'    => 'checkbox',
                'default' => 'no',
                'id'      => 'montonio_shipping_sandbox_mode'
            ),
            array(
                'type'    => 'select',
                'title'   => __( 'Order status when label printed', 'montonio-for-woocommerce' ),
                'class'   => 'wc-enhanced-select',
                'default' => isset( $order_statuses['wc-mon-label-printed'] ) ? 'wc-mon-label-printed' : 'no-change',
                'desc'    => __(
                    'What status should order be changed to in Woocommerce when label is printed in Montonio?<br>
                    Status will only be changed when order\'s current status is "Processing".',
                    'montonio-for-woocommerce'
                ),
                'options' => array_merge(
                    array(
                        'no-change' => __( '-- Do not change status --', 'montonio-for-woocommerce' )
                    ),
                    $order_statuses
                ),
                'id'      => 'montonio_shipping_orderStatusWhenLabelPrinted'
            ),
            array(
                'type'    => 'select',
                'title'   => __( 'Order status when shipment is delivered', 'montonio-for-woocommerce' ),
                'class'   => 'wc-enhanced-select',
                'default' => 'wc-completed',
                'desc'    => __( 'What status should the order be changed to in WooCommerce when the shipment is delivered?', 'montonio-for-woocommerce' ),
                'options' => array_merge(
                    array(
                        'no-change' => __( '-- Do not change status --', 'montonio-for-woocommerce' )
                    ),
                    $order_statuses
                ),
                'id'      => 'montonio_shipping_order_status_when_delivered'
            ),
            array(
                'title'   => __( 'Tracking code text for e-mail', 'montonio-for-woocommerce' ),
                'type'    => 'text',
                /* translators: help article url */
                'desc'    => '<a class="montonio-reset-email-tracking-code-text" href="#">' . __( 'Reset to default value', 'montonio-for-woocommerce' ) . '</a><br><br>' . sprintf( __( 'Text used before tracking codes in e-mail placeholder {montonio_tracking_info}.<br> Appears only if order has Montonio shipping and existing tracking code(s).<br> <a href="%s" target="_blank">Click here</a> to learn more about how to add the code to customer emails.', 'montonio-for-woocommerce' ), 'https://help.montonio.com/en/articles/69258-adding-tracking-codes-to-e-mails' ),
                'default' => __( 'Track your shipment:', 'montonio-for-woocommerce' ),
                'id'      => 'montonio_email_tracking_code_text'
            ),
            array(
                'title'   => __( 'Show parcel machine address in dropdown in checkout', 'montonio-for-woocommerce' ),
                'desc'    => __( 'Enable', 'montonio-for-woocommerce' ),
                'type'    => 'checkbox',
                'default' => 'yes',
                'id'      => 'montonio_shipping_show_address'
            ),
            array(
                'title'    => __( 'Show shipping provider logos in checkout', 'montonio-for-woocommerce' ),
                'desc'     => __( 'Enables', 'montonio-for-woocommerce' ),
                'desc_tip' => __( 'Applicable only in legacy checkout', 'montonio-for-woocommerce' ),
                'type'     => 'checkbox',
                'default'  => 'no',
                'id'       => 'montonio_shipping_show_provider_logos'
            ),
            array(
                'type' => 'sectionend',
                'id'   => 'montonio_shipping_general'
            ),
            array(
                'title' => __( 'Advanced', 'montonio-for-woocommerce' ),
                'type'  => 'title',
                'id'    => 'montonio_shipping_advanced'
            ),
            array(
                'title'   => __( 'Pickup point dropdown', 'montonio-for-woocommerce' ),
                'type'    => 'select',
                'class'   => 'wc-enhanced-select',
                'default' => 'choices',
                'desc'    => __( 'Select the type of dropdown to use for pickup points. We recommend using the "Choices" dropdown as it offers a better user experience and interface. The "SelectWoo" dropdown is available for legacy support in case of styling or compatibility issues with custom checkout themes (applicable only in legacy checkout).', 'montonio-for-woocommerce' ),
                'options' => array(
                    'choices' => 'Choices dropdown (recommended)',
                    'select2' => 'SelectWoo dropdown (legacy)'
                ),
                'id'      => 'montonio_shipping_dropdown_type'
            ),
            array(
                'type' => 'sectionend',
                'id'   => 'montonio_shipping_advanced'
            )
        );
    }
}
