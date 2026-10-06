<?php
defined( 'ABSPATH' ) || exit;

/**
 * Validation, order lookup, review tokens and mail delivery for
 * withdrawal-notice submissions.
 *
 * @since 10.4.0
 */
class WC_Montonio_Withdrawal_Request {

    /**
     * Sanitize one text value from user input, bounded in length.
     *
     * @since 10.4.0
     * @param array  $input     Input array.
     * @param string $key       Array key to read.
     * @param int    $max       Maximum length in characters.
     * @param bool   $multiline Whether line breaks are preserved.
     * @return string
     */
    public static function text( $input, $key, $max = 500, $multiline = false ) {
        $value = isset( $input[ $key ] ) ? $input[ $key ] : '';

        if ( ! is_string( $value ) ) {
            return '';
        }

        $value = $multiline ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );

        return mb_substr( $value, 0, $max );
    }

    /**
     * Count a submission attempt against a per-address rate limit.
     *
     * @since 10.4.0
     * @param string $bucket Limit bucket name.
     * @param int    $limit  Allowed attempts per 10-minute window.
     * @return bool True when the attempt is within the limit.
     */
    public static function rate_limit( $bucket, $limit = 20 ) {
        // Never trust client-provided forwarding headers. Store a salted hash, not the address.
        $ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
        $key   = 'montonio_withdrawal_rate_' . hash_hmac( 'sha256', $bucket . '|' . $ip, wp_salt( 'nonce' ) );
        $state = get_transient( $key );

        if ( ! is_array( $state ) || $state['until'] <= time() ) {
            $state = array( 'count' => 0, 'until' => time() + 600 );
        }

        if ( $state['count'] >= $limit ) {
            return false;
        }

        ++$state['count'];
        set_transient( $key, $state, max( 1, $state['until'] - time() ) );

        return true;
    }

    /**
     * Find an order matching an order number and billing email.
     *
     * @since 10.4.0
     * @param string $number Customer-supplied order number.
     * @param string $email  Customer-supplied billing email.
     * @param int    $id     Known order ID to verify, or 0 to search.
     * @return WC_Order|false
     */
    public static function find_order( $number, $email, $id = 0 ) {
        if ( ! $number || ! is_email( $email ) ) {
            return false;
        }

        $candidate = $id ? $id : ( ctype_digit( (string) $number ) ? (int) $number : 0 );
        $order     = $candidate ? wc_get_order( $candidate ) : false;

        if ( self::matches( $order, $number, $email ) ) {
            return $order;
        }

        if ( $id ) {
            return false;
        }

        // get_order_number() includes sequential/custom number filters. Bound work per lookup.
        $orders = wc_get_orders( array( 'type' => 'shop_order', 'billing_email' => $email, 'limit' => 100, 'orderby' => 'date', 'order' => 'DESC' ) );

        foreach ( $orders as $order ) {
            if ( self::matches( $order, $number, $email ) ) {
                return $order;
            }
        }

        // Numbering extensions can resolve older orders without exposing a public order search.
        $resolved = apply_filters( 'wc_montonio_withdrawal_resolve_order_id', 0, $number, $email );
        $order    = $resolved ? wc_get_order( $resolved ) : false;

        return self::matches( $order, $number, $email ) ? $order : false;
    }

    /**
     * Whether an order matches the supplied order number and billing email.
     *
     * @since 10.4.0
     * @param WC_Order|false $order  Order to check.
     * @param string         $number Customer-supplied order number.
     * @param string         $email  Customer-supplied billing email.
     * @return bool
     */
    private static function matches( $order, $number, $email ) {
        return $order && 'shop_order' === $order->get_type()
            && hash_equals( (string) $order->get_order_number(), (string) $number )
            && hash_equals( strtolower( trim( $order->get_billing_email() ) ), strtolower( trim( $email ) ) );
    }

    /**
     * Validate submitted withdrawal details into a clean data array.
     *
     * Matched orders are re-verified and posted item IDs and quantities are
     * checked against the order server-side.
     *
     * @since 10.4.0
     * @param array $input Raw submitted input.
     * @return array|WP_Error
     */
    public static function validate( $input ) {
        $d = array();

        $d['email']        = self::text( $input, 'email', 254 );
        $d['order_number'] = self::text( $input, 'order_number' );
        $d['reason']       = self::text( $input, 'reason', 3000, true );

        $errors = array();

        $raw_email = isset( $input['email'] ) && is_string( $input['email'] ) ? $input['email'] : '';

        if ( ! is_email( $d['email'] ) || preg_match( '/[\r\n]/', $raw_email ) ) {
            $errors[] = __( 'Enter a valid email address.', 'montonio-for-woocommerce' );
        }

        $d['items']           = array();
        $d['order_id']        = 0;
        $d['order_date']      = '';
        $d['payment_method']  = '';
        $d['currency']        = '';
        $d['item_count']      = 0;
        $d['shipping_method'] = '';
        $d['shipping_total']  = 0.0;
        // The submission page's language, captured at submit time; translates
        // the notification emails and is stored on the submission.
        $d['locale']          = determine_locale();

        $id    = isset( $input['matched_id'] ) && is_scalar( $input['matched_id'] ) ? absint( $input['matched_id'] ) : 0;
        $order = $id ? self::find_order( $d['order_number'], $d['email'], $id ) : false;

        if ( ! $order ) {
            $errors[] = __( 'The order details changed. Load the order again.', 'montonio-for-woocommerce' );
        } else {
            $d['order_id']        = $order->get_id();
            $d['order_date']      = $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '';
            $d['payment_method']  = $order->get_payment_method_title();
            $d['currency']        = $order->get_currency();
            $d['item_count']      = (int) $order->get_item_count();
            // Delivery is refunded too when the whole order is withdrawn.
            $d['shipping_method'] = $order->get_shipping_method();
            $d['shipping_total']  = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();

            $selected   = isset( $input['selected'] ) && is_array( $input['selected'] ) ? $input['selected'] : array();
            $quantities = isset( $input['quantities'] ) && is_array( $input['quantities'] ) ? $input['quantities'] : array();
            $items      = $order->get_items();

            foreach ( array_keys( $selected + $quantities ) as $key ) {
                if ( ! isset( $items[ $key ] ) ) {
                    $errors[] = __( 'An item does not belong to this order. Load the order again.', 'montonio-for-woocommerce' );
                }
            }

            // Unchecked items are kept; their posted quantities are ignored.
            $checked = false;

            foreach ( $items as $item_id => $item ) {
                if ( ! isset( $selected[ $item_id ] ) || ! in_array( $selected[ $item_id ], array( '1', true ), true ) ) {
                    continue;
                }

                $checked = true;
                $raw     = isset( $quantities[ $item_id ] ) ? $quantities[ $item_id ] : (string) $item->get_quantity();

                if ( ! is_scalar( $raw ) || ! preg_match( '/^\d+$/', (string) $raw ) || (int) $raw < 1 || (int) $raw > $item->get_quantity() ) {
                    $errors[] = __( 'Item quantities must be whole numbers between one and the quantity ordered.', 'montonio-for-woocommerce' );
                    continue;
                }

                // A snapshot, so the record still identifies the product if the order
                // or product changes later.
                $product      = $item->get_product();
                $d['items'][] = array(
                    'id'           => $item_id,
                    'name'         => $item->get_name(),
                    'quantity'     => (int) $raw,
                    'ordered'      => (int) $item->get_quantity(),
                    // What the customer paid for the line: after discounts, with tax.
                    'total'        => (float) $item->get_total() + (float) $item->get_total_tax(),
                    'product_id'   => $item->get_product_id(),
                    'variation_id' => $item->get_variation_id(),
                    'sku'          => $product ? $product->get_sku() : '',
                    'attributes'   => self::item_attributes( $item )
                );
            }

            if ( ! $checked ) {
                $errors[] = __( 'Select at least one item to withdraw from.', 'montonio-for-woocommerce' );
            }
        }

        return $errors ? new WP_Error( 'validation', implode( ' ', array_unique( $errors ) ) ) : $d;
    }

    /**
     * Sign validated data into a review token with a 2-hour expiry.
     *
     * @since 10.4.0
     * @param array $data Validated withdrawal data.
     * @return string
     */
    public static function sign( $data ) {
        $payload = base64_encode( wp_json_encode( array( 'id' => wp_generate_uuid4(), 'expires' => time() + 7200, 'data' => $data ) ) );

        return $payload . '.' . hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
    }

    /**
     * Verify a review token and return its payload.
     *
     * @since 10.4.0
     * @param mixed $token Submitted token.
     * @return array|false
     */
    public static function verify( $token ) {
        if ( ! is_string( $token ) || strlen( $token ) > 50000 ) {
            return false;
        }

        $parts = explode( '.', $token );

        if ( count( $parts ) !== 2 || ! hash_equals( hash_hmac( 'sha256', $parts[0], wp_salt( 'auth' ) ), $parts[1] ) ) {
            return false;
        }

        $payload = json_decode( base64_decode( $parts[0], true ), true );

        return is_array( $payload ) && isset( $payload['expires'], $payload['id'], $payload['data'] ) && $payload['expires'] >= time() ? $payload : false;
    }

    /**
     * Build mail headers for the merchant notice or customer acknowledgement.
     *
     * Replies to the merchant notice go to the customer; replies to the
     * acknowledgement go to the store's public contact address. The sender is
     * set by WooCommerce's mailer; see deliver().
     *
     * @since 10.4.0
     * @param array  $settings Withdrawal settings.
     * @param bool   $merchant Whether this is the merchant notice.
     * @param string $email    Customer email for the merchant Reply-To.
     * @return array
     */
    public static function headers( $settings, $merchant, $email = '' ) {
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        $reply = $merchant ? $email : $settings['contact_email'];

        // is_email() also rejects line breaks, so neither address can inject headers.
        if ( $reply && is_email( $reply ) ) {
            $headers[] = 'Reply-To: ' . $reply;
        }

        return $headers;
    }

    /**
     * Send one email through WooCommerce's mailer, in the store's email
     * template, and record on the withdrawal record whether it went out.
     *
     * wc_mail() sends from the WooCommerce sender and ignores a From header.
     * Its address falls back here through the withdrawal page's public contact
     * address and the WordPress admin address, taking the first that is a valid
     * single address. A merchant who has visited the WooCommerce email screen
     * and cleared the sender field leaves an empty option rather than a missing
     * one, which no default can cover, and an empty sender fails the send.
     *
     * @since 10.4.0
     * @param int    $id        Withdrawal record ID.
     * @param string $reference Request reference, for the log.
     * @param string $recipient 'merchant' or 'customer'.
     * @param array  $message   To, subject, heading, body (escaped HTML) and headers.
     * @param array  $settings  Withdrawal settings.
     * @return bool Whether the mail service accepted the message.
     */
    private static function deliver( $id, $reference, $recipient, $message, $settings ) {
        $address = function ( $sender ) use ( $settings ) {
            foreach ( array( $sender, $settings['contact_email'], get_option( 'admin_email' ) ) as $candidate ) {
                if ( is_string( $candidate ) && is_email( $candidate ) ) {
                    return $candidate;
                }
            }

            return $sender;
        };

        // Without a name the mail would carry no sender name at all.
        $name = function ( $sender ) {
            return is_string( $sender ) && '' !== trim( $sender ) ? $sender : get_bloginfo( 'name', 'display' );
        };

        add_filter( 'woocommerce_email_from_address', $address, 100 );
        add_filter( 'woocommerce_email_from_name', $name, 100 );

        try {
            $sent = (bool) wc_mail( $message['to'], $message['subject'], WC()->mailer()->wrap_message( $message['heading'], $message['body'] ), $message['headers'] );
        } catch ( Throwable $e ) {
            // A mail plugin's woocommerce_mail_callback can throw; treat it as a failed send.
            WC_Montonio_Logger::log( 'Withdrawal notice: ' . $recipient . ' email threw ' . get_class( $e ) . ': ' . $e->getMessage() . ' Reference: ' . $reference );
            $sent = false;
        } finally {
            remove_filter( 'woocommerce_email_from_address', $address, 100 );
            remove_filter( 'woocommerce_email_from_name', $name, 100 );
        }

        WC_Montonio_Withdrawal_Store::set_mail( $id, $recipient, $sent );

        if ( ! $sent ) {
            WC_Montonio_Logger::log( 'Withdrawal notice: ' . $recipient . ' email could not be handed to the mail service. Reference: ' . $reference );
        }

        return $sent;
    }

    /**
     * Render labelled rows as escaped HTML: each in its own paragraph with the
     * label above the value, or inline as lines of one paragraph.
     *
     * @since 10.4.0
     * @param array $rows   Label => value, labels without their colon.
     * @param bool  $inline Whether to put each value after its label on one line.
     * @return string
     */
    private static function rows_html( $rows, $inline = false ) {
        $lines = array();

        foreach ( $rows as $label => $value ) {
            $lines[] = '<strong>' . esc_html( $label . ':' ) . '</strong>' . ( $inline ? ' ' : '<br>' ) . self::text_html( $value );
        }

        return $lines ? '<p>' . implode( $inline ? '<br>' : '</p><p>', $lines ) . '</p>' : '';
    }

    /**
     * Escape text for an email body, keeping its line breaks.
     *
     * Newlines become <br> rather than staying in the markup, where
     * wrap_message()'s wpautop() would turn them into paragraphs.
     *
     * @since 10.4.0
     * @param string $text Plain text.
     * @return string
     */
    private static function text_html( $text ) {
        return str_replace( array( "\r\n", "\n" ), '<br>', esc_html( $text ) );
    }

    /**
     * List an order item's visible attributes, including values already in the
     * item name: variation titles leave out "Any" attributes and list the rest
     * without labels.
     *
     * @since 10.4.0
     * @param WC_Order_Item_Product $item Order item.
     * @return array[] Attributes, each with a label and a value.
     */
    public static function item_attributes( $item ) {
        $attributes = array();

        foreach ( $item->get_formatted_meta_data( '_', true ) as $meta ) {
            // Store attribute names are often lowercase, e.g. "size".
            $label        = trim( wp_strip_all_tags( $meta->display_key ) );
            $attributes[] = array(
                'label' => function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $label, 0, 1 ) ) . mb_substr( $label, 1 ) : ucfirst( $label ),
                'value' => trim( wp_strip_all_tags( $meta->display_value ) )
            );
        }

        return $attributes;
    }

    /**
     * Build the labelled summary rows for a validated submission.
     *
     * The store notice gets English labels and values whatever the current
     * locale; customers get the translated ones. Both columns of each text sit
     * on one line so a missing entry is visible. Item lines keep the store's own
     * product and attribute names.
     *
     * @since 10.4.0
     * @param array  $d        Validated withdrawal data.
     * @param string $audience 'merchant' for English literals, anything else for translated text.
     * @return array Label => value rows.
     */
    public static function summary( $d, $audience = 'customer' ) {
        $table = array(
            'email'        => array( 'Email', __( 'Email', 'montonio-for-woocommerce' ) ),
            'order_number' => array( 'Order number', __( 'Order number', 'montonio-for-woocommerce' ) ),
            'items'        => array( 'Items', __( 'Items', 'montonio-for-woocommerce' ) ),
            'order_date'   => array( 'Order date', __( 'Order date', 'montonio-for-woocommerce' ) ),
            'reason'       => array( 'Additional information', __( 'Additional information', 'montonio-for-woocommerce' ) )
        );

        $column = 'merchant' === $audience ? 0 : 1;
        $text   = array();

        foreach ( $table as $key => $texts ) {
            $text[ $key ] = $texts[ $column ];
        }

        $rows = array(
            $text['email']        => $d['email'],
            $text['order_number'] => $d['order_number']
        );

        if ( $d['order_date'] ) {
            // The store notice keeps ISO dates: it is English end to end, and a formatted
            // date would bring in month names in the site language.
            $rows[ $text['order_date'] ] = 'merchant' === $audience ? $d['order_date'] : mysql2date( wc_date_format(), $d['order_date'] );
        }

        if ( $d['items'] ) {
            $items = array();

            foreach ( $d['items'] as $item ) {
                $line = $item['quantity'] . ' × ' . $item['name'];

                if ( ! empty( $item['attributes'] ) ) {
                    $line .= ' (' . implode( ', ', array_map( function ( $attribute ) {
                        return $attribute['label'] . ': ' . $attribute['value'];
                    }, $item['attributes'] ) ) . ')';
                }

                $items[] = $line;
            }

            $rows[ $text['items'] ] = implode( "\n", $items );
        }

        if ( $d['reason'] ) {
            $rows[ $text['reason'] ] = $d['reason'];
        }

        return $rows;
    }

    /**
     * Re-load the plugin's translations for the locale that is now current.
     *
     * WordPress before 6.1 cannot reload a plugin textdomain across a locale
     * switch: the switcher unloads the domain, and the just-in-time loader then
     * refuses it because the domain is flagged as unloaded, while its fallback
     * path search never looks inside the plugin's own languages folder. Every
     * string would fall back to English for the remainder of the request, so
     * this is called after switching and after restoring. WooCommerce reloads
     * its own textdomain in wc_switch_to_site_locale() for the same reason.
     *
     * Harmless on 6.1+, where the switcher resolves the catalogue itself.
     *
     * @since 10.4.0
     * @return void
     */
    private static function reload_textdomain() {
        load_plugin_textdomain( 'montonio-for-woocommerce', false, plugin_basename( WC_MONTONIO_PLUGIN_PATH ) . '/languages' );
    }

    /**
     * Record a confirmed submission, then send the merchant notice and
     * customer acknowledgement, with independent retry states per recipient,
     * kept on the withdrawal record.
     *
     * The record is the withdrawal: once it exists the store has received the
     * notice, so only a failure to record is an error. The emails are
     * notifications. A confirm of the same request sends again only those
     * that failed; the confirmation page offers it when the acknowledgement
     * failed, and the admin detail page shows which emails went out.
     *
     * The merchant notice is English whatever language the customer used.
     * The customer acknowledgement follows the locale captured when the
     * customer submitted the form. A short-lived lock prevents double-sends
     * from concurrent confirms of the same review token.
     *
     * @since 10.4.0
     * @param array $payload  Verified token payload.
     * @param array $settings Withdrawal settings.
     * @return array|WP_Error Delivery state, or an error to show the customer.
     */
    public static function send( $payload, $settings ) {
        $locks = new Montonio_Lock_Manager();
        $lock  = 'withdrawal_send_' . hash( 'sha256', $payload['id'] );
        $state = self::delivery( $payload['id'] );

        if ( $state && $state['merchant'] && $state['customer'] ) {
            return $state;
        }

        // Expires by itself, so a request that dies mid-send does not block retries.
        if ( ! $locks->acquire_lock( $lock ) ) {
            return new WP_Error( 'busy', __( 'This request is being sent. Wait a moment and try again.', 'montonio-for-woocommerce' ) );
        }

        $switched = false;

        try {
            // Read again after acquiring the lock: another request may have just finished.
            $id = WC_Montonio_Withdrawal_Store::find( $payload['id'] );

            if ( ! $id ) {
                $id = self::record( $payload['id'], $payload['data'] );
            }

            // The record holds the delivery state, so nothing is sent without one.
            if ( ! $id ) {
                return new WP_Error( 'record', __( 'We could not record your request. Try again or contact the store directly.', 'montonio-for-woocommerce' ) );
            }

            $state = self::delivery( $payload['id'] );
            $d     = $payload['data'];

            // A failed notice does not stop the confirmation: the store already has the record.
            if ( ! $state['merchant'] ) {
                $to = WC_Montonio_Withdrawals::parse_email_list( $settings['recipient'] );

                if ( is_wp_error( $to ) || ! $to ) {
                    WC_Montonio_Logger::log( 'Withdrawal notice: no valid store email is configured. Reference: ' . $state['reference'] );
                } else {
                    $state['merchant'] = self::deliver( $id, $state['reference'], 'merchant', self::merchant_message( $to, $d, $state, $settings ), $settings );
                }
            }

            if ( ! $state['customer'] ) {
                $locale = isset( $d['locale'] ) && is_string( $d['locale'] ) ? $d['locale'] : '';
                // switch_to_locale() returns false when the locale is already current.
                $switched = $locale ? switch_to_locale( $locale ) : false;

                if ( $switched ) {
                    self::reload_textdomain();
                }

                // Built and wrapped inside the switched locale, so text and template match.
                $state['customer'] = self::deliver( $id, $state['reference'], 'customer', self::customer_message( $d, $state, $settings ), $settings );
            }

            return $state;
        } finally {
            if ( $switched ) {
                restore_previous_locale();
                self::reload_textdomain();
            }

            $locks->release_lock( $lock );
        }
    }

    /**
     * Build the merchant notice.
     *
     * English literals throughout, independent of the current locale: the
     * store reads this whatever language the customer used. The declaration
     * is the customer's statement, forwarded verbatim. The submission time
     * follows the WordPress date and time settings, like the rest of the admin.
     *
     * @since 10.4.0
     * @param array $to       Merchant recipients.
     * @param array $d        Validated withdrawal data.
     * @param array $state    Delivery state, for the reference and record.
     * @param array $settings Withdrawal settings.
     * @return array Message for deliver().
     */
    private static function merchant_message( $to, $d, $state, $settings ) {
        $body  = '<p>I hereby give notice that I withdraw from the purchase identified below.</p>';
        $body .= self::rows_html( array(
            'Request reference' => $state['reference'],
            'Submitted at'      => get_the_date( '', $state['id'] ) . ' ' . get_the_time( '', $state['id'] )
        ), true );
        $body .= self::rows_html( self::summary( $d, 'merchant' ) );
        $body .= '<p>Order lookup: Order number and billing email matched. Customer-supplied contact details are not identity verification.</p>';

        $order = $d['order_id'] ? wc_get_order( $d['order_id'] ) : false;

        if ( $order ) {
            $body .= '<p><a href="' . esc_url( $order->get_edit_order_url() ) . '">View matched order</a></p>';
        }

        $title = 'Withdrawal request';

        return array(
            'to'      => $to,
            'subject' => $title . ' [' . $state['reference'] . ']',
            'heading' => $title,
            'body'    => $body,
            'headers' => self::headers( $settings, true, $d['email'] )
        );
    }

    /**
     * Build the customer acknowledgement in the current locale.
     *
     * @since 10.4.0
     * @param array $d        Validated withdrawal data.
     * @param array $state    Delivery state, for the reference and record.
     * @param array $settings Withdrawal settings.
     * @return array Message for deliver().
     */
    private static function customer_message( $d, $state, $settings ) {
        $body  = '<p>' . esc_html__( 'This email acknowledges your withdrawal notice. It does not confirm a refund or return approval.', 'montonio-for-woocommerce' ) . '</p>';
        $body .= '<p>' . esc_html__( 'I hereby give notice that I withdraw from the purchase identified below.', 'montonio-for-woocommerce' ) . '</p>';
        $body .= self::rows_html( array(
            __( 'Request reference', 'montonio-for-woocommerce' ) => $state['reference'],
            __( 'Submitted at', 'montonio-for-woocommerce' )      => get_the_date( '', $state['id'] ) . ' ' . get_the_time( '', $state['id'] )
        ), true );
        $body .= self::rows_html( self::summary( $d ) );

        // The merchant's own formatting, as shown on the withdrawal page.
        if ( $settings['instructions'] ) {
            $body .= '<p>' . wp_kses_post( $settings['instructions'] ) . '</p>';
        }

        $body .= self::rows_html( array( __( 'Contact', 'montonio-for-woocommerce' ) => $settings['contact_email'] ), true );

        $title = __( 'Acknowledgement of your withdrawal', 'montonio-for-woocommerce' );

        return array(
            'to'      => $d['email'],
            'subject' => $title . ' [' . $state['reference'] . ']',
            'heading' => $title,
            'body'    => $body,
            'headers' => self::headers( $settings, false )
        );
    }

    /**
     * Delivery state of a recorded request, in the shape the confirmation reads.
     *
     * @since 10.4.0
     * @param string $reference Request reference.
     * @return array|null Reference, record ID and per-recipient sent flags.
     */
    private static function delivery( $reference ) {
        $record = WC_Montonio_Withdrawal_Store::get( WC_Montonio_Withdrawal_Store::find( $reference ) );

        if ( ! $record ) {
            return null;
        }

        return array(
            'reference' => $reference,
            'id'        => $record['id'],
            'merchant'  => $record['mail_merchant'],
            'customer'  => $record['mail_customer']
        );
    }

    /**
     * Record a confirmed submission and mark its order with a note and meta.
     *
     * Called once per review token, inside the send() lock.
     *
     * @since 10.4.0
     * @param string $reference Request reference.
     * @param array  $d         Validated withdrawal data.
     * @return int Record post ID, or 0 when it could not be created.
     */
    public static function record( $reference, $d ) {
        $id = WC_Montonio_Withdrawal_Store::create( $reference, $d );

        if ( ! $id ) {
            WC_Montonio_Logger::log( 'Withdrawal notice: the request could not be recorded. Reference: ' . $reference );
            return 0;
        }

        update_option( 'montonio_withdrawal_submissions_total', (int) get_option( 'montonio_withdrawal_submissions_total', 0 ) + 1, false );

        $order = $d['order_id'] ? wc_get_order( $d['order_id'] ) : false;

        if ( $order ) {
            $order->update_meta_data( '_montonio_withdrawal_requested', $reference );
            $order->save();
            $order->add_order_note( sprintf(
                'Customer submitted a withdrawal notice for this order via the withdrawal page. Reference: %s',
                $reference
            ) );
        }

        return $id;
    }
}
