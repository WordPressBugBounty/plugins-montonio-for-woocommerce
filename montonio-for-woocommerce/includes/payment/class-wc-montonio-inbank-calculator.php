<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WooCommerce Montonio Inbank Calculator Class
 *
 * Handles the display and functionality of Montonio Inbank installment payment calculators.
 *
 * @since 9.0.6
 */
class WC_Montonio_Inbank_Calculator {

    /**
     * Calculator settings from WooCommerce options
     *
     * @since 9.0.6
     * @var array
     */
    private $settings;

    /**
     * Whether calculator is enabled for display
     *
     * @since 9.0.6
     * @var bool
     */
    private $enabled;

    /**
     * Minimum amount for calculator display
     *
     * @since 9.0.6
     * @var float
     */
    private $min_amount;

    /**
     * Maximum amount for calculator display
     *
     * @since 9.0.6
     * @var float
     */
    private $max_amount;

    /**
     * Calculator display template type
     *
     * @since 9.0.6
     * @var string
     */
    private $template;

    /**
     * Calculator visual mode/theme
     *
     * @since 9.0.6
     * @var string
     */
    private $mode;

    /**
     * Regional setting for calculator
     *
     * @since 9.0.6
     * @var string
     */
    private $region;

    /**
     * Shop UUIDs for different regions
     *
     * @since 9.0.6
     * @var array
     */
    const SHOP_UUIDS = array(
        'ee' => '9a6bebb3-ade9-4968-800c-95ac1f3adecc',
        'lt' => 'c6c58bb9-fc34-40b3-8c3c-b9f401f67fba',
        'lv' => '9a4c7e03-b06c-4970-9b47-0827c8137b7b'
    );

    /**
     * Product codes for different regions
     *
     * @since 9.0.6
     * @var array
     */
    const PRODUCT_CODES = array(
        'ee' => 'hp_epos_montonio_119',
        'lt' => 'hp_epos_montonio119intr_max10000',
        'lv' => 'hp_montonio_epos_i_admin_m'
    );

    /**
     * Initialize the calculator with settings and register hooks
     *
     * Loads WooCommerce settings, sets default values, registers shortcode,
     * and sets up display hooks for automatic calculator positioning.
     *
     * @since 9.0.6
     * @return void
     */
    public function __construct() {
        $this->settings   = get_option( 'woocommerce_wc_montonio_hire_purchase_settings', array() );
        $this->enabled    = isset( $this->settings['calculator_enabled'] ) && 'yes' === $this->settings['calculator_enabled'];
        $this->min_amount = isset( $this->settings['min_amount'] ) ? $this->settings['min_amount'] : 100;
        $this->max_amount = 10000;
        $this->template   = isset( $this->settings['calculator_template'] ) ? $this->settings['calculator_template'] : 'no_editable_amount';
        $this->mode       = isset( $this->settings['calculator_mode'] ) ? $this->settings['calculator_mode'] : 'lavender';
        $this->region     = isset( $this->settings['calculator_region'] ) ? $this->settings['calculator_region'] : 'ee';

        add_shortcode( 'montonio_calculator', array( $this, 'render_calculator_shortcode' ) );

        $this->register_calculator_display_hooks();
    }

    /**
     * Register calculator display hooks based on admin settings
     *
     * Parses comma-separated hook names from settings and registers
     * the calculator display function to each valid WordPress hook.
     *
     * @since 9.0.6
     * @return void
     */
    private function register_calculator_display_hooks() {
        if ( empty( $this->settings['calculator_hooks'] ) ) {
            return;
        }

        // Split and clean hook names
        $hook_names = array_map( 'trim', explode( ',', $this->settings['calculator_hooks'] ) );
        $hook_names = array_filter( $hook_names );

        foreach ( $hook_names as $hook_name ) {
            add_action( $hook_name, array( $this, 'display_calculator_on_hook' ), 25 );
        }
    }

    /**
     * Display calculator when called from registered WordPress hooks
     *
     * Wrapper function that outputs the calculator HTML when triggered
     * by WordPress action hooks configured in the admin settings.
     *
     * @since 9.0.6
     * @return void
     */
    public function display_calculator_on_hook() {
        echo $this->render_calculator_shortcode();
    }

    /**
     * Render the Montonio calculator shortcode
     *
     * Processes shortcode attributes, determines the appropriate product amount,
     * validates the amount against minimum/maximum limits, and generates the
     * calculator HTML if valid.
     *
     * @since 9.0.6
     * @param array $atts Shortcode attributes including optional product_id
     * @return string HTML output for the calculator or empty string if invalid
     */
    public function render_calculator_shortcode( $atts = array() ) {
        if ( ! $this->enabled ) {
            return '';
        }

        $atts = shortcode_atts( array(
            'product_id' => ''
        ), $atts );

        $amount = $this->determine_product_amount( $atts );

        if ( ! $this->is_valid_amount( $amount ) ) {
            return '';
        }

        return $this->generate_calculator_html( $amount );
    }

