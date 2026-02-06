<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class WC_Montonio_Banners
 * 
 * Handles the display and management of promotional banners in the WordPress admin area.
 * Provides AJAX functionality for dismissing banners and stores user preferences.
 *
 * @since 9.0.5
 */
class WC_Montonio_Banners {
    /**
     * WC_Montonio_Banners constructor.
     *
     * @since 9.0.5
     */
    public function __construct() {
        add_action( 'wp_ajax_update_montonio_banner_visibility', array( $this, 'update_montonio_banner_visibility' ) );
        add_action( 'admin_notices', array( $this, 'render_all_banners' ) );
    }

    /**
     * Render all available banners
     *
     * @since 9.0.5
     * @return void
     */
    public function render_all_banners() {
        $this->banner_jan_2026();
    }

    /**
     * Handles updating whether to display the banner via AJAX.
     * 
     * Sets the banner status to hidden (0) in user meta when dismissed.
     *
     * @since 9.0.5
     * @return void Dies with 1 on success, -1 on failure
     */
    public function update_montonio_banner_visibility() {
        $id = isset( $_POST['id'] ) ? sanitize_key( $_POST['id'] ) : '';
    
        if ( empty( $id ) ) {
            wp_die( -1 );
        }
        
        check_ajax_referer( $id . '_nonce', $id . '_nonce_field' );

        // Get existing banner settings or initialize empty array
        $montonio_banners = get_user_meta( get_current_user_id(), 'montonio_banners', true );

        if ( ! is_array( $montonio_banners ) ) {
            $montonio_banners = array();
        }

        // Update the specific banner status (0 = hidden/dismissed)
        $montonio_banners[$id] = 0;

        // Save the updated array
        update_user_meta( get_current_user_id(), 'montonio_banners', $montonio_banners );

        wp_die( 1 );
    }

    /**
     * Check if a banner is dismissed for current user
     *
     * @since 9.0.5
     * @param string $id The banner ID to check
     * @return bool True if banner is dismissed, false otherwise
     */
    public function is_dismissed( $id ) {
        $montonio_banners = get_user_meta( get_current_user_id(), 'montonio_banners', true );

        return is_array( $montonio_banners ) && isset( $montonio_banners[$id] ) && $montonio_banners[$id] == 0;
    }

