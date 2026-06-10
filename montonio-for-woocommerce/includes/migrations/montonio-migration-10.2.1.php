<?php
defined( 'ABSPATH' ) || exit;

class Montonio_Migration_10_2_1 {

    /**
     * Rename the legacy 'choices' value of montonio_shipping_dropdown_type to
     * 'choices-js' so merchants who previously selected the ChoicesJS dropdown
     * keep that selection after the option value rename.
     *
     * @return void
     */
    public static function migrate_up() {
        if ( 'choices' === get_option( 'montonio_shipping_dropdown_type' ) ) {
            update_option( 'montonio_shipping_dropdown_type', 'choices-js' );
        }
    }
}

Montonio_Migration_10_2_1::migrate_up();
