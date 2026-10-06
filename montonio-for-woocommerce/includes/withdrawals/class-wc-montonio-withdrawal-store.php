<?php
defined( 'ABSPATH' ) || exit;

/**
 * Storage for confirmed withdrawal notices as a private post type.
 *
 * Each notice is the customer's legal record that they withdrew, so it is
 * independent of the order and survives the order being deleted. This class
 * is the only code that reads or writes these posts and their meta.
 *
 * @since 10.4.0
 */
class WC_Montonio_Withdrawal_Store {

    /**
     * Post type of withdrawal records.
     *
     * @since 10.4.0
     */
    const POST_TYPE = 'montonio_withdrawal';

    /**
     * Status of records waiting to be handled.
     *
     * @since 10.4.0
     */
    const STATUS_INBOX = 'mwd-inbox';

    /**
     * Status of handled records.
     *
     * @since 10.4.0
     */
    const STATUS_COMPLETE = 'mwd-complete';

    /**
     * Register storage hooks.
     *
     * @since 10.4.0
     * @return void
     */
    public static function init() {
        add_action( 'init', array( __CLASS__, 'register' ) );
        add_filter( 'pre_trash_post', array( __CLASS__, 'guard_trash' ), 10, 2 );
        add_filter( 'wp_untrash_post_status', array( __CLASS__, 'untrash_status' ), 10, 3 );
    }

    /**
     * Register the post type and its two statuses.
     *
     * Records are created only by the withdrawal form: core creation is
     * disabled and every other capability requires manage_woocommerce.
     *
     * @since 10.4.0
     * @return void
     */
    public static function register() {
        register_post_type( self::POST_TYPE, array(
            'labels'              => array(
                'name'               => 'Withdrawals',
                'singular_name'      => 'Withdrawal',
                'menu_name'          => 'Withdrawals',
                'all_items'          => 'Withdrawals',
                'search_items'       => 'Search withdrawals',
                'not_found'          => 'No withdrawals found.',
                'not_found_in_trash' => 'No withdrawals found in Trash.'
            ),
            'public'              => false,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_ui'             => true,
            'show_in_menu'        => 'woocommerce',
            'show_in_nav_menus'   => false,
            'show_in_admin_bar'   => false,
            'show_in_rest'        => false,
            'rewrite'             => false,
            'query_var'           => false,
            'can_export'          => false,
            'supports'            => false,
            'map_meta_cap'        => true,
            'capabilities'        => array(
                'create_posts'           => 'do_not_allow',
                'edit_posts'             => 'manage_woocommerce',
                'edit_others_posts'      => 'manage_woocommerce',
                'edit_published_posts'   => 'manage_woocommerce',
                'edit_private_posts'     => 'manage_woocommerce',
                'publish_posts'          => 'manage_woocommerce',
                'read_private_posts'     => 'manage_woocommerce',
                'delete_posts'           => 'manage_woocommerce',
                'delete_others_posts'    => 'manage_woocommerce',
                'delete_published_posts' => 'manage_woocommerce',
                'delete_private_posts'   => 'manage_woocommerce'
            )
        ) );

        register_post_status( self::STATUS_INBOX, array(
            'label'                     => 'Pending',
            'public'                    => false,
            'protected'                 => true,
            'exclude_from_search'       => true,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Pending <span class="count">(%s)</span>', 'Pending <span class="count">(%s)</span>' )
        ) );

        register_post_status( self::STATUS_COMPLETE, array(
            'label'                     => 'Completed',
            'public'                    => false,
            'protected'                 => true,
            'exclude_from_search'       => true,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Completed <span class="count">(%s)</span>', 'Completed <span class="count">(%s)</span>' )
        ) );
    }

