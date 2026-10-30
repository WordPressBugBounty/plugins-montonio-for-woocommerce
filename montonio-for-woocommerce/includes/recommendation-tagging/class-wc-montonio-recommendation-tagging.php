<?php
defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Montonio_Recommendation_Tagging
 *
 * Records, per cart item, which recommendation surface the product was added from (an
 * up-sell list, a cross-sell list, or neither) and the anchor products whose lists held it,
 * with the evidence the decision was made from, then copies both to the order item as meta.
 * Classification is by link membership at add time; a short-lived note the storefront script
 * sends to the store, kept with the shopper's session, narrows the anchor and carries a click from
 * a recommendation card to the product page. The tag also travels with a cart rescue: cart capture sends it to Ladu and the
 * restore puts it back on the rebuilt cart item.
 */
class WC_Montonio_Recommendation_Tagging {
    const MIN_WC_VERSION = '10.9.0';
    const NOTE_VERSION   = 'v3';
    const NOTE_PARTS     = 7;
    const NOTE_MAX_AGE   = 1800;
    const CART_ITEM_KEY  = 'montonio_rec';
    const OPTION_ENABLED = 'montonio_rec_tagging_enabled';
    const LOG_SOURCE     = 'montonio-recommendation-tagging';
    const SURFACES       = array( 'upsell', 'cross_sell', 'organic' );
    // The section words a note may carry; only cross_sell changes a verdict, the rest are kept as evidence.
    const HINTS          = array( 'upsell', 'cross_sell', 'related', 'unknown' );
    const MAX_ID         = 2147483647;
    const MAX_ANCHORS    = 20;
    const MAX_CANDIDATES = 20;
    const MAX_SHOWN      = 24;
    const NOTE_ACTION    = 'montonio_rec_note';
    const ITEM_META_KEYS = array( '_montonio_rec_surface', '_montonio_rec_anchors', '_montonio_rec_added_at', '_montonio_rec_evidence' );