    /**
     * Determine product amount from various WooCommerce contexts
     *
     * @since 9.0.6
     * @param array $atts Shortcode attributes that may contain product_id
     * @return float The determined product amount or 0 if none found
     */
    private function determine_product_amount( $atts = array() ) {
        // Check if we're on cart or checkout pages
        if ( is_cart() || is_checkout() ) {
            return $this->get_cart_total();
        }

        // Try to get from specific product ID
        if ( ! empty( $atts['product_id'] ) ) {
            $amount = $this->get_product_price_by_id( $atts['product_id'] );
            if ( $amount > 0 ) {
                return $amount;
            }
        }

        // Try to get from global product context
        return $this->get_current_product_price();
    }

    /**
     * Calculate total cart amount including taxes
     *
     * @since 9.0.6
     * @return float Cart total amount or 0 if cart unavailable
     */
    private function get_cart_total() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return 0;
        }

        $cart_total = WC()->cart->get_cart_contents_total() + WC()->cart->get_taxes_total();
        return (float) $cart_total;
    }

    /**
     * Retrieve product price by specific product ID with validation
     *
     * @since 9.0.6
     * @param mixed $product_id The product ID to retrieve price for
     * @return float Product display price or 0 if invalid/not found
     */
    private function get_product_price_by_id( $product_id ) {
        if ( ! is_numeric( $product_id ) || $product_id <= 0 ) {
            return 0;
        }

        $product = wc_get_product( intval( $product_id ) );

        if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
            return 0;
        }

        return (float) wc_get_price_to_display( $product );
    }

    /**
     * Get current product price from WordPress global context
     *
     * @since 9.0.6
     * @return float Current product display price or 0 if no product context
     */
    private function get_current_product_price() {
        global $product;

        if ( ! is_a( $product, 'WC_Product' ) ) {
            return 0;
        }

        return (float) wc_get_price_to_display( $product );
    }

    /**
     * Validate if amount falls within acceptable calculator range
     *
     * @since 9.0.6
     * @param mixed $amount The amount to validate
     * @return bool True if amount is valid for calculator display
     */
    private function is_valid_amount( $amount ) {
        $amount = (float) $amount;

        if ( $amount <= 0 ) {
            return false;
        }

        return $amount >= (float) $this->min_amount && $amount <= (float) $this->max_amount;
    }

    /**
     * Generate complete calculator HTML and JavaScript output
     *
     * @since 9.0.6
     * @param float $amount The validated product amount for calculator
     * @return string Complete HTML and JavaScript for calculator widget
     */
    private function generate_calculator_html( $amount ) {
        $lang          = $this->determine_language( $this->region );
        $calculator_id = 'inbank-calculator-' . uniqid();

        return $this->render_calculator_widget( $calculator_id, $amount, $lang );
    }

    /**
     * Determine appropriate language based on region and WordPress locale
     *
     * @since 9.0.6
     * @param string $region The configured calculator region
     * @return string Language code for calculator localization
     */
    private function determine_language( $region ) {
        $current_locale = apply_filters( 'wpml_current_language', get_locale() );
        $lang           = WC_Montonio_Helper::get_locale( $current_locale );

        // Handle Estonian language mapping
        if ( 'et' === $lang ) {
            $lang = 'ee';
        }

        return $lang;
    }

    /**
     * Render the complete calculator widget HTML and JavaScript initialization
     *
     * @since 9.0.6
     * @param string $calculator_id Unique identifier for the calculator instance
     * @param float $amount The product amount to display in calculator
     * @param string $lang Language code for calculator localization
     * @return string Complete HTML and JavaScript output for calculator widget
     * @throws Exception Internally for JavaScript loading errors, caught and handled within the function
     */
    private function render_calculator_widget( $calculator_id, $amount, $lang ) {
        ob_start();
        ?>
        <div id="<?php echo esc_attr( $calculator_id ); ?>" class="montonio-inbank-calculator"></div>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                !function(w,d,i,u){
                    (function(){
                        return new Promise(function(r,j){
                            if(w.CalculatorWidget) return r(w.CalculatorWidget);
                            if(d.getElementById(i)) return void setInterval(function(){
                                w.CalculatorWidget&&(clearInterval(this),r(w.CalculatorWidget))
                            },100);
                            var s=d.createElement("script");
                            s.id=i,s.src=u,s.async=1,
                            s.onload=function(){
                                w.CalculatorWidget&&w.CalculatorWidget.init?r(w.CalculatorWidget):j("init not found")
                            },
                            s.onerror=function(){j("script load failed")},
                            d.head.appendChild(s)
                        })
                    })().then(function(calculator){
                        calculator.init("<?php echo esc_js( $calculator_id ); ?>", {
                            layout: "default",
                            variant: "calculator-indivy-plan",
                            shop_uuid: "<?php echo esc_js( self::SHOP_UUIDS[$this->region] ); ?>",
                            product_code: "<?php echo esc_js( self::PRODUCT_CODES[$this->region] ); ?>",
                            amount: <?php echo (float) wc_format_decimal( $amount, 2 ); ?>,
                            template: "<?php echo esc_js( $this->template ); ?>",
                            mode: "<?php echo esc_js( $this->mode ); ?>",
                            lang: "<?php echo esc_js( $lang ); ?>",
                            region: "<?php echo esc_js( $this->region ); ?>"
                        });
                    }).catch(console.error);
                }(window,document,"inbank-calculator-script","https://calculator.inbank.eu/api/calculator");
            });
        </script>
        <?php
        return ob_get_clean();
    }
}
new WC_Montonio_Inbank_Calculator();