    /**
     * Record a confirmed withdrawal in the Inbox.
     *
     * @since 10.4.0
     * @param string $reference Request reference (UUID).
     * @param array  $d         Validated withdrawal data.
     * @return int Post ID, or 0 when the record could not be created.
     */
    public static function create( $reference, $d ) {
        // wp_insert_post() and update_metadata() unslash what they save, and the
        // data is already unslashed, so slash the strings to keep backslashes.
        // Only strings: wp_slash() of a whole array turns integers into strings
        // before WordPress 5.5.
        $items = map_deep( is_array( $d['items'] ) ? $d['items'] : array(), array( __CLASS__, 'slash_string' ) );

        $id = wp_insert_post( array(
            'post_type'   => self::POST_TYPE,
            'post_status' => self::STATUS_INBOX,
            'post_name'   => $reference,
            // Core list search matches the title, so it carries the order, email and reference.
            'post_title'  => wp_slash( sprintf( 'Order #%s — %s — %s', $d['order_number'], $d['email'], $reference ) ),
            'post_author' => 0,
            'meta_input'  => array(
                '_reference'       => $reference,
                '_order_id'        => (int) $d['order_id'],
                '_order_number'    => wp_slash( (string) $d['order_number'] ),
                '_email'           => wp_slash( (string) $d['email'] ),
                '_locale'          => isset( $d['locale'] ) && is_string( $d['locale'] ) ? $d['locale'] : determine_locale(),
                '_items'           => $items,
                '_order_date'      => (string) $d['order_date'],
                '_payment_method'  => isset( $d['payment_method'] ) ? wp_slash( (string) $d['payment_method'] ) : '',
                '_currency'        => isset( $d['currency'] ) ? (string) $d['currency'] : '',
                '_item_count'      => isset( $d['item_count'] ) ? (int) $d['item_count'] : 0,
                '_shipping_method' => isset( $d['shipping_method'] ) ? wp_slash( (string) $d['shipping_method'] ) : '',
                '_shipping_total'  => isset( $d['shipping_total'] ) ? (float) $d['shipping_total'] : 0.0,
                '_reason'          => wp_slash( (string) $d['reason'] ),
                '_mail_merchant'   => 0,
                '_mail_customer'   => 0
            )
        ), true );

        return is_wp_error( $id ) ? 0 : (int) $id;
    }

    /**
     * Slash one value if it is a string. map_deep() callback.
     *
     * @since 10.4.0
     * @param mixed $value Value.
     * @return mixed
     */
    public static function slash_string( $value ) {
        return is_string( $value ) ? wp_slash( $value ) : $value;
    }

    /**
     * Find a record by its request reference, including trashed records.
     *
     * @since 10.4.0
     * @param string $reference Request reference.
     * @return int Post ID, or 0.
     */
    public static function find( $reference ) {
        // Trashing renames the slug to <slug>__trashed until the record is restored.
        $ids = get_posts( array(
            'post_type'      => self::POST_TYPE,
            'post_name__in'  => array( $reference, $reference . '__trashed' ),
            'post_status'    => array( self::STATUS_INBOX, self::STATUS_COMPLETE, 'trash' ),
            'posts_per_page' => 1,
            'fields'         => 'ids'
        ) );

        return $ids ? (int) $ids[0] : 0;
    }

    /**
     * Find an order's Pending and Completed records, newest first.
     *
     * @since 10.4.0
     * @param int $order_id Order ID.
     * @return int[] Post IDs.
     */
    public static function find_by_order( $order_id ) {
        return array_map( 'intval', get_posts( array(
            'post_type'      => self::POST_TYPE,
            'post_status'    => array( self::STATUS_INBOX, self::STATUS_COMPLETE ),
            'meta_key'       => '_order_id',
            'meta_value'     => (int) $order_id,
            'posts_per_page' => 20,
            'fields'         => 'ids'
        ) ) );
    }

    /**
     * Load one record.
     *
     * @since 10.4.0
     * @param int $id Post ID.
     * @return array|null Record fields, or null when the ID is not a withdrawal.
     */
    public static function get( $id ) {
        $post = $id ? get_post( $id ) : null;

        if ( ! $post || self::POST_TYPE !== $post->post_type ) {
            return null;
        }

        $statuses = array( self::STATUS_INBOX => 'inbox', self::STATUS_COMPLETE => 'complete', 'trash' => 'trash' );
        $items    = get_post_meta( $post->ID, '_items', true );

        return array(
            'id'              => (int) $post->ID,
            'status'          => isset( $statuses[ $post->post_status ] ) ? $statuses[ $post->post_status ] : 'inbox',
            'reference'       => (string) get_post_meta( $post->ID, '_reference', true ),
            'created'         => $post->post_date_gmt,
            'order_id'        => (int) get_post_meta( $post->ID, '_order_id', true ),
            'order_number'    => (string) get_post_meta( $post->ID, '_order_number', true ),
            'email'           => (string) get_post_meta( $post->ID, '_email', true ),
            'locale'          => (string) get_post_meta( $post->ID, '_locale', true ),
            'items'           => is_array( $items ) ? $items : array(),
            'order_date'      => (string) get_post_meta( $post->ID, '_order_date', true ),
            'payment_method'  => (string) get_post_meta( $post->ID, '_payment_method', true ),
            'currency'        => (string) get_post_meta( $post->ID, '_currency', true ),
            'item_count'      => (int) get_post_meta( $post->ID, '_item_count', true ),
            'shipping_method' => (string) get_post_meta( $post->ID, '_shipping_method', true ),
            'shipping_total'  => (float) get_post_meta( $post->ID, '_shipping_total', true ),
            'reason'          => (string) get_post_meta( $post->ID, '_reason', true ),
            'mail_merchant'   => (bool) get_post_meta( $post->ID, '_mail_merchant', true ),
            'mail_customer'   => (bool) get_post_meta( $post->ID, '_mail_customer', true )
        );
    }

