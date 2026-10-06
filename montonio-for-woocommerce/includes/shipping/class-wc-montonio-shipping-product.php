<?php

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Montonio_Shipping_Product for handling Montonio Shipping V2 product settings
 * @since 7.0.0
 */
class WC_Montonio_Shipping_Product {

    /**
     * Initialize hooks for product shipping options.
     *
     * @since 9.5.0
     * @return void
     */
    public static function init() {
        add_action( 'woocommerce_product_options_shipping', array( __CLASS__, 'add_product_shipping_options' ) );
        add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product_shipping_options' ) );
        add_action( 'woocommerce_product_bulk_edit_end', array( __CLASS__, 'add_bulk_edit_shipping_options' ) );
        add_action( 'woocommerce_product_bulk_edit_save', array( __CLASS__, 'save_bulk_edit_shipping_options' ) );
    }

    /**
     * Get the product shipping options, keyed by meta key
     *
     * @since 10.4.0
     * @return array
     */
    private static function get_shipping_options() {
        return array(
            '_montonio_no_parcel_machine' => array(
                'label'       => __( 'Disable parcel machines', 'montonio-for-woocommerce' ),
                'description' => __( 'Disable "Parcel machine" shipping methods if this product is added to cart', 'montonio-for-woocommerce' ),
            ),
            '_montonio_separate_label'    => array(
                'label'       => __( 'Separate shipping label', 'montonio-for-woocommerce' ),
                'description' => __( 'Create a separate Montonio shipping label for each of these products', 'montonio-for-woocommerce' ),
            ),
            '_montonio_fragile'           => array(
                'label'       => __( 'Fragile shipping', 'montonio-for-woocommerce' ),
                'description' => __( 'Ship these products using the fragile service. Only available with select Montonio carriers', 'montonio-for-woocommerce' ),
            ),
        );
    }

    /**
     * Add custom options to product page under shipping settings tab
     *
     * @since 7.0.0
     * @return void
     */
    public static function add_product_shipping_options() {
        global $post;

        echo '<div class="montonio_shipping_options">';
        foreach ( self::get_shipping_options() as $meta_key => $option ) {
            woocommerce_wp_checkbox(
                array(
                    'id'          => $meta_key,
                    'label'       => $option['label'],
                    'description' => $option['description'],
                    'value'       => get_post_meta( $post->ID, $meta_key, true ),
                )
            );
        }
        echo '</div>';
    }

    /**
     * Save custom setting values in meta
     *
     * @since 7.0.0
     * @param int $post_id The ID of the product being saved
     * @return void
     */
    public static function save_product_shipping_options( $post_id ) {
        foreach ( array_keys( self::get_shipping_options() ) as $meta_key ) {
            $value = isset( $_POST[ $meta_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $meta_key ] ) ) : null;

            update_post_meta( $post_id, $meta_key, $value );
        }
    }

    /**
     * Add Montonio shipping options to the products list bulk edit panel
     *
     * @since 10.4.0
     * @return void
     */
    public static function add_bulk_edit_shipping_options() {
        foreach ( self::get_shipping_options() as $meta_key => $option ) {
            ?>
            <div class="inline-edit-group">
                <label class="alignleft">
                    <span class="title"><?php echo esc_html( $option['label'] ); ?></span>
                    <span class="input-text-wrap">
                        <select class="<?php echo esc_attr( $meta_key ); ?>" name="<?php echo esc_attr( $meta_key ); ?>">
                            <option value=""><?php esc_html_e( '— No change —', 'montonio-for-woocommerce' ); ?></option>
                            <option value="yes"><?php esc_html_e( 'Yes', 'montonio-for-woocommerce' ); ?></option>
                            <option value="no"><?php esc_html_e( 'No', 'montonio-for-woocommerce' ); ?></option>
                        </select>
                    </span>
                </label>
            </div>
            <?php
        }
    }

    /**
     * Save Montonio shipping options from the products list bulk edit panel
     *
     * WooCommerce verifies the bulk edit nonce before firing woocommerce_product_bulk_edit_save.
     *
     * @since 10.4.0
     * @param WC_Product $product The product being bulk edited
     * @return void
     */
    public static function save_bulk_edit_shipping_options( $product ) {
        foreach ( array_keys( self::get_shipping_options() ) as $meta_key ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $value = isset( $_REQUEST[ $meta_key ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $meta_key ] ) ) : '';

            if ( 'yes' === $value ) {
                update_post_meta( $product->get_id(), $meta_key, 'yes' );
            } elseif ( 'no' === $value ) {
                update_post_meta( $product->get_id(), $meta_key, '' );
            }
        }
    }
}
