<?php
defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Montonio_Shipping_Route_Setup_View
 *
 * Stateless renderer for the PHP-rendered shipping route setup wizard.
 * Takes data from WC_Montonio_Shipping_Route_Setup and returns HTML strings.
 *
 * @since 9.4.0
 */
class WC_Montonio_Shipping_Route_Setup_View {

    /**
     * Render the preview table with zones, methods, rates, dimension inputs, and action buttons.
     *
     * @since 9.4.0
     * @param array $plan The setup plan from compute_plan().
     * @return string HTML string.
     */
    public static function render_preview( $plan ) {
        $zones               = ! empty( $plan['zones'] ) ? $plan['zones'] : array();
        $currency            = ! empty( $plan['currency'] ) ? $plan['currency'] : get_woocommerce_currency();
        $has_dynamic_methods = ! empty( $plan['has_dynamic_methods'] );
        $warnings            = ! empty( $plan['warnings'] ) ? $plan['warnings'] : array();
        $dimension_unit      = ! empty( $plan['dimension_unit'] ) ? $plan['dimension_unit'] : get_option( 'woocommerce_dimension_unit', 'cm' );
        $weight_unit         = ! empty( $plan['weight_unit'] ) ? $plan['weight_unit'] : get_option( 'woocommerce_weight_unit', 'kg' );

        if ( empty( $zones ) ) {
            ob_start();
            ?>
            <div class="notice notice-info inline">
                <p><?php esc_html_e( 'No shipping methods available to set up.', 'montonio-for-woocommerce' ); ?></p>
            </div>
            <p class="montonio-setup-actions">
                <button type="button" class="button button-secondary" id="montonio-php-cancel-btn">
                    <?php esc_html_e( 'Back', 'montonio-for-woocommerce' ); ?>
                </button>
            </p>
            <?php
return ob_get_clean();
        }

        ob_start();
        ?>
        <div class="montonio-setup-preview">
            <p><?php esc_html_e( 'The following zones and methods will be created:', 'montonio-for-woocommerce' ); ?></p>

            <?php if ( ! empty( $warnings ) ): ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php
foreach ( $warnings as $index => $warning ) {
            if ( $index > 0 ) {
                echo '<br>';
            }
            echo esc_html( $warning );
        }
        ?>
                    </p>
                </div>
            <?php endif; ?>

            <table class="widefat montonio-setup-preview-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Zone', 'montonio-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'Countries', 'montonio-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'Method', 'montonio-for-woocommerce' ); ?></th>
                        <th>
                            <?php
printf(
            /* translators: %s: currency code */
            esc_html__( 'Rate (%s, excl. VAT)', 'montonio-for-woocommerce' ),
            esc_html( $currency )
        );
        ?>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php
foreach ( $zones as $zone ):
            $methods       = ! empty( $zone['methods'] ) ? $zone['methods'] : array();
            $row_count     = max( count( $methods ), 1 );
            $countries_str = ! empty( $zone['countries'] ) ? implode( ', ', $zone['countries'] ) : '';
            $is_existing   = ! empty( $zone['skipped'] );

            for ( $j = 0; $j < $row_count; $j++ ):
                $classes = array();
                if ( 0 === $j ) {
                    $classes[] = 'montonio-zone-first-row';
                }
                if ( $is_existing ) {
                    $classes[] = 'montonio-setup-skipped';
                }
        ?>
                            <tr<?php echo ! empty( $classes ) ? ' class="' . esc_attr( implode( ' ', $classes ) ) . '"' : ''; ?>>
                                <?php if ( 0 === $j ): ?>
                                    <td rowspan="<?php echo esc_attr( $row_count ); ?>">
                                        <?php echo esc_html( $zone['name'] ); ?>
                                        <?php if ( $is_existing ): ?>
                                            <em>(<?php
if ( ! empty( $zone['covered_by'] ) ) {
                    printf(
                        /* translators: %s: name of the existing zone */
                        esc_html__( 'already covered by zone "%s"', 'montonio-for-woocommerce' ),
                        esc_html( $zone['covered_by'] )
                    );
                } else {
                    esc_html_e( 'already covered by an existing zone', 'montonio-for-woocommerce' );
                }
                ?>)</em>
                                            <br>
                                            <em><?php esc_html_e( 'New methods must be added manually', 'montonio-for-woocommerce' ); ?></em>
                                        <?php endif; ?>
                                    </td>
                                    <td rowspan="<?php echo esc_attr( $row_count ); ?>">
                                        <?php echo esc_html( $countries_str ); ?>
                                    </td>
                                <?php endif; ?>

                                <?php if ( isset( $methods[$j] ) ): ?>
                                    <td><?php echo esc_html( $methods[$j]['title'] ); ?></td>
                                    <td><?php echo self::format_rate( $methods[$j]['rate'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped  ?></td>
                                <?php else: ?>
                                    <td colspan="2">&mdash;</td>
                                <?php endif; ?>
                            </tr>
                        <?php endfor; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ( $has_dynamic_methods ): ?>
                <div class="montonio-setup-dimensions">
                    <h3><?php esc_html_e( 'Default Package Dimensions for International Shipping', 'montonio-for-woocommerce' ); ?></h3>
                    <p><?php esc_html_e( 'These dimensions are used when products do not have shipping dimensions defined. Required for dynamic rate calculation.', 'montonio-for-woocommerce' ); ?></p>

                    <div class="notice notice-error inline montonio-dim-error hidden" id="montonio-php-dim-error">
                        <p><?php esc_html_e( 'Please fill in all package dimension fields with values greater than 0.', 'montonio-for-woocommerce' ); ?></p>
                    </div>

                    <table class="form-table">
                        <tbody>
                            <tr>
                                <th>
                                    <label for="montonio-php-dim-length">
                                        <?php
printf(
            /* translators: %s: dimension unit (e.g. cm) */
            esc_html__( 'Length (%s)', 'montonio-for-woocommerce' ),
            esc_html( $dimension_unit )
        );
        ?>
                                        <span class="required">*</span>
                                    </label>
                                </th>
                                <td><input type="number" id="montonio-php-dim-length" class="small-text" min="0.01" step="0.01" /></td>
                            </tr>
                            <tr>
                                <th>
                                    <label for="montonio-php-dim-width">
                                        <?php
printf(
            /* translators: %s: dimension unit (e.g. cm) */
            esc_html__( 'Width (%s)', 'montonio-for-woocommerce' ),
            esc_html( $dimension_unit )
        );
        ?>
                                        <span class="required">*</span>
                                    </label>
                                </th>
                                <td><input type="number" id="montonio-php-dim-width" class="small-text" min="0.01" step="0.01" /></td>
                            </tr>
                            <tr>
                                <th>
                                    <label for="montonio-php-dim-height">
                                        <?php
printf(
            /* translators: %s: dimension unit (e.g. cm) */
            esc_html__( 'Height (%s)', 'montonio-for-woocommerce' ),
            esc_html( $dimension_unit )
        );
        ?>
                                        <span class="required">*</span>
                                    </label>
                                </th>
                                <td><input type="number" id="montonio-php-dim-height" class="small-text" min="0.01" step="0.01" /></td>
                            </tr>
                            <tr>
                                <th>
                                    <label for="montonio-php-dim-weight">
                                        <?php
printf(
            /* translators: %s: weight unit (e.g. kg) */
            esc_html__( 'Weight (%s)', 'montonio-for-woocommerce' ),
            esc_html( $weight_unit )
        );
        ?>
                                        <span class="required">*</span>
                                    </label>
                                </th>
                                <td><input type="number" id="montonio-php-dim-weight" class="small-text" min="0.01" step="0.01" /></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <p class="montonio-setup-actions">
                <button type="button" class="button button-secondary" id="montonio-php-cancel-btn">
                    <?php esc_html_e( 'Cancel', 'montonio-for-woocommerce' ); ?>
                </button>
                <button type="button" class="button button-primary" id="montonio-php-confirm-btn">
                    <?php esc_html_e( 'Confirm and create', 'montonio-for-woocommerce' ); ?>
                </button>
            </p>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Render the summary after zone creation.
     *
     * @since 9.4.0
     * @param array $result  The result from execute_plan() with 'created' and 'skipped' keys.
     * @param array $skipped Additional zones skipped due to TOCTOU re-validation.
     * @return string HTML string.
     */
    public static function render_summary( $result, $skipped = array() ) {
        $created       = ! empty( $result['created'] ) ? $result['created'] : array();
        $all_skipped   = ! empty( $result['skipped'] ) ? $result['skipped'] : array();
        $total_methods = 0;

        // Merge any additional TOCTOU-skipped zones
        if ( ! empty( $skipped ) ) {
            $all_skipped = array_merge( $all_skipped, $skipped );
        }

        foreach ( $created as $zone ) {
            $total_methods += isset( $zone['method_count'] ) ? (int) $zone['method_count'] : 0;
        }

        $shipping_settings_url = admin_url( 'admin.php?page=wc-settings&tab=shipping' );

        ob_start();
        ?>
        <div class="montonio-setup-summary">
            <div class="notice notice-success inline">
                <p><strong><?php esc_html_e( 'Routes created successfully', 'montonio-for-woocommerce' ); ?></strong></p>
            </div>

            <p>
                <?php
printf(
            /* translators: 1: number of zones created, 2: number of methods created */
            esc_html__( 'Created %1$d shipping zones with %2$d shipping methods:', 'montonio-for-woocommerce' ),
            count( $created ),
            $total_methods
        );
        ?>
            </p>

            <ul>
                <?php foreach ( $created as $zone ): ?>
                    <li>
                        <?php
$countries_str = ! empty( $zone['countries'] ) ? implode( ', ', $zone['countries'] ) : '';
        $method_count  = isset( $zone['method_count'] ) ? (int) $zone['method_count'] : 0;
        printf(
            /* translators: 1: zone name, 2: country codes, 3: number of methods */
            esc_html__( '%1$s (%2$s) — %3$d methods', 'montonio-for-woocommerce' ),
            esc_html( $zone['name'] ),
            esc_html( $countries_str ),
            $method_count
        );
        ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if ( ! empty( $all_skipped ) ): ?>
                <p>
                    <?php
printf(
            /* translators: %d: number of skipped zones */
            esc_html__( '%d zones skipped (already covered by existing zones)', 'montonio-for-woocommerce' ),
            count( $all_skipped )
        );
        ?>
                </p>
                <ul>
                    <?php foreach ( $all_skipped as $zone ): ?>
                        <li><?php
if ( ! empty( $zone['covered_by'] ) ) {
            printf(
                /* translators: 1: zone name, 2: name of the existing zone */
                esc_html__( '%1$s — already covered by zone "%2$s"', 'montonio-for-woocommerce' ),
                esc_html( $zone['name'] ),
                esc_html( $zone['covered_by'] )
            );
        } else {
            echo esc_html( $zone['name'] );
        }
        ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <p>
                <?php
printf(
            /* translators: %s: URL to WooCommerce shipping settings */
            wp_kses_post( __( 'Edit zones in <a href="%s">WooCommerce &gt; Settings &gt; Shipping</a>.', 'montonio-for-woocommerce' ) ),
            esc_url( $shipping_settings_url )
        );
        ?>
            </p>

            <p class="montonio-setup-actions">
                <button type="button" class="button button-secondary" id="montonio-php-done-btn">
                    <?php esc_html_e( 'Done', 'montonio-for-woocommerce' ); ?>
                </button>
            </p>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Render an error notice with a back button.
     *
     * @since 9.4.0
     * @param string $message The error message.
     * @return string HTML string.
     */
    public static function render_error( $message ) {
        ob_start();
        ?>
        <div class="notice notice-error inline">
            <p><?php echo esc_html( $message ); ?></p>
        </div>
        <p class="montonio-setup-actions">
            <button type="button" class="button button-secondary" id="montonio-php-back-btn">
                <?php esc_html_e( 'Back', 'montonio-for-woocommerce' ); ?>
            </button>
        </p>
        <?php

        return ob_get_clean();
    }

    /**
     * Render an info notice with a back button.
     *
     * @since 9.4.0
     * @param string $message The info message.
     * @return string HTML string.
     */
    public static function render_info( $message ) {
        ob_start();
        ?>
        <div class="notice notice-info inline">
            <p><?php echo esc_html( $message ); ?></p>
        </div>
        <p class="montonio-setup-actions">
            <button type="button" class="button button-secondary" id="montonio-php-back-btn">
                <?php esc_html_e( 'Back', 'montonio-for-woocommerce' ); ?>
            </button>
        </p>
        <?php

        return ob_get_clean();
    }

    /**
     * Format a rate value for display.
     *
     * @since 9.4.0
     * @param mixed $rate The rate value, or null for dynamic rates.
     * @return string Escaped HTML string.
     */
    private static function format_rate( $rate ) {
        if ( null === $rate || '' === $rate ) {
            return '<em>' . esc_html__( 'Dynamic', 'montonio-for-woocommerce' ) . '</em>';
        }

        $num = (float) $rate;

        return esc_html( number_format( $num, 2, '.', '' ) );
    }
}
