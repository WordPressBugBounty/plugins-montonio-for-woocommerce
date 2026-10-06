<?php
defined( 'ABSPATH' ) || exit;

/**
 * Class for the Montonio Withdrawals module.
 *
 * Publishes a withdrawal-notice page where customers can inform the store
 * that they withdraw from a purchase. Submissions are emailed to the store;
 * the module never issues refunds or modifies orders.
 *
 * @since 10.4.0
 */
class WC_Montonio_Withdrawals {

    /**
     * Meta key marking the page generated and owned by this module.
     *
     * @since 10.4.0
     */
    const PAGE_OWNED_META = '_montonio_withdrawal_owned';

    /**
     * Shortcode tag that renders the withdrawal form.
     *
     * @since 10.4.0
     */
    const SHORTCODE = 'montonio_withdrawal_page';

    /**
     * Singleton instance of the class.
     *
     * @var mixed
     */
    private static $instance;

    /**
     * Get the singleton instance of the class.
     *
     * @since 10.4.0
     * @return self The singleton instance of the class.
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * The constructor for the Montonio Withdrawals class.
     *
     * @since 10.4.0
     */
    protected function __construct() {
        $this->includes();
        $this->register_hooks();
    }

    /**
     * Require the module classes.
     *
     * @since 10.4.0
     * @return void
     */
    private function includes() {
        require_once WC_MONTONIO_PLUGIN_PATH . '/includes/withdrawals/class-wc-montonio-withdrawal-store.php';
        require_once WC_MONTONIO_PLUGIN_PATH . '/includes/withdrawals/class-wc-montonio-withdrawal-admin.php';
        require_once WC_MONTONIO_PLUGIN_PATH . '/includes/withdrawals/class-wc-montonio-withdrawal-request.php';
        require_once WC_MONTONIO_PLUGIN_PATH . '/includes/withdrawals/class-wc-montonio-withdrawal-frontend.php';
        require_once WC_MONTONIO_PLUGIN_PATH . '/blocks/class-wc-montonio-withdrawal-block.php';
    }

    /**
     * Register module hooks.
     *
     * @since 10.4.0
     * @return void
     */
    private function register_hooks() {
        // Runs after WooCommerce has saved the Withdrawals settings tab fields.
        add_action( 'woocommerce_update_options_montonio_withdrawals', array( $this, 'maybe_generate_page' ) );

        WC_Montonio_Withdrawal_Store::init();

        if ( is_admin() ) {
            WC_Montonio_Withdrawal_Admin::init();
        }

        WC_Montonio_Withdrawal_Frontend::init();
        WC_Montonio_Withdrawal_Block::init();
    }

    /**
     * Whether the withdrawal page feature is enabled.
     *
     * @since 10.4.0
     * @return bool
     */
    public static function is_enabled() {
        return 'yes' === get_option( 'montonio_withdrawal_enabled' );
    }

    /**
     * Get the ID of the generated withdrawal page.
     *
     * @since 10.4.0
     * @return int Page ID, or 0 when no page has been generated.
     */
    public static function get_page_id() {
        return (int) get_option( 'montonio_withdrawal_page_id', 0 );
    }

    /**
     * Whether the given post content contains the withdrawal form component.
     *
     * @since 10.4.0
     * @param string $content Post content.
     * @return bool
     */
    public static function page_has_form( $content ) {
        return has_shortcode( (string) $content, self::SHORTCODE ) || has_block( WC_Montonio_Withdrawal_Block::NAME, (string) $content );
    }

    /**
     * Get the content for a newly generated withdrawal page, honoring the
     * page editor format setting.
     *
     * The block is used when explicitly chosen, or automatically when the
     * block editor can insert it; the shortcode is the universal fallback.
     * Existing pages are never converted.
     *
     * @since 10.4.0
     * @return string
     */
    public static function new_page_content() {
        $mode = get_option( 'montonio_withdrawal_page_mode', 'auto' );

        // is_available() includes the registration check.
        if ( ( 'block' === $mode && WC_Montonio_Withdrawal_Block::is_registered() )
            || ( 'auto' === $mode && WC_Montonio_Withdrawal_Block::is_available() ) ) {
            return '<!-- wp:' . WC_Montonio_Withdrawal_Block::NAME . ' /-->';
        }

        return '[' . self::SHORTCODE . ']';
    }

