<?php
defined( 'ABSPATH' ) || exit;

/**
 * Montonio API Settings
 *
 * Handles the API settings page for Montonio payment gateway.
 * Provides configuration for live and sandbox API credentials.
 *
 * @extends WC_Settings_API
 */
class WC_Montonio_API_Settings extends WC_Settings_API {
    /**
     * WC_Montonio_API_Settings constructor.
     */
    public function __construct() {
        $this->id = 'wc_montonio_api';

        add_action( 'woocommerce_settings_checkout', array( $this, 'output_settings' ) );
        add_action( 'woocommerce_update_options_checkout_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_update_options_checkout_' . $this->id, array( $this, 'on_setting_save' ), 20 );

        $this->init_form_fields();
    }

    /**
     * Initialize form fields
     *
     * @return void
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'access_key' => array(
                'type'    => 'void',
            ),            
            'secret_key' => array(
                'type'    => 'void',
            ),
            'sandbox_access_key' => array(
                'type'    => 'void',
            ),
            'sandbox_secret_key' => array(
                'type'    => 'void',
            ),             
            'test_mode' => array(
                'type' => 'void_checkbox',
            ),
            'live_keys_available'         => array(
                'type'  => 'void'
            ),
            'connection'         => array(
                'type'  => 'api_connection'
            ),            
            'general_title'           => array(
                'type'  => 'title',
                'title' => __( 'General settings', 'montonio-for-woocommerce' )
            ),
            'merchant_reference_type' => array(
                'title'       => __( 'Merchant reference type', 'montonio-for-woocommerce' ),
                'type'        => 'select',
                'description' => __( '<strong>Use order ID:</strong> Uses the default WooCommere order ID.<br><br><strong>Use order number:</strong> Allows you to use a custom order number. This option is useful if you have a custom order numbering system in place.<br><br><strong>Add prefix:</strong> Allows you to add a custom prefix to the default order ID.', 'montonio-for-woocommerce' ),
                'default'     => 'order_id',
                'options'     => array(
                    'order_id'     => __( 'Use order ID', 'montonio-for-woocommerce' ),
                    'order_number' => __( 'Use order number', 'montonio-for-woocommerce' ),
                    'add_prefix'   => __( 'Add custom prefix', 'montonio-for-woocommerce' )
                )
            ),
            'order_prefix'            => array(
                'title'       => __( 'Order ID prefix', 'montonio-for-woocommerce' ),
                'type'        => 'text',
                'description' => ''
            )
        );
    }

    /**
     * Generate HTML for void field type
     *
     * Used to suppress rendering of legacy form fields that are now managed
     * through the custom API connection UI.
     *
     * @since 10.1.0
     * @param string $key  Field key.
     * @param array  $data Field data.
     * @return string Empty string.
     */
    public function generate_void_html( $key, $data ) {
        return '';
    }

    /**
     * Generate HTML for void_checkbox field type
     *
     * Used for checkbox fields whose input is rendered inline elsewhere (e.g. in
     * api_keys_form_html()) but still need WC's checkbox save normalisation.
     *
     * @since 10.1.0
     * @param string $key  Field key.
     * @param array  $data Field data.
     * @return string Empty string.
     */
    public function generate_void_checkbox_html( $key, $data ) {
        return '';
    }

    /**
     * Validator for the void_checkbox field type
     *
     * Normalises the POST value to 'yes'/'no' like WC's built-in checkbox field,
     * so the inline checkbox saves correctly.
     *
     * @since 10.1.0
     * @param string $key   Field key.
     * @param string $value Posted value.
     * @return string 'yes' if checked, 'no' otherwise.
     */
    public function validate_void_checkbox_field( $key, $value ) {
        return ! is_null( $value ) ? 'yes' : 'no';
    }

    /**
     * Generate HTML for API connection field
     *
     * Renders either the "connected" state or the "connect" form depending
     * on whether valid API keys are currently configured.
     *
     * @since 10.1.0
     * @param string $key  Field key.
     * @param array  $data Field data.
     * @return string Rendered HTML.
     */
    public function generate_api_connection_html( $key, $data ) {
        $has_live_keys    = ! empty( $this->get_option( 'access_key' ) ) && ! empty( $this->get_option( 'secret_key' ) );
        $has_sandbox_keys = ! empty( $this->get_option( 'sandbox_access_key' ) ) && ! empty( $this->get_option( 'sandbox_secret_key' ) );

        if ( $has_live_keys || $has_sandbox_keys ) {
            return $this->connected_html();
        }

        return $this->connect_html();
    }