    /**
     * Registers the hooks. Below the WooCommerce floor only the admin item-meta hiding stays on, and the floor is logged once a day where Ladu switched tagging on.
     *
     * @return void
     */
    public static function init() {
        // Before the floor check: orders tagged before a WooCommerce downgrade, or before the floor was raised, stay hidden on the admin order screen either way.
        add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hide_order_itemmeta' ) );

        if ( ! self::is_supported() ) {
            // The option is autoloaded, so the check costs no query. Once a day at best: the throttle is a transient, so a flushed object cache repeats the line.
            if ( 'yes' === get_option( self::OPTION_ENABLED ) ) {
                WC_Montonio_Logger::warning_once( 'rec_below_floor', 'Recommendation tagging is off: WooCommerce is older than ' . self::MIN_WC_VERSION . '.', self::LOG_SOURCE );
            }
            return;
        }

        // Last, so no other filter on the new cart item rebuilds it without the tag.
        add_filter( 'woocommerce_add_cart_item', array( __CLASS__, 'tag_new_line' ), PHP_INT_MAX, 2 );
        add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'copy_tag_to_order_line' ), 10, 3 );
        // After the plugin's enqueue_frontend_assets (10), which registers the note script.
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_note_script' ), 20 );
        add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
        add_action( 'wc_ajax_' . self::NOTE_ACTION, array( __CLASS__, 'note_request' ) );
    }

    /**
     * Keeps the tag's keys off the admin order screen, where WooCommerce shows underscore-prefixed item meta and lets staff edit it by hand.
     *
     * @param string[] $hidden The keys WooCommerce already hides.
     * @return string[]
     */
    public static function hide_order_itemmeta( $hidden ) {
        return array_merge( (array) $hidden, self::ITEM_META_KEYS );
    }

    /**
     * The wc-ajax note endpoint: keeps the note the storefront script sent and answers 204 whatever happened, so it reveals nothing.
     *
     * @return void
     */
    public static function note_request() {
        try {
            self::receive_note( wp_unslash( $_POST ), $_SERVER ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        } catch ( Throwable $e ) {
            WC_Montonio_Logger::warning_once( 'rec_note_failed', 'Recommendation note request failed: ' . WC_Montonio_Logger::describe( $e ), self::LOG_SOURCE );
        }

        // wp_die() answers an Ajax request 200 unless told otherwise.
        wp_die( '', '', array( 'response' => 204 ) );
    }

    /**
     * Keeps a note sent by the storefront script under the session's customer id, in a row of its own in WooCommerce's
     * sessions table: the session blob is rewritten whole by whichever request finishes last, so a note kept there could
     * overwrite the cart of an add made in the same instant. The endpoint has no rate limit: a client can only overwrite
     * its own note, and WooCommerce sessions can be created without it anyway.
     *
     * @param array $post   The request's form fields.
     * @param array $server The request's server variables.
     * @return string What was done: stored, or the reason it was not.
     */
    public static function receive_note( $post, $server ) {
        if ( ! self::is_enabled() ) {
            return 'off';
        }
        // The endpoint's URL is in every product page's config, so crawlers fetch it.
        if ( ! isset( $server['REQUEST_METHOD'] ) || 'POST' !== strtoupper( (string) $server['REQUEST_METHOD'] ) ) {
            return 'method';
        }
        if ( ! self::is_same_origin( $server ) ) {
            return 'origin';
        }

        $note = self::note_from_request( $post );
        if ( null === $note['note'] ) {
            WC_Montonio_Logger::warning_once( 'rec_note_rejected', 'Recommendation note request rejected: ' . $note['why'], self::LOG_SOURCE );
            return 'invalid';
        }

        if ( ! self::has_consent() ) {
            return 'consent';
        }
        if ( ! self::uses_session_table() ) {
            WC_Montonio_Logger::warning_once( 'rec_note_no_session_table', 'Recommendation notes are not kept: the session handler is ' . ( is_object( WC()->session ) ? get_class( WC()->session ) : 'not loaded' ) . '.', self::LOG_SOURCE );
            return 'no_session';
        }
        if ( ! WC()->session->has_session() ) {
            // Only an explicit false switches it off, as with the tagging filter.
            if ( false === apply_filters( 'wc_montonio_recommendation_note_creates_session', true ) ) {
                return 'no_session';
            }
            WC()->session->set_customer_session_cookie( true );
        }

        self::set_row( self::note_key( WC()->session->get_customer_id() ), $note['note'], self::NOTE_MAX_AGE );

        return 'stored';
    }

    // Exactly WooCommerce's handler: Redis and Memcached session plugins extend it with a cleanup that never sweeps this table.
    private static function uses_session_table() {
        return WC()->session && 'WC_Session_Handler' === get_class( WC()->session );
    }

    // The prefix keeps these apart from WooCommerce's own keys ("t_" guests, numeric users); the hash only makes the name fit char(32).
    private static function note_key( $customer_id ) {
        return 'm_' . substr( wp_hash( 'note:' . $customer_id ), 0, 30 );
    }

    // Read past WooCommerce's session cache, which never holds these rows; the cleanup runs twice a day, so an expired row is skipped here.
    private static function get_row( $key ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT session_value, session_expiry FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key = %s", $key ) );

        return $row && (int) $row->session_expiry >= time() ? $row->session_value : null;
    }

    private static function set_row( $key, $value, $ttl ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}woocommerce_sessions (session_key, session_value, session_expiry) VALUES (%s, %s, %d) ON DUPLICATE KEY UPDATE session_value = VALUES(session_value), session_expiry = VALUES(session_expiry)", $key, $value, time() + (int) $ttl ) );
    }

    private static function delete_row( $key ) {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'woocommerce_sessions', array( 'session_key' => $key ) );
    }

    // Sec-Fetch-Site decides alone when the browser sends it; without it, the Origin must name the request's own host and port.
    private static function is_same_origin( $server ) {
        if ( isset( $server['HTTP_SEC_FETCH_SITE'] ) && '' !== $server['HTTP_SEC_FETCH_SITE'] ) {
            return 'same-origin' === strtolower( (string) $server['HTTP_SEC_FETCH_SITE'] );
        }

        $parts = wp_parse_url( isset( $server['HTTP_ORIGIN'] ) ? (string) $server['HTTP_ORIGIN'] : '' );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! isset( $server['HTTP_HOST'] ) ) {
            return false;
        }

        return strtolower( $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) ) === strtolower( (string) $server['HTTP_HOST'] );
    }

    /**
     * The request's note in the stored v3 form, with the server's receipt time in the time field; null when any field
     * does not hold under the rules the add reads it by, which also bound its size.
     *
     * @return array 'note': the stored form, or null; 'why': what failed, naming no value.
     */
    private static function note_from_request( $post ) {
        $invalid = array( 'note' => null, 'why' => 'not a form body' );

        if ( ! is_array( $post ) ) {
            return $invalid;
        }

        $fields = array();
        foreach ( array( 'v', 'anchor', 'clicked', 'hint', 'shown', 'link' ) as $name ) {
            if ( ! isset( $post[ $name ] ) || ! is_string( $post[ $name ] ) ) {
                return array_merge( $invalid, array( 'why' => 'field ' . $name . ' missing or not text' ) );
            }
            $fields[ $name ] = $post[ $name ];
        }

        $at      = time();
        $decoded = self::decode_note_parts( array( $fields['v'], $fields['anchor'], $fields['clicked'], $fields['hint'], (string) $at, $fields['shown'], $fields['link'] ) );
        if ( null === $decoded ) {
            return array_merge( $invalid, array( 'why' => 'fields not a valid ' . self::NOTE_VERSION . ' note' ) );
        }

        return array(
            'note' => implode( '|', array( self::NOTE_VERSION, $decoded['anchor'], (string) $decoded['clicked'], $decoded['hint'], $at, implode( ',', $decoded['shown'] ), $decoded['link'] ) ),
            'why'  => '',
        );
    }

    /**
     * Enqueues the note script, registered with the plugin's other storefront scripts, on single-product and cart pages, telling it the page's anchor.
     *
     * @return void
     */
    public static function enqueue_note_script() {
        try {
            if ( ! self::is_enabled() ) {
                return;
            }

            $anchor = null;

            if ( is_product() ) {
                $anchor = (int) get_queried_object_id();
            } elseif ( is_cart() ) {
                $anchor = 'cart';
            }

            if ( null === $anchor || 0 === $anchor ) {
                return;
            }

            $config = array(
                'anchor'   => (string) $anchor,
                'endpoint' => WC_AJAX::get_endpoint( self::NOTE_ACTION ),
                'version'  => self::NOTE_VERSION,
                // The note script's limits, kept with the server's so each is stated once.
                'maxId'    => self::MAX_ID,
                'maxShown' => self::MAX_SHOWN,
            );
            // The Blocks cart's script reads the cart store, which carries every cross-sell's id and link.
            if ( 'cart' === $anchor && WC()->cart && ! has_block( 'woocommerce/cart', wc_get_page_id( 'cart' ) ) ) {
                try {
                    $config['links'] = (object) self::cart_link_map( WC()->cart );
                } catch ( Throwable $e ) {
                    WC_Montonio_Logger::warning_once( 'rec_link_map_failed', 'Recommendation link map failed: ' . WC_Montonio_Logger::describe( $e ), self::LOG_SOURCE );
                }
            }

            wp_localize_script( 'montonio-recommendation-note', 'montonioRecNote', $config );
            wp_enqueue_script( 'montonio-recommendation-note' );
        } catch ( Throwable $e ) {
            WC_Montonio_Logger::warning_once( 'rec_note_failed', 'Recommendation note script failed: ' . WC_Montonio_Logger::describe( $e ), self::LOG_SOURCE );
        }
    }

    /**
     * The cart's cross-sells as link key => product id (the first product wins a shared link), chosen as the Store API chooses the Blocks cart's
     * (visible products only), so a card that shows no id resolves through the link it holds.
     *
     * @param WC_Cart $cart The cart.
     * @return array
     */
    public static function cart_link_map( WC_Cart $cart ) {
        $map = array();
        $ids = $cart->get_cross_sells();

        if ( ! $ids ) {
            return $map;
        }

        _prime_post_caches( $ids );

        foreach ( array_filter( array_map( 'wc_get_product', $ids ), 'wc_products_array_filter_visible' ) as $product ) {
            $key = self::link_key( $product->get_permalink() );
            if ( '' !== $key && ! isset( $map[ $key ] ) ) {
                $map[ $key ] = $product->get_id();
            }
        }

        return $map;
    }

    /**
     * A product link's comparison key, the same one the note script builds: host (with a non-default
     * port, an internationalised name in its ASCII form), path without a trailing slash, and the query,
     * each part percent-encoded the way the browser's URL parser encodes it, so a raw UTF-8 slug, its
     * encoded form and a differently cased encoding compare equal, and plain-permalink links stay distinct.
     *
     * @return string Empty when the link has no host.
     */
    public static function link_key( $url ) {
        $parts = wp_parse_url( (string) $url );

        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return '';
        }

        $scheme  = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
        $default = ( 'http' === $scheme && 80 === ( $parts['port'] ?? 0 ) ) || ( 'https' === $scheme && 443 === ( $parts['port'] ?? 0 ) );
        $port    = isset( $parts['port'] ) && ! $default ? ':' . $parts['port'] : '';
        $path    = untrailingslashit( self::normalize_encoding( isset( $parts['path'] ) ? $parts['path'] : '', '/' ) );
        $query   = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . self::normalize_encoding( $parts['query'], '&=' ) : '';

        return self::ascii_host( $parts['host'] ) . $port . $path . $query;
    }

    /**
     * The short hash of a link's key that the note carries for a card without an id: FNV-1a 32-bit,
     * eight hex characters, the same the note script computes.
     *
     * @return string Empty when the link has no host.
     */
    public static function link_hash( $url ) {
        $key = self::link_key( $url );

        return '' === $key ? '' : hash( 'fnv1a32', $key );
    }

    // A host as the browser sends it: lowercase, an internationalised name as punycode.
    private static function ascii_host( $host ) {
        // strtolower leaves a multibyte capital such as Ü as it is, and the browser lowercases before encoding.
        $host = function_exists( 'mb_strtolower' ) ? mb_strtolower( $host, 'UTF-8' ) : strtolower( $host );

        if ( 1 === preg_match( '/[^\x00-\x7f]/', $host ) ) {
            try {
                $host = class_exists( 'WpOrg\\Requests\\IdnaEncoder' ) ? \WpOrg\Requests\IdnaEncoder::encode( $host ) : Requests_IDNAEncoder::encode( $host );
            } catch ( Throwable $e ) {
                return $host;
            }
        }

        return strtolower( $host );
    }

    // Each piece between $separators decoded and encoded again as encodeURIComponent does, so one spelling remains.
    private static function normalize_encoding( $value, $separators ) {
        $out = '';

        foreach ( preg_split( '/([' . preg_quote( $separators, '/' ) . '])/', (string) $value, -1, PREG_SPLIT_DELIM_CAPTURE ) as $piece ) {
            $out .= 1 === strlen( $piece ) && false !== strpos( $separators, $piece ) ? $piece : str_replace( array( '%21', '%2A', '%27', '%28', '%29' ), array( '!', '*', "'", '(', ')' ), rawurlencode( rawurldecode( $piece ) ) );
        }

        return $out;
    }

    /**
     * Whether the active WooCommerce is at the floor this feature was verified on.
     *
     * @return bool
     */
    public static function is_supported() {
        return WC_Montonio_Helper::is_wc_at_least( self::MIN_WC_VERSION );
    }

    /**
     * Whether tagging is on: WooCommerce at the floor and the filter not switching it off.
     *
     * @return bool
     */
    public static function is_active() {
        return self::is_supported() && self::is_enabled();
    }

    /**
     * Puts the tags Ladu kept from the rescued cart back on the rebuilt cart items that have none, marked restored.
     * The first well-shaped answer line for a product and variation decides that cart item, even when its tag
     * does not normalise. The cart is then saved to
     * the session and, for a logged-in shopper, the persistent cart, whatever recalculation follows.
     *
     * @param mixed $lines The restore answer's lines, one per cart item: product_id, variation_id and rec each.
     * @return void
     */
    public static function restore_tags( $lines ) {
        try {
            if ( ! is_array( $lines ) || ! WC()->cart ) {
                return;
            }

            $recs     = array();
            $misshape = array();
            foreach ( $lines as $line ) {
                if ( ! is_array( $line ) || ! isset( $line['product_id'], $line['rec'] ) ) {
                    continue;
                }
                $key = (int) $line['product_id'] . ':' . ( isset( $line['variation_id'] ) ? (int) $line['variation_id'] : 0 );
                if ( ! is_array( $line['rec'] ) ) {
                    $misshape[ $key ] = gettype( $line['rec'] );
                    continue;
                }
                if ( ! isset( $recs[ $key ] ) ) {
                    $recs[ $key ] = $line['rec'];
                }
            }

            $cart     = WC()->cart;
            $changed  = false;
            $unusable = array();
            foreach ( $cart->cart_contents as $cart_item_key => $item ) {
                $key = (int) ( $item['product_id'] ?? 0 ) . ':' . (int) ( $item['variation_id'] ?? 0 );
                if ( isset( $item[ self::CART_ITEM_KEY ] ) ) {
                    continue;
                }
                if ( ! isset( $recs[ $key ] ) ) {
                    if ( isset( $misshape[ $key ] ) ) {
                        $unusable[] = 'a ' . $misshape[ $key ];
                    }
                    continue;
                }
                $tag = self::normalize_tag( array_merge( $recs[ $key ], array( 'evidence' => array( 'v' => 1, 'restored' => true ) ) ) );
                if ( null !== $tag ) {
                    $cart->cart_contents[ $cart_item_key ][ self::CART_ITEM_KEY ] = $tag;
                    $changed = true;
                } else {
                    $unusable[] = implode( ',', array_map( 'sanitize_key', array_keys( $recs[ $key ] ) ) );
                }
            }

            // A Ladu answer this plugin cannot read would otherwise leave every rescued order untagged without a trace.
            if ( $unusable ) {
                WC_Montonio_Logger::warning_once( 'rec_restore_unusable', 'Recommendation tag restore skipped ' . count( $unusable ) . ' unusable tag(s), keys: ' . implode( ' | ', array_unique( $unusable ) ), self::LOG_SOURCE );
            }

            if ( $changed ) {
                $cart->set_session();
                $cart->persistent_cart_update();
            }
        } catch ( Throwable $e ) {
            WC_Montonio_Logger::warning_once( 'rec_restore_failed', 'Recommendation tag restore failed: ' . WC_Montonio_Logger::describe( $e ), self::LOG_SOURCE );
        }
    }

    /**
     * Whether tagging runs on this store: only where Ladu switched it on, and the local filter can still
     * switch it off. Only an explicit false from the filter does, so a callback that forgets to return does not.
     *
     * @return bool
     */
    public static function is_enabled() {
        return 'yes' === get_option( self::OPTION_ENABLED, 'no' ) && false !== apply_filters( 'wc_montonio_recommendation_tagging_enabled', true );
    }

    // WooCommerce's internal checkout-link endpoint query var (Blocks CheckoutLink), set while it rebuilds a cart from a shared link.
    private static function is_checkout_link_rebuild() {
        return (bool) get_query_var( 'checkout-link' );
    }

    /**
     * Tags the cart item WooCommerce is creating. The filter runs only when an item is created, on the
     * classic cart and the Store API alike, never on a merge into an existing item, so the first add
     * decides the item's tag and an item that was not tagged then stays untagged.
     *
     * @param mixed  $item          The cart item being created.
     * @param string $cart_item_key The cart item key.
     * @return mixed The cart item, tagged when tagging applies.
     */
    public static function tag_new_line( $item, $cart_item_key = '' ) {
        $product_id = 0;

        try {
            if ( ! is_array( $item ) || empty( $item['product_id'] ) || ! self::is_enabled() || self::is_checkout_link_rebuild() || ! WC()->cart ) {
                return $item;
            }

            // Plugins that rebuild their own cart items run this filter on existing ones too; the first add's tag stands.
            if ( isset( $item[ self::CART_ITEM_KEY ] ) ) {
                return $item;
            }

            $product_id   = (int) $item['product_id'];
            $variation_id = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
            $note         = self::held_note();
            // Consent withdrawn after the click: the note goes unused and is deleted.
            if ( null !== $note && ! self::has_consent() ) {
                self::spend_note();
                $note = null;
            }
            $referer      = wp_get_raw_referer() ?: null; // Unsanitised; the resolver sanitises it.
            $lines        = self::cart_products( WC()->cart );
            $links        = function () use ( $lines ) {
                return self::cross_sell_links( $lines );
            };
            $result       = self::classify( $product_id, $variation_id, $note, $referer, $links, $lines );

            $tag = apply_filters( 'wc_montonio_recommendation_tag', array(
                'v'        => 1,
                'surface'  => $result['surface'],
                'anchors'  => $result['anchors'],
                'added_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                'evidence' => $result['evidence'],
            ), $cart_item_key, $product_id, $variation_id );

            $normalized = self::normalize_tag( $tag );

            if ( null === $normalized ) {
                WC_Montonio_Logger::warning_once( 'rec_tag_rejected', 'Recommendation tag filter returned an unusable value: ' . gettype( $tag ) . ( is_array( $tag ) ? ' with keys ' . implode( ',', array_map( 'strval', array_keys( $tag ) ) ) : '' ), self::LOG_SOURCE );
                return $item;
            }

            $item[ self::CART_ITEM_KEY ] = $normalized;

            if ( ! empty( $result['evidence']['note']['used'] ) ) {
                self::spend_note();
            }
        } catch ( Throwable $e ) {
            WC_Montonio_Logger::warning_once( 'rec_tag_failed', 'Recommendation tagging failed for product ' . $product_id . ': ' . WC_Montonio_Logger::describe( $e ), self::LOG_SOURCE );
        }

        return $item;
    }

    /**
     * Whether a note may be kept and used: under the WP Consent API only with statistics consent, otherwise always.
     * The note request and the add are never served from a page cache, so the API reads the shopper's own consent.
     *
     * @return bool
     */
    private static function has_consent() {
        return ! function_exists( 'wp_has_consent' ) || wp_has_consent( 'statistics', plugin_basename( WC_MONTONIO_PLUGIN_FILE ) );
    }

    // The note kept for this session, in its stored v3 form, or null.
    private static function held_note() {
        return self::uses_session_table() ? self::get_row( self::note_key( WC()->session->get_customer_id() ) ) : null;
    }

    // A note credits one add: a later add of the same product, e.g. after removing it, must come from a new click.
    // Only the add the note names spends it, so the other products of a grouped add leave it in place.
    private static function spend_note() {
        if ( self::uses_session_table() ) {
            self::delete_row( self::note_key( WC()->session->get_customer_id() ) );
        }
    }

    /**
     * Decides the surface and anchors for an add, by link membership.
     *
     * @param int         $product_id   The product added (the parent for a variation).
     * @param int         $variation_id The variation added, or 0.
     * @param string|null    $note          The note kept for the session, in its stored v3 form, or null.
     * @param string|null    $referer       The request Referer, or null.
     * @param array|callable $cart_links    Cross-sell ids of every other cart item, keyed by that item's parent product id,
     *                                      or a callable building them, called only when the cart's cross-sells decide.
     * @param WC_Product[]   $cart_products The other cart items' products keyed by parent product id, reused as anchors.
     * @return array 'surface': upsell, cross_sell or organic; 'anchors': product ids, empty for organic;
     *               'evidence': what the decision was made from.
     */
    public static function classify( $product_id, $variation_id, $note, $referer, $cart_links, $cart_products = array() ) {
        $product_id   = (int) $product_id;
        $variation_id = (int) $variation_id;
        $page         = self::resolve_referer( $referer, $product_id );
        $decoded      = self::decode_note( $note );
        $used         = null !== $decoded && self::note_applies( $decoded, $product_id, $variation_id, $page );
        $anchor       = $used ? $decoded['anchor'] : $page;
        $hint         = $used ? $decoded['hint'] : 'unknown';
        $other        = is_int( $anchor ) && $anchor !== $product_id;
        // Link pickers accept variations, so a list may hold the variation itself rather than its parent.
        $added    = array_values( array_filter( array( $product_id, $variation_id ) ) );
        $surface  = 'organic';
        $anchors  = array();
        $evidence = array(
            'v'          => 1,
            'note'       => self::note_evidence( $decoded, $used ),
            'page'       => $page,
            'candidates' => array(),
            // Only a note that decided the add says what the shopper saw before it.
            'shown'      => $used ? $decoded['shown'] : array(),
            'restored'   => false,
        );

        if ( $other ) {
            $lists    = self::anchor_lists( $anchor, $cart_products );
            $in_up    = (bool) array_intersect( $added, $lists['upsell'] );
            $in_cross = (bool) array_intersect( $added, $lists['cross_sell'] );

            $evidence['candidates'][] = array( 'anchor' => $anchor, 'upsell' => $in_up, 'cross_sell' => $in_cross );

            if ( $in_up && $in_cross ) {
                $surface = 'cross_sell' === $hint ? 'cross_sell' : 'upsell';
            } elseif ( $in_up ) {
                $surface = 'upsell';
            } elseif ( $in_cross ) {
                $surface = 'cross_sell';
            }
            if ( 'organic' !== $surface ) {
                $anchors = array( $anchor );
            }
        }

        // The cart's cross-sells: a card on the cart page, or a drawer on another product's page. On a product
        // page a note from a Related or up-sell section rules the drawer out.
        $drawer = $other && ! ( $used && in_array( $hint, array( 'related', 'upsell' ), true ) );
        if ( 'organic' === $surface && ( 'cart' === $anchor || $drawer ) ) {
            foreach ( is_callable( $cart_links ) ? $cart_links() : (array) $cart_links as $line_product_id => $cross_sell_ids ) {
                if ( (int) $line_product_id === $product_id ) {
                    continue;
                }
                $linked = (bool) array_intersect( $added, array_map( 'intval', (array) $cross_sell_ids ) );
                $evidence['candidates'][] = array( 'anchor' => (int) $line_product_id, 'cross_sell' => $linked );
                if ( $linked ) {
                    $anchors[] = (int) $line_product_id;
                }
            }
            if ( $anchors ) {
                $surface = 'cross_sell';
                $anchors = array_values( array_unique( $anchors ) );
            }
        }

        return array( 'surface' => $surface, 'anchors' => $anchors, 'evidence' => $evidence );
    }

    /**
     * The tag as stored: the known keys, typed, with the evidence bounded by normalize_evidence. Anything else the filter returned is dropped, anchors
     * are kept to post ids that fit a 32-bit integer and capped at 20, and a tag whose surface or
     * added_at is not well-formed, or that names a list but no anchor, is not stored at all.
     *
     * @return array|null
     */
    private static function normalize_tag( $tag ) {
        if ( ! is_array( $tag ) || ! isset( $tag['surface'], $tag['added_at'] ) || ! in_array( $tag['surface'], self::SURFACES, true ) ) {
            return null;
        }

        if ( ! is_string( $tag['added_at'] ) || 1 !== preg_match( '/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z$/', $tag['added_at'] ) ) {
            return null;
        }

        $anchors = array();
        if ( 'organic' !== $tag['surface'] ) {
            foreach ( isset( $tag['anchors'] ) && is_array( $tag['anchors'] ) ? $tag['anchors'] : array() as $anchor ) {
                $id = self::post_id( $anchor );
                if ( null !== $id && ! in_array( $id, $anchors, true ) ) {
                    $anchors[] = $id;
                }
            }
            $anchors = array_slice( $anchors, 0, self::MAX_ANCHORS );
            if ( ! $anchors ) {
                return null;
            }
        }

        return array(
            'v'        => 1,
            'surface'  => $tag['surface'],
            'anchors'  => $anchors,
            'added_at' => $tag['added_at'],
            'evidence' => self::normalize_evidence( isset( $tag['evidence'] ) ? $tag['evidence'] : null ),
        );
    }

    /**
     * The evidence as stored: known keys only, each validated and bounded, so no note or filter text
     * reaches order meta. A tag kept from before the evidence existed gets its version only.
     *
     * @return array
     */
    private static function normalize_evidence( $evidence ) {
        $out = array( 'v' => 1 );

        if ( ! is_array( $evidence ) ) {
            return $out;
        }
        if ( array_key_exists( 'note', $evidence ) ) {
            $out['note'] = self::normalize_note_evidence( $evidence['note'] );
        }
        if ( array_key_exists( 'page', $evidence ) ) {
            $out['page'] = self::anchor_value( $evidence['page'] );
        }
        if ( isset( $evidence['candidates'] ) && is_array( $evidence['candidates'] ) ) {
            $out['candidates'] = array();
            foreach ( $evidence['candidates'] as $candidate ) {
                $id = is_array( $candidate ) && isset( $candidate['anchor'] ) ? self::post_id( $candidate['anchor'] ) : null;
                if ( null === $id ) {
                    continue;
                }
                $entry = array( 'anchor' => $id );
                foreach ( array( 'upsell', 'cross_sell' ) as $list ) {
                    if ( isset( $candidate[ $list ] ) && is_bool( $candidate[ $list ] ) ) {
                        $entry[ $list ] = $candidate[ $list ];
                    }
                }
                $out['candidates'][] = $entry;
            }
            $out['candidates'] = array_slice( $out['candidates'], 0, self::MAX_CANDIDATES );
        }
        if ( isset( $evidence['shown'] ) && is_array( $evidence['shown'] ) ) {
            $out['shown'] = array_slice( array_values( array_filter( array_map( array( __CLASS__, 'post_id' ), $evidence['shown'] ) ) ), 0, self::MAX_SHOWN );
        }
        if ( isset( $evidence['restored'] ) ) {
            $out['restored'] = true === $evidence['restored'];
        }

        return $out;
    }

    private static function normalize_note_evidence( $note ) {
        if ( ! is_array( $note ) || ! isset( $note['anchor'], $note['hint'], $note['age_s'], $note['used'] ) || ! array_key_exists( 'clicked', $note ) ) {
            return null;
        }
        $anchor  = self::anchor_value( $note['anchor'] );
        $clicked = null === $note['clicked'] ? null : self::post_id( $note['clicked'] );
        if ( null === $anchor || ( null !== $note['clicked'] && null === $clicked ) || ! in_array( $note['hint'], self::HINTS, true ) || ! is_int( $note['age_s'] ) || ! is_bool( $note['used'] ) ) {
            return null;
        }
        $out = array( 'anchor' => $anchor, 'clicked' => $clicked, 'hint' => $note['hint'], 'age_s' => $note['age_s'], 'used' => $note['used'] );
        if ( isset( $note['link'] ) && is_bool( $note['link'] ) ) {
            $out['link'] = $note['link'];
        }
        return $out;
    }

    // A note or page anchor: a post id or the cart.
    private static function anchor_value( $value ) {
        return 'cart' === $value ? 'cart' : self::post_id( $value );
    }

    /**
     * The note kept for the session, read field by field; null when its shape, version or any
     * field but the shown list does not hold, so a forged note neither decides an add nor reaches order
     * meta. A malformed shown list is dropped on its own. Freshness and whether it names this add are note_applies' to decide.
     *
     * @return array|null 'anchor': product id or 'cart'; 'clicked': product id or null; 'hint'; 'ts'; 'shown'.
     */
    private static function decode_note( $note ) {
        if ( ! is_string( $note ) || '' === $note ) {
            return null;
        }

        $decoded = self::decode_note_parts( explode( '|', $note ) );

        // A format drift between the script and this reader would otherwise read every click-through as organic.
        if ( null === $decoded ) {
            $version = sanitize_key( substr( strtok( $note, '|' ), 0, 8 ) );
            WC_Montonio_Logger::warning_once( 'rec_note_rejected', 'Recommendation note rejected: version "' . $version . '" with ' . count( explode( '|', $note ) ) . ' parts', self::LOG_SOURCE );
        }

        return $decoded;
    }

    private static function decode_note_parts( $parts ) {
        if ( self::NOTE_VERSION !== $parts[0] || self::NOTE_PARTS !== count( $parts ) ) {
            return null;
        }

        list( , $anchor, $clicked, $hint, $ts ) = $parts;
        $anchor = self::anchor_value( $anchor );

        if ( null === $anchor || ! in_array( $hint, self::HINTS, true ) || 1 !== preg_match( '/^[0-9]{1,10}$/', $ts ) ) {
            return null;
        }

        if ( '' === $clicked ) {
            $clicked = null;
        } else {
            $clicked = self::post_id( $clicked );
            if ( null === $clicked ) {
                return null;
            }
        }

        // The link hash of a card without an id, which must look like one.
        $link = $parts[6];
        if ( '' !== $link && 1 !== preg_match( '/^[0-9a-f]{8}$/', $link ) ) {
            return null;
        }

        return array(
            'anchor'  => $anchor,
            'clicked' => $clicked,
            'hint'    => $hint,
            'ts'      => (int) $ts,
            'shown'   => self::shown_ids( $parts[5] ),
            'link'    => $link,
        );
    }

    // The note's shown card ids; a list holding anything but post ids, or more than the bound, is dropped whole.
    private static function shown_ids( $list ) {
        // Counted before the split, so an oversized list costs no array.
        if ( '' === $list || substr_count( $list, ',' ) >= self::MAX_SHOWN ) {
            return array();
        }
        $ids = array_map( array( __CLASS__, 'post_id' ), explode( ',', $list ) );
        return in_array( null, $ids, true ) ? array() : $ids;
    }

    /**
     * Whether a decoded note decides this add: fresh, and naming the product added. A note whose clicked
     * product is empty names it through the card's link hash when it has one, else it is trusted only on
     * the anchor's own page: a card click with no id followed by an add of some other product reached
     * another way must not credit the anchor.
     */
    private static function note_applies( $decoded, $product_id, $variation_id, $page ) {
        $now = time();

        if ( $now - $decoded['ts'] > self::NOTE_MAX_AGE || $decoded['ts'] - $now > 60 ) {
            return false;
        }

        if ( null !== $decoded['clicked'] ) {
            return in_array( $decoded['clicked'], array( $product_id, $variation_id ), true );
        }

        if ( '' !== $decoded['link'] ) {
            $links = array( self::link_hash( get_permalink( $product_id ) ) );
            if ( $variation_id > 0 ) {
                $links[] = self::link_hash( get_permalink( $variation_id ) );
            }
            return in_array( $decoded['link'], $links, true );
        }

        return $page === $decoded['anchor'];
    }

    private static function note_evidence( $decoded, $used ) {
        if ( null === $decoded ) {
            return null;
        }
        return array(
            'anchor'  => $decoded['anchor'],
            'clicked' => $decoded['clicked'],
            'hint'    => $decoded['hint'],
            'age_s'   => time() - $decoded['ts'],
            'used'    => $used,
            'link'    => '' !== $decoded['link'],
        );
    }

    /**
     * The page the add was made from, when the Referer is this site's cart page or a product page.
     * Resolved once per Referer per request: a grouped, batch or order-again add classifies many items
     * against one Referer.
     *
     * @param mixed $referer    The request Referer; under WP-CLI, null also empties the memo, for a test script that changes the site between adds.
     * @param int   $product_id The product being added, whose own page is the common Referer.
     * @return int|string|null A product id, 'cart', or null.
     */
    private static function resolve_referer( $referer, $product_id ) {
        static $resolved = array();

        if ( null === $referer ) {
            if ( defined( 'WP_CLI' ) && WP_CLI ) {
                $resolved = array();
            }
            return null;
        }
        if ( ! is_string( $referer ) || '' === $referer ) {
            return null;
        }
        if ( ! array_key_exists( $referer, $resolved ) ) {
            $resolved[ $referer ] = self::referer_page( $referer, $product_id );
        }

        return $resolved[ $referer ];
    }

    /**
     * Resolves a same-site Referer to a post the way WordPress reads the request: the p, page_id and
     * product query vars first (plain permalinks, or a query link under pretty ones), the front page
     * for the home path, else the rewrite rules. The product's own page is matched on its link key
     * before any of that, since most adds come from there.
     */
    private static function referer_page( $referer, $product_id ) {
        $referer = esc_url_raw( $referer );
        $url     = wp_parse_url( $referer );
        $home = wp_parse_url( home_url() );

        if ( ! is_array( $url ) || ! is_array( $home ) || empty( $url['host'] ) || empty( $home['host'] ) ) {
            return null;
        }
        if ( preg_replace( '/^www\\./', '', strtolower( $url['host'] ) ) !== preg_replace( '/^www\\./', '', strtolower( $home['host'] ) ) ) {
            return null;
        }

        $product_id = (int) $product_id;
        if ( $product_id > 0 && self::link_key( $referer ) === self::link_key( get_permalink( $product_id ) ) ) {
            return 'product' === get_post_type( $product_id ) ? $product_id : null;
        }

        parse_str( isset( $url['query'] ) ? $url['query'] : '', $query );
        $product_type = get_post_type_object( 'product' );
        $product_var  = $product_type && $product_type->query_var ? $product_type->query_var : 'product';
        $post_id      = 0;

        if ( isset( $query['p'] ) ) {
            $post_id = (int) $query['p'];
        } elseif ( isset( $query['page_id'] ) ) {
            $post_id = (int) $query['page_id'];
        } elseif ( isset( $query[ $product_var ] ) && is_string( $query[ $product_var ] ) && '' !== $query[ $product_var ] ) {
            $product = get_page_by_path( sanitize_title( $query[ $product_var ] ), OBJECT, 'product' );
            $post_id = $product ? (int) $product->ID : 0;
        } elseif ( untrailingslashit( isset( $url['path'] ) ? $url['path'] : '' ) === untrailingslashit( isset( $home['path'] ) ? $home['path'] : '' ) ) {
            // The bare home URL is the front page; a search or an archive on the home path is not, though url_to_postid would say so.
            $post_id = empty( $url['query'] ) && 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
        } else {
            $post_id = url_to_postid( $referer );
        }

        if ( $post_id <= 0 ) {
            return null;
        }
        if ( $post_id === (int) wc_get_page_id( 'cart' ) ) {
            return 'cart';
        }

        return 'product' === get_post_type( $post_id ) ? $post_id : null;
    }

    /**
     * The anchor's configured up-sell and cross-sell lists, not filtered for visibility or display limits,
     * read through the product getters so the lists are the ones the storefront rendered. A cart item's
     * product is reused rather than hydrated again.
     *
     * @return array 'upsell' and 'cross_sell' arrays of product ids.
     */
    private static function anchor_lists( $anchor_id, $cart_products ) {
        $product = isset( $cart_products[ $anchor_id ] ) && $cart_products[ $anchor_id ] instanceof WC_Product ? $cart_products[ $anchor_id ] : wc_get_product( $anchor_id );

        if ( $product && $product->is_type( 'variation' ) ) {
            $product = wc_get_product( $product->get_parent_id() );
        }

        if ( ! $product ) {
            return array( 'upsell' => array(), 'cross_sell' => array() );
        }

        return array(
            'upsell'     => array_map( 'intval', (array) $product->get_upsell_ids() ),
            'cross_sell' => array_map( 'intval', (array) $product->get_cross_sell_ids() ),
        );
    }

    /**
     * The hydrated product of every cart item, keyed by the item's parent product id; the item being
     * created is not in the cart yet.
     */
    private static function cart_products( WC_Cart $cart ) {
        $products = array();

        foreach ( $cart->get_cart() as $line ) {
            if ( empty( $line['quantity'] ) || empty( $line['data'] ) || ! $line['data'] instanceof WC_Product ) {
                continue;
            }
            $products[ (int) $line['product_id'] ] = $line['data'];
        }

        return $products;
    }

    /**
     * Cross-sell ids per cart item. WC_Cart::get_cross_sells() is not used: it flattens every item into
     * one list, losing which item links the product.
     */
    private static function cross_sell_links( $products ) {
        $links = array();

        foreach ( $products as $product_id => $product ) {
            $links[ $product_id ] = $product->get_cross_sell_ids();
        }

        return $links;
    }

    /**
     * A WordPress post id that fits a 32-bit integer, from an int or a digit string; null otherwise.
     */
    private static function post_id( $value ) {
        if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]{0,9}$/', $value ) ) {
            $value = (int) $value;
        }
        return is_int( $value ) && $value >= 1 && $value <= self::MAX_ID ? $value : null;
    }

    /**
     * A cart item's tag as cart capture sends it to Ladu: the tag without its evidence, so the rescue
     * store holds no browsing evidence beside the shopper's email.
     *
     * @param array $cart_item The cart item.
     * @return array|null
     */
    public static function snapshot_rec( $cart_item ) {
        if ( ! is_array( $cart_item ) || empty( $cart_item[ self::CART_ITEM_KEY ] ) ) {
            return null;
        }

        $tag = self::normalize_tag( $cart_item[ self::CART_ITEM_KEY ] );

        if ( null === $tag ) {
            return null;
        }

        unset( $tag['evidence'] );

        return $tag;
    }

    /**
     * Offers the merchant suggested text for the site's privacy policy page, while tagging is on.
     * Checked on each admin_init, so the text follows Ladu's switch.
     *
     * @return void
     */
    public static function add_privacy_policy_content() {
        if ( self::is_enabled() && function_exists( 'wp_add_privacy_policy_content' ) ) {
            wp_add_privacy_policy_content( 'Montonio for WooCommerce', wp_kses_post( wpautop( self::privacy_policy_text(), false ) ) );
        }
    }

    /**
     * @return string
     */
    public static function privacy_policy_text() {
        return __( 'When this store has Montonio Ladu recommendations switched on, clicking a product recommendation on a product or cart page sends the store a short note: the product ids involved, the name of the page section, the ids of the recommendations shown and, for a recommendation shown without a product id, a short hash of the product link that was clicked. The note contains no identifier of the shopper. The store keeps it on its own server, tied to the shopper\'s WooCommerce session, for 30 minutes, and uses it when a product is added to the cart; if the shopper has no WooCommerce session yet, the click starts one, which sets WooCommerce\'s session cookie. Where the store uses the WP Consent API, the note is kept and used only with the shopper\'s statistics consent. When a product is added to the cart, the store records with the order item whether it was added from an up-sell or cross-sell recommendation and which products recommended it. This information is stored in the store\'s own order data and is used to measure how recommendations perform.', 'montonio-for-woocommerce' );
    }

    /**
     * Copies a tagged cart item's tag to the order item as meta.
     *
     * @param WC_Order_Item_Product $item          The order item being created.
     * @param string                $cart_item_key The cart item key.
     * @param array                 $values        The cart item.
     * @return void
     */
    public static function copy_tag_to_order_line( $item, $cart_item_key = '', $values = array() ) {
        try {
            if ( ! $item instanceof WC_Order_Item_Product || ! is_array( $values ) || empty( $values[ self::CART_ITEM_KEY ] ) ) {
                return;
            }

            $tag = self::normalize_tag( $values[ self::CART_ITEM_KEY ] );

            if ( null === $tag ) {
                return;
            }

            $item->add_meta_data( '_montonio_rec_surface', $tag['surface'], true );
            $item->add_meta_data( '_montonio_rec_anchors', implode( ',', $tag['anchors'] ), true );
            $item->add_meta_data( '_montonio_rec_added_at', $tag['added_at'], true );
            $item->add_meta_data( '_montonio_rec_evidence', wp_json_encode( $tag['evidence'] ), true );
        } catch ( Throwable $e ) {
            WC_Montonio_Logger::warning_once( 'rec_copy_failed', 'Recommendation tag copy failed for cart item ' . sanitize_key( (string) $cart_item_key ) . ': ' . WC_Montonio_Logger::describe( $e ), self::LOG_SOURCE );
        }
    }
}
