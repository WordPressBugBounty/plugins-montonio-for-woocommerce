<?php
defined('ABSPATH') or exit;

class MontonioHelper {

    /**
     * Method that returns the appropriate locale identifier used in Montonio's systems
     *
     * @param string $locale The locale to search for
     * @return string identifier for locale if found, en_US by default
     */
    public static function getLocale($locale) {
        if (in_array($locale, array('lt', 'lt_LT', 'lt_lt', 'lt-LT', 'lt-lt'))) {
            return 'lt';
        } else if (in_array($locale, array('lv', 'lv_LV', 'lv_lv', 'lv-LV', 'lv-lv'))) {
            return 'lv';
        } else if (in_array($locale, array('ru', 'ru_RU', 'ru_ru', 'ru-RU', 'ru-ru'))) {
            return 'ru';
        } else if (in_array($locale, array('et', 'ee', 'EE', 'ee_EE', 'ee-EE', 'et_EE', 'et-EE', 'et_ee', 'et-ee'))) {
            return 'et';
        } else if (in_array($locale, array('fi', 'fi_FI', 'fi_fi', 'fi-FI', 'fi-fi'))) {
            return 'fi';
        } else if (in_array($locale, array('pl', 'pl_PL', 'pl_pl', 'pl-PL', 'pl-pl'))) {
            return 'pl';
        } else {
            return 'en_US';
        }
    }

    /**
     * Method that returns the appropriate currency identifier used in Montonio's systems
     *
     * @param string $locale The currency to search for
     * @return string identifier for currency if found, EUR by default
     */
    public static function getCurrency() {
        global $woocommerce_wpml;

        $currency = get_woocommerce_currency();

        // WPML Multi-Currency support
        if ( ! is_null($woocommerce_wpml ) ) {
            if ( function_exists( 'wcml_is_multi_currency_on' ) && wcml_is_multi_currency_on() ) {
                $currency = $woocommerce_wpml->multi_currency->get_client_currency();
            }
        }

        if ($currency === 'PLN') {
            return 'PLN';
        } else {
            return 'EUR';
        }
    }

    public static function isClientCurrencySupported( $supported_currencies = array( 'EUR', 'PLN' ) ) {
        global $woocommerce_wpml;

        $currency = get_woocommerce_currency();

        // WPML Multi-Currency support
        if ( ! is_null($woocommerce_wpml ) ) {
            if ( function_exists( 'wcml_is_multi_currency_on' ) && wcml_is_multi_currency_on() ) {
                $currency = $woocommerce_wpml->multi_currency->get_client_currency();
            }
        }

        return in_array( $currency, $supported_currencies );
    }

    /**
     * Handle the result of an action and append it to the status report
     *
     * @since 7.1.2
     * @param array $status_report The status report of the OTA sync
     * @param string $status Status of the action ('success' or 'error')
     * @param string $message The message to include in the status report
     */
    public static function append_to_status_report( &$status_report, $status, $message ) {
        $status_report['sync_results'][] = array(
            'status'  => $status,
            'message' => date('Y-m-d H:i:s') . ' - ' . $message
        );
    }
}