    /**
     * Record whether one email was handed to the mail service.
     *
     * @since 10.4.0
     * @param int    $id        Post ID.
     * @param string $recipient merchant or customer.
     * @param bool   $sent      Whether it was sent.
     * @return void
     */
    public static function set_mail( $id, $recipient, $sent ) {
        if ( in_array( $recipient, array( 'merchant', 'customer' ), true ) ) {
            update_post_meta( $id, '_mail_' . $recipient, $sent ? 1 : 0 );
        }
    }

    /**
     * Move records between Inbox and Complete.
     *
     * Only records actually changing status are counted, so the count can
     * drive the completed-submissions telemetry counter.
     *
     * @since 10.4.0
     * @param array|int $ids    Post IDs.
     * @param string    $status inbox or complete.
     * @return int Number of records changed.
     */
    public static function set_status( $ids, $status ) {
        $statuses = array( 'inbox' => self::STATUS_INBOX, 'complete' => self::STATUS_COMPLETE );

        if ( ! isset( $statuses[ $status ] ) ) {
            return 0;
        }

        $changed = 0;

        foreach ( array_filter( array_map( 'absint', (array) $ids ) ) as $id ) {
            $current = get_post_status( $id );

            if ( self::POST_TYPE !== get_post_type( $id ) || ! in_array( $current, $statuses, true ) || $statuses[ $status ] === $current ) {
                continue;
            }

            if ( wp_update_post( array( 'ID' => $id, 'post_status' => $statuses[ $status ] ) ) ) {
                $changed++;
            }
        }

        if ( $changed ) {
            $completed = (int) get_option( 'montonio_withdrawal_submissions_completed', 0 ) + ( 'complete' === $status ? $changed : -$changed );
            update_option( 'montonio_withdrawal_submissions_completed', max( 0, $completed ), false );
        }

        return $changed;
    }

    /**
     * Move completed records to the Trash; Inbox records are skipped.
     *
     * @since 10.4.0
     * @param array|int $ids Post IDs.
     * @return int Number of records trashed.
     */
    public static function trash( $ids ) {
        $trashed = 0;

        foreach ( array_filter( array_map( 'absint', (array) $ids ) ) as $id ) {
            if ( self::POST_TYPE === get_post_type( $id ) && self::STATUS_COMPLETE === get_post_status( $id ) && wp_trash_post( $id ) ) {
                $trashed++;
            }
        }

        return $trashed;
    }

    /**
     * Number of records per status, from core's cached post counts.
     *
     * @since 10.4.0
     * @return array Counts keyed inbox and complete.
     */
    public static function count() {
        $counts = wp_count_posts( self::POST_TYPE );

        return array(
            'inbox'    => isset( $counts->{self::STATUS_INBOX} ) ? (int) $counts->{self::STATUS_INBOX} : 0,
            'complete' => isset( $counts->{self::STATUS_COMPLETE} ) ? (int) $counts->{self::STATUS_COMPLETE} : 0
        );
    }

    /**
     * Refuse to trash a record that has not been handled. pre_trash_post filter.
     *
     * @since 10.4.0
     * @param bool|null $check Short-circuit value.
     * @param WP_Post   $post  Post being trashed.
     * @return bool|null
     */
    public static function guard_trash( $check, $post ) {
        if ( null === $check && $post && self::POST_TYPE === $post->post_type && self::STATUS_COMPLETE !== $post->post_status ) {
            return false;
        }

        return $check;
    }

    /**
     * Restore a record with its pre-trash status. wp_untrash_post_status filter.
     *
     * WordPress 5.6+ restores untrashed posts as drafts, which would drop a
     * record out of both views.
     *
     * @since 10.4.0
     * @param string $new_status      Status core would restore.
     * @param int    $post_id         Post ID.
     * @param string $previous_status Status before trashing.
     * @return string
     */
    public static function untrash_status( $new_status, $post_id, $previous_status ) {
        if ( self::POST_TYPE === get_post_type( $post_id ) && in_array( $previous_status, array( self::STATUS_INBOX, self::STATUS_COMPLETE ), true ) ) {
            return $previous_status;
        }

        return $new_status;
    }
}
