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
        global $current_section;
        if ( $current_section && ! did_action( 'woocommerce_update_options_checkout_' . $current_section ) ) {
            do_action( 'woocommerce_update_options_checkout_' . $current_section );
        }
    }

    public static function montonio_admin_menu( $id = null ) {
        $installed_payment_methods = WC()->payment_gateways()->payment_gateways();

        $menu_items = array(
            'wc_montonio_api'           => array(
                'title'        => 'API Settings',
                'type'         => 'settings',
                'check_status' => false
            ),
            'wc_montonio_payments'      => array(
                'title'        => 'Bank Payments',
                'type'         => 'payment_method',
                'check_status' => true
            ),
            'wc_montonio_card'          => array(
                'title'        => 'Card Payments',
                'type'         => 'payment_method',
                'check_status' => true
            ),
            'wc_montonio_blik'          => array(
                'title'        => 'BLIK',
                'type'         => 'payment_method',
                'check_status' => true
            ),
            'wc_montonio_bnpl'          => array(
                'title'        => 'Pay Later',
                'type'         => 'payment_method',
                'check_status' => true
            ),
            'wc_montonio_hire_purchase' => array(
                'title'        => 'Financing',
                'type'         => 'payment_method',
                'check_status' => true
            ),
            'montonio_shipping'         => array(
                'title'        => 'Shipping',
                'type'         => 'settings',
                'check_status' => false
            )
        );
        ?>

        <div class="montonio-menu">
            <ul>
                <?php foreach ( $menu_items as $key => $value ): ?>
                    <li <?php echo $id == $key ? 'class="active"' : ''; ?>>
                        <?php
                        $url = $key == 'montonio_shipping'
                            ? admin_url( 'admin.php?page=wc-settings&tab=' . $key )
                            : admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . $key );
                        ?>
                        <a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $value['title'] ); ?></a>

                        <?php if ( $value['check_status'] && $value['type'] == 'payment_method' ) :
                            $settings = $installed_payment_methods[$key]->settings;

                            if ( $settings['enabled'] == 'yes' && $settings['sandbox_mode'] == 'yes' ) : ?>
	                            <span class="montonio-status montonio-status--sandbox"></span>
	                        <?php endif;?>
                        <?php endif;?>
                    </li>
                <?php endforeach;?>
            </ul>
        </div>
        <?php
    }

    public static function render_banner( $content, $class = '', $icon = null ) {
        ?>
        <div class="<?php echo esc_attr( implode( ' ', array( 'montonio-card', $class, ! empty( $icon ) ? 'montonio-card--icon' : '' ) ) ); ?>">
            <div class="montonio-card__body">
                <?php if ( ! empty( $icon ) ) : ?>
                    <span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
                <?php endif;?>

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
                        echo '<span class="api-status--green"><span class="dashicons dashicons-yes-alt"></span>Added</span>';
                    } else {
                        echo '<span class="api-status--gray"><span class="dashicons dashicons-warning"></span>Not Added</span>';
                    }?>

                    <p><?php echo esc_html__( 'Sandbox keys:', 'montonio-for-woocommerce' ); ?></p>
                    <?php if ( ! empty( $api_settings['sandbox_access_key'] ) && ! empty( $api_settings['sandbox_secret_key'] ) ) {
                        echo '<span class="api-status--green"><span class="dashicons dashicons-yes-alt"></span>Added</span>';
                    } else {
                        echo '<span class="api-status--gray"><span class="dashicons dashicons-warning"></span>Not Added</span>';
                    }?>
                </div>
            </div>

            <div class="montonio-card__footer">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_montonio_api' ) ); ?>" class="components-button is-secondary"><?php echo esc_html__( 'Edit account keys', 'montonio-for-woocommerce' ); ?></a>
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
                'class'   => 'montonio-card--yellow',
                'icon'    => null
            );
        }

        if ( 'montonio_shipping' === $id ) {
            $banners[] = array(
                /* translators: help article url */
                'content' => sprintf( __( 'Follow these instructions to set up shipping: <a href="%s" target="_blank">How to set up Shipping solution</a>', 'montonio-for-woocommerce' ), 'https://help.montonio.com/en/articles/57066-how-to-set-up-shipping-solution' ),
                'class'   => null,
                'icon'    => 'dashicons-info-outline'
            );
        } else {
            $banners[] = array(
                /* translators: help article url */
                'content' => sprintf( __( 'Follow these instructions to set up payment methods: <a href="%s" target="_blank">Activating Payment Methods in WooCommerce</a>', 'montonio-for-woocommerce' ), 'https://help.montonio.com/en/articles/68142-activating-payment-methods-in-woocommerce' ),
                'class'   => null,
                'icon'    => 'dashicons-info-outline'
            );
        }
        ?>

        <h2>
            <?php echo esc_html( $title ); ?>
            <small class="wc-admin-breadcrumb">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ); ?>" aria-label="Return to payments">⤴</a>
            </small>
        </h2>

        <div class="montonio-options-wrapper <?php echo esc_attr( $id ); ?>">
            <img class="montonio-logo" src="<?php echo esc_url( WC_MONTONIO_PLUGIN_URL . '/assets/images/montonio-logo.svg' ); ?>" alt="Montonio logo" />

            <?php
            self::montonio_admin_menu( $id );

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

        <?php
    }
}
WC_Montonio_Display_Admin_Options::init();
