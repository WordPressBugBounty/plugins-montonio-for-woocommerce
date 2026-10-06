<?php
defined( 'ABSPATH' ) || exit;

/**
 * Gutenberg block for the Montonio withdrawal form.
 *
 * Registers the montonio/withdrawal-form dynamic block whose render shares
 * the shortcode's PHP renderer. Block attributes carry display copy only;
 * store details and email routing always come from the saved settings.
 *
 * @since 10.4.0
 */
class WC_Montonio_Withdrawal_Block {

    /**
     * Block name.
     *
     * @since 10.4.0
     */
    const NAME = 'montonio/withdrawal-form';

    /**
     * Inline markup allowed in the page-specific return instructions.
     *
     * @since 10.4.0
     * @var array
     */
    const ALLOWED_HTML = array(
        'strong' => array(),
        'em'     => array(),
        'br'     => array(),
        'a'      => array( 'href' => true, 'title' => true )
    );

    /**
     * Register block hooks on stacks with block.json metadata support.
     *
     * On older stacks (WordPress < 5.5) the block is never registered and the
     * shortcode remains the only placement.
     *
     * @since 10.4.0
     * @return void
     */
    public static function init() {
        if ( ! function_exists( 'register_block_type_from_metadata' ) ) {
            return;
        }

        add_action( 'init', array( __CLASS__, 'register' ) );
        add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'add_editor_defaults' ) );
    }

    /**
     * Whether the block has been registered on this request.
     *
     * @since 10.4.0
     * @return bool
     */
    public static function is_registered() {
        return WP_Block_Type_Registry::get_instance()->is_registered( self::NAME );
    }

    /**
     * Whether the block can be inserted on pages in the block editor.
     *
     * @since 10.4.0
     * @return bool
     */
    public static function is_available() {
        // use_block_editor_for_post_type() lives in wp-admin/includes, which only admin requests load.
        if ( ! self::is_registered() || ! function_exists( 'use_block_editor_for_post_type' ) || ! use_block_editor_for_post_type( 'page' ) ) {
            return false;
        }

        // Respect editor block allow-lists where the API exists (WP 5.8+).
        if ( class_exists( 'WP_Block_Editor_Context' ) && function_exists( 'get_allowed_block_types' ) ) {
            $context = new WP_Block_Editor_Context( array( 'post' => new WP_Post( (object) array( 'ID' => 0, 'post_type' => 'page' ) ) ) );
            $allowed = get_allowed_block_types( $context );

            return true === $allowed || ( is_array( $allowed ) && in_array( self::NAME, $allowed, true ) );
        }

        return true;
    }

    /**
     * Register the block from its built metadata.
     *
     * Core auto-registers the editor script's translations from block.json's
     * textdomain, but passes no path: on WordPress 6.1+ that resolves through
     * WP_Textdomain_Registry to this plugin's languages folder, while on 5.5-6.0
     * it only looks in wp-content/languages/plugins/ and never finds the bundled
     * catalogues. Pass the path explicitly, the way the sibling blocks do, so the
     * editor is translated across the whole supported range.
     *
     * The JSON catalogue filenames are md5-keyed to the script's plugin-relative
     * path ("blocks/build/wc-montonio-withdrawal/index.js"), so renaming the block
     * directory or changing the webpack output path silently orphans all six
     * translation files with no error anywhere.
     *
     * @since 10.4.0
     * @return void
     */
    public static function register() {
        $block = register_block_type_from_metadata(
            WC_MONTONIO_PLUGIN_PATH . '/blocks/build/wc-montonio-withdrawal',
            array( 'render_callback' => array( __CLASS__, 'render' ) )
        );

        if ( ! $block || empty( $block->editor_script ) ) {
            return;
        }

        wp_set_script_translations(
            $block->editor_script,
            'montonio-for-woocommerce',
            WC_MONTONIO_PLUGIN_PATH . '/languages'
        );
    }

    /**
     * Pass the saved page copy and public store details to the editor script.
     *
     * The RichText default (instructions) and the store details the editor's
     * replica of step one renders (legal name, contact email, return address)
     * are all public page copy.
     * Email routing (the recipient list) is never included in editor data or block
     * attributes.
     *
     * @since 10.4.0
     * @return void
     */
    public static function add_editor_defaults() {
        $block = WP_Block_Type_Registry::get_instance()->get_registered( self::NAME );

        if ( ! $block || empty( $block->editor_script ) ) {
            return;
        }

        $settings = WC_Montonio_Withdrawals::get_settings();
        $store    = array( 'legal_name', 'contact_email', 'return_address' );

        wp_add_inline_script(
            $block->editor_script,
            'window.wcMontonioWithdrawalBlock = ' . wp_json_encode( array( 'store' => array_intersect_key( $settings, array_flip( $store ) ) ) ) . ';',
            'before'
        );
    }

    /**
     * The withdrawal settings, with the page's own return instructions.
     *
     * Attributes come from stored post content, so the instructions are
     * re-sanitized on every use: inline markup only, bounded length.
     *
     * @since 10.4.0
     * @param array $attributes Raw block attributes.
     * @return array Settings for the shared renderer.
     */
    public static function settings( $attributes ) {
        $settings = WC_Montonio_Withdrawals::get_settings();

        if ( isset( $attributes['instructions'] ) && is_string( $attributes['instructions'] ) ) {
            $settings['instructions'] = wp_kses( mb_substr( $attributes['instructions'], 0, 10000 ), self::ALLOWED_HTML );
        }

        return $settings;
    }

    /**
     * Resolve saved page copy for POSTs and async transitions as well as rendering.
     * Routing remains in the module settings; posted display attributes are ignored.
     *
     * @since 10.4.0
     * @return array
     */
    public static function page_settings() {
        $post = get_post();
        $attributes = $post ? self::find_attributes( parse_blocks( $post->post_content ) ) : array();

        return self::settings( is_array( $attributes ) ? $attributes : array() );
    }

    /**
     * Find the withdrawal form even when nested inside layout blocks.
     *
     * @since 10.4.0
     * @param array $blocks Parsed page blocks.
     * @return array|null
     */
    private static function find_attributes( $blocks ) {
        foreach ( $blocks as $block ) {
            if ( self::NAME === $block['blockName'] ) {
                return $block['attrs'];
            }

            $attributes = self::find_attributes( $block['innerBlocks'] );

            if ( null !== $attributes ) {
                return $attributes;
            }
        }

        return null;
    }

    /**
     * Render callback: delegate to the shared frontend renderer.
     *
     * @since 10.4.0
     * @param array $attributes Block attributes.
     * @return string
     */
    public static function render( $attributes ) {
        return WC_Montonio_Withdrawal_Frontend::render_settings( self::settings( is_array( $attributes ) ? $attributes : array() ) );
    }
}
