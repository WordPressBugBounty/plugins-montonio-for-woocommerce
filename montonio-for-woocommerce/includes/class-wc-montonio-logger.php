<?php
defined( 'ABSPATH' ) || exit;

class WC_Montonio_Logger {

	public static $logger;
	const WC_LOG_FILENAME = 'montonio-for-woocommerce';
	const ONCE_PREFIX     = 'montonio_log_once_';

	public static function log( $message ) {
		if ( ! class_exists( 'WC_Logger' ) ) {
			return;
		}

		if ( apply_filters( 'wc_montonio_logging', true, $message ) ) {
			if ( empty( self::$logger ) ) {
				self::$logger = wc_get_logger();
			}

			self::$logger->debug( self::entry( $message ), [ 'source' => self::WC_LOG_FILENAME ] );
		}
	}

	/**
	 * Writes a warning at most once a day per key, so a fault that repeats on every request writes one line.
	 * Like log(), it writes nothing while the wc_montonio_logging filter is false and heads the line with the version.
	 *
	 * @param string $key     What is being reported; the throttle is per key.
	 * @param string $message The line.
	 * @param string $source  The WooCommerce log source.
	 * @return void
	 */
	public static function warning_once( $key, $message, $source = self::WC_LOG_FILENAME ) {
		try {
			if ( ! class_exists( 'WC_Logger' ) || ! apply_filters( 'wc_montonio_logging', true, $message ) ) {
				return;
			}

			$transient = self::ONCE_PREFIX . $key;

			if ( false !== get_transient( $transient ) ) {
				return;
			}

			set_transient( $transient, 1, DAY_IN_SECONDS );
			wc_get_logger()->warning( self::entry( $message ), [ 'source' => $source ] );
		} catch ( Throwable $e ) {
			// A failing transient or log handler must not turn a logged fault into a broken request.
			return;
		}
	}

	// A log line headed with the plugin version.
	private static function entry( $message ) {
		return "\n" . '====Montonio Version: ' . WC_MONTONIO_PLUGIN_VERSION . '====' . "\n" . $message . "\n\n";
	}

	/**
	 * An exception as one log line: class, message and where it was thrown.
	 *
	 * @param Throwable $e The exception.
	 * @return string
	 */
	public static function describe( Throwable $e ) {
		return get_class( $e ) . ': ' . $e->getMessage() . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine();
	}
}
