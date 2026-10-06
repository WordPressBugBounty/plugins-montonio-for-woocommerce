<?php
defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce settings tab for the Montonio Withdrawals module.
 *
 * @since 10.4.0
 */
class WC_Montonio_Withdrawals_Settings extends WC_Settings_Page {

    /**
     * Constructor
     */
    public function __construct() {
        $this->id    = 'montonio_withdrawals';
        $this->label = 'Right of withdrawal page';

        add_filter( 'woocommerce_admin_settings_sanitize_option_montonio_withdrawal_contact_email', array( $this, 'sanitize_contact_email' ), 10, 3 );
        add_filter( 'woocommerce_admin_settings_sanitize_option_montonio_withdrawal_recipient', array( $this, 'sanitize_recipient' ), 10, 3 );
        add_action( 'woocommerce_admin_field_montonio_withdrawal_notice', array( $this, 'output_notice' ) );

        parent::__construct();
    }

    /**
     * Edit settings page layout
     */
    public function output() {
        ob_start();

        WC_Admin_Settings::output_fields( $this->get_settings() );

        $withdrawal_options = ob_get_contents();
        ob_end_clean();

        WC_Montonio_Admin_Settings_Page::render_options_page(
            $this->label,
            $withdrawal_options,
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
     * Used when creating the Montonio Withdrawals settings tab
     *
     * @return array
     */
    public function get_settings_for_default_section() {
        $defaults = WC_Montonio_Withdrawals::get_defaults();
        $page_id  = WC_Montonio_Withdrawals::get_page_id();
        $status   = $page_id ? get_post_status( $page_id ) : false;

        if ( 'trash' === $status ) {
            $page_notice = array( 'Your withdrawal page is in the Trash', 'Restore it from the Trash, or delete it permanently so saving creates a new page.' );
        } elseif ( $status ) {
            $page_notice = array( 'Your withdrawal page', sprintf(
                '<a href="%1$s" target="_blank" rel="noopener">%2$s</a> is where customers send their withdrawal notices. Change its title, address or translations in the <a href="%3$s">page editor</a>; saving these settings never changes them.',
                esc_url( 'publish' === $status ? get_permalink( $page_id ) : get_preview_post_link( $page_id ) ),
                esc_html( get_the_title( $page_id ) ),
                esc_url( get_edit_post_link( $page_id, 'raw' ) )
            ) );
        } else {
            $page_notice = array( 'A page is created when you save', sprintf(
                'With the withdrawal page enabled, saving publishes a new page called “%s”. You can then rename, move or translate it in the page editor, like any other page.',
                esc_html( __( 'Withdraw from a purchase', 'montonio-for-woocommerce' ) )
            ) );
        }

        return array(
            array(
                'title'   => 'Enable/Disable',
                'desc'    => 'Enable the right of withdrawal page',
                'type'    => 'checkbox',
                'default' => 'no',
                'id'      => 'montonio_withdrawal_enabled'
            ),
            array(
                'type' => 'sectionend',
                'id'   => 'montonio_withdrawal_general'
            ),
            array(
                'title' => 'Store details',
                'type'  => 'title',
                'id'    => 'montonio_withdrawal_store_details'
            ),
            array(
                'title'             => 'Legal entity name',
                'type'              => 'text',
                'default'           => '',
                'custom_attributes' => array( 'maxlength' => 1000 ),
                'id'                => 'montonio_withdrawal_legal_name'
            ),
            array(
                'title'             => 'Return address (optional)',
                'type'              => 'textarea',
                'default'           => $defaults['return_address'],
                'css'               => 'height:80px;',
                'custom_attributes' => array( 'maxlength' => 5000 ),
                'id'                => 'montonio_withdrawal_return_address'
            ),
            array(
                'title'             => 'Public contact email',
                'type'              => 'text',
                'default'           => $defaults['contact_email'],
                'custom_attributes' => array( 'maxlength' => 1000 ),
                'id'                => 'montonio_withdrawal_contact_email'
            ),
            array(
                'type' => 'sectionend',
                'id'   => 'montonio_withdrawal_store_details'
            ),
            array(
                'title' => 'Email routing',
                'type'  => 'title',
                'desc'  => '<strong>Choose where your store receives withdrawal requests.</strong> Customers don\'t need to be added here. They get their own acknowledgement email automatically, with the withdrawal details, reference, date and time. It confirms the request was received, not that a refund has been issued.',
                'id'    => 'montonio_withdrawal_email_routing'
            ),
            array(
                'title'             => 'Send requests to',
                'type'              => 'text',
                'desc'              => 'Multiple addresses need to be comma separated. Uses WordPress admin email as fallback.',
                'default'           => $defaults['recipient'],
                'custom_attributes' => array( 'maxlength' => 1000 ),
                'id'                => 'montonio_withdrawal_recipient'
            ),
            array(
                'type' => 'sectionend',
                'id'   => 'montonio_withdrawal_email_routing'
            ),
            array(
                'title' => 'Page',
                'type'  => 'title',
                'id'    => 'montonio_withdrawal_page'
            ),
            array(
                'title'   => 'Page editor format',
                'type'    => 'select',
                'class'   => 'wc-enhanced-select',
                'default' => 'auto',
                'options' => array(
                    'auto'      => 'Automatic (keep existing pages unchanged)',
                    'block'     => 'Block editor',
                    'shortcode' => 'Classic Editor / shortcode'
                ),
                'id'      => 'montonio_withdrawal_page_mode'
            ),
            // Not the table's first row: settings tables size their columns from it.
            array(
                'type'  => 'montonio_withdrawal_notice',
                'title' => $page_notice[0],
                'desc'  => $page_notice[1],
                'id'    => 'montonio_withdrawal_page_notice'
            ),
            array(
                'type' => 'sectionend',
                'id'   => 'montonio_withdrawal_page'
            )
        );
    }

    /**
     * Output an informational notice as a full-width form-table row.
     *
     * @since 10.4.0
     * @param array $value Field definition array from WooCommerce settings.
     * @return void
     */
    public function output_notice( $value ) {
        echo '<tr valign="top"><td colspan="2" style="padding: 0;">';
        // The description is our own copy; any page title in it is escaped where it is built.
        WC_Montonio_Admin_Settings_Page::render_banner(
            '<strong>' . esc_html( $value['title'] ) . '</strong><br>' . $value['desc'],
            'montonio-notice--neutral montonio-notice--compact',
            'info-circle'
        );
        echo '</td></tr>';
    }

    /**
     * Sanitize the public contact email option (exactly one address required).
     *
     * @since 10.4.0
     * @param mixed  $value     Sanitized value.
     * @param array  $option    Option field definition.
     * @param string $raw_value Raw submitted value.
     * @return string
     */
    public function sanitize_contact_email( $value, $option, $raw_value ) {
        $emails = WC_Montonio_Withdrawals::parse_email_list( is_string( $raw_value ) ? trim( $raw_value ) : '' );

        if ( is_wp_error( $emails ) || 1 !== count( $emails ) ) {
            WC_Admin_Settings::add_error( 'Enter one valid public contact email address.' );
            return $this->get_previous_value( $option );
        }

        return $emails[0];
    }

    /**
     * Sanitize the recipient list option (at least one address required).
     *
     * @since 10.4.0
     * @param mixed  $value     Sanitized value.
     * @param array  $option    Option field definition.
     * @param string $raw_value Raw submitted value.
     * @return string
     */
    public function sanitize_recipient( $value, $option, $raw_value ) {
        $emails = WC_Montonio_Withdrawals::parse_email_list( is_string( $raw_value ) ? trim( $raw_value ) : '' );

        if ( is_wp_error( $emails ) ) {
            WC_Admin_Settings::add_error( $emails->get_error_message() );
            return $this->get_previous_value( $option );
        }

        if ( empty( $emails ) ) {
            WC_Admin_Settings::add_error( 'Enter at least one recipient email address for withdrawal requests.' );
            return $this->get_previous_value( $option );
        }

        return implode( ', ', $emails );
    }

    /**
     * Get the previously stored value for an option, falling back to its default.
     *
     * @since 10.4.0
     * @param array $option Option field definition.
     * @return mixed
     */
    private function get_previous_value( $option ) {
        return get_option( $option['id'], isset( $option['default'] ) ? $option['default'] : '' );
    }
}
