<?php
defined( 'ABSPATH' ) || exit;

/**
 * Base class for all Montonio payment gateways.
 *
 * Holds the boilerplate shared by every Montonio gateway: callback handlers,
 * admin notices, the settings page renderer, refunds, the gateway icon class,
 * and settings validation. Concrete gateways set $api_method_key, implement the
 * method-specific pieces (init_form_fields, payment_fields, process_payment,
 * is_available), and call register_montonio_hooks() from their constructor once
 * $this->id is set.
 *
 * @class    WC_Montonio_Payment_Gateway
 * @extends  WC_Payment_Gateway
 */
abstract class WC_Montonio_Payment_Gateway extends WC_Payment_Gateway {

    /**
     * Notices (array)
     *
     * @var array
     */
    protected $admin_notices = array();

    /**
     * Is test mode active?
     *
     * @var bool
     */
    public $test_mode;

    /**
     * The Montonio payment method identifier for this gateway (e.g.
     * `cardPayments`, `paymentInitiation`). Used to look up the method in the
     * synced `paymentMethods` response, to confirm it is active on the
     * merchant's Montonio account, and as the `payment.method` value sent when
     * creating an order.
     *
     * @var string
     */
    protected $api_method_key = '';

    /**
     * Register the hooks shared by every Montonio gateway.
     *
     * Must be called from the concrete gateway's constructor after $this->id
     * has been set. Gateways that need frontend scripts register their own
     * `wp_enqueue_scripts` hook for payment_scripts().
     *
     * @return void
     */
    protected function register_montonio_hooks() {
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_filter( 'woocommerce_settings_api_sanitized_fields_' . $this->id, array( $this, 'validate_settings' ) );
        add_filter( 'woocommerce_gateway_icon', array( $this, 'add_icon_class' ), 10, 2 );
        add_action( 'admin_notices', array( $this, 'display_admin_notices' ), 999 );

        add_action( 'woocommerce_api_' . $this->id, array( 'WC_Montonio_Callbacks', 'handle_return' ) );
        add_action( 'woocommerce_api_' . $this->id . '_notification', array( 'WC_Montonio_Callbacks', 'handle_notification' ) );
    }

    /**
     * Validate gateway settings before saving.
     *
     * @param  array $settings The settings array being saved.
     * @return array           Unmodified settings on success, or settings with 'enabled' set to 'no' on failure.
     */
    public function validate_settings( $settings ) {
        if ( ! is_array( $settings ) || 'no' === $settings['enabled'] ) {
            return $settings;
        }

        try {
            $payment_methods = WC_Montonio_Data_Sync::sync_payment_methods();
            $payment_methods = json_decode( $payment_methods, true );

            if ( empty( $payment_methods['paymentMethods'][ $this->api_method_key ] ) ) {
                throw new Exception( sprintf(
                    /* translators: %s: payment method title */
                    __( '%s is not active on your Montonio account. Please check your Montonio Partner System settings.', 'montonio-for-woocommerce' ),
                    $this->method_title
                    )
                );
            }
        } catch ( Exception $e ) {
            $message = sprintf(
                /* translators: 1) payment method title 2) error returned by Montonio API */
                __( '<strong>%1$s could not be enabled.</strong><br>Error: %2$s', 'montonio-for-woocommerce' ),
                $this->method_title,
                esc_html( $e->getMessage() )
            );

            $this->add_admin_notice( $message, 'error' );
            $settings['enabled'] = 'no';

            return $settings;
        }

        return $settings;
    }

    /**
     * Add custom CSS class to the gateway icon.
     *
     * @param  string $icon The default icon HTML.
     * @param  string $id   The gateway ID.
     * @return string       Modified icon HTML with added classes.
     */
    public function add_icon_class( $icon, $id = '' ) {
        if ( $id === $this->id ) {
            return str_replace( 'src="', 'class="montonio-payment-method-icon ' . esc_attr( str_replace( '_', '-', $this->id ) . '-icon' ) . '" src="', $icon );
        }

        return $icon;
    }

    /**
     * Process a refund for an order.
     *
     * @param  int    $order_id The ID of the order to refund.
     * @param  float  $amount   The amount to refund (null for full refund).
     * @param  string $reason   The reason for the refund.
     * @return bool             True on success, false on failure.
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        return WC_Montonio_Refund::init_refund(
            $order_id,
            $amount,
            $reason
        );
    }

    /**
     * Render the admin options/settings page.
     *
     * @return void
     */
    public function admin_options() {
        WC_Montonio_Admin_Settings_Page::render_options_page(
            $this->method_title,
            $this->generate_settings_html( array(), false ),
            $this->id
        );
    }

    /**
     * Add an admin notice to be displayed.
     *
     * @param  string $message The notice message content.
     * @param  string $class   The CSS class for the notice (e.g., 'notice-error', 'notice-success').
     * @return void
     */
    public function add_admin_notice( $message, $class ) {
        $this->admin_notices[] = array( 'message' => $message, 'class' => $class );
    }

    /**
     * Display all queued admin notices.
     *
     * @return void
     */
    public function display_admin_notices() {
        foreach ( $this->admin_notices as $notice ) {
            echo '<div class="montonio-notice montonio-notice--' . esc_attr( $notice['class'] ) . ' notice notice-' . esc_attr( $notice['class'] ) . '">';
            echo '	<p>' . wp_kses_post( $notice['message'] ) . '</p>';
            echo '</div>';
        }
    }
}
