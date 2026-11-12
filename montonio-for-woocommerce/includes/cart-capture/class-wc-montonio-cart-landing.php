<?php
defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Montonio_Cart_Landing
 *
 * Reports to Ladu that a cart rescue email's restore link was opened, on every checkout landing that
 * carries one, independently of the restore. The report is queued on the page request and sent by
 * Action Scheduler.
 *
 * @since 10.5.2
 */
class WC_Montonio_Cart_Landing {
    const ACTION_HOOK             = 'montonio_cart_landed_push';
    // The payload waits here, not in the action's args, which Action Scheduler keeps for weeks after a run.
    const PREFIX                  = 'montonio_cl_';
    const TTL                     = DAY_IN_SECONDS;
    const USER_AGENT_MAX_BYTES    = 256;
    const TOKEN_MAC_CONTEXT       = 'montonio-cart-restore|';
    // Bounds what one real link, replayed without a session and with a new user agent each time, can queue.
    const MAX_SESSIONLESS_REPORTS = 20;

    /**
     * Registers the landing hooks.
     *
     * @since 10.5.2
     * @return void
     */
    public static function init() {
        // Before WC_Montonio_Cart_Restore::maybe_restore (template_redirect, 5), so the landing is reported whatever the restore decides.
        add_action( 'template_redirect', array( __CLASS__, 'report_landing' ), 4 );
        add_action( self::ACTION_HOOK, array( __CLASS__, 'push_landing' ), 10, 1 );
    }

    /**
     * Queues a report to Ladu that the rescue link in the checkout URL was opened, whatever
     * WC_Montonio_Cart_Restore decides next. The report is sent by push_landing, off the page request.
     * Never throws: a failed report must not stop the restore or the page.
     *
     * @since 10.5.2
     * @return void
     */
    public static function report_landing() {
        try {
            $token = WC_Montonio_Cart_Restore::requested_token();

            if ( null === $token ) {
                return;
            }

            if ( ! WC_Montonio_Cart_Capture::is_active() ) {
                return;
            }

            // Only a restore id Ladu minted is reported, so made-up ids cannot flood the queue. The restore itself
            // stays open to ids minted before Ladu signed them.
            $keys = WC_Montonio_Helper::get_api_keys();

            if ( ! self::is_signed_token( $token, (string) $keys['secret_key'] ) ) {
                // Regenerated keys, a test/live switch or a link from before Ladu signed ids all land here; the id is not logged.
                WC_Montonio_Logger::warning_once( 'cart_landing_unverified', 'Cart landing not reported: the restore id did not verify against the current API keys' );
                return;
            }

            // A landing without a session is reported too, with an empty key: Ladu records the open, and no cart row
            // has an empty key, so it never feeds attribution.
            $session_key = WC()->session && WC()->session->has_session() ? WC_Montonio_Cart_Capture::session_key() : '';
            $user_agent  = self::user_agent();
            // Without a session the browser tells landings apart, so a scanner's landing cannot hide the shopper's.
            $key         = self::PREFIX . md5( $token . '|' . $session_key . ( '' === $session_key ? '|' . md5( $user_agent ) : '' ) );

            // One report per id and session while the transient lives, independent of the restore's tried flag.
            // A report already queued or running for this key is not queued twice, even when its transient is gone.
            if ( false === get_transient( $key ) && ! as_has_scheduled_action( self::ACTION_HOOK, array( $key ), WC_Montonio_Cart_Capture::ACTION_GROUP ) ) {
                if ( '' === $session_key && ! self::count_sessionless_report( $token ) ) {
                    return;
                }

                set_transient( $key, array(
                    'restore_token'  => $token,
                    'session_key'    => $session_key,
                    'plugin_version' => WC_MONTONIO_PLUGIN_VERSION,
                    'user_agent'     => $user_agent,
                    // The open time: the report may be sent minutes later, after retries.
                    'landed_at'      => WC_Montonio_Cart_Capture::timestamp()
                ), self::TTL );

                if ( ! as_enqueue_async_action( self::ACTION_HOOK, array( $key ), WC_Montonio_Cart_Capture::ACTION_GROUP, false, WC_Montonio_Cart_Capture::ACTION_PRIORITY ) ) {
                    delete_transient( $key );
                    throw new Exception( 'enqueueing the report failed' );
                }
            }
        } catch ( Throwable $e ) {
            WC_Montonio_Logger::log( 'Cart landing report failed: ' . WC_Montonio_Logger::describe( $e ) );
        }
    }

