<?php
defined( 'ABSPATH' ) || exit;
?>

<tr class="montonio-pickup-point">
    <td colspan="2" class="forminp">
        <div class="montonio-pickup-point__container">
            <label for="montonio-pickup-point__search-input" class="montonio-pickup-point__label">
                <?php echo esc_html__( 'Pickup point', 'montonio-for-woocommerce' ); ?> 
                <abbr class="required" title="required">*</abbr>
            </label>

            <div class="montonio-pickup-point__search">
                <div class="montonio-pickup-point__search-logos" data-operators='<?php echo esc_attr( json_encode( ! empty( $operators ) && is_array( $operators ) ? $operators : array( $carrier ) ) ); ?>'></div>
                <input 
                    type="text" 
                    id="montonio-pickup-point__search-input" 
                    class="montonio-pickup-point__search-input"
                    placeholder="<?php echo esc_html__( 'Type at least 3 characters to search...', 'montonio-for-woocommerce' ); ?>" 
                    autocomplete="off" 
                    data-carrier="<?php echo esc_attr( $carrier ); ?>" 
                    data-country="<?php echo esc_attr( $country ); ?>"
                    data-type="<?php echo esc_attr( $type ); ?>"
                >
            </div>

                
            <div class="montonio-pickup-point__dropdown" id="montonio-pickup-point__dropdown">
                <!-- Results will be populated here -->
            </div>
            
            <div class="montonio-pickup-point__error montonio-pickup-point__error--hidden" id="montonio-pickup-point__error">
                <!-- Error messages will appear here -->
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