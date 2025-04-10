<?php

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Montonio_Shipping_Product for handling Montonio Shipping V2 product settings
 * @since 7.0.0
 */
class WC_Montonio_Shipping_Product {
    /**
     * Constructor for the WC_Montonio_Shipping_Product class
     *
     * @since 7.0.0
     */
    public function __construct() {
        // Add custom options to product page
        add_action( 'woocommerce_product_options_shipping', array( $this, 'add_product_shipping_options' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_shipping_options' ) );
    }

    /**
     * Add custom options to product page under shipping settings tab
     * 
     * @since 7.0.0
     * @return void
     */
    public function add_product_shipping_options () {
        global $post;
        $montonio_no_parcel_machine = get_post_meta( $post->ID, '_montonio_no_parcel_machine', true );
        $montonio_separate_label = get_post_meta( $post->ID, '_montonio_separate_label', true );

        echo '<div class="montonio_shipping_options">';
        woocommerce_wp_checkbox( 
            array( 
                'id'            => '_montonio_no_parcel_machine', 
                'label'         => __( 'Parcel machine support', 'montonio-for-woocommerce' ), 
                'description'   => __( 'Disable "Parcel machine" shipping methods if this product is added to cart', 'montonio-for-woocommerce' ),
                'value'         => $montonio_no_parcel_machine,
            )
        );
        woocommerce_wp_checkbox( 
            array( 
                'id'            => '_montonio_separate_label', 
                'label'         => __( 'Separate shipping label', 'montonio-for-woocommerce' ), 
                'description'   => __( 'Create a separate Montonio shipping label for each of these products', 'montonio-for-woocommerce' ),
                'value'         => $montonio_separate_label,
            )
        );
        echo '</div>';
    }

    /**
     * Save custom setting values in meta
     * 
     * @since 7.0.0
     * @param int $post_id The ID of the product being saved
     * @return void
     */
    public function save_product_shipping_options( $post_id ) {
        $no_parcel_machine = isset( $_POST['_montonio_no_parcel_machine'] ) ? sanitize_text_field( wp_unslash( $_POST['_montonio_no_parcel_machine'] ) ) : null;
        $separate_label = isset( $_POST['_montonio_separate_label'] ) ? sanitize_text_field( wp_unslash( $_POST['_montonio_separate_label'] ) ) : null;

        update_post_meta( $post_id, '_montonio_no_parcel_machine', $no_parcel_machine );
        update_post_meta( $post_id, '_montonio_separate_label', $separate_label );
    }
}

new WC_Montonio_Shipping_Product();