    /**
     * Convert the withdrawal form token on an existing page to the chosen
     * page editor format, leaving the rest of the page as it is.
     *
     * Automatic never converts. Block editor turns a shortcode block or a
     * bare shortcode into the form block when the block is registered;
     * escaped [[...]] shortcodes stay. Classic Editor / shortcode turns
     * every form block into the shortcode and records a settings notice
     * when a block carried page-specific wording, which the shortcode
     * cannot show.
     *
     * @since 10.4.0
     * @param string $content Current post content.
     * @return string Converted content, or the content unchanged.
     */
    private static function convert_page_content( $content ) {
        $mode  = get_option( 'montonio_withdrawal_page_mode', 'auto' );
        $block = '<!-- wp:' . WC_Montonio_Withdrawal_Block::NAME . ' /-->';

        if ( 'block' === $mode && WC_Montonio_Withdrawal_Block::is_registered() && ! has_block( WC_Montonio_Withdrawal_Block::NAME, $content ) ) {
            $content = preg_replace( '/<!--\s+wp:shortcode\s+-->\s*\[' . self::SHORTCODE . '\b[^\]]*\]\s*<!--\s+\/wp:shortcode\s+-->/', $block, $content );
            $content = preg_replace_callback( '/' . get_shortcode_regex( array( self::SHORTCODE ) ) . '/', function ( $match ) use ( $block ) {
                // Groups 1 and 6 are the extra brackets of an escaped [[shortcode]].
                return '[' === $match[1] && ']' === $match[6] ? $match[0] : $match[1] . $block . $match[6];
            }, $content );
        } elseif ( 'shortcode' === $mode && has_block( WC_Montonio_Withdrawal_Block::NAME, $content ) ) {
            $wording = false;
            $content = preg_replace_callback( '/<!--\s+wp:' . preg_quote( WC_Montonio_Withdrawal_Block::NAME, '/' ) . '(?:\s+(\{.*?\}))?\s+\/-->/s', function ( $match ) use ( &$wording ) {
                $attributes = isset( $match[1] ) ? json_decode( $match[1], true ) : null;
                $wording    = $wording || ! empty( $attributes['instructions'] );

                return '[' . self::SHORTCODE . ']';
            }, $content );

            if ( $wording && class_exists( 'WC_Admin_Settings' ) ) {
                WC_Admin_Settings::add_message( 'The page-specific return instructions were removed from the withdrawal page. The shortcode page shows none.' );
            }
        }

        return $content;
    }

    /**
     * Get the withdrawal settings as one array, with suggested defaults
     * for options that have never been saved.
     *
     * @since 10.4.0
     * @return array
     */
    public static function get_settings() {
        $defaults = self::get_defaults();

        return array(
            'legal_name'     => trim( (string) get_option( 'montonio_withdrawal_legal_name', '' ) ),
            'return_address' => get_option( 'montonio_withdrawal_return_address', $defaults['return_address'] ),
            'contact_email'  => get_option( 'montonio_withdrawal_contact_email', $defaults['contact_email'] ),
            'recipient'      => get_option( 'montonio_withdrawal_recipient', $defaults['recipient'] ),
            'instructions'   => ''
        );
    }

