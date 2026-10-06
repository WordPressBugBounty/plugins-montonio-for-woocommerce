<?php
defined( 'ABSPATH' ) || exit;

/**
 * Customer-facing withdrawal form: shortcode rendering, request handling
 * and the Order → Review → Confirmation journey.
 *
 * @since 10.4.0
 */
class WC_Montonio_Withdrawal_Frontend {

    /**
     * Nonce action for the withdrawal form.
     *
     * @since 10.4.0
     */
    const NONCE_ACTION = 'montonio_withdrawal_request';

    /**
     * Largest ordered quantity offered as a dropdown; larger ones get a number field.
     *
     * @since 10.4.0
     * @var int
     */
    const QUANTITY_SELECT_MAX = 20;

    /**
     * Sanitized submitted input for re-rendering the form.
     *
     * @var array
     */
    private static $input = array();

    /**
     * Error message to show above the form.
     *
     * @var string
     */
    private static $error = '';

    /**
     * Matched order for the current request.
     *
     * @var WC_Order|false
     */
    private static $order = false;

    /**
     * Signed review token for the current request.
     *
     * @var string
     */
    private static $token = '';

    /**
     * Verified token payload for the review and confirmation stages.
     *
     * @var array|null
     */
    private static $payload = null;

    /**
     * Mail delivery state after a confirmed submission.
     *
     * @var array|null
     */
    private static $result = null;

    /**
     * Current journey stage: order, form, review or success.
     *
     * @var string
     */
    private static $stage = 'order';

