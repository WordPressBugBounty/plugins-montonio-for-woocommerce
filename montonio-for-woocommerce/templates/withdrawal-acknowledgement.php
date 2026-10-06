<?php
/**
 * Acknowledgement information shared by details and review.
 *
 * @since 10.4.0
 * @var array $settings Saved withdrawal settings.
 */
defined( 'ABSPATH' ) || exit;
?>
<p class="montonio-withdrawal-help montonio-withdrawal-ack-copy">
    <?php /* translators: %s: seller legal entity name */ echo esc_html( sprintf( __( 'Once you confirm your withdrawal, %s will send you an acknowledgement by email without delay, which you can save for your records.', 'montonio-for-woocommerce' ), $settings['legal_name'] ) ); ?>
    <?php esc_html_e( 'This acknowledges your notice and does not confirm a refund or return approval.', 'montonio-for-woocommerce' ); ?>
    <?php
    printf(
        /* translators: %s: link to Directive (EU) 2023/2673 */
        esc_html__( 'See Article 11a(4) of Directive 2011/83/EU, inserted by %s.', 'montonio-for-woocommerce' ),
        '<a href="https://eur-lex.europa.eu/eli/dir/2023/2673/oj/eng" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Directive (EU) 2023/2673', 'montonio-for-woocommerce' ) . '</a>'
    );
    ?>
</p>
