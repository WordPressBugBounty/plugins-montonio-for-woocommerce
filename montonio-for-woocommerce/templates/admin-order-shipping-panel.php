
<?php
defined( 'ABSPATH' ) || exit;

if ( empty( $order ) ) {
    return;
}

$title                  = 'Montonio Shipping';
$shipment_id            = $order->get_meta( '_wc_montonio_shipping_shipment_id' );
$shipment_status        = $order->get_meta( '_wc_montonio_shipping_shipment_status' );
$shipment_status_reason = $order->get_meta( '_wc_montonio_shipping_shipment_status_reason' );

$shipping_method = WC_Montonio_Shipping_Helper::get_chosen_montonio_shipping_method_for_order( $order );

if ( empty( $shipping_method ) ) {
    return;
}

$tracking_codes          = $shipping_method->get_meta( 'tracking_codes' );
$shipping_method_item_id = $order->get_meta( '_montonio_pickup_point_uuid' );

if ( ! empty( $shipping_method_item_id ) ) {
    $shipping_method_item = WC_Montonio_Shipping_Item_Manager::get_shipping_method_item( $shipping_method_item_id );
    $carrier_name         = $shipping_method_item->carrier_code;
    $type                 = $shipping_method_item->item_type;
} else {
    $shipping_method_instance = WC_Montonio_Shipping_Helper::create_shipping_method_instance( $shipping_method->get_method_id(), $shipping_method->get_instance_id() );
    $carrier_name             = $shipping_method_instance->provider_name;
    $type                     = $shipping_method_instance->type_v2;
}

$type_label = __( 'Pickup point', 'montonio-for-woocommerce' );

if ( $type === 'courier' ) {
    $type_label = __( 'Courier', 'montonio-for-woocommerce' );
}

$error_reason = '';

if ( ! empty( $shipment_status_reason ) ) {
    $error_reason = $shipment_status_reason;
} else {
    if ( 'creationFailed' === $shipment_status ) {
        $error_reason = __( 'Shipment creation failed. Please check order notes for error reason.', 'montonio-for-woocommerce' );
    } elseif ( 'updateFailed' === $shipment_status ) {
        $error_reason = __( 'Shipment update failed. Please check order notes for error reason', 'montonio-for-woocommerce' );
    } elseif ( 'registrationFailed' === $shipment_status ) {
        $error_reason = __( 'Shipment registration in the carrier system failed. Please check order notes for error reason.', 'montonio-for-woocommerce' );
    }
}
?>

