<?php
/**
 * Withdrawal page: item list for a matched order, with an image and a checkbox
 * per item and a quantity for items ordered more than once: a dropdown, or a
 * number field for large quantities.
 *
 * Also rendered on its own as the async lookup fragment.
 *
 * @since 10.4.0
 *
 * @var WC_Order|false $order Matched order.
 * @var array          $input Current input values.
 */
defined( 'ABSPATH' ) || exit;
?>
<?php if ( $order ) : ?>
<div class="montonio-withdrawal-items">
    <?php foreach ( $order->get_items() as $item_id => $item ) : ?>
    <?php
    $selected = WC_Montonio_Withdrawal_Frontend::is_selected( $input, $item_id );
    $product  = $item->get_product();
    // Decorative: the product name is right next to the image.
    $attr     = array( 'alt' => '', 'class' => 'montonio-withdrawal-item-image' );
    $image    = $product ? $product->get_image( 'woocommerce_gallery_thumbnail', $attr ) : wc_placeholder_img( 'woocommerce_gallery_thumbnail', $attr );
    $qty      = isset( $input['quantities'] ) && is_array( $input['quantities'] ) && isset( $input['quantities'][ $item_id ] ) && is_scalar( $input['quantities'][ $item_id ] ) ? $input['quantities'][ $item_id ] : $item->get_quantity();
    ?>
    <div class="montonio-withdrawal-item" data-quantity="<?php echo esc_attr( $item->get_quantity() ); ?>">
        <label class="montonio-withdrawal-check" for="montonio-withdrawal-item-<?php echo esc_attr( $item_id ); ?>"><input type="checkbox" id="montonio-withdrawal-item-<?php echo esc_attr( $item_id ); ?>" name="montonio_withdrawal[selected][<?php echo esc_attr( $item_id ); ?>]" value="1" <?php checked( $selected ); ?>><?php echo $image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php echo esc_html( $item->get_name() ); ?><?php foreach ( WC_Montonio_Withdrawal_Request::item_attributes( $item ) as $attribute ) : ?><span class="montonio-withdrawal-item-meta"><?php echo esc_html( $attribute['label'] . ': ' . $attribute['value'] ); ?></span><?php endforeach; ?><span class="montonio-withdrawal-item-meta"><?php /* translators: %s: quantity ordered */ echo esc_html( sprintf( __( 'Quantity: %s', 'montonio-for-woocommerce' ), $item->get_quantity() ) ); ?></span></span></label>
        <?php if ( $item->get_quantity() > 1 ) : ?>
        <?php /* translators: %s: item name */ $qty_label = sprintf( __( 'Quantity to return: %s', 'montonio-for-woocommerce' ), $item->get_name() ); ?>
        <div class="montonio-withdrawal-quantity">
            <?php if ( $item->get_quantity() <= WC_Montonio_Withdrawal_Frontend::QUANTITY_SELECT_MAX ) : ?>
            <select aria-label="<?php echo esc_attr( $qty_label ); ?>" id="montonio-withdrawal-qty-<?php echo esc_attr( $item_id ); ?>" name="montonio_withdrawal[quantities][<?php echo esc_attr( $item_id ); ?>]"><?php for ( $n = 1; $n <= $item->get_quantity(); $n++ ) : ?><option value="<?php echo esc_attr( $n ); ?>"<?php selected( (int) $qty, $n ); ?>><?php echo esc_html( $n ); ?></option><?php endfor; ?></select>
            <?php else : ?>
            <input aria-label="<?php echo esc_attr( $qty_label ); ?>" type="number" inputmode="numeric" min="1" max="<?php echo esc_attr( $item->get_quantity() ); ?>" step="1" id="montonio-withdrawal-qty-<?php echo esc_attr( $item_id ); ?>" name="montonio_withdrawal[quantities][<?php echo esc_attr( $item_id ); ?>]" value="<?php echo esc_attr( $qty ); ?>">
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
