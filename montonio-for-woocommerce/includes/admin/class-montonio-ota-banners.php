<?php
defined( 'ABSPATH' ) || exit;

/**
 * Class Montonio_OTA_Banners
 *
 * Handles remotely-managed admin banners delivered via OTA (over-the-air) REST API.
 * Banners are stored in a WordPress option and rendered as admin notices with
 * template-based styling (lilac, indigo, beige, carribean, and warning for account issues),
 * or as native admin notices (wc-info, wc-success, wc-warning, wc-error).
 * A store holds at most one styled banner and two native ones (BANNER_LIMITS).
 *
 * @since 10.5.0
 */
class Montonio_OTA_Banners {

    /**
     * WordPress option key for storing OTA banner data.
     *
     * @since 10.5.0
     */
    const OPTION_KEY = 'montonio_ota_banners';

    /**
     * REST API namespace (shared with Montonio_OTA_Updates).
     *
     * @since 10.5.0
     */
    const NAMESPACE_PREFIX = 'montonio/ota';

    /**
     * Allowed banner template types.
     *
     * @since 10.5.0
     */
    const ALLOWED_TEMPLATES = array( 'lilac', 'indigo', 'beige', 'carribean', 'warning', 'wc-info', 'wc-success', 'wc-warning', 'wc-error' );

    /**
     * Template prefix for banners rendered as native WordPress/WooCommerce admin notices.
     * The rest of the template name is the notice type (info, success, warning, error).
     *
     * @since 10.5.0
     */
    const NATIVE_TEMPLATE_PREFIX = 'wc-';

    /**
     * Maximum number of banners a store can hold and show at once, per template group:
     * styled (Montonio templates) and native (wc-* templates).
     *
     * @since 10.5.0
     */
    const BANNER_LIMITS = array(
        'styled' => 1,
        'native' => 2,
    );

    /**
     * Prefix for banner HTML IDs, which are also the keys of dismissed banners.
     *
     * @since 10.5.0
     */
    const BANNER_ID_PREFIX = 'montonio_ota_';

    /**
     * User meta key holding the banners the user has dismissed, as banner HTML ID => 0.
     *
     * @since 10.5.0
     */
    const DISMISSED_META_KEY = 'montonio_banners';

    /**
     * Domain that banner images must be served from (itself or any subdomain, over HTTPS).
     *
     * @since 10.5.0
     */
    const ALLOWED_IMAGE_DOMAIN = 'montonio.com';