    /**
     * Render the montonio_banner_jan_2026 promotional banner
     *
     * @return void Early return if conditions not met
     */
    private function banner_jan_2026() {
        $id = 'montonio_banner_jan_2026';
        $api_keys = WC_Montonio_Helper::get_api_keys();

        if ( empty( $api_keys['access_key'] ) || empty( $api_keys['secret_key'] ) ) {
            return;
        }

        if ( $this->is_dismissed( $id ) ) {
            return;
        }

        if ( WC_Montonio_Helper::get_locale() === 'pl' ) {
            return;
        }
        ?>

        <div id="<?php echo esc_attr( $id ); ?>" class="montonio-banner notice">
            <?php wp_nonce_field( esc_attr( $id ) . '_nonce', esc_attr( $id ) . '_nonce_field' ); ?>

            <a class="montonio-banner__close" href="<?php echo esc_url( add_query_arg( $id, 0 ) ); ?>" aria-label="<?php esc_attr_e( 'Dismiss this notice' ); ?>">
                <span><?php esc_attr_e( 'Dismiss this notice', 'montonio-for-woocommerce' ); ?></span>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none">
                    <path d="M12 4L4 12M4 4L12 12" stroke="#FCFBFB" stroke-width="1.33" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </a>

            <div class="montonio-banner__logo">
                <svg width="184" height="24" viewBox="0 0 184 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M66.4403 6.00977C63.8911 6.00977 61.9707 7.13537 61.1103 9.15497C60.3487 6.70497 58.5277 6.00977 56.3099 6.00977C53.8931 6.00977 52.1383 7.03597 51.2445 8.88997V6.47317H48.0001V21.5366H51.6417L51.7079 13.3594C51.7411 10.3138 53.3963 9.22097 55.1179 9.22097C56.7731 9.22097 57.9651 9.94937 57.9981 13.1608L58.0643 21.5366H61.7059V13.3594C61.7391 10.3138 63.4275 9.22097 65.1821 9.22097C66.7711 9.22097 67.9631 9.94937 67.9961 13.1608L68.0625 21.5366H71.7041V12.6972C71.7041 7.30097 69.4529 6.00977 66.4403 6.00977Z" fill="currentColor"></path>
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M83.5243 18.5901C80.9751 18.5901 79.0879 16.7031 79.1541 13.9885C79.2203 11.3399 80.9751 9.41969 83.5243 9.41969C86.1065 9.41969 87.8613 11.3069 87.8943 13.9885C87.9607 16.7031 86.1065 18.5901 83.5243 18.5901ZM83.5243 6.00989C78.8893 6.00989 75.3801 9.15489 75.3801 14.0547C75.3801 18.9213 78.8231 22.0001 83.5243 22.0001C88.2255 22.0001 91.6685 18.9213 91.6685 14.0547C91.6685 9.15489 88.1591 6.00989 83.5243 6.00989Z" fill="currentColor"></path>
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M103.21 6.00977C100.76 6.00977 98.972 7.06917 98.078 8.88997V6.47317H94.8336V21.5366H98.4754L98.5414 13.2932C98.5746 10.3466 100.197 9.25417 102.051 9.25417C103.739 9.25417 104.898 9.94937 104.931 13.1608L104.997 21.5366H108.606V12.6972C108.606 7.30097 106.388 6.00977 103.21 6.00977Z" fill="currentColor"></path>
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M118.641 18.7888C117.217 18.7888 116.621 17.9278 116.621 15.8424V9.4858L120.958 9.4526V6.44H116.621V1H112.979V6.4732H111.125V9.5188H112.979V16.1072C112.979 20.3116 114.403 22 117.647 22C119.501 22 121.057 21.5034 122.018 20.775L121.157 17.8288C120.428 18.4578 119.601 18.7888 118.641 18.7888Z" fill="currentColor"></path>
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M131.784 18.5901C129.235 18.5901 127.348 16.7031 127.414 13.9885C127.48 11.3399 129.235 9.41969 131.784 9.41969C134.366 9.41969 136.121 11.3069 136.154 13.9885C136.22 16.7031 134.366 18.5901 131.784 18.5901ZM131.784 6.00989C127.149 6.00989 123.64 9.15489 123.64 14.0547C123.64 18.9213 127.083 22.0001 131.784 22.0001C136.485 22.0001 139.928 18.9213 139.928 14.0547C139.928 9.15489 136.419 6.00989 131.784 6.00989Z" fill="currentColor"></path>
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M151.469 6.00977C149.019 6.00977 147.232 7.06917 146.338 8.88997V6.47317H143.093V21.5366H146.735L146.801 13.2932C146.834 10.3466 148.457 9.25417 150.311 9.25417C151.999 9.25417 153.158 9.94937 153.191 13.1608L153.257 21.5366H156.866V12.6972C156.866 7.30097 154.648 6.00977 151.469 6.00977Z" fill="currentColor"></path>
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M162.898 0C161.706 0 160.68 0.9932 160.68 2.185C160.68 3.3768 161.706 4.37 162.898 4.37C164.057 4.37 165.083 3.3768 165.083 2.185C165.083 0.9932 164.057 0 162.898 0Z" fill="currentColor"></path>
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M160.945 21.5365H164.586L164.652 6.47327H160.945V21.5365Z" fill="currentColor"></path>
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M175.856 18.5901C173.307 18.5901 171.419 16.7031 171.486 13.9885C171.552 11.3399 173.307 9.41969 175.856 9.41969C178.438 9.41969 180.193 11.3069 180.226 13.9885C180.292 16.7031 178.438 18.5901 175.856 18.5901ZM175.856 6.00989C171.221 6.00989 167.712 9.15489 167.712 14.0547C167.712 18.9213 171.155 22.0001 175.856 22.0001C180.557 22.0001 184 18.9213 184 14.0547C184 9.15489 180.491 6.00989 175.856 6.00989Z" fill="currentColor"></path>
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M24.1428 20.7427C23.332 21.5535 22.2544 21.9999 21.1078 21.9999C19.9614 21.9999 18.8834 21.5535 18.0726 20.7427L15.114 17.7843L13.7 16.3701L12.2858 17.7843L9.32739 20.7427C8.51679 21.5535 7.43859 21.9999 6.29219 21.9999C5.14579 21.9999 4.06799 21.5535 3.25699 20.7427C2.44659 19.9321 1.99999 18.8543 1.99999 17.7077C1.99999 16.5613 2.44659 15.4833 3.25699 14.6725L10.4102 7.51972L10.4502 7.47972L10.488 7.43752C10.5494 7.36852 10.605 7.30952 10.6572 7.25712C11.4678 6.44652 12.5458 6.00012 13.693 6.00012H13.7054H13.7078C14.8544 6.00012 15.9322 6.44652 16.7432 7.25752C16.7954 7.30972 16.8504 7.36832 16.9114 7.43672L16.9494 7.47932L16.9898 7.51952L24.143 14.6725C24.9536 15.4833 25.4 16.5613 25.4 17.7077C25.4 18.8543 24.9536 19.9321 24.1428 20.7427ZM30.3054 7.25732C29.4946 6.44672 28.4168 6.00012 27.2704 6.00012C26.1238 6.00012 25.046 6.44672 24.2352 7.25732L21.8956 9.59692L18.404 6.10532C18.3244 6.01612 18.2426 5.92852 18.157 5.84292C16.9286 4.61432 15.318 4.00012 13.7078 4.00012H13.7H13.6922C12.082 4.00012 10.4716 4.61412 9.24299 5.84292C9.15739 5.92852 9.07559 6.01632 8.99619 6.10532L1.84299 13.2583C-0.614411 15.7157 -0.614411 19.6997 1.84299 22.1569C3.07159 23.3857 4.68199 23.9999 6.29219 23.9999C7.90259 23.9999 9.51299 23.3857 10.7416 22.1569L13.7 19.1985L16.6586 22.1569C17.8872 23.3857 19.4974 23.9999 21.1078 23.9999C22.718 23.9999 24.3286 23.3857 25.557 22.1569C27.1356 20.5785 27.6982 18.3703 27.2488 16.3411L31.627 20.7193C32.4378 21.5299 33.5154 21.9763 34.6622 21.9763C35.8086 21.9763 36.8864 21.5299 37.697 20.7193C38.5078 19.9085 38.9544 18.8305 38.9544 17.6841C38.9544 16.5377 38.5078 15.4597 37.697 14.6491L30.3054 7.25732Z" fill="currentColor"></path>
                </svg>
            </div>

            <div class="montonio-banner__content">
                <div class="montonio-banner__row">
                    <div class="montonio-banner__col" style="flex: 1;">
                        <h2><?php esc_html_e( 'International Shipping', 'montonio-for-woocommerce' ); ?> <span>Available now</span></h2>
                        <p><?php echo wp_kses_post( 
                            __( 'Send parcels to over 20+ EU countries, including Sweden, Germany, Spain, Italy, France, and more. <br><strong>Q1 2026 special: pricing is based only on weight, no matter the parcel size.</strong>', 'montonio-for-woocommerce' )
                        ); ?></p>
            
                        <div class="montonio-banner__actions">
                            <div class="montonio-banner__button">
                                <a class="montonio-button montonio-button--beige" href="https://help.montonio.com/en/articles/431075-montonio-international-shipping" target="_blank">
                                    <?php esc_html_e( 'Learn more and activate', 'montonio-for-woocommerce' ); ?>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none">
                                    <path d="M17.5 7.50001L17.5 2.50001M17.5 2.50001H12.5M17.5 2.50001L10 10M8.33333 2.5H6.5C5.09987 2.5 4.3998 2.5 3.86502 2.77248C3.39462 3.01217 3.01217 3.39462 2.77248 3.86502C2.5 4.3998 2.5 5.09987 2.5 6.5V13.5C2.5 14.9001 2.5 15.6002 2.77248 16.135C3.01217 16.6054 3.39462 16.9878 3.86502 17.2275C4.3998 17.5 5.09987 17.5 6.5 17.5H13.5C14.9001 17.5 15.6002 17.5 16.135 17.2275C16.6054 16.9878 16.9878 16.6054 17.2275 16.135C17.5 15.6002 17.5 14.9001 17.5 13.5V11.6667" stroke="#260071" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </a>
                            </div>
                            <div class="montonio-banner__button">
                                <a class="montonio-button montonio-button--transparent" href="https://shipping-calculator.montonio.com/?carrierCode=novaPost" target="_blank">
                                    <?php esc_html_e( 'Calculate shipping costs', 'montonio-for-woocommerce' ); ?>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none">
                                    <path d="M17.5 7.50001L17.5 2.50001M17.5 2.50001H12.5M17.5 2.50001L10 10M8.33333 2.5H6.5C5.09987 2.5 4.3998 2.5 3.86502 2.77248C3.39462 3.01217 3.01217 3.39462 2.77248 3.86502C2.5 4.3998 2.5 5.09987 2.5 6.5V13.5C2.5 14.9001 2.5 15.6002 2.77248 16.135C3.01217 16.6054 3.39462 16.9878 3.86502 17.2275C4.3998 17.5 5.09987 17.5 6.5 17.5H13.5C14.9001 17.5 15.6002 17.5 16.135 17.2275C16.6054 16.9878 16.9878 16.6054 17.2275 16.135C17.5 15.6002 17.5 14.9001 17.5 13.5V11.6667" stroke="#ffffff" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="montonio-banner__col">
                        <img src="<?php echo esc_url( WC_MONTONIO_PLUGIN_URL . '/assets/images/banner_jan_2026.svg' ); ?>" alt="<?php esc_attr_e( 'International Shipping', 'montonio-for-woocommerce' ); ?>" style="max-width: 188px; height: auto; margin-right:24px;">    
                    </div>
                </div>
            </div>
        </div>
    <?php
    }
}
new WC_Montonio_Banners();