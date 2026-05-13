<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles the display of Montonio admin options pages.
 *
 * @since 7.0.0
 */
class WC_Montonio_Admin_Settings_Page {

    /**
     * Initialize hooks.
     *
     * @since 7.0.0
     * @return void
     */
    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'maybe_restrict_gateway_settings' ) );
    }

    /**
     * Set up gateway restrictions and filters.
     *
     * Registers filters for card gateway requirements and restricts
     * gateway settings when API keys are not configured.
     * Hooked on admin_init to run before WooCommerce loads gateway settings.
     *
     * @since 9.3.0
     * @return void
     */
    public static function maybe_restrict_gateway_settings() {
        add_filter( 'woocommerce_settings_api_form_fields_wc_montonio_card', array( __CLASS__, 'maybe_disable_card_gateway_toggle_when_required' ) );
        add_filter( 'woocommerce_settings_api_sanitized_fields_wc_montonio_card', array( __CLASS__, 'maybe_override_card_enabled_when_required' ) );

        if ( ! isset( $_GET['page'], $_GET['tab'], $_GET['section'] ) ) {
            return;
        }

        if ( 'wc-settings' !== $_GET['page'] || 'checkout' !== $_GET['tab'] ) {
            return;
        }

        $current_section = sanitize_text_field( wp_unslash( $_GET['section'] ) );

        // Skip if not a Montonio section or if it's the API settings page
        if ( ! self::is_montonio_section( $current_section ) ) {
            return;
        }

        // API settings page doesn't need gateway restrictions
        if ( 'wc_montonio_api' === $current_section ) {
            return;
        }

        if ( WC_Montonio_Helper::has_api_keys() ) {
            return;
        }

        add_filter( 'option_woocommerce_' . $current_section . '_settings', array( __CLASS__, 'override_gateway_enabled_for_missing_keys' ) );
        add_filter( 'woocommerce_settings_api_form_fields_' . $current_section, array( __CLASS__, 'disable_gateway_toggle_for_missing_keys' ) );
    }

    /**
     * Disable the gateway toggle when API keys are missing.
     *
     * @since 9.3.0
     * @param array $fields Gateway settings fields.
     * @return array Modified fields.
     */
    public static function disable_gateway_toggle_for_missing_keys( $fields ) {
        if ( ! isset( $fields['enabled'] ) ) {
            return $fields;
        }

        $mode    = WC_Montonio_Helper::is_test_mode() ? __( 'Sandbox', 'montonio-for-woocommerce' ) : __( 'Live', 'montonio-for-woocommerce' );
        $message = sprintf(
            /* translators: %1$s: mode (Sandbox/Live), %2$s: URL to API settings */
            __( '%1$s API keys missing. Please <a href="%2$s">add API keys here</a> to enable this payment method.', 'montonio-for-woocommerce' ),
            $mode,
            admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_montonio_api' )
        );

        $fields['enabled']['custom_attributes'] = array( 'disabled' => 'disabled' );
        $fields['enabled']['description']       = '<div class="montonio-disabled-reason">' . $message . '</div>';

        return $fields;
    }

    /**
     * Force gateway to be disabled when API keys are missing.
     *
     * @since 9.3.0
     * @param mixed $settings Gateway settings.
     * @return array Modified settings with enabled set to 'no'.
     */
    public static function override_gateway_enabled_for_missing_keys( $settings ) {
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        $settings['enabled'] = 'no';

        return $settings;
    }

    /**
     * Disable the gateway toggle when card payments are required by subscription plan.
     *
     * @since 9.3.0
     * @param array $fields Gateway settings fields.
     * @return array Modified fields.
     */
    public static function maybe_disable_card_gateway_toggle_when_required( $fields ) {
        if ( ! WC_Montonio_Helper::is_card_payment_required() || ! isset( $fields['enabled'] ) ) {
            return $fields;
        }

        $message = sprintf(
            /* translators: 1: Opening anchor tag 2: Closing anchor tag */
            __( 'You can\'t disable this payment method because your %1$spricing plan%2$s requires card payments when any other Montonio payment method is enabled.', 'montonio-for-woocommerce' ),
            '<a href="https://www.montonio.com/pricing" target="_blank">',
            '</a>'
        );

        $fields['enabled']['custom_attributes'] = array( 'checked' => 'checked', 'disabled' => 'disabled' );
        $fields['enabled']['description']       = '<div class="montonio-disabled-reason">' . $message . '</div>';

        return $fields;
    }

    /**
     * Force card payments to be enabled when saving settings if required by subscription plan.
     *
     * @since 9.3.3
     * @param array $settings Sanitized settings before save.
     * @return array Modified settings.
     */
    public static function maybe_override_card_enabled_when_required( $settings ) {
        if ( WC_Montonio_Helper::is_card_payment_required() ) {
            $settings['enabled'] = 'yes';
        }

        return $settings;
    }

    /**
     * Get menu items configuration.
     *
     * @since 7.0.0
     * @return array Menu items with their configuration.
     */
    private static function get_menu_items() {
        return array(
            'wc_montonio_api'           => array(
                'title'        => __( 'Main Settings', 'montonio-for-woocommerce' ),
                'type'         => 'settings',
                'check_status' => false,
            ),
            'wc_montonio_payments'      => array(
                'title'        => __( 'Bank Payments', 'montonio-for-woocommerce' ),
                'type'         => 'payment_method',
                'check_status' => true,
            ),
            'wc_montonio_card'          => array(
                'title'        => __( 'Card Payments', 'montonio-for-woocommerce' ),
                'type'         => 'payment_method',
                'check_status' => true,
            ),
            'wc_montonio_blik'          => array(
                'title'        => __( 'BLIK', 'montonio-for-woocommerce' ),
                'type'         => 'payment_method',
                'check_status' => true,
            ),
            'wc_montonio_bnpl'          => array(
                'title'        => __( 'Pay Later', 'montonio-for-woocommerce' ),
                'type'         => 'payment_method',
                'check_status' => true,
            ),
            'wc_montonio_hire_purchase' => array(
                'title'        => __( 'Financing', 'montonio-for-woocommerce' ),
                'type'         => 'payment_method',
                'check_status' => true,
            ),
            'montonio_shipping'         => array(
                'title'        => __( 'Shipping', 'montonio-for-woocommerce' ),
                'type'         => 'shipping',
                'check_status' => true,
            )
        );
    }

    /**
     * Render the Montonio admin navigation menu.
     *
     * @since 7.0.0
     * @param string|null $id Current section ID to highlight as active.
     * @return void
     */
    public static function render_admin_menu( $id = null ) {
        $menu_items = self::get_menu_items();
        ?>
        <div class="montonio-menu">
            <ul class="montonio-menu__list">
                <?php foreach ( $menu_items as $section => $value ):
                    $url = 'montonio_shipping' === $section
                    ? admin_url( 'admin.php?page=wc-settings&tab=' . $section )
                    : admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . $section );

                    $is_enabled   = false;
                    $is_test_mode = WC_Montonio_Helper::is_test_mode();

                    if ( WC_Montonio_Helper::has_api_keys() && $value['check_status'] ) {
                        if ( 'payment_method' === $value['type'] ) {
                            $settings   = get_option( 'woocommerce_' . $section . '_settings' );
                            $is_enabled = ( isset( $settings['enabled'] ) && 'yes' === $settings['enabled'] );

                            if ( 'wc_montonio_card' === $section && WC_Montonio_Helper::is_card_payment_required() ) {
                                $is_enabled = true;
                            }
                        } elseif ( 'shipping' === $value['type'] ) {
                            $is_enabled = ( 'yes' === get_option( 'montonio_shipping_enabled' ) );
                        }
                    }
                    ?>
                <li class="<?php echo esc_attr( $section . ( $id === $section ? ' active' : '' ) ); ?>">
                    <a href="<?php echo esc_url( $url ); ?>">
                        <?php echo esc_html( $value['title'] ); ?>

                        <?php if ( $is_enabled ): ?>
                            <span class="montonio-badge <?php echo esc_attr( $is_test_mode ? 'montonio-badge--warning' : 'montonio-badge--success' ); ?>">
                                <span class="montonio-badge__text"><?php echo esc_html( $is_test_mode ? 'Test mode' : 'Active' ); ?></span>
                            </span>
                        <?php endif; ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /**
     * Render a banner/notice card.
     *
     * @since 7.0.0
     * @param string      $content Banner content (HTML allowed).
     * @param string      $class   Additional CSS classes.
     * @param string|null $icon    Dashicon class name.
     * @return void
     */
    public static function render_banner( $content, $class = '', $icon = null ) {
        ?>
        <div class="<?php echo esc_attr( trim( 'montonio-card ' . $class ) ); ?>">
            <div class="montonio-card__body">
                <?php if ( ! empty( $icon ) ): ?>
                    <div class="montonio-card__icon" style="--icon-url: url('<?php echo esc_url( WC_MONTONIO_PLUGIN_URL . '/assets/images/' . $icon . '.svg'); ?>');"></div>
                <?php endif; ?>
                <div class="montonio-card__content"><?php echo wp_kses_post( $content ); ?></div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the API status banner showing key configuration status.
     *
     * @since 7.0.0
     * @return void
     */
    public static function render_api_status_banner( $id = null ) {
        ?>
        <?php if ( WC_Montonio_Helper::has_api_keys() ) : ?>
            <div class="montonio-card montonio-card--api-status">
                <div class="montonio-card__body">
                        <?php $store_details = WC_Montonio_Helper::get_store_details(); ?>
                        <?php if ( ! empty( $store_details ) ): ?>
                            <div class="montonio-store-details">
                                <div>
                                    <div class="store-name-container">
                                        <a href="https://partner.montonio.com/stores/<?php echo esc_attr( $store_details['uuid'] ); ?>" class="store-name" target="_blank" title="<?php esc_html_e( 'Open Store in Montonio Partner System', 'montonio-for-woocommerce' ); ?>"><?php echo esc_html( $store_details['name'] ); ?></a>
                                        <span class="montonio-badge montonio-badge--success"><?php esc_html_e( 'Connected', 'montonio-for-woocommerce' ); ?></span>
                                     </div>
                                    <div class="store-uuid">UUID: <?php echo esc_attr( $store_details['uuid'] ); ?></div>
                                </div>
                                <div>
                                    <a href="<?php echo esc_url( Montonio_Connection::get_disconnect_url() ); ?>" class="montonio-button montonio-button--secondary"><?php esc_html_e( 'Disconnect', 'montonio-for-woocommerce' ); ?></a>
                                </div>
                            </div>
                        <?php endif; ?>
                    
                </div>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Render the full options page layout.
     *
     * @since 7.0.0
     * @param string $title    Page title.
     * @param string $settings Settings HTML content.
     * @param string $id       Current section ID.
     * @return void
     */
    public static function render_options_page( $title, $settings, $id ) {
        $banners = array();

        if ( WC_Montonio_Helper::is_test_mode() ) {
            $banners[] = array(
                'content' => sprintf(
                    '<strong>%s</strong><br>%s',
                    __( 'Test mode enabled!', 'montonio-for-woocommerce' ),
                    __( 'Test mode is for integration testing only. Payments are not processed.', 'montonio-for-woocommerce' )
                ),
                'class'   => 'montonio-card--notice montonio-card--yellow',
                'icon'    => null
            );
        }

        if ( 'montonio_shipping' === $id ) {
            $banners[] = array(
                'content' => sprintf(
                    /* translators: %s: help article URL */
                    __( 'Follow these instructions to set up shipping: <a href="%s" target="_blank">How to set up Shipping solution</a>', 'montonio-for-woocommerce' ),
                    'https://help.montonio.com/en/articles/57066-how-to-set-up-shipping-solution'
                ),
                'class'   => 'montonio-card--notice montonio-card--purple montonio-card--icon',
                'icon'    => 'info-circle'
            );
        } else {
            $banners[] = array(
                'content' => sprintf(
                    /* translators: %s: help article URL */
                    __( 'Follow these instructions to set up payment methods: <a href="%s" target="_blank">Activating payment methods</a>', 'montonio-for-woocommerce' ),
                    'https://help.montonio.com/en/articles/68142-activating-payment-methods-in-woocommerce'
                ),
                'class'   => 'montonio-card--notice montonio-card--purple montonio-card--icon',
                'icon'    => 'info-circle'
            );
        }
        ?>
        <h2 class="montonio-options-title">
            <?php echo esc_html( $title ); ?>
            <small class="wc-admin-breadcrumb">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ); ?>" aria-label="<?php esc_attr_e( 'Return to payments', 'montonio-for-woocommerce' ); ?>">⤴</a>
            </small>
        </h2>

        <div class="montonio-options <?php echo esc_attr( $id ); ?>">
            <div class="montonio-options__container">
                <div class="montonio-sidebar">
                    <img class="montonio-logo" src="<?php echo esc_url( WC_MONTONIO_PLUGIN_URL . '/assets/images/montonio-logo.svg' ); ?>" alt="<?php esc_attr_e( 'Montonio logo', 'montonio-for-woocommerce' ); ?>" />

                    <?php self::render_admin_menu( $id ); ?>
                </div>

                <div class="montonio-options__content">
                    <?php
                    foreach ( $banners as $banner ) {
                        self::render_banner( $banner['content'], $banner['class'], $banner['icon'] );
                    }

                    self::render_api_status_banner( $id );
                    ?>

                    <div class="montonio-card montonio-card--settings montonio-card--border">
                        <div class="montonio-card__body">
                            <table class="form-table">
                                <?php echo $settings; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Check if a section belongs to Montonio.
     *
     * @since 7.0.0
     * @param string $section Section ID to check.
     * @return bool True if it's a Montonio section.
     */
    private static function is_montonio_section( $section ) {
        $montonio_sections = array_keys( self::get_menu_items() );
        return in_array( $section, $montonio_sections, true );
    }
}