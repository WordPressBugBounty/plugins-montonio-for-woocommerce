<?php
/**
 * Plugin Name:       Montonio for WooCommerce
 * Plugin URI:        https://www.montonio.com
 * Description:       All-in-one plug & play checkout solution
 * Version:           9.3.6
 * Author:            Montonio
 * Author URI:        https://www.montonio.com
 * Text Domain:       montonio-for-woocommerce
 * Domain Path:       /languages
 * License:           GPL version 3 or later
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.html
 *
 * Requires Plugins: woocommerce
 * WC requires at least: 4.0.0
 * WC tested up to: 10.4.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WC_MONTONIO_PLUGIN_VERSION', '9.3.6' );
define( 'WC_MONTONIO_PLUGIN_URL', plugins_url( '', __FILE__ ) );
define( 'WC_MONTONIO_PLUGIN_PATH', dirname( __FILE__ ) );
define( 'WC_MONTONIO_PLUGIN_FILE', __FILE__ );
define( 'WC_MONTONIO_DIR', __DIR__ );

if ( ! class_exists( 'Montonio' ) ) {
    class Montonio {

        /**
         * Singleton instance of the class.
         *
         * @var mixed
         */
        private static $instance;

        /**
         * Get the singleton instance of the class.
         *
         * @return self The singleton instance of the class.
         */
        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        /**
         * Array to hold admin notices.
         *
         * @var array
         */
        protected $admin_notices = array();

        /**
         * Montonio constructor.
         *
         * @return void
         */
        public function __construct() {
            add_action( 'plugins_loaded', array( $this, 'init' ) );
            add_action( 'before_woocommerce_init', array( $this, 'load_textdomain' ) );
            add_action( 'woocommerce_init', array( $this, 'init_api_settings' ) );
            add_action( 'admin_notices', array( $this, 'display_admin_notices' ), 9999 );
        }

        /**
         * Initialize the plugin.
         *
         * @return void
         */
        public function init() {
            if ( ! class_exists( 'WooCommerce' ) ) {
                /* translators: link to WooCommerce plugin page */
                $message = sprintf( esc_html__( 'Montonio for WooCommerce requires WooCommerce to be installed and active. You can download %s here.', 'montonio-for-woocommerce' ), '<a href="https://wordpress.org/plugins/woocommerce/" target="_blank">WooCommerce</a>' );
                $this->add_admin_notice( $message, 'error' );
                return;
            }

            $version = get_option( 'wc_montonio_plugin_version', '0' );

            if ( version_compare( $version, '9.1.4', '<' ) ) {
                if ( version_compare( $version, '6.4.2', '<=' ) ) {
                    require_once WC_MONTONIO_PLUGIN_PATH . '/includes/migrations/montonio-migration-6.4.2.php';
                }

                require_once WC_MONTONIO_PLUGIN_PATH . '/includes/migrations/montonio-migration-9.1.4.php';
            }

            update_option( 'wc_montonio_plugin_version', WC_MONTONIO_PLUGIN_VERSION );

            // TODO: We should not rely on an external JWT library, but must definitely use our own.
            if ( ! class_exists( 'JWT' ) ) {
                require_once WC_MONTONIO_PLUGIN_PATH . '/lib/jwt/JWT.php';
            }

            require_once WC_MONTONIO_PLUGIN_PATH . '/lib/class-montonio-singleton.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/lib/class-montonio-lock-manager.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/class-wc-montonio-logger.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/class-wc-montonio-helper.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/payment/class-wc-montonio-api.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/payment/class-wc-montonio-callbacks.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/payment/class-wc-montonio-refund.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/payment/payment-methods/class-wc-montonio-payments.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/payment/payment-methods/class-wc-montonio-card.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/payment/payment-methods/class-wc-montonio-blik.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/payment/payment-methods/class-wc-montonio-bnpl.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/payment/payment-methods/class-wc-montonio-hire-purchase.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/payment/class-wc-montonio-inbank-calculator.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/payment/class-wc-montonio-inline-checkout.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/admin/class-wc-montonio-admin-settings-page.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/admin/class-wc-montonio-data-sync.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/admin/class-wc-montonio-banners.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/admin/class-wc-montonio-telemetry-service.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/admin/class-montonio-ota-updates.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/class-wc-montonio-shipping.php';
            require_once WC_MONTONIO_PLUGIN_PATH . '/blocks/class-wc-montonio-blocks-manager.php';

            if ( get_option( 'montonio_shipping_enabled' ) === 'yes' ) {
                require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/class-montonio-shipping-method.php';

                require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/dpd/class-montonio-dpd-parcel-machines.php';
                require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/dpd/class-montonio-dpd-parcel-shops.php';
                require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/dpd/class-montonio-dpd-courier.php';
                require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/smartpost/class-montonio-smartpost-parcel-machines.php';
                require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/smartpost/class-montonio-smartpost-post-offices.php';
                require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/smartpost/class-montonio-smartpost-courier.php';
                require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/omniva/class-montonio-omniva-parcel-machines.php';
                require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/omniva/class-montonio-omniva-post-offices.php';
                require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/omniva/class-montonio-omniva-courier.php';
                require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/venipak/class-montonio-venipak-parcel-machines.php';
                require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/venipak/class-montonio-venipak-parcel-shops.php';
                require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/venipak/class-montonio-venipak-courier.php';
                require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/unisend/class-montonio-unisend-parcel-machines.php';
                require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/unisend/class-montonio-unisend-courier.php';
                require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/latvian-post/class-montonio-latvian-post-parcel-machines.php';
                require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/latvian-post/class-montonio-latvian-post-courier.php';
                require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/inpost/class-montonio-inpost-parcel-machines.php';
                require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/orlen/class-montonio-orlen-parcel-machines.php';
                require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/montonio-international-shipping/class-montonio-international-shipping-pickup-points.php';
                require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/montonio-international-shipping/class-montonio-international-shipping-courier.php';

                add_filter( 'woocommerce_shipping_methods', array( $this, 'add_shipping_methods' ), 5 );
            }

            add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
            add_action( 'admin_notices', array( $this, 'live_api_keys_notice' ) );
            add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
            add_filter( 'woocommerce_payment_gateways', array( $this, 'add_payment_methods' ) );
            add_filter( 'woocommerce_get_settings_pages', array( $this, 'add_settings_pages' ) );
            add_filter( 'wc_montonio_merchant_reference', array( $this, 'modify_merchant_references' ), 9, 2 );
            add_filter( 'wc_montonio_merchant_reference_display', array( $this, 'modify_merchant_references' ), 9, 2 );
        }

        /**
         * Add custom payment methods to WooCommerce.
         *
         * @param array $methods The existing payment methods.
         * @return array The updated array of payment methods.
         */
        public function add_payment_methods( $methods ) {
            $methods[] = 'WC_Montonio_Card';
            $methods[] = 'WC_Montonio_Payments';
            $methods[] = 'WC_Montonio_Blik';
            $methods[] = 'WC_Montonio_BNPL';
            $methods[] = 'WC_Montonio_Hire_Purchase';

            return $methods;
        }

        /**
         * Modify merchant references to be sent to the Montonio API.
         *
         * @since 7.0.1
         * @param string $order_id_or_number The original order ID or order number, depending on the context.
         * @param WC_Order $order The order object.
         * @return string The modified merchant reference to be sent to the Montonio API.
         */
        public function modify_merchant_references( $order_id_or_number, $order ) {
            $api_settings = get_option( 'woocommerce_wc_montonio_api_settings' );
            $type         = $api_settings['merchant_reference_type'] ?? '';

            if ( $type === 'order_number' ) {
                return $order->get_order_number();
            }

            if ( $type === 'add_prefix' && ! empty( $api_settings['order_prefix'] ) ) {
                return $api_settings['order_prefix'] . '-' . $order_id_or_number;
            }

            return $order_id_or_number;
        }

        /**
         * Add custom shipping methods to WooCommerce.
         *
         * @param array $methods The existing shipping methods.
         * @return array The updated array of shipping methods.
         */
        public function add_shipping_methods( $methods ) {
            $methods['montonio_international_shipping_pickup_points'] = 'Montonio_International_Shipping_Pickup_Points';
            $methods['montonio_international_shipping_courier']       = 'Montonio_International_Shipping_Courier';
            $methods['montonio_omniva_parcel_machines']               = 'Montonio_Omniva_Parcel_Machines';
            $methods['montonio_omniva_post_offices']                  = 'Montonio_Omniva_Post_Offices';
            $methods['montonio_omniva_courier']                       = 'Montonio_Omniva_Courier';
            $methods['montonio_dpd_parcel_machines']                  = 'Montonio_DPD_Parcel_Machines';
            $methods['montonio_dpd_parcel_shops']                     = 'Montonio_DPD_Parcel_Shops';
            $methods['montonio_dpd_courier']                          = 'Montonio_DPD_Courier';
            $methods['montonio_venipak_parcel_machines']              = 'Montonio_Venipak_Parcel_Machines';
            $methods['montonio_venipak_post_offices']                 = 'Montonio_Venipak_Parcel_Shops';
            $methods['montonio_venipak_courier']                      = 'Montonio_Venipak_Courier';
            $methods['montonio_itella_parcel_machines']               = 'Montonio_Smartpost_Parcel_Machines';
            $methods['montonio_itella_post_offices']                  = 'Montonio_Smartpost_Post_Offices';
            $methods['montonio_itella_courier']                       = 'Montonio_Smartpost_Courier';
            $methods['montonio_unisend_parcel_machines']              = 'Montonio_Unisend_Parcel_Machines';
            $methods['montonio_unisend_courier']                      = 'Montonio_Unisend_Courier';
            $methods['montonio_latvian_post_parcel_machines']         = 'Montonio_Latvian_Post_Parcel_Machines';
            $methods['montonio_latvian_post_courier']                 = 'Montonio_Latvian_Post_Courier';
            $methods['montonio_inpost_parcel_machines']               = 'Montonio_Inpost_Parcel_Machines';
            $methods['montonio_orlen_parcel_machines']                = 'Montonio_Orlen_Parcel_Machines';

            return $methods;
        }

        /**
         * Add custom action links to the plugin's entry in the plugins list.
         *
         * @param array $links An array of the existing plugin action links.
         * @return array The updated array of plugin action links.
         */
        public function plugin_action_links( $links ) {
            $setting_link = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_montonio_api' );

            $plugin_links = array(
                '<a href="' . $setting_link . '">' . __( 'Settings', 'montonio-for-woocommerce' ) . '</a>'
            );

            return array_merge( $plugin_links, $links );
        }

        /**
         * Add custom settings pages to WooCommerce.
         *
         * @param array $settings The existing WooCommerce settings pages.
         * @return array The updated array of WooCommerce settings pages.
         */
        public function add_settings_pages( $settings ) {
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/class-wc-montonio-shipping-settings.php';

            $settings[] = new WC_Montonio_Shipping_Settings();
            return $settings;
        }

        /**
         * Register and enqueue frontend assets.
         *
         * Handles stylesheets and scripts for checkout, payment methods,
         * and shipping pickup points on the storefront.
         *
         * @return void
         */
        public function enqueue_frontend_assets() {
            wp_register_style( 'montonio-style', WC_MONTONIO_PLUGIN_URL . '/assets/css/montonio-style.css', array(), WC_MONTONIO_PLUGIN_VERSION );
            wp_register_style( 'montonio-pickup-points', WC_MONTONIO_PLUGIN_URL . '/assets/css/pickup-points.css', array(), WC_MONTONIO_PLUGIN_VERSION );

            wp_register_script( 'montonio-js-legacy', 'https://public.montonio.com/assets/montonio-js/3.x/montonio.bundle.js', array(), WC_MONTONIO_PLUGIN_VERSION, true );
            wp_register_script( 'montonio-js', 'https://js.montonio.com/1.x.x/montonio.umd.js', array(), WC_MONTONIO_PLUGIN_VERSION, true );
            wp_register_script( 'montonio-pis', WC_MONTONIO_PLUGIN_URL . '/assets/js/montonio-pis.js', array( 'jquery' ), WC_MONTONIO_PLUGIN_VERSION, true );
            wp_register_script( 'montonio-bnpl', WC_MONTONIO_PLUGIN_URL . '/assets/js/montonio-bnpl.js', array( 'jquery' ), WC_MONTONIO_PLUGIN_VERSION, true );
            wp_register_script( 'montonio-embedded-card-legacy', WC_MONTONIO_PLUGIN_URL . '/assets/js/montonio-embedded-card-legacy.js', array( 'jquery', 'montonio-js-legacy' ), WC_MONTONIO_PLUGIN_VERSION, true );
            wp_register_script( 'montonio-embedded-blik', WC_MONTONIO_PLUGIN_URL . '/assets/js/montonio-embedded-blik.js', array( 'jquery', 'montonio-js-legacy' ), WC_MONTONIO_PLUGIN_VERSION, true );
            wp_register_script( 'montonio-embedded-card', WC_MONTONIO_PLUGIN_URL . '/assets/js/montonio-embedded-card.js', array( 'jquery', 'montonio-js' ), WC_MONTONIO_PLUGIN_VERSION, true );
            wp_register_script( 'montonio-shipping-pickup-points', WC_MONTONIO_PLUGIN_URL . '/assets/js/montonio-shipping-pickup-points.js', array( 'jquery', 'montonio-js-legacy' ), WC_MONTONIO_PLUGIN_VERSION, true );
            wp_register_script( 'montonio-shipping-pickup-points-search', WC_MONTONIO_PLUGIN_URL . '/assets/js/montonio-shipping-pickup-points-search.js', array( 'jquery' ), WC_MONTONIO_PLUGIN_VERSION, true );
            wp_register_script( 'montonio-shipping-pickup-points-legacy', WC_MONTONIO_PLUGIN_URL . '/assets/js/montonio-shipping-pickup-points-legacy.js', array( 'selectWoo' ), WC_MONTONIO_PLUGIN_VERSION, true );

            wp_enqueue_style( 'montonio-style' );
        }

        /**
         * Register and enqueue admin assets.
         *
         * Handles stylesheets and scripts for the WordPress admin area,
         * including settings pages and order management.
         *
         * @return void
         */
        public function enqueue_admin_assets() {
            wp_register_style( 'montonio-admin-style', WC_MONTONIO_PLUGIN_URL . '/assets/css/montonio-admin-style.css', array(), WC_MONTONIO_PLUGIN_VERSION );
            wp_register_script( 'montonio-admin-script', WC_MONTONIO_PLUGIN_URL . '/assets/js/montonio-admin-script.js', array( 'jquery' ), WC_MONTONIO_PLUGIN_VERSION, true );
            wp_register_script( 'montonio-shipping-pickup-points-admin', WC_MONTONIO_PLUGIN_URL . '/assets/js/montonio-shipping-pickup-points-admin.js', array( 'jquery' ), WC_MONTONIO_PLUGIN_VERSION, true );
            wp_register_script( 'wc-montonio-shipping-shipment-manager', WC_MONTONIO_PLUGIN_URL . '/assets/js/wc-montonio-shipping-shipment-manager.js', array( 'jquery', 'wp-i18n' ), WC_MONTONIO_PLUGIN_VERSION, true );
            wp_register_script( 'wc-montonio-shipping-label-printing', WC_MONTONIO_PLUGIN_URL . '/assets/js/wc-montonio-shipping-label-printing.js', array( 'jquery', 'wp-i18n' ), WC_MONTONIO_PLUGIN_VERSION, true );

            wp_enqueue_style( 'montonio-admin-style' );
            wp_enqueue_script( 'montonio-admin-script' );
        }

        /**
         * Load plugin textdomain for internationalization.
         *
         * @return void
         */
        public function load_textdomain() {
            load_plugin_textdomain( 'montonio-for-woocommerce', false, plugin_basename( dirname( __FILE__ ) ) . '/languages' );
        }

        /**
         * Initialize Montonio API Settings.
         *
         * @return void
         */
        public function init_api_settings() {
            require_once WC_MONTONIO_PLUGIN_PATH . '/includes/admin/class-wc-montonio-api-settings.php';
        }

        /**
         * Add an admin notice to be displayed.
         *
         * @param string $message The message to be displayed in the admin notice.
         * @param string $class   The CSS class for the admin notice (e.g., 'error', 'updated').
         * @return void
         */
        public function add_admin_notice( $message, $class ) {
            $this->admin_notices[] = array( 'message' => $message, 'class' => $class );
        }

        /**
         * Display admin notices.
         *
         * @return void
         */
        public function display_admin_notices() {
            foreach ( $this->admin_notices as $notice ) {
                echo '<div id="message" class="' . esc_attr( $notice['class'] ) . '">';
                echo '<p>' . wp_kses_post( $notice['message'] ) . '</p>';
                echo '</div>';
            }
        }

        public function live_api_keys_notice() {
            if ( WC_Montonio_Helper::has_api_keys() ) {
                return;
            }
            ?>
            <div class="montonio-api-key-notice notice notice-warning is-dismissible">
                <div class="montonio-api-key-notice__content">
                    <img src="<?php echo esc_url( WC_MONTONIO_PLUGIN_URL . '/assets/images/montonio-logo.svg' ); ?>" alt="Montonio">
                    <h3><?php esc_html_e( 'Start using Montonio', 'montonio-for-woocommerce' ); ?></h3>
                    <p>
                        <?php esc_html_e( 'You haven\'t entered the Live API keys for the Montonio Payments module.', 'montonio-for-woocommerce' ); ?>
                        <br>
                        <?php
                        printf(
                            /* translators: 1) HTML anchor open tag 2) HTML anchor closing tag */
                            esc_html__( '%1$sClick here%2$s to enter your Live API keys and start using Montonio.', 'montonio-for-woocommerce' ),
                            '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_montonio_api' ) ) . '">',
                            '</a>'
                        );
                        ?>
                    </p>

                    <h3><?php esc_html_e( 'Need help?', 'montonio-for-woocommerce' ); ?></h3>
                    <a href="https://help.montonio.com/en/articles/68142-activating-payment-methods-in-woocommerce" target="_blank" rel="noopener">
                        <?php esc_html_e( 'How to activate Montonio payment methods', 'montonio-for-woocommerce' ); ?>
                    </a>
                </div>
            </div>
            <?php
        }
    }
    Montonio::get_instance();
}

add_action( 'before_woocommerce_init', 'montonio_declare_wooocommerce_compatibilities' );
function montonio_declare_wooocommerce_compatibilities() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
    }
}