<div class="montonio-shipping-panel">
    <div class="montonio-shipping-panel__body">
        <div class="montonio-shipping-panel__header">
            <div class="montonio-shipping-panel__header-content">
                <img class="montonio-shipping-panel__logo" src="<?php echo esc_url( WC_MONTONIO_PLUGIN_URL . '/assets/images/' . $carrier_name . '-rect.svg' ); ?>">
                <div class="montonio-shipping-panel__title"><?php echo esc_html( str_replace( '_', ' ', $carrier_name ) ); ?><span><?php echo esc_html( $type_label ); ?></span></div>
            </div>

            <?php
            $status = $order->get_meta( '_wc_montonio_shipping_shipment_status' );

            $status_labels = array(
                'pending'            => __( 'Pending', 'montonio-for-woocommerce' ),
                'creationFailed'     => __( 'Creation failed', 'montonio-for-woocommerce' ),
                'registered'         => __( 'Registered', 'montonio-for-woocommerce' ),
                'registrationFailed' => __( 'Registration failed', 'montonio-for-woocommerce' ),
                'labelsCreated'      => __( 'Labels created', 'montonio-for-woocommerce' ),
                'inTransit'          => __( 'In transit', 'montonio-for-woocommerce' ),
                'awaitingCollection' => __( 'Awaiting collection', 'montonio-for-woocommerce' ),
                'delivered'          => __( 'Delivered', 'montonio-for-woocommerce' )
            );

            if ( ! empty( $status ) ) {
                $status_label = isset( $status_labels[$status] ) ? $status_labels[$status] : ucfirst( strtolower( preg_replace( '/(?<!^)([A-Z])/', ' $1', $status ) ) );

                echo '<mark class="montonio-shipping-panel__status montonio-shipping-panel__status--' . esc_html( $status ) . '"><span>' . esc_html( $status_label ) . '</span></mark>';
            }
            ?>
        </div>

        <?php if ( empty( $shipment_id ) ): ?>
            <?php if ( empty( $error_reason ) ): ?>
                <div class="montonio-shipping-panel__notice montonio-shipping-panel__notice--yellow">
                    <p><?php echo esc_html__( 'We\'ve noticed that this order includes Montonio\'s shipping method, but it seems that it\'s not registred in our Partner System yet, please click on "Create shipment in Montonio" to generate the shipment and obtain the tracking codes.', 'montonio-for-woocommerce' ); ?></p>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="montonio-shipping-panel__row">
                <h4><?php echo esc_html__( 'Montonio shipment ID:', 'montonio-for-woocommerce' ); ?></h4>
                <strong><?php echo esc_html( $shipment_id ); ?></strong>
            </div>

            <?php if ( ! empty( $tracking_codes ) && 'pending' !== $shipment_status ): ?>
                <div class="montonio-shipping-panel__row">
                    <h4><?php echo esc_html__( 'Shipment tracking code(s):', 'montonio-for-woocommerce' ); ?></h4>
                    <?php echo wp_kses_post( $tracking_codes ); ?>
                </div>
            <?php endif; ?>

            <?php if ( 'pending' === $shipment_status ): ?>
                <div class="montonio-shipping-panel__notice montonio-shipping-panel__notice--blue">
                    <p><?php echo esc_html__( 'Shipment successfully created in Montonio. Waiting for tracking codes.', 'montonio-for-woocommerce' ); ?></p>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ( ! empty( $error_reason ) ): ?>
            <div class="montonio-shipping-panel__notice montonio-shipping-panel__notice--red">
                <p><?php echo wp_kses_post( $error_reason ); ?></p>
            </div>
        <?php endif; ?>

        <?php
        $show_update_button = ! empty( $shipment_id ) && in_array( $shipment_status, array( 'registered', 'registrationFailed', 'labelsCreated', 'updateFailed' ) );
        $show_create_button = empty( $shipment_id );
        $show_print_button  = ! empty( $tracking_codes ) && ! in_array( $shipment_status, array( 'pending', 'inTransit', 'awaitingCollection', 'delivered', 'returned' ) );

        if ( $show_update_button || $show_create_button || $show_print_button ) : ?>
            <div class="montonio-shipping-panel__actions">
                <?php if ( $show_update_button ) : ?>
                    <a id="montonio-shipping-send-shipment" data-type="update" class="montonio-button montonio-button--secondary"><?php echo esc_html__( 'Update shipment in Montonio', 'montonio-for-woocommerce' ); ?></a>
                <?php elseif ( $show_create_button ) : ?>
                    <a id="montonio-shipping-send-shipment" data-type="create" class="montonio-button montonio-button--secondary"><?php echo esc_html__( 'Create shipment in Montonio', 'montonio-for-woocommerce' ); ?></a>
                <?php endif; ?>

                <?php if ( $show_print_button ) : ?>
                    <a id="montonio-shipping-print-label" class="montonio-button"><?php echo esc_html__( 'Print label', 'montonio-for-woocommerce' ); ?></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>    

    <div class="montonio-shipping-panel__blocker">
        <div class="montonio-shipping-panel__loader">
            <svg version="1.1" id="loader-1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" width="40px"  height="40px" viewBox="0 0 50 50" style="enable-background:new 0 0 50 50;" xml:space="preserve">
                <path fill="#442DD2" d="M43.935,25.145c0-10.318-8.364-18.683-18.683-18.683c-10.318,0-18.683,8.365-18.683,18.683h4.068c0-8.071,6.543-14.615,14.615-14.615c8.072,0,14.615,6.543,14.615,14.615H43.935z">
                    <animateTransform attributeType="xml"
                    attributeName="transform"
                    type="rotate"
                    from="0 25 25"
                    to="360 25 25"
                    dur="0.6s"
                    repeatCount="indefinite"/>
                </path>
            </svg>
        </div>
    </div>
</div>