    /**
     * Sends a queued landing report, the Action Scheduler callback of report_landing. A final answer ends it; a
     * transport error, 408, 429 or 5xx is retried with cart capture's backoff, up to its attempt cap.
     *
     * @since 10.5.2
     * @param string $key The transient that holds the report.
     * @return void
     * @throws Exception When the report is given up or its retry cannot be scheduled, so Action Scheduler records the failure.
     */
    public static function push_landing( $key ) {
        $key     = (string) $key;
        $landing = get_transient( $key );

        if ( 0 !== strpos( $key, self::PREFIX ) ) {
            return;
        }

        // An object cache can evict the payload before the queued action runs.
        if ( false === $landing ) {
            WC_Montonio_Logger::log( 'Cart landing report dropped: its payload was gone when the queued action ran' );
            return;
        }

        if ( ! is_array( $landing ) || ! isset( $landing['restore_token'] ) ) {
            return;
        }

        // Capture switched off or unable to send: the report is dropped, with its payload.
        if ( ! WC_Montonio_Cart_Capture::is_active() ) {
            delete_transient( $key );
            return;
        }

        try {
            $result = WC_Montonio_Ladu_Client::post( 'landed', array_merge( array( 'kind' => 'landed' ), $landing ), time() + 60, WC_Montonio_Cart_Capture::SEND_TIMEOUT_SECONDS );
        } catch ( Throwable $e ) {
            // Counted as a transport error, so it is retried like one.
            $result = array( 'status' => 0, 'body' => '', 'error' => new WP_Error( 'exception', WC_Montonio_Logger::describe( $e ) ) );
        }

        $attempts_key = self::landed_attempts_key( $key );
        $outcome      = null !== $result['error'] ? 'failed: ' . $result['error']->get_error_code() : 'answered HTTP ' . $result['status'];

        // 404 also comes from a Ladu without the route, which a retry cannot change.
        if ( ! WC_Montonio_Cart_Capture::is_retryable_status( $result['status'] ) ) {
            // The payload goes; a marker without it keeps a repeated landing from queueing the report again.
            set_transient( $key, array( 'status' => 'sent' ), self::TTL );
            delete_transient( $attempts_key );

            if ( $result['status'] < 200 || $result['status'] > 299 ) {
                WC_Montonio_Logger::log( 'Cart landing report ' . $outcome );
            }

            return;
        }

        $attempt = (int) get_transient( $attempts_key );
        $message = 'Cart landing report ' . $outcome . ' on attempt ' . ( $attempt + 1 ) . ' of ' . WC_Montonio_Cart_Capture::MAX_ATTEMPTS;
        $failed  = WC_Montonio_Cart_Capture::schedule_retry( self::ACTION_HOOK, array( $key ), $attempts_key, $message );

        if ( null !== $failed ) {
            delete_transient( $key );
            delete_transient( $attempts_key );
            throw new Exception( $message . '; ' . $failed );
        }
    }

    /**
     * Whether $token is a restore id Ladu minted for this store: a 32-hex nonce followed by the token MAC of that nonce.
     *
     * @internal Public for the tests; not part of the public API.
     * @since 10.5.2
     * @param string $token  The 64-hex restore id.
     * @param string $secret The store's Montonio secret key for the current mode.
     * @return bool
     */
    public static function is_signed_token( $token, $secret ) {
        if ( '' === $secret || 1 !== preg_match( '/^[0-9a-f]{64}$/', (string) $token ) ) {
            return false;
        }

        return hash_equals( self::token_mac( substr( $token, 0, 32 ), $secret ), substr( $token, 32 ) );
    }

    /**
     * The MAC Ladu appends to a restore id's nonce: the first 16 bytes, in hex, of HMAC-SHA256 over
     * TOKEN_MAC_CONTEXT and the nonce, keyed with the store's Montonio secret key.
     *
     * @internal Public for the tests; not part of the public API.
     * @since 10.5.2
     * @param string $nonce  The token's first 32 hex characters.
     * @param string $secret The store's Montonio secret key for the current mode.
     * @return string 32 hex characters.
     */
    public static function token_mac( $nonce, $secret ) {
        return substr( hash_hmac( 'sha256', self::TOKEN_MAC_CONTEXT . $nonce, $secret ), 0, 32 );
    }

    /**
     * The transient that counts a landing report's send attempts.
     *
     * @internal Public for the tests; not part of the public API.
     * @since 10.5.2
     * @param string $key The transient that holds the report.
     * @return string
     */
    public static function landed_attempts_key( $key ) {
        return self::PREFIX . 'n_' . substr( (string) $key, strlen( self::PREFIX ) );
    }

    /**
     * The transient that counts the reports queued for $token from landings without a session.
     *
     * @internal Public for the tests; not part of the public API.
     * @since 10.5.2
     * @param string $token The restore id.
     * @return string
     */
    public static function sessionless_count_key( $token ) {
        return self::PREFIX . 'c_' . md5( (string) $token );
    }

    /**
     * Counts one more report for $token from a landing without a session, unless MAX_SESSIONLESS_REPORTS were
     * already counted while the counter lives.
     *
     * @since 10.5.2
     * @param string $token The restore id.
     * @return bool Whether the report may be queued.
     */
    private static function count_sessionless_report( $token ) {
        $counter = self::sessionless_count_key( $token );
        $count   = (int) get_transient( $counter );

        if ( $count >= self::MAX_SESSIONLESS_REPORTS ) {
            return false;
        }

        set_transient( $counter, $count + 1, self::TTL );
        return true;
    }

    /**
     * The landing browser's user agent, sanitized and cut to USER_AGENT_MAX_BYTES on a character boundary so the claim stays valid UTF-8.
     *
     * @since 10.5.2
     * @return string Empty when the request has none.
     */
    private static function user_agent() {
        if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) || ! is_string( $_SERVER['HTTP_USER_AGENT'] ) ) {
            return '';
        }

        $user_agent = substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, self::USER_AGENT_MAX_BYTES );

        for ( $i = 0; $i < 4 && '' !== $user_agent && 1 !== preg_match( '//u', $user_agent ); $i++ ) {
            $user_agent = substr( $user_agent, 0, -1 );
        }

        return 1 === preg_match( '//u', $user_agent ) ? $user_agent : '';
    }
}
