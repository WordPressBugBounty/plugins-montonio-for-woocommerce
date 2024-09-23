<?php
defined( 'ABSPATH' ) or exit;

class Montonio_Shipping_Settings extends WC_Settings_Page {

    /**
	 * Constructor
	 */
    public function __construct() {
        $this->id    = 'montonio_shipping';
        $this->label = __( ' Montonio Shipping', 'montonio-for-woocommerce' );

        parent::__construct();
    }

    public static function create() {
        return new self();
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
        $countries     = array( '' => '-- Choose country --' );
        $countries     = array_merge( $countries, (new WC_Countries() )->get_countries() );
        $order_statuses = wc_get_order_statuses();

        if ( ! array_key_exists('wc-mon-label-printed', $order_statuses ) ) {
            $order_statuses['wc-mon-label-printed'] = __( 'Label printed', 'montonio-for-woocommerce' );
        }

        return array(
            array(
                'title'       => __( 'Enable/Disable', 'montonio-for-woocommerce' ),
                'desc'       => __( 'Enable Montonio Shipping', 'montonio-for-woocommerce' ),
                'type'        => 'checkbox',
                'default'     => 'no',
                'id'          => 'montonio_shipping_enabled'
            ),
            array(
                'title' => __( 'Shipping version', 'montonio-for-woocommerce' ),
                'type' => 'select',
                'class' => 'wc-enhanced-select',
                'default' => get_option( 'montonio_shipping_enabled' ) !== 'yes' ? 'yes' : 'no',
                'desc' => 'Choose which shipping version to use. Shipping V2 provides new features and improvements.',
                'options'     => array(
                    'no' => 'Shipping V1',
                    'yes' => '(NEW) Shipping V2',
                ),
                'id' => 'montonio_shipping_enable_v2'
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
                'id'      => 'montonio_email_tracking_code_text',
                'title'   => __( 'Tracking code text for e-mail', 'montonio-for-woocommerce' ),
                'type'    => 'text',
                'desc' => '<a class="montonio-reset-email-tracking-code-text" href="#">' . __( 'Reset to default value', 'montonio-for-woocommerce' ) . '</a><br><br>' . sprintf( __( 'Text used before tracking codes in e-mail placeholder {montonio_tracking_info}.<br> Appears only if order has Montonio shipping and existing tracking code(s).<br> <a href="%s" target="_blank">Click here</a> to learn more about how to add the code to customer emails.', 'montonio-for-woocommerce' ), 'https://help.montonio.com/en/articles/69258-adding-tracking-codes-to-e-mails' ),
                'default' => __( 'Track your shipment:', 'montonio-for-woocommerce' )
            ),
            array(
                'id'      => 'montonio_shipping_order_prefix',
                'title'   => __( 'Order Prefix', 'montonio-for-woocommerce' ),
                'type'    => 'text',
                'desc'    => __(
                    '<strong>[MULTISTORE]</strong><br />
                     If you are using Montonio in multiple shops with only one pair of API keys, <br />
                     set the Order Prefix here to distinguish between orders in the Montonio Partner System',
                    'montonio-for-woocommerce'
                ),
                'default' => '',
                'class' => 'montonio_shipping_v1_field',
                'autoload' => false,
            ),
            array(
                'title'       => __( 'Show parcel machine address in dropdown in checkout', 'montonio-for-woocommerce' ),
                'desc'       => __('Enable', 'montonio-for-woocommerce' ),
                'type'        => 'checkbox',
                'default'     => 'no',
                'id'          => 'montonio_shipping_show_address'
            ),
            array(
                'title'       => __( 'Show shipping provider logos in checkout', 'montonio-for-woocommerce' ),
                'desc'       => __( 'Enable', 'montonio-for-woocommerce' ),
                'type'        => 'checkbox',
                'default'     => 'no',
                'id'          => 'montonio_shipping_show_provider_logos'
            ),
            array(
                'title'   => __( 'Sender\'s name', 'montonio-for-woocommerce' ),
                'type'    => 'text',
                'default' => '',
                'custom_attributes' => array( 'required' => 'required' ),
                'class' => 'montonio_shipping_v1_field montonio_shipping_v1_field--required',
                'id'      => 'montonio_shipping_senderName'
            ),
            array(
                'title'   => __( 'Sender\'s phone', 'montonio-for-woocommerce' ),
                'type'    => 'text',
                'default' => '',
                'custom_attributes' => array( 'required' => 'required' ),
                'class' => 'montonio_shipping_v1_field montonio_shipping_v1_field--required',
                'id'      => 'montonio_shipping_senderPhone'
            ),
            array(
                'title'   => __( 'Sender\'s street address', 'montonio-for-woocommerce' ),
                'type'    => 'text',
                'default' => '',
                'custom_attributes' => array( 'required' => 'required' ),
                'class' => 'montonio_shipping_v1_field montonio_shipping_v1_field--required',
                'id'      => 'montonio_shipping_senderStreetAddress'
            ),
            array(
                'title'   => __( 'Sender\'s city', 'montonio-for-woocommerce' ),
                'type'    => 'text',
                'default' => '',
                'custom_attributes' => array( 'required' => 'required' ),
                'class' => 'montonio_shipping_v1_field montonio_shipping_v1_field--required',
                'id'      => 'montonio_shipping_senderLocality'
            ),
            array(
                'title'   => __( 'Sender\'s county', 'montonio-for-woocommerce' ),
                'type'    => 'text',
                'default' => '',
                'custom_attributes' => array( 'required' => 'required' ),
                'class' => 'montonio_shipping_v1_field montonio_shipping_v1_field--required',
                'id'      => 'montonio_shipping_senderRegion'
            ),
            array(
                'title'   => __( 'Sender\'s postal code', 'montonio-for-woocommerce' ),
                'type'    => 'text',
                'default' => '',
                'custom_attributes' => array( 'required' => 'required' ),
                'class' => 'montonio_shipping_v1_field montonio_shipping_v1_field--required',
                'id'      => 'montonio_shipping_senderPostalCode'
            ),
            array(
                'title'   => __( 'Sender\'s country', 'montonio-for-woocommerce' ),
                'type'    => 'select',
                'options' => $countries,
                'default' => '',
                'custom_attributes' => array( 'required' => 'required' ),
                'class' => 'montonio_shipping_v1_field montonio_shipping_v1_field--required',
                'id'      => 'montonio_shipping_senderCountry'
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
                'title' => __( 'Pickup point dropdown', 'montonio-for-woocommerce' ),
                'type' => 'select',
                'class' => 'wc-enhanced-select',
                'default' => 'choices',
                'desc' =>  __( 'Select the type of dropdown to use for pickup points. We recommend using the "Choices" dropdown as it offers a better user experience and interface. The "SelectWoo" dropdown is available for legacy support in case of styling or compatibility issues with custom checkout themes.', 'montonio-for-woocommerce' ),
                'options'     => array(
                    'choices' => 'Choices dropdown (recommended)',
                    'select2' => 'SelectWoo dropdown (legacy)',
                ),
                'id' => 'montonio_shipping_dropdown_type',
                'class' => 'montonio_shipping_v2_field',
            ),
            array(
                'id' => 'montonio_shipping_css',
                'class' => 'montonio_shipping_v1_field',
                'title' => __( 'CSS for checkout', 'montonio-for-woocommerce' ),
                'type' => 'textarea',
                'desc' => __(
                    'Here you can insert additional CSS rules for checkout.',
                    'montonio-for-woocommerce'
                ),
            ),
            array(
                'title' => __( 'Enqueue Mode', 'montonio-for-woocommerce' ),
                'type' => 'select',
                'class' => 'wc-enhanced-select',
                'default' => 'enqueue',
                'desc' => __(
                    'Select how to enqueue CSS and JavaScript files to your store',
                    'montonio-for-woocommerce'
                ),
                'options'     => array(
                    'enqueue' => 'Enqueue (recommended)',
                    'echo' => 'Echo',
                ),
                'id' => 'montonio_shipping_enqueue_mode',
                'class' => 'montonio_shipping_v1_field',
            ),
            array(
                'title'       => __( 'Re-register selectWoo script', 'montonio-for-woocommerce' ),
                'label'       => __( 'Yes', 'montonio-for-woocommerce' ),
                'type'        => 'checkbox',
                'desc' => __(
                    'Some themes may deregister selectWoo (used for advanced dropdown functionality). Use this if the pickup points selection is not searchable.',
                    'montonio-for-woocommerce'
                ),
                'default'     => 'no',
                'id'          => 'montonio_shipping_register_selectWoo',
                'class' => 'montonio_shipping_v1_field',
            ),
            array(
                'type' => 'sectionend',
                'id'   => 'montonio_shipping_advanced'
            )
        );
    }
}
