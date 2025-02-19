<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Montonio_Logger {

	public static $logger;
	const WC_LOG_FILENAME = 'montonio-for-woocommerce';

	public static function log( $message ) {
		if ( ! class_exists( 'WC_Logger' ) ) {
			return;
		}

		if ( apply_filters( 'wc_montonio_logging', true, $message ) ) {
			if ( empty( self::$logger ) ) {
				self::$logger = wc_get_logger();
			}

			$log_entry  = "\n" . '====Montonio Version: ' . WC_MONTONIO_PLUGIN_VERSION . '====' . "\n";
			$log_entry .= $message . "\n\n";

			self::$logger->debug( $log_entry, [ 'source' => self::WC_LOG_FILENAME ] );
		}
	}
}
