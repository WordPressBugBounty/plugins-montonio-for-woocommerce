<?php
defined( 'ABSPATH' ) or exit;

class WC_Montonio_Shipping_Settings extends WC_Settings_Page {

    /**
     * Constructor
     */
    public function __construct() {
        $this->id    = 'montonio_shipping';
        $this->label = __( 'Montonio Shipping', 'montonio-for-woocommerce' );

        add_action( 'woocommerce_admin_field_montonio_carriers_table', array( $this, 'output_montonio_carriers_table' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_route_setup_scripts' ) );

        parent::__construct();
    }

    /**
     * Enqueue the route setup JS on the Montonio Shipping settings page.
     *
     * @since 9.4.0
     */
    public function enqueue_route_setup_scripts() {
        $screen = get_current_screen();

        if ( empty( $screen ) ) {
            return;
        }

        if ( ! isset( $_GET['tab'] ) || 'montonio_shipping' !== sanitize_text_field( wp_unslash( $_GET['tab'] ) ) ) {
            return;
        }

        wp_localize_script( 'montonio-admin-shipping-setup', 'montonioAdminShippingSetup', array(
            'ajax_url'              => admin_url( 'admin-ajax.php' ),
            'nonce'                 => wp_create_nonce( 'montonio_setup_routes' ),
            'shipping_settings_url' => admin_url( 'admin.php?page=wc-settings&tab=shipping' ),
            'modal_title'           => __( 'Setup Shipping Routes', 'montonio-for-woocommerce' ),
            'network_error_html'    => WC_Montonio_Shipping_Route_Setup_View::render_error(
                __( 'A network error occurred. Please try again.', 'montonio-for-woocommerce' )
            ),
        ) );

        wp_enqueue_script( 'montonio-admin-shipping-setup' );
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

        WC_Montonio_Admin_Settings_Page::render_options_page(
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
                'type'    => 'select',
                'title'   => __( 'Create shipment on order status', 'montonio-for-woocommerce' ),
                'class'   => 'wc-enhanced-select',
                'default' => 'wc-processing',
                'desc'    => __( 'Select the order status that triggers automatic shipment creation in Montonio. Choose "Disabled" to create shipments manually.', 'montonio-for-woocommerce' ),
                'options' => array_merge(
                    array(
                        'disabled' => __( '-- Disabled (manual creation only) --', 'montonio-for-woocommerce' )
                    ),
                    $order_statuses
                ),
                'id'      => 'montonio_shipping_create_shipment_on_status'
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
                'title'   => __( 'Pickup point selector', 'montonio-for-woocommerce' ),
                'type'    => 'select',
                'class'   => 'wc-enhanced-select',
                'default' => 'default',
                'desc' => __( 'Choose the pickup point selection type used in the legacy checkout.', 'montonio-for-woocommerce' ),
                'options' => array(
                    'default' => 'Default selector (recommended)',
                    'choices' => 'Choices dropdown',
                    'select2' => 'SelectWoo dropdown (legacy)'
                ),
                'id'      => 'montonio_shipping_dropdown_type'
            ),
            array(
                'type' => 'sectionend',
                'id'   => 'montonio_shipping_advanced'
            ),
            array(
                'type' => 'montonio_carriers_table',
                'id'   => 'montonio_carriers_table'
            )
        );
    }

    /**
     * Output the carriers table on the shipping settings page.
     *
     * @since 9.4.0
     * @param array $value Field definition array from WooCommerce settings.
     * @return void
     */
    public function output_montonio_carriers_table( $value ) {
        if ( 'yes' !== get_option( 'montonio_shipping_enabled' ) ) {
            return;
        }

        // Close the parent form-table so we can render full-width HTML
        echo '</table>';

        if ( ! WC_Montonio_Helper::has_api_keys() ) {
            $mode = WC_Montonio_Helper::is_test_mode() ? __( 'Sandbox', 'montonio-for-woocommerce' ) : __( 'Live', 'montonio-for-woocommerce' );
            echo '<div class="notice notice-warning inline"><p>';
            echo wp_kses_post(
                sprintf(
                    /* translators: %1$s: mode (Sandbox/Live), %2$s: opening link tag to API settings, %3$s: closing link tag */
                    __( '%1$s API keys are not configured. %2$sConfigure API keys%3$s to view carriers.', 'montonio-for-woocommerce' ),
                    esc_html( $mode ),
                    '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_montonio_api' ) ) . '">',
                    '</a>'
                )
            );
            echo '</p></div>';
            echo '<table class="form-table">';
            return;
        }

