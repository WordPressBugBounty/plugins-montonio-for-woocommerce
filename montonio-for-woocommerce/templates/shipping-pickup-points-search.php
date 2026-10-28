<?php
defined( 'ABSPATH' ) || exit;
?>

<tr class="montonio-pickup-point">
    <td colspan="2" class="forminp">
        <div class="montonio-pickup-point__container">
            <label id="montonio-pickup-point__label" class="montonio-pickup-point__label">
                <?php echo esc_html__( 'Pickup point', 'montonio-for-woocommerce' ); ?> 
                <abbr class="required" title="required">*</abbr>
            </label>

            <div class="montonio-pickup-point__search">
                <div class="montonio-pickup-point__search-logos" data-operators='<?php echo esc_attr( json_encode( ! empty( $operators ) && is_array( $operators ) ? $operators : array( $carrier ) ) ); ?>'></div>
                <div id="montonio-pickup-point__toggle" class="montonio-pickup-point__toggle" tabindex="0" role="combobox" aria-haspopup="listbox" aria-controls="montonio-pickup-point__dropdown" aria-labelledby="montonio-pickup-point__label">
                    <span class="montonio-pickup-point__toggle-placeholder"><?php echo esc_html__( 'Select a pickup point', 'montonio-for-woocommerce' ); ?></span>
                    <span class="montonio-pickup-point__toggle-value"></span>
                </div>
            </div>

            <div class="montonio-pickup-point__panel" id="montonio-pickup-point__panel">
                <input 
                    type="text" 
                    id="montonio-pickup-point__search-input" 
                    class="montonio-pickup-point__search-input"
                    placeholder="<?php echo esc_attr__( 'Search by name, city or address', 'montonio-for-woocommerce' ); ?>" 
                    autocomplete="off" 
                    role="searchbox"
                    aria-autocomplete="list"
                    aria-controls="montonio-pickup-point__dropdown"
                    data-carrier="<?php echo esc_attr( $carrier ); ?>" 
                    data-country="<?php echo esc_attr( $country ); ?>"
                    data-city="<?php echo esc_attr( $city ?? '' ); ?>"
                    data-type="<?php echo esc_attr( $type ); ?>"
                >

                <div class="montonio-pickup-point__dropdown" id="montonio-pickup-point__dropdown" role="listbox" tabindex="-1" aria-labelledby="montonio-pickup-point__label">
                    <!-- Results will be populated here -->
                </div>
            </div>
        </div>

        <input 
            type="hidden" 
            id="montonio_pickup_point" 
            class="montonio-pickup-point__selected" 
            name="montonio_pickup_point" 
            value=""
        >
    </td>
</tr>