    /**
     * Register frontend hooks.
     *
     * @since 10.4.0
     * @return void
     */
    public static function init() {
        add_shortcode( WC_Montonio_Withdrawals::SHORTCODE, array( __CLASS__, 'render' ) );
        add_action( 'init', array( __CLASS__, 'register_style' ) );
        add_action( 'template_redirect', array( __CLASS__, 'handle' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 20 );
        add_filter( 'woocommerce_my_account_my_orders_actions', array( __CLASS__, 'order_action' ), 10, 2 );
    }

    /**
     * Whether the current request is for a page containing the withdrawal form.
     *
     * @since 10.4.0
     * @return bool
     */
    public static function is_page() {
        $post = get_post();

        return is_singular( 'page' ) && $post && WC_Montonio_Withdrawals::page_has_form( $post->post_content );
    }

    /**
     * Register the withdrawal page stylesheet for the frontend and the block editor.
     *
     * block.json declares this handle as the block's style, so it must exist
     * before the editor collects assets. That is the exception to the
     * register-in-enqueue_admin_assets() pattern: an iframed editor copies the
     * registered handles inside _wp_get_iframed_editor_assets(), which
     * get_block_editor_settings() runs before admin-header.php fires
     * admin_enqueue_scripts, so a handle registered there never reaches the
     * iframe. init runs on every request, including WordPress 5.0-5.4 where
     * the block class returns early and only the shortcode uses the style.
     *
     * @since 10.4.0
     * @return void
     */
    public static function register_style() {
        wp_register_style( 'montonio-withdrawal', WC_MONTONIO_PLUGIN_URL . '/assets/css/montonio-withdrawal.css', array(), WC_MONTONIO_PLUGIN_VERSION );
    }

    /**
     * Enqueue the withdrawal page assets: the stylesheet registered here on
     * init and the script registered in montonio.php.
     *
     * @since 10.4.0
     * @return void
     */
    public static function enqueue_assets() {
        if ( ! self::is_page() ) {
            return;
        }

        wp_enqueue_style( 'montonio-withdrawal' );
        wp_enqueue_script( 'montonio-withdrawal' );
    }

    /**
     * Process a withdrawal page request and answer async lookups.
     *
     * @since 10.4.0
     * @return void
     */
    public static function handle() {
        if ( ! self::is_page() ) {
            return;
        }

        self::process( WC_Montonio_Withdrawal_Block::page_settings() );

        if ( self::is_post() && '1' === self::posted( 'montonio_withdrawal_async' ) && in_array( self::posted( 'montonio_withdrawal_action' ), array( 'lookup', 'change' ), true ) ) {
            wp_send_json( array(
                'error' => self::$error,
                'stage' => self::$stage,
                'html'  => self::render()
            ) );
        }
    }

    /**
     * Process the current request into form state.
     *
     * Handles logged-in prefill on GET and order navigation, review, edit
     * and confirmation actions on POST.
     *
     * @since 10.4.0
     * @param array $settings Withdrawal settings.
     * @return void
     */
    private static function process( $settings ) {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }

        nocache_headers();

        if ( ! headers_sent() ) {
            header( 'Referrer-Policy: same-origin' );
        }

        if ( ! WC_Montonio_Withdrawals::is_enabled() || ! $settings['legal_name'] ) {
            return;
        }

        if ( ! self::is_post() ) {
            // A URL order ID alone never grants access to an order, including guest orders.
            $id    = isset( $_GET['order'] ) && is_scalar( $_GET['order'] ) ? absint( $_GET['order'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; the order must belong to the logged-in customer.
            $order = $id && is_user_logged_in() ? wc_get_order( $id ) : false;

            if ( $order && 'shop_order' === $order->get_type() && $order->get_customer_id() === get_current_user_id() ) {
                self::$order = $order;
                self::$stage = 'form';
                self::$input = array(
                    'email'        => $order->get_billing_email(),
                    'order_number' => $order->get_order_number(),
                    'matched_id'   => $id
                );
            }

            return;
        }

        // Sanitised field by field in WC_Montonio_Withdrawal_Request::validate(), after the nonce check below.
        self::$input = isset( $_POST['montonio_withdrawal'] ) && is_array( $_POST['montonio_withdrawal'] ) ? wp_unslash( $_POST['montonio_withdrawal'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        // Presentation state only; matching and submission data are always revalidated.
        self::$stage = 'form' === self::posted( 'montonio_withdrawal_stage' ) ? 'form' : 'order';

        if ( ! wp_verify_nonce( self::posted( '_wpnonce' ), self::NONCE_ACTION ) ) {
            self::$error = __( 'This form session expired. Review your details and try again.', 'montonio-for-woocommerce' );
            self::keep_details();
            return;
        }

        if ( ! empty( self::$input['website'] ) || ! WC_Montonio_Withdrawal_Request::rate_limit( 'form', 30 ) ) {
            self::$error = __( 'We could not process your request. Please try again later or contact the store directly.', 'montonio-for-woocommerce' );
            self::keep_details();
            return;
        }

        $action = self::posted( 'montonio_withdrawal_action' );

        if ( in_array( $action, array( 'review', 'edit' ), true ) ) {
            self::$stage = 'form';
        }

        if ( in_array( $action, array( 'lookup', 'change' ), true ) ) {
            self::$stage = 'order';
            // Item IDs belong to the previous match, not a newly selected purchase.
            self::$input['selected']   = array();
            self::$input['quantities'] = array();
        }

        if ( 'change' === $action ) {
            self::$input['matched_id'] = 0;
            self::$order = false;
            return;
        }

        if ( in_array( $action, array( 'confirm', 'edit' ), true ) ) {
            // Left unsanitised: verify() checks it against its HMAC signature.
            $token   = isset( $_POST['montonio_withdrawal_token'] ) && is_string( $_POST['montonio_withdrawal_token'] ) ? wp_unslash( $_POST['montonio_withdrawal_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $payload = WC_Montonio_Withdrawal_Request::verify( $token );

            if ( ! $payload ) {
                self::$error = __( 'The review expired or changed. Please enter your request again.', 'montonio-for-woocommerce' );
                self::$stage = 'order';
                return;
            }

            self::$token   = $token;
            self::$payload = $payload;
            self::$input   = $payload['data'];

            self::$input['matched_id'] = $payload['data']['order_id'];
            self::$input['selected']   = array_fill_keys( array_column( $payload['data']['items'], 'id' ), '1' );
            self::$input['quantities'] = array_column( $payload['data']['items'], 'quantity', 'id' );

            if ( 'confirm' === $action ) {
                self::$stage = 'review';
                $result      = WC_Montonio_Withdrawal_Request::send( $payload, $settings );

                if ( is_wp_error( $result ) ) {
                    self::$error = $result->get_error_message();
                    return;
                }

                self::$result = $result;
                self::$stage  = 'success';
                return;
            }
        }

        self::$input['matched_id'] = isset( self::$input['matched_id'] ) && is_scalar( self::$input['matched_id'] ) ? absint( self::$input['matched_id'] ) : 0;

        if ( self::$input['matched_id'] || 'lookup' === $action ) {
            self::$order = WC_Montonio_Withdrawal_Request::find_order(
                WC_Montonio_Withdrawal_Request::text( self::$input, 'order_number' ),
                WC_Montonio_Withdrawal_Request::text( self::$input, 'email', 254 ),
                'lookup' === $action ? 0 : absint( self::$input['matched_id'] )
            );

            if ( self::$order ) {
                self::$input['matched_id'] = self::$order->get_id();

                // A matched lookup goes straight to the details step.
                if ( 'lookup' === $action ) {
                    self::$stage = 'form';
                }
            } else {
                self::$input['matched_id'] = 0;
                self::$error               = __( 'We could not match that order number and email. Check both, or contact the store.', 'montonio-for-woocommerce' );

                self::$stage = 'order';

                if ( 'lookup' !== $action ) {
                    return;
                }
            }
        }

        // The details step always belongs to a verified order.
        if ( 'form' === self::$stage && ! self::$order ) {
            self::$error = __( 'The order details changed. Load the order again.', 'montonio-for-woocommerce' );
            self::$stage = 'order';
            return;
        }

        if ( 'review' === $action ) {
            $data = WC_Montonio_Withdrawal_Request::validate( self::$input );

            if ( is_wp_error( $data ) ) {
                self::$error = $data->get_error_message();
                return;
            }

            self::$token   = WC_Montonio_Withdrawal_Request::sign( $data );
            self::$payload = WC_Montonio_Withdrawal_Request::verify( self::$token );

            if ( ! self::$payload ) {
                self::$error = __( 'This order has too many items to withdraw from online. Please contact the store directly.', 'montonio-for-woocommerce' );
                return;
            }

            self::$stage = 'review';
        }
    }

    /**
     * Whether this is a POST request.
     *
     * @since 10.4.0
     * @return bool
     */
    private static function is_post() {
        return isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
    }

    /**
     * One posted text field, unslashed and sanitised.
     *
     * process() verifies the form nonce before any posted value takes effect.
     *
     * @since 10.4.0
     * @param string $key Field name.
     * @return string
     */
    private static function posted( $key ) {
        return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
    }

    /**
     * Keep a rejected details step on screen by reloading its verified order,
     * or fall back to the order step when the order no longer matches.
     *
     * @since 10.4.0
     * @return void
     */
    private static function keep_details() {
        if ( 'form' !== self::$stage ) {
            return;
        }

        $id          = isset( self::$input['matched_id'] ) && is_scalar( self::$input['matched_id'] ) ? absint( self::$input['matched_id'] ) : 0;
        self::$order = $id ? WC_Montonio_Withdrawal_Request::find_order(
            WC_Montonio_Withdrawal_Request::text( self::$input, 'order_number' ),
            WC_Montonio_Withdrawal_Request::text( self::$input, 'email', 254 ),
            $id
        ) : false;

        if ( ! self::$order ) {
            self::$stage = 'order';
        }
    }

    /**
     * Whether an order item is checked for return in the current input.
     *
     * @since 10.4.0
     * @param array $input   Current input values.
     * @param int   $item_id Order item ID.
     * @return bool
     */
    public static function is_selected( $input, $item_id ) {
        return isset( $input['selected'] ) && is_array( $input['selected'] ) && isset( $input['selected'][ $item_id ] ) && in_array( $input['selected'][ $item_id ], array( '1', true ), true );
    }

    /**
     * Count the units checked for return against the order's total units.
     *
     * A checked item counts its posted quantity, kept within the ordered
     * quantity, or its ordered quantity when none was posted.
     *
     * @since 10.4.0
     * @param WC_Order $order Matched order.
     * @param array    $input Current input values.
     * @return array Selected and total unit counts.
     */
    public static function selection_count( $order, $input ) {
        $quantities = isset( $input['quantities'] ) && is_array( $input['quantities'] ) ? $input['quantities'] : array();
        $selected   = 0;
        $total      = 0;

        foreach ( $order->get_items() as $item_id => $item ) {
            $ordered = (int) $item->get_quantity();
            $total  += $ordered;

            if ( self::is_selected( $input, $item_id ) ) {
                $quantity  = isset( $quantities[ $item_id ] ) && is_scalar( $quantities[ $item_id ] ) ? (int) $quantities[ $item_id ] : $ordered;
                $selected += min( max( $quantity, 1 ), $ordered );
            }
        }

        return array( $selected, $total );
    }

    /**
     * Shortcode callback rendering the withdrawal form.
     *
     * @since 10.4.0
     * @return string
     */
    public static function render() {
        return self::render_settings( WC_Montonio_Withdrawal_Block::page_settings() );
    }

    /**
     * Render the withdrawal form with the given settings.
     *
     * Shared by the shortcode and the block render callback; the block passes
     * settings with its sanitized rich-text attributes merged in.
     *
     * @since 10.4.0
     * @param array $settings Withdrawal settings.
     * @return string
     */
    public static function render_settings( $settings ) {
        if ( ! WC_Montonio_Withdrawals::is_enabled() || ! $settings['legal_name'] ) {
            return '<p>' . esc_html__( 'The withdrawal form is not available. Please contact the store directly.', 'montonio-for-woocommerce' ) . '</p>';
        }

        return wc_get_template_html(
            'withdrawal-page.php',
            array(
                'settings' => $settings,
                'input'    => self::$input,
                'error'    => self::$error,
                'order'    => self::$order,
                'stage'    => self::$stage,
                'token'    => self::$token,
                'payload'  => self::$payload,
                'result'   => self::$result
            ),
            '',
            WC_MONTONIO_PLUGIN_PATH . '/templates/'
        );
    }

    /**
     * Output one labelled form field.
     *
     * @since 10.4.0
     * @param array  $input    Current input values.
     * @param string $key      Field key.
     * @param string $label    Field label.
     * @param string $type     Input type, or textarea.
     * @param bool   $required Whether the field is required.
     * @return void
     */
    public static function field( $input, $key, $label, $type = 'text', $required = false ) {
        $value = WC_Montonio_Withdrawal_Request::text( $input, $key, 3000, 'textarea' === $type );

        echo '<div class="montonio-withdrawal-field"><label for="montonio-withdrawal-' . esc_attr( $key ) . '">' . esc_html( $label ) . ( $required ? ' <span aria-hidden="true">*</span>' : '' ) . '</label>';

        $attrs = ' id="montonio-withdrawal-' . esc_attr( $key ) . '" name="montonio_withdrawal[' . esc_attr( $key ) . ']"' . ( $required ? ' required' : '' );

        if ( 'email' === $key ) {
            $attrs .= ' autocomplete="email"';
        }

        if ( 'textarea' === $type ) {
            echo '<textarea maxlength="3000" rows="3"' . $attrs . '>' . esc_textarea( $value ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        } else {
            echo '<input type="' . esc_attr( $type ) . '" maxlength="' . ( 'email' === $key ? '254' : '200' ) . '"' . $attrs . ' value="' . esc_attr( $value ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        echo '</div>';
    }

    /**
     * Add a withdrawal link to My Account order rows.
     *
     * @since 10.4.0
     * @param array    $actions Existing order actions.
     * @param WC_Order $order   Order for the row.
     * @return array
     */
    public static function order_action( $actions, $order ) {
        $page_id = WC_Montonio_Withdrawals::get_page_id();

        if ( WC_Montonio_Withdrawals::is_enabled() && $page_id && 'publish' === get_post_status( $page_id ) ) {
            $actions['montonio_withdraw'] = array(
                'url'  => add_query_arg( 'order', $order->get_id(), get_permalink( $page_id ) ),
                'name' => __( 'Withdraw from a purchase', 'montonio-for-woocommerce' )
            );
        }

        return $actions;
    }
}
