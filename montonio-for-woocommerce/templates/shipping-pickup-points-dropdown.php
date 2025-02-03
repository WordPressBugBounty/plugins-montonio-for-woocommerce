<?php

defined( 'ABSPATH' ) || exit;

$include_address = get_option( 'montonio_shipping_show_address' );
?>

<tr class="montonio-pickup-point">
    <td colspan="2" class="forminp">
        <div class="montonio-pickup-point-select-wrapper">
            <label for="montonio-shipping-pickup-point-dropdown"><?php echo __('Pickup point', 'montonio-for-woocommerce'); ?> <abbr class="required" title="required">*</abbr></label>
            <select name="montonio_pickup_point" id="montonio-shipping-pickup-point-dropdown" class="montonio-shipping-pickup-point-dropdown montonio-pickup-point-select" data-shipping-method="<?php echo $shipping_method; ?>">
                <!-- Default option -->
                <option value=""><?php echo __('Select a pickup point', 'montonio-for-woocommerce'); ?></option>
                <?php foreach ( $shipping_method_items as $locality => $items ): ?>
                <optgroup label="<?php echo esc_attr( $locality ); ?>">
                    <?php foreach ( $items as $item ): ?>
                    <option value="<?php echo esc_attr( $item['id'] ); ?>">
                        <?php echo esc_html( $item['name'] ); ?>

                        <?php if ( $include_address === 'yes' && ! empty( trim( $item['address'] ) ) ) {
                            echo ' - ' . esc_html( $item['address'] ) . ', ' . esc_attr( $locality );
                        } ?>
                    </option>
                    <?php endforeach; ?>
                </optgroup>
                <?php endforeach; ?>
            </select>
        </div>
        <br />
    </td>
</tr>