    /**
     * Render the "connected" state HTML
     *
     * Displayed when valid live or sandbox API keys are present.
     *
     * @since 10.1.0
     * @return string Rendered HTML.
     */
    public function connected_html() {
        $connection_uuid = $this->get_option( 'connection' );
        $is_connected    = ! empty( $connection_uuid );

        ob_start();
        ?>
        </table>

        <div class="api-keys">
            <div class="api-keys__connected">
                <div class="api-keys__connected-body">
                    <input type="hidden" id="woocommerce_wc_montonio_api_connection" name="woocommerce_wc_montonio_api_connection" value="<?php echo esc_attr( $this->get_option( 'connection' ) ); ?>" readonly />
                    <input type="hidden" id="woocommerce_wc_montonio_api_live_keys_available" name="woocommerce_wc_montonio_api_live_keys_available" value="<?php echo esc_attr( $this->get_option( 'live_keys_available' ) ); ?>" readonly />
                    <?php echo $this->api_keys_form_html( $is_connected ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
            </div>
        </div>

        <table class="form-table">
        <?php
        return ob_get_clean();
    }

    /**
     * Render the "connect" state HTML
     *
     * Displayed when no API keys are configured. Offers both the Montonio
     * Partner System OAuth-style connection flow and a manual API key entry form.
     *
     * @since 10.1.0
     * @return string Rendered HTML.
     */
    public function connect_html() {
        ob_start();
        ?>
        </table>

        <div class="api-keys">
            <h3 class="api-keys__title" id="woocommerce_wc_montonio_api_title"><?php esc_html_e( 'Connect your store', 'montonio-for-woocommerce' ); ?></h3>
            <div class="api-keys__description"><?php esc_html_e( 'Link this WooCommerce store to a Montonio store to start accepting payments and shipping orders.', 'montonio-for-woocommerce' ); ?></div>

            <div class="api-keys__connect">
                <div class="api-keys__connect-icon">
                    <img src="<?php echo esc_url( WC_MONTONIO_PLUGIN_URL . '/assets/images/woo-logo.svg' ); ?>" alt="WooCommerce">
                    <span></span>
                    <img src="<?php echo esc_url( WC_MONTONIO_PLUGIN_URL . '/assets/images/montonio-logo-icon-dark.svg' ); ?>" alt="Montonio">
                </div>
                <div class="api-keys__connect-body">
                    <h3 class="api-keys__connect-title"><?php esc_html_e( 'Connect via Montonio account', 'montonio-for-woocommerce' ); ?></h3>
                    <p class="api-keys__connect-text"><?php esc_html_e( 'Sign in to your Montonio Partner System and authorise this store. API keys are issued and configured automatically.', 'montonio-for-woocommerce' ); ?></p>
                    <ul class="api-keys__connect-list">
                        <li class="api-keys__connect-item"><?php esc_html_e( 'Faster setup — get connected in minutes', 'montonio-for-woocommerce' ); ?></li>
                        <li class="api-keys__connect-item"><?php esc_html_e( 'Automatic API key management — no manual entry required', 'montonio-for-woocommerce' ); ?></li>
                        <li class="api-keys__connect-item"><?php esc_html_e( 'Secure authentication via Montonio Partner System', 'montonio-for-woocommerce' ); ?></li>
                    </ul>
                    <a href="<?php echo esc_url( Montonio_Connection::get_connect_url() ); ?>" class="api-keys__button montonio-button montonio-button--lg"><?php esc_html_e( 'Connect Montonio account', 'montonio-for-woocommerce' ); ?></a>
                </div>
            </div>

            <div class="api-keys__separator"><span><?php esc_html_e( 'Or', 'montonio-for-woocommerce' ); ?></span></div>

            <div class="api-keys__manual">
                <div class="api-keys__manual-toggle">
                    <div class="api-keys__manual-toggle-icon">
                        <img src="<?php echo esc_url( WC_MONTONIO_PLUGIN_URL . '/assets/images/passcode.svg' ); ?>" alt="">
                    </div>
                    <div class="api-keys__manual-toggle-body">
                        <div class="api-keys__manual-title"><?php esc_html_e( 'Enter API keys manually', 'montonio-for-woocommerce' ); ?></div>
                        <div class="api-keys__manual-description"><?php esc_html_e( 'For advanced setups — copy keys from the Montonio Partner System and paste them below.', 'montonio-for-woocommerce' ); ?></div>
                    </div>
                </div>
                <div class="api-keys__manual-content">
                    <input type="hidden" id="woocommerce_wc_montonio_api_connection" name="woocommerce_wc_montonio_api_connection" value="" readonly />
                    <input type="hidden" id="woocommerce_wc_montonio_api_live_keys_available" name="woocommerce_wc_montonio_api_live_keys_available" value="" readonly />
                    <?php echo $this->api_keys_form_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
            </div>
        </div>

        <table class="form-table">
        <?php
        return ob_get_clean();
    }

    /**
     * Render the manual API keys form HTML
     *
     * Outputs the live and sandbox access/secret key inputs, plus the
     * test mode toggle.
     *
     * @since 10.1.0
     * @return string Rendered HTML.
     */
    private function api_keys_form_html( $is_connected = false ) {
        $disabled_attr       = $is_connected ? 'readonly' : '';
        $live_keys_available = 'yes' === $this->get_option( 'live_keys_available' );
        $awaiting_approval   = $is_connected && ! $live_keys_available;

        ob_start();
        ?>
        <div class="api-keys__forms">
            <?php if ( $awaiting_approval ) : ?>
                <div class="api-keys__group api-keys__group--live api-keys__group--pending">
                    <div class="api-keys__group-title">
                        <?php esc_html_e( 'Live keys', 'montonio-for-woocommerce' ); ?>
                        <span class="montonio-badge montonio-badge--neutral-inverted">
                            <?php esc_html_e( 'Pending approval', 'montonio-for-woocommerce' ); ?>
                        </span>
                    </div>
                    <div class="api-keys__group-description">
                        <?php esc_html_e( 'Your store is not yet approved by Montonio. Once the approval is complete, click the "Reconnect" button to receive your live API keys and start accepting real transactions.', 'montonio-for-woocommerce' ); ?>
                    </div>
                    <div class="api-keys__group-actions">
                        <a href="<?php echo esc_url( Montonio_Connection::get_connect_url() ); ?>" class="montonio-button montonio-button--secondary">
                            <?php esc_html_e( 'Reconnect', 'montonio-for-woocommerce' ); ?>
                        </a>
                    </div>
                </div>
            <?php else : ?>
                <div class="api-keys__group api-keys__group--live">
                    <div class="api-keys__group-title">
                        <?php esc_html_e( 'Live keys', 'montonio-for-woocommerce' ); ?>
                        <?php if ( $is_connected ) : ?>
                            <span class="montonio-badge">
                                <?php esc_html_e( 'Issued by Montonio', 'montonio-for-woocommerce' ); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="api-keys__group-description"><?php esc_html_e( 'These are your live API keys for processing real transactions.', 'montonio-for-woocommerce' ); ?></div>

                    <div class="api-keys__fields">
                        <div class="api-keys__field">
                            <label for="woocommerce_wc_montonio_api_access_key"><?php esc_html_e( 'Access Key', 'montonio-for-woocommerce' ); ?></label>
                            <input type="text" id="woocommerce_wc_montonio_api_access_key" name="woocommerce_wc_montonio_api_access_key" value="<?php echo esc_attr( $this->get_option( 'access_key' ) ); ?>" <?php echo esc_attr( $disabled_attr ); ?> />
                        </div>

                        <div class="api-keys__field">
                            <label for="woocommerce_wc_montonio_api_secret_key"><?php esc_html_e( 'Secret Key', 'montonio-for-woocommerce' ); ?></label>
                            <input type="password" id="woocommerce_wc_montonio_api_secret_key" name="woocommerce_wc_montonio_api_secret_key" value="<?php echo esc_attr( $this->get_option( 'secret_key' ) ); ?>" <?php echo esc_attr( $disabled_attr ); ?> />
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="api-keys__group api-keys__group--sandbox">
                <div class="api-keys__group-header">
                    <div class="api-keys__group-title"><?php esc_html_e( 'Sandbox keys for testing', 'montonio-for-woocommerce' ); ?></div>
                    <label class="montonio-toggle" for="woocommerce_wc_montonio_api_test_mode">
                        <input class="montonio-toggle__input" type="checkbox" name="woocommerce_wc_montonio_api_test_mode" id="woocommerce_wc_montonio_api_test_mode" value="1" <?php checked( $this->get_option( 'test_mode' ), 'yes' ); ?> />
                        <span class="montonio-toggle__switch" aria-hidden="true"></span>
                        <span class="montonio-toggle__label"><?php esc_html_e( 'Test mode', 'montonio-for-woocommerce' ); ?></span>
                    </label>
                </div>

                <div class="api-keys__group-description"><?php esc_html_e( 'These are your sandbox API keys for testing purposes.', 'montonio-for-woocommerce' ); ?></div>

                <div class="api-keys__fields">
                    <div class="api-keys__field">
                        <label for="woocommerce_wc_montonio_api_sandbox_access_key"><?php esc_html_e( 'Access Key', 'montonio-for-woocommerce' ); ?></label>
                        <input type="text" id="woocommerce_wc_montonio_api_sandbox_access_key" name="woocommerce_wc_montonio_api_sandbox_access_key" value="<?php echo esc_attr( $this->get_option( 'sandbox_access_key' ) ); ?>" <?php echo esc_attr( $disabled_attr ); ?> />
                    </div>

                    <div class="api-keys__field">
                        <label for="woocommerce_wc_montonio_api_sandbox_secret_key"><?php esc_html_e( 'Secret Key', 'montonio-for-woocommerce' ); ?></label>
                        <input type="password" id="woocommerce_wc_montonio_api_sandbox_secret_key" name="woocommerce_wc_montonio_api_sandbox_secret_key" value="<?php echo esc_attr( $this->get_option( 'sandbox_secret_key' ) ); ?>" <?php echo esc_attr( $disabled_attr ); ?> />
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Output settings page
     *
     * Renders the Main settings page in WooCommerce admin.
     *
     * @since 9.3.0
     * @return void
     */
    public function output_settings() {
        global $current_section;

        if ( $current_section !== $this->id ) {
            return;
        }

        WC_Montonio_Admin_Settings_Page::render_options_page(
            __( 'Main Settings', 'montonio-for-woocommerce' ),
            $this->generate_settings_html( array(), false ),
            $this->id
        );
    }

    /**
     * Handle settings save
     *
     * Validates API credentials after settings are saved.
     * Clears invalid keys and syncs payment methods on success.
     *
     * @since 9.3.0
     * @return void
     */
    public function on_setting_save() {
        $this->init_settings();

        $environments = array(
            ''         => array(
                'access_label' => __( 'Live Access Key is not valid.', 'montonio-for-woocommerce' ),
                'secret_label' => __( 'Live Secret Key is not valid.', 'montonio-for-woocommerce' )
            ),
            'sandbox_' => array(
                'access_label' => __( 'Sandbox Access Key is not valid.', 'montonio-for-woocommerce' ),
                'secret_label' => __( 'Sandbox Secret Key is not valid.', 'montonio-for-woocommerce' )
            )
        );

        foreach ( $environments as $prefix => $labels ) {
            $access_key = $this->get_option( $prefix . 'access_key' );
            $secret_key = $this->get_option( $prefix . 'secret_key' );

            if ( ! empty( $access_key ) && ! WC_Montonio_Helper::is_valid_uuid( $access_key ) ) {
                WC_Admin_Settings::add_error( $labels['access_label'] );
                $this->update_option( $prefix . 'access_key', '' );
            }

            if ( ! empty( $secret_key ) && ! $this->is_valid_secret_key( $secret_key ) ) {
                WC_Admin_Settings::add_error( $labels['secret_label'] );
                $this->update_option( $prefix . 'secret_key', '' );
            }
        }

        // Sync only against the currently active environment
        $active_prefix = 'yes' === $this->get_option( 'test_mode' ) ? 'sandbox_' : '';
        $access_key    = $this->get_option( $active_prefix . 'access_key' );
        $secret_key    = $this->get_option( $active_prefix . 'secret_key' );

        if ( empty( $access_key ) || empty( $secret_key ) ) {
            return;
        }

        $this->sync_payment_methods( $active_prefix );
    }

    /**
     * Sync payment methods with Montonio API
     *
     * Attempts to sync payment methods and handles API errors.
     * Clears credentials if authentication fails.
     *
     * @since 9.3.0
     * @param string $prefix Option prefix ('sandbox_' or '').
     * @return void
     */
    private function sync_payment_methods( $prefix ) {
        try {
            WC_Montonio_Data_Sync::sync_payment_methods();
        } catch ( Exception $e ) {
            $message = $e->getMessage();

            // Check if error indicates invalid credentials
            if ( strpos( $message, 'Unauthorized' ) !== false || strpos( $message, 'Forbidden' ) !== false ) {
                WC_Admin_Settings::add_error(
                    __( 'API credentials are invalid. Please check your Access Key and Secret Key.', 'montonio-for-woocommerce' )
                );

                WC_Admin_Settings::add_error(
                    __( 'API response: ', 'montonio-for-woocommerce' ) . $message
                );

                $this->update_option( $prefix . 'access_key', '' );
                $this->update_option( $prefix . 'secret_key', '' );
            } else {
                WC_Admin_Settings::add_error(
                    __( 'Payment method sync failed: ', 'montonio-for-woocommerce' ) . $message
                );
            }
        }
    }

    /**
     * Validate secret key format
     *
     * Checks if the provided value is a valid base64-encoded string
     * with the expected length (44 characters, decoding to 33 bytes).
     *
     * @since 9.3.0
     * @param string $value The secret key to validate.
     * @return bool True if valid, false otherwise.
     */
    private function is_valid_secret_key( $value ) {
        if ( strlen( $value ) !== 44 ) {
            return false;
        }

        if ( ! preg_match( '/^[A-Za-z0-9+\/=]+$/', $value ) ) {
            return false;
        }

        $decoded = base64_decode( $value, true );

        if ( $decoded === false || strlen( $decoded ) !== 33 ) {
            return false;
        }

        return true;
    }
}