        try {
            $api      = new WC_Montonio_Shipping_API();
            $response = $api->get_carriers();
            $data     = json_decode( $response );
            $all_carriers = isset( $data->carriers ) ? $data->carriers : array();
            $carriers     = array_filter( $all_carriers, function( $carrier ) {
                return ! empty( $carrier->contracts );
            } );
        } catch ( Exception $e ) {
            WC_Montonio_Logger::log( 'Carriers table: failed to load carriers: ' . $e->getMessage() );
            echo '<div class="notice notice-error inline"><p>';
            esc_html_e( 'Could not load carriers. Please try again later.', 'montonio-for-woocommerce' );
            echo '</p></div>';
            echo '<table class="form-table">';
            return;
        }

        $name_overrides = array(
            'novaPost' => __( 'Montonio International Shipping', 'montonio-for-woocommerce' ),
        );

        $logo_overrides = array(
            'novaPost' => 'montonio_international_shipping-rect.svg',
        );

        ?>
        <div id="montonio-carriers-wrapper">
        <h2><?php esc_html_e( 'Activated carriers in Montonio', 'montonio-for-woocommerce' ); ?></h2>

        <?php if ( empty( $carriers ) ) : ?>
            <div class="notice notice-info inline"><p>
                <?php esc_html_e( 'No carriers available for this store.', 'montonio-for-woocommerce' ); ?>
            </p></div>
        <?php else : ?>
            <table class="widefat montonio-carriers-table">
                <thead>
                    <tr>
                        <th class="montonio-carriers-table__logo"></th>
                        <th><?php esc_html_e( 'Carrier', 'montonio-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'Contract Type', 'montonio-for-woocommerce' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $carriers as $carrier ) :
                        $carrier_code = $carrier->code;
                        $display_name = isset( $name_overrides[ $carrier_code ] ) ? $name_overrides[ $carrier_code ] : $carrier->name;
                        $logo_filename = isset( $logo_overrides[ $carrier_code ] ) ? $logo_overrides[ $carrier_code ] : $carrier_code . '-rect.svg';
                        $logo_path     = '/assets/images/' . $logo_filename;
                        $logo_url      = file_exists( WC_MONTONIO_PLUGIN_PATH . $logo_path )
                            ? WC_MONTONIO_PLUGIN_URL . $logo_path
                            : WC_MONTONIO_PLUGIN_URL . '/assets/images/default-carrier-logo.svg';
                    ?>
                        <tr>
                            <td class="montonio-carriers-table__logo">
                                <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $display_name ); ?>" />
                            </td>
                            <td class="montonio-carriers-table__name"><?php echo esc_html( $display_name ); ?></td>
                            <?php
                            $has_montonio = false;
                            $has_direct   = false;

                            foreach ( $carrier->contracts as $contract ) {
                                if ( ! empty( $contract->isDirectContract ) ) {
                                    $has_direct = true;
                                } else {
                                    $has_montonio = true;
                                }
                            }

                            $contract_types = array();

                            if ( $has_montonio ) {
                                $contract_types[] = __( 'Montonio', 'montonio-for-woocommerce' );
                            }

                            if ( $has_direct ) {
                                $contract_types[] = __( 'Direct', 'montonio-for-woocommerce' );
                            }
                            ?>
                            <td>
                                <ul class="montonio-carriers-table__contracts">
                                    <?php foreach ( $contract_types as $type ) : ?>
                                        <li><?php echo esc_html( $type ); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <br>
        <p class="montonio-setup-routes-trigger">
            <button type="button" class="button button-primary" id="montonio-setup-routes-btn"><?php esc_html_e( 'Setup routes', 'montonio-for-woocommerce' ); ?></button>
            <span class="description"><?php esc_html_e( 'Automatically create shipping zones and methods based on your active carriers with Montonio contracts.', 'montonio-for-woocommerce' ); ?></span>
        </p>
        </div>

        <?php
        // Re-open the parent form-table
        echo '<table class="form-table">';
    }
}
