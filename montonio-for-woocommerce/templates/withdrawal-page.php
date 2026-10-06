<?php
/**
 * Customer withdrawal journey: order and item selection, review and confirmation.
 *
 * @since 10.4.0
 */
defined( 'ABSPATH' ) || exit;
?>
<section class="montonio-withdrawal" data-stage="<?php echo esc_attr( $stage ); ?>" data-loading="<?php esc_attr_e( 'Loading order items…', 'montonio-for-woocommerce' ); ?>" data-load-error="<?php esc_attr_e( 'Could not load the order. Please try again.', 'montonio-for-woocommerce' ); ?>" aria-label="<?php esc_attr_e( 'Withdrawal form', 'montonio-for-woocommerce' ); ?>">
    <ol class="montonio-withdrawal-progress" aria-label="<?php esc_attr_e( 'Progress', 'montonio-for-woocommerce' ); ?>">
        <?php // Item selection belongs to the Order step: the details view has no fields of its own. ?>
        <?php $current_step = 'form' === $stage ? 'order' : $stage; ?>
        <?php foreach ( array( 'order' => __( 'Order', 'montonio-for-woocommerce' ), 'review' => __( 'Review', 'montonio-for-woocommerce' ), 'success' => __( 'Confirmation', 'montonio-for-woocommerce' ) ) as $key => $label ) : ?><li <?php echo $current_step === $key ? 'aria-current="step"' : ''; ?>><span><?php echo esc_html( $label ); ?></span></li><?php endforeach; ?>
    </ol>
    <?php if ( $error ) : ?><div class="montonio-withdrawal-alert montonio-withdrawal-error" role="alert" tabindex="-1" data-focus><?php echo esc_html( $error ); ?></div><?php endif; ?>
    <div class="montonio-withdrawal-alert" data-montonio-lookup-message role="status" aria-live="polite" hidden></div>
    <?php
    // One title per step, above both columns. Errors come first so they keep focus.
    $titles = array(
        'order'   => __( 'Which order is this about?', 'montonio-for-woocommerce' ),
        'form'    => __( 'Select the items to return', 'montonio-for-woocommerce' ),
        'review'  => __( 'Review your withdrawal', 'montonio-for-woocommerce' ),
        'success' => __( 'Your withdrawal notice is on its way', 'montonio-for-woocommerce' )
    );
    ?>
    <h2 class="montonio-withdrawal-title"<?php echo 'order' === $stage ? '' : ' tabindex="-1" data-focus'; ?>><?php echo esc_html( $titles[ $stage ] ); ?></h2>
    <div class="montonio-withdrawal-layout"><div class="montonio-withdrawal-main">
    <?php if ( 'success' === $stage ) : ?>
        <div class="montonio-withdrawal-panel"><span class="montonio-withdrawal-badge montonio-withdrawal-notice-badge"><?php esc_html_e( 'Notice submitted', 'montonio-for-woocommerce' ); ?></span>
        <?php if ( $result['customer'] ) : ?><p><?php esc_html_e( 'We\'ve sent an acknowledgement to your email. Any refund is handled separately.', 'montonio-for-woocommerce' ); ?></p>
        <?php else : ?><div class="montonio-withdrawal-alert montonio-withdrawal-error" role="alert"><?php esc_html_e( 'The store notification was accepted, but your acknowledgement email could not be sent. Save this page as your copy and retry the acknowledgement below.', 'montonio-for-woocommerce' ); ?></div><form method="post" action="<?php echo esc_url( get_permalink() ); ?>"><?php wp_nonce_field( WC_Montonio_Withdrawal_Frontend::NONCE_ACTION ); ?><input type="hidden" name="montonio_withdrawal_token" value="<?php echo esc_attr( $token ); ?>"><button class="montonio-withdrawal-button" name="montonio_withdrawal_action" value="confirm"><?php esc_html_e( 'Retry acknowledgement', 'montonio-for-woocommerce' ); ?></button></form><?php endif; ?>
        <dl class="montonio-withdrawal-summary"><div><dt><?php esc_html_e( 'Request reference', 'montonio-for-woocommerce' ); ?></dt><dd><?php echo esc_html( $result['reference'] ); ?></dd></div><div><dt><?php esc_html_e( 'Submitted at', 'montonio-for-woocommerce' ); ?></dt><dd><?php echo esc_html( get_the_date( '', $result['id'] ) . ' ' . get_the_time( '', $result['id'] ) ); ?></dd></div>
        <?php foreach ( WC_Montonio_Withdrawal_Request::summary( $payload['data'] ) as $label => $value ) : ?><div><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo nl2br( esc_html( $value ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dd></div><?php endforeach; ?></dl>
        <button type="button" class="montonio-withdrawal-button montonio-withdrawal-secondary montonio-withdrawal-print" hidden><?php esc_html_e( 'Print or save a copy', 'montonio-for-woocommerce' ); ?></button></div>
    <?php elseif ( 'review' === $stage ) : ?>
        <div class="montonio-withdrawal-panel">
        <p><?php esc_html_e( 'By confirming, you notify the seller that you withdraw from the purchase or items listed below.', 'montonio-for-woocommerce' ); ?></p>
        <dl class="montonio-withdrawal-summary"><?php foreach ( WC_Montonio_Withdrawal_Request::summary( $payload['data'] ) as $label => $value ) : ?><div><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo nl2br( esc_html( $value ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dd></div><?php endforeach; ?></dl>
        <form method="post" action="<?php echo esc_url( get_permalink() ); ?>" class="montonio-withdrawal-submit-form" id="montonio-withdrawal-form"><?php wp_nonce_field( WC_Montonio_Withdrawal_Frontend::NONCE_ACTION ); ?><input type="hidden" name="montonio_withdrawal_token" value="<?php echo esc_attr( $token ); ?>"></form></div>
    <?php else : ?>
        <form method="post" action="<?php echo esc_url( get_permalink() ); ?>" class="montonio-withdrawal-submit-form" id="montonio-withdrawal-form">
            <?php wp_nonce_field( WC_Montonio_Withdrawal_Frontend::NONCE_ACTION ); ?>
            <div class="montonio-withdrawal-trap" aria-hidden="true"><label><?php esc_html_e( 'Leave this field empty', 'montonio-for-woocommerce' ); ?><input name="montonio_withdrawal[website]" tabindex="-1" autocomplete="off"></label></div>
            <input type="hidden" name="montonio_withdrawal_stage" value="<?php echo esc_attr( $stage ); ?>">
            <?php if ( 'order' === $stage ) : ?>
            <input type="hidden" name="montonio_withdrawal[reason]" value="<?php echo esc_attr( WC_Montonio_Withdrawal_Request::text( $input, 'reason', 3000, true ) ); ?>">
            <div class="montonio-withdrawal-panel">
                <p><?php esc_html_e( 'Enter the order number from your confirmation email and the email used at checkout to load your items.', 'montonio-for-woocommerce' ); ?></p>
                <div class="montonio-withdrawal-grid">
                    <?php WC_Montonio_Withdrawal_Frontend::field( $input, 'order_number', __( 'Order number', 'montonio-for-woocommerce' ), 'text', true ); ?>
                    <?php WC_Montonio_Withdrawal_Frontend::field( $input, 'email', __( 'Email address', 'montonio-for-woocommerce' ), 'email', true ); ?>
                </div>
                <div class="montonio-withdrawal-order-actions">
                    <button class="montonio-withdrawal-button" name="montonio_withdrawal_action" value="lookup"><?php esc_html_e( 'Find order', 'montonio-for-woocommerce' ); ?></button>
                </div>
            </div>
            <?php else : ?>
            <div class="montonio-withdrawal-panel" data-montonio-details>
                <input type="hidden" name="montonio_withdrawal[matched_id]" value="<?php echo esc_attr( $order->get_id() ); ?>">
                <div class="montonio-withdrawal-alert montonio-withdrawal-matched-order"><div class="montonio-withdrawal-order-info"><span><strong><?php echo esc_html( __( 'Order number', 'montonio-for-woocommerce' ) . ':' ); ?></strong> <?php echo esc_html( $order->get_order_number() ); ?></span><?php if ( $order->get_date_created() ) : ?><span class="montonio-withdrawal-order-date"><strong><?php echo esc_html( __( 'Order date', 'montonio-for-woocommerce' ) . ':' ); ?></strong> <?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></span><?php endif; ?></div></div>
                <?php foreach ( array( 'order_number', 'email' ) as $key ) : ?><input type="hidden" name="montonio_withdrawal[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( isset( $input[ $key ] ) ? $input[ $key ] : '' ); ?>"><?php endforeach; ?>
                <div data-montonio-order-items><?php wc_get_template( 'withdrawal-items.php', array( 'order' => $order, 'input' => $input ), '', WC_MONTONIO_PLUGIN_PATH . '/templates/' ); ?></div>
                <?php WC_Montonio_Withdrawal_Frontend::field( $input, 'reason', __( 'Additional information (optional)', 'montonio-for-woocommerce' ), 'textarea' ); ?>
            </div>
            <?php endif; ?>
        </form>
    <?php endif; ?>
    </div><aside class="montonio-withdrawal-sidebar">
        <?php if ( in_array( $stage, array( 'form', 'review' ), true ) ) : ?>
        <?php // The step's buttons submit the form in the main column through the form attribute. ?>
        <div class="montonio-withdrawal-panel montonio-withdrawal-action-panel">
            <?php if ( 'review' === $stage ) : ?>
            <div class="montonio-withdrawal-actions"><button form="montonio-withdrawal-form" class="montonio-withdrawal-button" name="montonio_withdrawal_action" value="confirm"><?php esc_html_e( 'Confirm withdrawal', 'montonio-for-woocommerce' ); ?></button><button form="montonio-withdrawal-form" class="montonio-withdrawal-button montonio-withdrawal-secondary" name="montonio_withdrawal_action" value="edit"><?php esc_html_e( 'Edit details', 'montonio-for-woocommerce' ); ?></button></div>
            <?php else : ?>
            <?php list( $selected_units, $total_units ) = WC_Montonio_Withdrawal_Frontend::selection_count( $order, $input ); ?>
            <?php // The script keeps the count current as items are checked; the format needs no plural forms. ?>
            <div class="montonio-withdrawal-selection" data-montonio-selection data-none="<?php esc_attr_e( 'None selected yet', 'montonio-for-woocommerce' ); ?>" data-format="<?php /* translators: 1: units selected for return, 2: units in the order */ esc_attr_e( '%1$s of %2$s', 'montonio-for-woocommerce' ); ?>"><span><?php esc_html_e( 'Items to return', 'montonio-for-woocommerce' ); ?></span><strong data-montonio-selection-count><?php echo esc_html( $selected_units ? sprintf( /* translators: 1: units selected for return, 2: units in the order */ __( '%1$s of %2$s', 'montonio-for-woocommerce' ), $selected_units, $total_units ) : __( 'None selected yet', 'montonio-for-woocommerce' ) ); ?></strong></div>
            <div class="montonio-withdrawal-actions"><button form="montonio-withdrawal-form" class="montonio-withdrawal-button" name="montonio_withdrawal_action" value="review"><?php esc_html_e( 'Review withdrawal', 'montonio-for-woocommerce' ); ?></button><button form="montonio-withdrawal-form" class="montonio-withdrawal-text-button" name="montonio_withdrawal_action" value="change" formnovalidate><?php esc_html_e( 'Change order', 'montonio-for-woocommerce' ); ?></button></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="montonio-withdrawal-panel montonio-withdrawal-store-card"><h3><?php echo esc_html( $settings['legal_name'] ); ?></h3><a href="<?php echo esc_url( 'mailto:' . $settings['contact_email'] ); ?>"><?php echo esc_html( $settings['contact_email'] ); ?></a>
        <?php if ( $settings['return_address'] ) : ?><h4><?php esc_html_e( 'Return address', 'montonio-for-woocommerce' ); ?></h4><p><?php echo nl2br( esc_html( $settings['return_address'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p><?php endif; ?>
        <?php if ( $settings['instructions'] ) : ?><h4><?php esc_html_e( 'Returning your items', 'montonio-for-woocommerce' ); ?></h4><p><?php echo wp_kses( $settings['instructions'], WC_Montonio_Withdrawal_Block::ALLOWED_HTML ); ?></p><?php endif; ?>
    </div></aside>
    <?php if ( in_array( $stage, array( 'form', 'review' ), true ) ) : ?>
    <?php // Under the form box on desktop, last once the layout stacks (grid areas in the stylesheet). ?>
    <div class="montonio-withdrawal-ack"><?php wc_get_template( 'withdrawal-acknowledgement.php', array( 'settings' => $settings ), '', WC_MONTONIO_PLUGIN_PATH . '/templates/' ); ?></div>
    <?php endif; ?>
    </div>
</section>
