<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Montonio_Display_Admin_Options {
    public static function init() {
        add_action( 'woocommerce_settings_checkout', array( __CLASS__, 'output' ) );
        add_action( 'woocommerce_update_options_checkout', array( __CLASS__, 'save' ) );
    }

    public static function output() {
        global $current_section;
        do_action( 'woocommerce_montonio_settings_checkout_' . $current_section );
    }

    public static function save() {
        if ( ! is_admin() ) {
            return;
        }
        
        global $current_section;

        // Validate current section exists and is a Montonio section
        if ( empty( $current_section ) || ! self::is_montonio_section( $current_section ) ) {
            return;
        }

        if ( ! did_action( 'woocommerce_update_options_checkout_' . $current_section ) ) {
            do_action( 'woocommerce_update_options_checkout_' . $current_section );
        }
    }

    /**
     * Get menu items configuration with caching
     */
    private static function get_menu_items() {
        $menu_items = array(
            'wc_montonio_api'           => array(
                'title'        => __( 'API Settings', 'montonio-for-woocommerce' ),
                'type'         => 'settings',
                'check_status' => false,
                'icon'         => 'dashicons-admin-network'
            ),
            'wc_montonio_payments'      => array(
                'title'        => __( 'Bank Payments', 'montonio-for-woocommerce' ),
                'type'         => 'payment_method',
                'check_status' => true,
                'icon'         => 'dashicons-building'
            ),
            'wc_montonio_card'          => array(
                'title'        => __( 'Card Payments', 'montonio-for-woocommerce' ),
                'type'         => 'payment_method',
                'check_status' => true,
                'icon'         => 'dashicons-credit-card'
            ),
            'wc_montonio_blik'          => array(
                'title'        => __( 'BLIK', 'montonio-for-woocommerce' ),
                'type'         => 'payment_method',
                'check_status' => true,
                'icon'         => 'dashicons-smartphone'
            ),
            'wc_montonio_bnpl'          => array(
                'title'        => __( 'Pay Later', 'montonio-for-woocommerce' ),
                'type'         => 'payment_method',
                'check_status' => true,
                'icon'         => 'dashicons-calendar-alt'
            ),
            'wc_montonio_hire_purchase' => array(
                'title'        => __( 'Financing', 'montonio-for-woocommerce' ),
                'type'         => 'payment_method',
                'check_status' => true,
                'icon'         => 'dashicons-chart-line'
            ),
            'montonio_shipping'         => array(
                'title'        => __( 'Shipping', 'montonio-for-woocommerce' ),
                'type'         => 'shipping',
                'check_status' => true,
                'icon'         => 'dashicons-cart'
            )
        );
        
        return $menu_items;
    }

    public static function montonio_admin_menu( $id = null ) {
        $menu_items = self::get_menu_items();
        ?>

        <div class="montonio-menu">
            <ul class="montonio-menu__list">
                <?php foreach ( $menu_items as $key => $value ) :
                    // Determine URL based on menu item type
                    $url = $key === 'montonio_shipping'
                    ? admin_url( 'admin.php?page=wc-settings&tab=' . $key )
                    : admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . $key );

                    // Check if this is an enabled payment method
                    $is_enabled      = false;
                    $is_sandbox_mode = false;

                    if ( $value['check_status'] ) {
                        if ( $value['type'] === 'payment_method' ) {
                            $settings        = get_option( 'woocommerce_' . $key . '_settings' );
                            $is_enabled      = ( isset( $settings['enabled'] ) && $settings['enabled'] === 'yes' );
                            $is_sandbox_mode = ( isset( $settings['test_mode'] ) && $settings['test_mode'] === 'yes' );
                        } elseif ( $value['type'] === 'shipping' ) {
                            $is_enabled      = ( get_option( 'montonio_shipping_enabled' ) === 'yes' );
                            $is_sandbox_mode = ( get_option( 'montonio_shipping_sandbox_mode' ) === 'yes' );
                        }
                    }
                    ?>

                    <li class="<?php echo $key . ( $id === $key ? ' active' : '' ); ?>">
                        <a href="<?php echo esc_url( $url ); ?>">
                            <?php echo esc_html( $value['title'] ); ?>

                            <?php if ( $is_enabled ): ?>
                                <span class="montonio-status <?php echo $is_sandbox_mode ? 'montonio-status--sandbox' : 'montonio-status--live'; ?>">
                                    <span class="montonio-status__text"><?php echo $is_sandbox_mode ? 'Test mode' : 'Active'; ?></span>
                                </span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    public static function render_banner( $content, $class = '', $icon = null ) {
        ?>
        <div class="<?php echo esc_attr( implode( ' ', array( 'montonio-card', $class, ! empty( $icon ) ? 'montonio-card--icon' : '' ) ) ); ?>">
            <div class="montonio-card__body">
                <?php if ( ! empty( $icon ) ): ?>
                    <span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
                <?php endif; ?>

                <p><?php echo wp_kses_post( $content ); ?></p>
            </div>
        </div>
        <?php
    }

    public static function api_status_banner() {
        $api_settings = get_option( 'woocommerce_wc_montonio_api_settings' );
        ?>

        <div class="montonio-card">
            <div class="montonio-card__body">
                <h4><?php echo esc_html__( 'API keys status', 'montonio-for-woocommerce' ); ?></h4>
                <div class="montonio-api-status">
                    <p><?php echo esc_html__( 'Live keys:', 'montonio-for-woocommerce' ); ?></p>
                    <?php if ( ! empty( $api_settings['access_key'] ) && ! empty( $api_settings['secret_key'] ) ) {
                        echo '<div><span class="api-status api-status--green">Added</span></div>';
                    } else {
                        echo '<div><span class="api-status api-status--red">Not Added</span></div>';
                    }?>

                    <p><?php echo esc_html__( 'Sandbox keys:', 'montonio-for-woocommerce' ); ?></p>
                    <?php if ( ! empty( $api_settings['sandbox_access_key'] ) && ! empty( $api_settings['sandbox_secret_key'] ) ) {
                        echo '<div><span class="api-status api-status--green">Added</span></div>';
                    } else {
                        echo '<div><span class="api-status">Not Added</span></div>';
                    }?>
                </div>
            </div>

            <div class="montonio-card__footer">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_montonio_api' ) ); ?>" class="montonio-button montonio-button--secondary"><?php echo esc_html__( 'Edit account keys', 'montonio-for-woocommerce' ); ?></a>
            </div>
        </div>
        <?php
    }

    public static function display_options( $title, $settings, $id, $sandbox_mode = 'no' ) {
        $banners = array();

        if ( 'yes' === $sandbox_mode ) {
            $banners[] = array(
                'content' => sprintf(
                    '<strong>%s</strong><br>%s',
                    __( 'TEST MODE ENABLED!', 'montonio-for-woocommerce' ),
                    __( 'Test mode is for integration testing only. Payments are not processed.', 'montonio-for-woocommerce' )
                ),
                'class'   => 'montonio-card--notice montonio-card--yellow',
                'icon'    => null
            );
        }

        if ( 'montonio_shipping' === $id ) {
            $banners[] = array(
                /* translators: help article url */
                'content' => sprintf( __( 'Follow these instructions to set up shipping: <a href="%s" target="_blank">How to set up Shipping solution</a>', 'montonio-for-woocommerce' ), 'https://help.montonio.com/en/articles/57066-how-to-set-up-shipping-solution' ),
                'class'   => 'montonio-card--notice montonio-card--blue',
                'icon'    => 'dashicons-info-outline'
            );
        } else {
            $banners[] = array(
                /* translators: help article url */
                'content' => sprintf( __( 'Follow these instructions to set up payment methods: <a href="%s" target="_blank">Activating Payment Methods in WooCommerce</a>', 'montonio-for-woocommerce' ), 'https://help.montonio.com/en/articles/68142-activating-payment-methods-in-woocommerce' ),
                'class'   => 'montonio-card--notice montonio-card--blue',
                'icon'    => 'dashicons-info-outline'
            );
        }
        ?>

        <h2 class="montonio-options-title">
            <?php echo esc_html( $title ); ?>
            <small class="wc-admin-breadcrumb">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ); ?>" aria-label="Return to payments">⤴</a>
            </small>
        </h2>

        <div class="montonio-options <?php echo esc_attr( $id ); ?>">
            <div class="montonio-options__container">
                <div class="montonio-header">
                    <img class="montonio-logo" src="<?php echo esc_url( WC_MONTONIO_PLUGIN_URL . '/assets/images/montonio-logo.svg' ); ?>" alt="Montonio logo" />

                    <?php self::montonio_admin_menu( $id ); ?>
                </div>

                <div class="montonio-options__content">
                    <?php 
                    if ( ! empty( $banners ) ) {
                        foreach ( $banners as $banner ) {
                            self::render_banner( $banner['content'], $banner['class'], $banner['icon'] );
                        }
                    }

                    if ( 'wc_montonio_api' !== $id ) {
                        self::api_status_banner();
                    }
                    ?>

                    <div class="montonio-card">
                        <div class="montonio-card__body">
                            <table class="form-table">
                                <?php echo $settings; ?>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php
    }

    private static function is_montonio_section( $section ) {
        $montonio_sections = array_keys( self::get_menu_items() );
        return in_array( $section, $montonio_sections, true );
    }
}
WC_Montonio_Display_Admin_Options::init();
