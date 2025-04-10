
<?php
defined( 'ABSPATH' ) || exit;

if ( empty( $order ) ) {
    return;
}

$title                  = 'Montonio Shipping';
$logo                   = 'https://public.montonio.com/logo/montonio-logomark-s.png';
$shipment_id            = $order->get_meta( '_wc_montonio_shipping_shipment_id' );
$shipment_status        = $order->get_meta( '_wc_montonio_shipping_shipment_status' );
$shipment_status_reason = $order->get_meta( '_wc_montonio_shipping_shipment_status_reason' );
$tracking_codes         = '';

$shipping_method = WC_Montonio_Shipping_Helper::get_chosen_montonio_shipping_method_for_order( $order );

if ( empty( $shipping_method ) ) {
    return;
}

$shipping_method_instance = WC_Montonio_Shipping_Helper::create_shipping_method_instance( $shipping_method->get_method_id(), $shipping_method->get_instance_id() );
$title                    = $shipping_method_instance->get_instance_form_fields()['title']['default'];
$tracking_codes           = $shipping_method->get_meta( 'tracking_codes' );
$logo                     = $shipping_method_instance->logo;

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
            <h3 class="montonio-shipping-panel__title"><strong><?php echo esc_html( $title ); ?></strong></h3>
            <img class="montonio-shipping-panel__logo<?php echo empty( $shipping_method ) ? ' default-logo' : null; ?>"  src="<?php echo esc_url( $logo ); ?>">
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

        <div class="montonio-shipping-panel__actions">
            <?php if ( ! empty( $shipment_id ) && in_array( $shipment_status, array( 'registered', 'registrationFailed', 'labelsCreated', 'updateFailed' ) ) ): ?>
                <a id="montonio-shipping-send-shipment" data-type="update" class="montonio-button montonio-button--secondary"><?php echo esc_html__( 'Update shipment in Montonio', 'montonio-for-woocommerce' ); ?></a>
            <?php elseif ( empty( $shipment_id ) ): ?>
                <a id="montonio-shipping-send-shipment" data-type="create" class="montonio-button montonio-button--secondary"><?php echo esc_html__( 'Create shipment in Montonio', 'montonio-for-woocommerce' ); ?></a>
            <?php endif; ?>

            <?php if ( ! empty( $tracking_codes ) && 'registered' === $shipment_status ): ?>
                <a id="montonio-shipping-print-label" class="montonio-button"><?php echo esc_html__( 'Print label', 'montonio-for-woocommerce' ); ?></a>
            <?php endif; ?>
        </div>
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