    /**
     * Suggested defaults for the withdrawal settings, sourced from the
     * store's WooCommerce and WordPress configuration.
     *
     * @since 10.4.0
     * @return array
     */
    public static function get_defaults() {
        $new_order_settings = get_option( 'woocommerce_new_order_settings', array() );
        $recipient          = self::parse_email_list( isset( $new_order_settings['recipient'] ) ? $new_order_settings['recipient'] : '' );

        $store     = WC()->countries;
        $countries = $store->get_countries();
        $country   = $store->get_base_country();
        $address   = array(
            $store->get_base_address(),
            $store->get_base_address_2(),
            trim( $store->get_base_postcode() . ' ' . $store->get_base_city() ),
            isset( $countries[ $country ] ) ? $countries[ $country ] : $country
        );

        $sender = get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) );

        return array(
            'return_address' => implode( "\n", array_filter( $address ) ),
            'contact_email'  => is_email( $sender ) ? $sender : get_option( 'admin_email' ),
            'recipient'      => is_wp_error( $recipient ) || empty( $recipient ) ? get_option( 'admin_email' ) : implode( ', ', $recipient )
        );
    }

    /**
     * Parse a comma-separated list of email addresses.
     *
     * @since 10.4.0
     * @param mixed $value Raw field value.
     * @return array|WP_Error List of valid addresses, or an error describing the problem.
     */
    public static function parse_email_list( $value ) {
        if ( ! is_string( $value ) || strlen( $value ) > 1000 || preg_match( '/[\r\n]/', $value ) ) {
            return new WP_Error( 'email', 'Use email addresses separated by commas, without names or line breaks.' );
        }

        if ( '' === trim( $value ) ) {
            return array();
        }

        $emails = array_unique( array_map( 'trim', explode( ',', $value ) ) );

        if ( count( $emails ) > 10 ) {
            return new WP_Error( 'email', 'Use no more than 10 addresses in each field.' );
        }

        foreach ( $emails as $email ) {
            if ( ! is_email( $email ) ) {
                return new WP_Error( 'email', 'Enter valid email addresses separated by commas.' );
            }
        }

        return array_values( $emails );
    }

    /**
     * Count published pages and posts that contain the withdrawal form,
     * as a shortcode or as the block. Reported through telemetry.
     *
     * @since 10.4.0
     * @return int
     */
    public static function count_published_instances() {
        global $wpdb;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ( 'page', 'post' ) AND ( post_content LIKE %s OR post_content LIKE %s )",
            '%' . $wpdb->esc_like( '[' . self::SHORTCODE ) . '%',
            '%' . $wpdb->esc_like( '<!-- wp:' . WC_Montonio_Withdrawal_Block::NAME ) . '%'
        ) );
    }

    /**
     * Create the withdrawal page, or bring the existing one in line with the
     * page editor format setting.
     *
     * The page is created once with a default title and address; after that
     * the page editor owns its title, address and status, so translations and
     * renames are never overwritten. Reuses the page recorded in
     * montonio_withdrawal_page_id while it is still a page owned by this
     * module. Errors are surfaced as WooCommerce settings errors.
     *
     * @since 10.4.0
     * @return void
     */
    public function maybe_generate_page() {
        if ( ! self::is_enabled() ) {
            return;
        }

        if ( '' === trim( (string) get_option( 'montonio_withdrawal_legal_name', '' ) ) ) {
            WC_Admin_Settings::add_error( 'Enter the legal entity name — the withdrawal form stays hidden from customers until it is set.' );
        }

        $page_id = self::get_page_id();
        $page    = $page_id ? get_post( $page_id ) : null;

        if ( $page && ( 'page' !== $page->post_type || 'trash' === $page->post_status || ! get_post_meta( $page->ID, self::PAGE_OWNED_META, true ) ) ) {
            WC_Admin_Settings::add_error( 'The generated withdrawal page was removed or replaced. Restore it from Trash before saving, or delete it permanently so a new page can be created.' );
            return;
        }

        if ( ! $page ) {
            if ( ! current_user_can( 'edit_pages' ) || ! current_user_can( 'publish_pages' ) ) {
                WC_Admin_Settings::add_error( 'You do not have permission to create or publish pages.' );
                return;
            }

            $result = wp_insert_post( wp_slash( array(
                'post_title'   => __( 'Withdraw from a purchase', 'montonio-for-woocommerce' ),
                // WordPress moves to the next free address when this one is taken.
                'post_name'    => 'withdrawal',
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_content' => self::new_page_content()
            ) ), true );

            if ( is_wp_error( $result ) || ! $result ) {
                WC_Admin_Settings::add_error( 'WordPress could not save the withdrawal page. Please try again.' );
                return;
            }

            update_post_meta( $result, self::PAGE_OWNED_META, 1 );
            update_option( 'montonio_withdrawal_page_id', $result );
            return;
        }

        if ( ! current_user_can( 'edit_post', $page->ID ) ) {
            WC_Admin_Settings::add_error( 'You do not have permission to edit the generated withdrawal page.' );
            return;
        }

        if ( ! self::page_has_form( $page->post_content ) ) {
            WC_Admin_Settings::add_error( sprintf( 'The generated withdrawal page is missing its withdrawal form. Restore the %s shortcode on the page before saving.', '[' . self::SHORTCODE . ']' ) );
            return;
        }

        $content = self::convert_page_content( $page->post_content );

        if ( $content === $page->post_content ) {
            return;
        }

        if ( ! self::page_has_form( $content ) ) {
            WC_Admin_Settings::add_error( 'The withdrawal page could not be converted to the chosen page editor format, so it was left unchanged.' );
            return;
        }

        if ( is_wp_error( wp_update_post( wp_slash( array( 'ID' => $page->ID, 'post_content' => $content ) ), true ) ) ) {
            WC_Admin_Settings::add_error( 'WordPress could not save the withdrawal page. Please try again.' );
        }
    }
}