    /**
     * Initialize the class by registering hooks.
     *
     * @since 10.5.0
     * @return void
     */
    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        add_action( 'admin_notices', array( __CLASS__, 'render_banners' ) );
        add_action( 'wp_ajax_update_montonio_banner_visibility', array( __CLASS__, 'update_montonio_banner_visibility' ) );
    }

    /**
     * Register REST API routes for OTA banner management.
     *
     * @since 10.5.0
     * @return void
     */
    public static function register_routes() {
        register_rest_route( self::NAMESPACE_PREFIX, '/banners', array(
            array(
                'methods'             => 'GET',
                'permission_callback' => array( 'Montonio_OTA_Updates', 'merchant_apikey_auth_permissions_check' ),
                'callback'            => array( __CLASS__, 'handle_get' ),
            ),
            array(
                'methods'             => 'PUT',
                'permission_callback' => array( 'Montonio_OTA_Updates', 'merchant_apikey_auth_permissions_check' ),
                'callback'            => array( __CLASS__, 'handle_put' ),
            ),
            array(
                'methods'             => 'PATCH',
                'permission_callback' => array( 'Montonio_OTA_Updates', 'merchant_apikey_auth_permissions_check' ),
                'callback'            => array( __CLASS__, 'handle_patch' ),
            ),
            array(
                'methods'             => 'DELETE',
                'permission_callback' => array( 'Montonio_OTA_Updates', 'merchant_apikey_auth_permissions_check' ),
                'callback'            => array( __CLASS__, 'handle_delete' ),
            ),
        ) );
    }

    /**
     * Handle GET request — retrieve all stored banners.
     *
     * @since 10.5.0
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response
     */
    public static function handle_get( $request ) {
        return new WP_REST_Response( self::get_banners(), 200 );
    }

    /**
     * Handle PUT request — replace all banners with the provided set.
     *
     * @since 10.5.0
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_put( $request ) {
        $banners = self::validate_banners_payload( $request->get_json_params() );

        if ( is_wp_error( $banners ) ) {
            return $banners;
        }

        $banners = self::remove_expired( $banners );
        $limits  = self::check_banner_limits( $banners );

        if ( is_wp_error( $limits ) ) {
            return $limits;
        }

        self::save_banners( $banners );

        return new WP_REST_Response( $banners, 200 );
    }

    /**
     * Handle PATCH request — merge provided banners with existing ones (upsert by ID).
     *
     * @since 10.5.0
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_patch( $request ) {
        $new_banners = self::validate_banners_payload( $request->get_json_params() );

        if ( is_wp_error( $new_banners ) ) {
            return $new_banners;
        }

        $existing = self::get_banners();
        // array_replace, not array_merge: an all-digit banner ID is an integer key, which array_merge would renumber.
        $merged   = self::remove_expired( array_replace( $existing, $new_banners ) );
        $limits   = self::check_banner_limits( $merged );

        if ( is_wp_error( $limits ) ) {
            return $limits;
        }

        self::save_banners( $merged );

        return new WP_REST_Response( $merged, 200 );
    }

    /**
     * Handle DELETE request — remove specific banners by ID.
     *
     * Expected body: {"ids": ["banner_id_1", "banner_id_2"]}
     *
     * @since 10.5.0
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_delete( $request ) {
        $params = $request->get_json_params();
        $ids    = isset( $params['ids'] ) ? $params['ids'] : array();

        if ( empty( $ids ) || ! is_array( $ids ) ) {
            return new WP_Error( 'invalid_request', 'Request body must contain an "ids" array', array( 'status' => 400 ) );
        }

        $banners = self::get_banners();

        foreach ( $ids as $id ) {
            unset( $banners[ sanitize_key( $id ) ] );
        }

        self::save_banners( $banners );

        return new WP_REST_Response( $banners, 200 );
    }

    /**
     * Validate and sanitize a banners payload from a REST request.
     *
     * @since 10.5.0
     * @param mixed $payload Raw request body (expected: associative array of banner_id => banner_data).
     * @return array|WP_Error Sanitized banners array on success, WP_Error on validation failure.
     */
    public static function validate_banners_payload( $payload ) {
        if ( ! is_array( $payload ) || empty( $payload ) ) {
            return new WP_Error( 'invalid_request', 'Request body must be a non-empty JSON object', array( 'status' => 400 ) );
        }

        $validated = array();

        foreach ( $payload as $id => $banner ) {
            $sanitized_id = sanitize_key( $id );

            if ( empty( $sanitized_id ) || ! preg_match( '/^[a-z0-9_]+$/', $sanitized_id ) ) {
                return new WP_Error( 'invalid_banner_id', 'Banner ID "' . esc_html( $id ) . '" must contain only lowercase letters, numbers, and underscores', array( 'status' => 400 ) );
            }

            if ( ! is_array( $banner ) ) {
                return new WP_Error( 'invalid_banner', 'Banner "' . esc_html( $id ) . '" must be an object', array( 'status' => 400 ) );
            }

            if ( empty( $banner['template'] ) || ! in_array( $banner['template'], self::ALLOWED_TEMPLATES, true ) ) {
                return new WP_Error( 'invalid_template', 'Banner "' . esc_html( $id ) . '" has invalid template. Allowed: ' . implode( ', ', self::ALLOWED_TEMPLATES ), array( 'status' => 400 ) );
            }

            if ( empty( $banner['heading'] ) ) {
                return new WP_Error( 'missing_heading', 'Banner "' . esc_html( $id ) . '" is missing required "heading" field', array( 'status' => 400 ) );
            }

            if ( ! empty( $banner['image_url'] ) && ! self::is_allowed_image_url( $banner['image_url'] ) ) {
                return new WP_Error( 'invalid_image_url', 'Banner "' . esc_html( $id ) . '" has invalid "image_url". It must be an https:// URL on ' . self::ALLOWED_IMAGE_DOMAIN . ' or its subdomains', array( 'status' => 400 ) );
            }

            if ( ! empty( $banner['expire_at'] ) && ! self::is_valid_expire_at( $banner['expire_at'] ) ) {
                return new WP_Error( 'invalid_expire_at', 'Banner "' . esc_html( $id ) . '" has invalid "expire_at". It must be an ISO 8601 date or date-time, e.g. 2026-06-30 or 2026-06-30T21:00:00Z', array( 'status' => 400 ) );
            }

            $validated[ $sanitized_id ] = self::sanitize_banner( $banner );
        }

        return $validated;
    }

    /**
     * Sanitize a single banner's fields.
     *
     * @since 10.5.0
     * @param array $banner Raw banner data.
     * @return array Sanitized banner data.
     */
    private static function sanitize_banner( $banner ) {
        return array(
            'template'    => sanitize_text_field( $banner['template'] ),
            'label'       => ! empty( $banner['label'] ) ? sanitize_text_field( $banner['label'] ) : null,
            'heading'     => self::kses_heading( $banner['heading'] ),
            'content'     => ! empty( $banner['content'] ) ? self::kses_content( $banner['content'] ) : '',
            'can_dismiss' => isset( $banner['can_dismiss'] ) ? (bool) $banner['can_dismiss'] : true,
            'expire_at'   => self::sanitize_expire_at( $banner ),
            'cta_text'    => ! empty( $banner['cta_text'] ) ? sanitize_text_field( $banner['cta_text'] ) : null,
            'cta_url'     => ! empty( $banner['cta_url'] ) ? esc_url_raw( $banner['cta_url'] ) : null,
            'cta_new_tab' => isset( $banner['cta_new_tab'] ) ? (bool) $banner['cta_new_tab'] : true,
            'min_ver'     => ! empty( $banner['min_ver'] ) ? sanitize_text_field( $banner['min_ver'] ) : null,
            'max_ver'     => ! empty( $banner['max_ver'] ) ? sanitize_text_field( $banner['max_ver'] ) : null,
            'image_url'   => ! empty( $banner['image_url'] ) ? esc_url_raw( $banner['image_url'] ) : null,
            'image_alt'   => ! empty( $banner['image_alt'] ) ? sanitize_text_field( $banner['image_alt'] ) : '',
        );
    }

    /**
     * Check that an image URL is HTTPS and served from the allowed domain or one of its subdomains.
     *
     * @since 10.5.0
     * @param mixed $url The image URL.
     * @return bool True if the image may be rendered.
     */
    public static function is_allowed_image_url( $url ) {
        if ( ! is_string( $url ) ) {
            return false;
        }

        $parts = wp_parse_url( $url );

        if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return false;
        }

        $host   = strtolower( $parts['host'] );
        $domain = self::ALLOWED_IMAGE_DOMAIN;

        return $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain;
    }

    /**
     * Sanitize banner content: post markup without images or other embedded media.
     * Images are only allowed through the host-checked "image_url" field.
     *
     * @since 10.5.0
     * @param string $content Raw HTML content.
     * @return string Sanitized HTML.
     */
    private static function kses_content( $content ) {
        $allowed_html = wp_kses_allowed_html( 'post' );

        foreach ( array( 'img', 'picture', 'source', 'video', 'audio', 'track', 'iframe', 'object', 'embed' ) as $tag ) {
            unset( $allowed_html[ $tag ] );
        }

        return wp_kses( $content, $allowed_html );
    }

    /**
     * Sanitize a banner heading: plain text plus attribute-less <span> for highlighted words.
     *
     * @since 10.5.0
     * @param mixed $heading Raw heading.
     * @return string Sanitized heading.
     */
    private static function kses_heading( $heading ) {
        if ( ! is_string( $heading ) ) {
            return '';
        }

        return trim( wp_kses( $heading, array( 'span' => array() ) ) );
    }

    /**
     * Sanitize the expire_at field. validate_banners_payload() has already rejected invalid values.
     *
     * @since 10.5.0
     * @param array $banner Raw banner data.
     * @return string|null Sanitized date string or null.
     */
    private static function sanitize_expire_at( $banner ) {
        if ( empty( $banner['expire_at'] ) ) {
            return null;
        }

        return sanitize_text_field( $banner['expire_at'] );
    }

    /**
     * Check that expire_at is a real ISO 8601 date or date-time with nothing after it.
     * The whole value must match, so strtotime() can't read relative modifiers ("2026-01-01 +100 years"),
     * and the date must exist, so an impossible one ("2026-13-45") can't make a banner that never expires.
     *
     * @since 10.5.0
     * @param mixed $value The raw expire_at value.
     * @return bool True if the value is a valid date or date-time.
     */
    private static function is_valid_expire_at( $value ) {
        $pattern = '/^(\d{4})-(\d{2})-(\d{2})(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/';

        if ( ! is_string( $value ) || ! preg_match( $pattern, $value, $matches ) ) {
            return false;
        }

        return checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] ) && false !== strtotime( $value );
    }

    /**
     * Check that a banner set stays within BANNER_LIMITS.
     *
     * @since 10.5.0
     * @param array $banners Associative array of banner_id => banner_data, without expired banners.
     * @return true|WP_Error True if within the limits, WP_Error naming the banners in an over-full group otherwise.
     */
    public static function check_banner_limits( $banners ) {
        $groups = array_fill_keys( array_keys( self::BANNER_LIMITS ), array() );

        foreach ( $banners as $id => $banner ) {
            $groups[ self::get_template_group( $banner['template'] ) ][] = $id;
        }

        foreach ( $groups as $group => $ids ) {
            $limit = self::BANNER_LIMITS[ $group ];

            if ( count( $ids ) > $limit ) {
                return new WP_Error(
                    'banner_limit_exceeded',
                    'Too many ' . $group . ' banners: at most ' . $limit . ' allowed, got ' . count( $ids ) . ' (' . implode( ', ', $ids ) . '). Delete one first or replace the set with PUT',
                    array( 'status' => 400 )
                );
            }
        }

        return true;
    }

    /**
     * Get the limit group of a template: "native" for wc-* templates, "styled" for the rest.
     *
     * @since 10.5.0
     * @param string $template The banner template.
     * @return string Key of BANNER_LIMITS.
     */
    private static function get_template_group( $template ) {
        return 0 === strpos( $template, self::NATIVE_TEMPLATE_PREFIX ) ? 'native' : 'styled';
    }

    /**
     * Check whether a banner's expire_at has passed.
     *
     * @since 10.5.0
     * @param array $banner The banner data.
     * @return bool True if the banner has expired.
     */
    private static function is_expired( $banner ) {
        if ( empty( $banner['expire_at'] ) ) {
            return false;
        }

        $expire_time = strtotime( $banner['expire_at'] );

        return false !== $expire_time && time() > $expire_time;
    }

    /**
     * Drop expired banners, so they don't hold a slot in BANNER_LIMITS.
     *
     * @since 10.5.0
     * @param array $banners Associative array of banner_id => banner_data.
     * @return array The banners that have not expired.
     */
    private static function remove_expired( $banners ) {
        foreach ( $banners as $id => $banner ) {
            if ( self::is_expired( $banner ) ) {
                unset( $banners[ $id ] );
            }
        }

        return $banners;
    }

    /**
     * Get all stored OTA banners, without malformed ones.
     * The option can be written without the REST validation, so every reader (rendering, PATCH,
     * DELETE, GET and the limit check) gets only banners with the expected shape. The next
     * write then drops the malformed entries from the database.
     *
     * @since 10.5.0
     * @return array Associative array of banner_id => banner_data.
     */
    public static function get_banners() {
        $banners = get_option( self::OPTION_KEY, array() );

        if ( ! is_array( $banners ) ) {
            return array();
        }

        foreach ( $banners as $id => $banner ) {
            if ( ! self::is_valid_stored_banner( $id, $banner ) ) {
                unset( $banners[ $id ] );
            }
        }

        return $banners;
    }

    /**
     * Check that a stored banner has the shape the rest of the class expects.
     * The option can be written without the REST validation, so a wrong type here would otherwise
     * raise notices or a TypeError on every admin page and in the REST handlers.
     *
     * @since 10.5.0
     * @param int|string $id     The banner ID (without prefix).
     * @param mixed      $banner The stored banner.
     * @return bool True if the banner is well-formed.
     */
    private static function is_valid_stored_banner( $id, $banner ) {
        if ( ! preg_match( '/^[a-z0-9_]+$/', (string) $id ) || ! is_array( $banner ) ) {
            return false;
        }

        if ( ! isset( $banner['template'] ) || ! in_array( $banner['template'], self::ALLOWED_TEMPLATES, true ) ) {
            return false;
        }

        if ( ! isset( $banner['heading'] ) || ! is_string( $banner['heading'] ) || '' === $banner['heading'] ) {
            return false;
        }

        foreach ( array( 'label', 'content', 'expire_at', 'cta_text', 'cta_url', 'min_ver', 'max_ver', 'image_url', 'image_alt' ) as $field ) {
            if ( isset( $banner[ $field ] ) && ! is_string( $banner[ $field ] ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Save OTA banners to the database.
     *
     * @since 10.5.0
     * @param array $banners Associative array of banner_id => banner_data.
     * @return bool True if the value was updated, false otherwise.
     */
    public static function save_banners( $banners ) {
        return update_option( self::OPTION_KEY, $banners );
    }

    /**
     * Render all active OTA banners as admin notices, up to BANNER_LIMITS per group in stored order.
     * The REST endpoints already enforce the limits; this covers an option written another way.
     * Only users who can view orders see banners. Malformed banners are already left out by
     * get_banners(); one that fails while rendering is skipped so it can never break the admin page.
     * Hooked to `admin_notices`.
     *
     * @since 10.5.0
     * @return void
     */
    public static function render_banners() {
        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            return;
        }

        $banners = self::get_banners();

        if ( empty( $banners ) ) {
            return;
        }

        $shown = array_fill_keys( array_keys( self::BANNER_LIMITS ), 0 );

        foreach ( $banners as $id => $banner ) {
            $group = self::get_template_group( $banner['template'] );

            if ( $shown[ $group ] >= self::BANNER_LIMITS[ $group ] ) {
                continue;
            }

            // Buffer each banner, so one that throws half-way leaves no partial markup behind.
            ob_start();

            try {
                $rendered = self::maybe_render_banner( $id, $banner );
            } catch ( Throwable $e ) {
                $rendered = false;
            }

            if ( $rendered ) {
                ob_end_flush();
                $shown[ $group ]++;
            } else {
                ob_end_clean();
            }
        }
    }

    /**
     * Conditionally render a single banner after checking expiration, dismissal, and plugin version.
     *
     * @since 10.5.0
     * @param string $id     The banner ID (without prefix).
     * @param array  $banner The banner data.
     * @return bool True if the banner was rendered.
     */
    private static function maybe_render_banner( $id, $banner ) {
        $html_id = self::BANNER_ID_PREFIX . $id;

        if ( self::is_expired( $banner ) ) {
            return false;
        }

        // Check if dismissed by current user
        if ( ! empty( $banner['can_dismiss'] ) && self::is_dismissed( $html_id ) ) {
            return false;
        }

        // Check plugin version targeting
        $plugin_version = WC_MONTONIO_PLUGIN_VERSION;

        if ( ! empty( $banner['min_ver'] ) && version_compare( $plugin_version, $banner['min_ver'], '<' ) ) {
            return false;
        }

        if ( ! empty( $banner['max_ver'] ) && version_compare( $plugin_version, $banner['max_ver'], '>' ) ) {
            return false;
        }

        if ( 'native' === self::get_template_group( $banner['template'] ) ) {
            self::render_native_banner_html( $html_id, $banner );
            return true;
        }

        self::render_banner_html( $html_id, $banner );
        return true;
    }

    /**
     * Render the HTML for a single OTA banner.
     *
     * Uses `.montonio-ota-banner` and template-specific class for visual styling.
     * The dismiss handler in montonio-admin-script.js targets `.montonio-ota-banner`.
     * The image is only rendered if its URL still passes the host check, since the
     * option can be written without going through the REST validation.
     *
     * @since 10.5.0
     * @param string $html_id The full HTML ID (with prefix).
     * @param array  $banner  The banner data.
     * @return void
     */
    private static function render_banner_html( $html_id, $banner ) {
        $template_class = 'montonio-ota-banner--' . esc_attr( $banner['template'] );
        $can_dismiss    = ! empty( $banner['can_dismiss'] );
        $image_url      = ! empty( $banner['image_url'] ) && self::is_allowed_image_url( $banner['image_url'] ) ? $banner['image_url'] : '';
        $image_alt      = ! empty( $banner['image_alt'] ) ? $banner['image_alt'] : '';
        $label          = ! empty( $banner['label'] ) ? $banner['label'] : 'Montonio';
        ?>
        <div id="<?php echo esc_attr( $html_id ); ?>" class="montonio-ota-banner <?php echo esc_attr( $template_class ); ?> notice">
            <?php if ( $can_dismiss ) : ?>
                <?php wp_nonce_field( $html_id . '_nonce', $html_id . '_nonce_field' ); ?>
                <a class="montonio-ota-banner__close" href="<?php echo esc_url( add_query_arg( $html_id, 0 ) ); ?>" aria-label="<?php esc_attr_e( 'Dismiss this notice', 'montonio-for-woocommerce' ); ?>">
                    <span><?php esc_html_e( 'Dismiss this notice', 'montonio-for-woocommerce' ); ?></span>
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none">
                        <path d="M12 4L4 12M4 4L12 12" stroke="currentColor" stroke-width="1.33" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
            <?php endif; ?>

            <div class="montonio-ota-banner__body">
                <div class="montonio-ota-banner__text">
                    <div class="montonio-badge"><?php echo esc_html( $label ); ?></div>
                    <h4 class="montonio-ota-banner__heading"><?php echo self::kses_heading( $banner['heading'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized by wp_kses ?></h4>
                    <?php if ( ! empty( $banner['content'] ) ) : ?>
                        <div class="montonio-ota-banner__content"><?php echo self::kses_content( $banner['content'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized by wp_kses ?></div>
                    <?php endif; ?>
                    <?php if ( ! empty( $banner['cta_text'] ) && ! empty( $banner['cta_url'] ) ) : ?>
                        <a class="montonio-ota-banner__cta<?php echo self::cta_opens_new_tab( $banner ) ? ' montonio-ota-banner__cta--external' : ''; ?>" href="<?php echo esc_url( $banner['cta_url'] ); ?>"<?php echo self::cta_target_attributes( $banner ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup ?>>
                            <?php echo esc_html( $banner['cta_text'] ); ?>
                            <?php if ( self::cta_opens_new_tab( $banner ) ) : ?>
                                <span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'montonio-for-woocommerce' ); ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>
                </div>
                <?php if ( $image_url ) : ?>
                    <div class="montonio-ota-banner__media">
                        <img class="montonio-ota-banner__image" src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $image_alt ); ?>" decoding="async">
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Whether the CTA opens in a new tab: yes unless the banner sets cta_new_tab to false.
     * Banners stored before the field existed open in a new tab.
     *
     * @since 10.5.0
     * @param array $banner The banner data.
     * @return bool True if the CTA opens in a new tab.
     */
    private static function cta_opens_new_tab( $banner ) {
        return ! isset( $banner['cta_new_tab'] ) || (bool) $banner['cta_new_tab'];
    }

    /**
     * Attributes that open the CTA in a new tab, if it should.
     *
     * @since 10.5.0
     * @param array $banner The banner data.
     * @return string The attributes with a leading space, or an empty string.
     */
    private static function cta_target_attributes( $banner ) {
        if ( ! self::cta_opens_new_tab( $banner ) ) {
            return '';
        }

        return ' target="_blank" rel="noopener noreferrer"';
    }

    /**
     * Render a banner as a native admin notice (`notice notice-{type}`).
     *
     * Native notices have no badge, image or highlight colour: the heading is bold
     * plain text at the start of the first paragraph and the CTA is a plain link.
     * WordPress adds the dismiss button to `.is-dismissible`; the click handler in
     * montonio-admin-script.js targets `.montonio-ota-notice` to store the dismissal.
     *
     * @since 10.5.0
     * @param string $html_id The full HTML ID (with prefix).
     * @param array  $banner  The banner data.
     * @return void
     */
    private static function render_native_banner_html( $html_id, $banner ) {
        $notice_type = substr( $banner['template'], strlen( self::NATIVE_TEMPLATE_PREFIX ) );
        $can_dismiss = ! empty( $banner['can_dismiss'] );
        $heading     = '<strong>' . esc_html( wp_strip_all_tags( $banner['heading'] ) ) . '</strong>';
        $content     = ! empty( $banner['content'] ) ? trim( wpautop( self::kses_content( $banner['content'] ) ) ) : '';

        // Put the heading at the start of the first paragraph, or in its own when the content opens with another block.
        if ( 0 === strpos( $content, '<p>' ) ) {
            $content = '<p>' . $heading . ' ' . substr( $content, 3 );
        } else {
            $content = '<p>' . $heading . '</p>' . $content;
        }
        ?>
        <div id="<?php echo esc_attr( $html_id ); ?>" class="montonio-ota-notice notice notice-<?php echo esc_attr( $notice_type ); ?><?php echo $can_dismiss ? ' is-dismissible' : ''; ?>">
            <?php if ( $can_dismiss ) : ?>
                <?php wp_nonce_field( $html_id . '_nonce', $html_id . '_nonce_field' ); ?>
            <?php endif; ?>
            <?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- heading escaped above, content sanitized by wp_kses ?>
            <?php if ( ! empty( $banner['cta_text'] ) && ! empty( $banner['cta_url'] ) ) : ?>
                <p><a href="<?php echo esc_url( $banner['cta_url'] ); ?>"<?php echo self::cta_target_attributes( $banner ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup ?>><?php echo esc_html( $banner['cta_text'] ); ?></a></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Check whether the current user has dismissed a banner.
     *
     * @since 10.5.0
     * @param string $html_id The banner HTML ID (with prefix).
     * @return bool True if the banner is dismissed.
     */
    public static function is_dismissed( $html_id ) {
        $dismissed = get_user_meta( get_current_user_id(), self::DISMISSED_META_KEY, true );

        return is_array( $dismissed ) && isset( $dismissed[ $html_id ] ) && 0 === $dismissed[ $html_id ];
    }

    /**
     * Handle the AJAX request that dismisses a banner for the current user.
     *
     * @since 10.5.0
     * @return void Dies with 1 on success, -1 on failure.
     */
    public static function update_montonio_banner_visibility() {
        $id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';

        if ( 0 !== strpos( $id, self::BANNER_ID_PREFIX ) ) {
            wp_die( -1 );
        }

        check_ajax_referer( $id . '_nonce', $id . '_nonce_field' );

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_die( -1 );
        }

        $dismissed = get_user_meta( get_current_user_id(), self::DISMISSED_META_KEY, true );

        if ( ! is_array( $dismissed ) ) {
            $dismissed = array();
        }

        $dismissed[ $id ] = 0;

        update_user_meta( get_current_user_id(), self::DISMISSED_META_KEY, $dismissed );

        wp_die( 1 );
    }
}
