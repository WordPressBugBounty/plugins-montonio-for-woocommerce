<?php
defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce → Withdrawals: the core list screen for withdrawal records,
 * their read-only detail page and the new-request menu bubble.
 *
 * Records are the customer's notice, so nothing here edits them: the core
 * editor redirects to the detail page and only the status can change.
 *
 * @since 10.4.0
 */
class WC_Montonio_Withdrawal_Admin {

    /**
     * Slug of the hidden detail page.
     *
     * @since 10.4.0
     */
    const DETAIL_PAGE = 'montonio-withdrawal';

    /**
     * admin-post.php action for single status changes.
     *
     * @since 10.4.0
     */
    const STATUS_ACTION = 'montonio_withdrawal_status';

    /**
     * Register admin hooks.
     *
     * @since 10.4.0
     * @return void
     */
    public static function init() {
        $type = WC_Montonio_Withdrawal_Store::POST_TYPE;

        add_filter( 'manage_' . $type . '_posts_columns', array( __CLASS__, 'columns' ) );
        add_filter( 'manage_edit-' . $type . '_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
        add_action( 'manage_' . $type . '_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
        add_filter( 'list_table_primary_column', array( __CLASS__, 'primary_column' ), 10, 2 );
        add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
        add_filter( 'bulk_actions-edit-' . $type, array( __CLASS__, 'bulk_actions' ) );
        add_filter( 'handle_bulk_actions-edit-' . $type, array( __CLASS__, 'handle_bulk_actions' ), 10, 3 );
        add_filter( 'get_edit_post_link', array( __CLASS__, 'edit_link' ), 10, 2 );
        add_action( 'load-post.php', array( __CLASS__, 'redirect_edit_screen' ) );
        add_action( 'admin_menu', array( __CLASS__, 'register_detail_page' ) );
        add_action( 'admin_menu', array( __CLASS__, 'add_menu_bubble' ), 100 );
        add_action( 'admin_head', array( __CLASS__, 'hide_detail_page' ) );
        add_filter( 'submenu_file', array( __CLASS__, 'highlight_menu' ) );
        add_action( 'admin_post_' . self::STATUS_ACTION, array( __CLASS__, 'handle_status_action' ) );
        add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'order_notice' ) );
    }

    /**
     * URL of the withdrawal list.
     *
     * @since 10.4.0
     * @return string
     */
    public static function list_url() {
        return admin_url( 'edit.php?post_type=' . WC_Montonio_Withdrawal_Store::POST_TYPE );
    }

    /**
     * URL of one record's detail page.
     *
     * @since 10.4.0
     * @param int $id Post ID.
     * @return string
     */
    public static function detail_url( $id ) {
        return add_query_arg( array( 'page' => self::DETAIL_PAGE, 'id' => (int) $id ), admin_url( 'admin.php' ) );
    }

    /**
     * Nonced URL moving one record to inbox or complete.
     *
     * @since 10.4.0
     * @param int    $id     Post ID.
     * @param string $status inbox or complete.
     * @return string
     */
    public static function status_url( $id, $status ) {
        return wp_nonce_url(
            add_query_arg( array( 'action' => self::STATUS_ACTION, 'id' => (int) $id, 'status' => $status ), admin_url( 'admin-post.php' ) ),
            self::STATUS_ACTION . '-' . (int) $id
        );
    }

    /**
     * List columns.
     *
     * @since 10.4.0
     * @param array $columns Core columns.
     * @return array
     */
    public static function columns( $columns ) {
        return array(
            'cb'         => isset( $columns['cb'] ) ? $columns['cb'] : '<input type="checkbox" />',
            'request'    => 'Request',
            'submitted'  => 'Date',
            'mwd_status' => 'Status',
            'order'      => 'Order number',
            'email'      => 'Email'
        );
    }

    /**
     * Sortable columns: Date sorts by post date, newest first.
     *
     * @since 10.4.0
     * @return array
     */
    public static function sortable_columns() {
        return array( 'submitted' => array( 'date', true ) );
    }

    /**
     * Request is the primary column on the withdrawal list, so it carries the row actions.
     *
     * @since 10.4.0
     * @param string $default   Default primary column.
     * @param string $screen_id Screen ID.
     * @return string
     */
    public static function primary_column( $default, $screen_id ) {
        return 'edit-' . WC_Montonio_Withdrawal_Store::POST_TYPE === $screen_id ? 'request' : $default;
    }

    /**
     * Render one custom column cell.
     *
     * @since 10.4.0
     * @param string $column  Column key.
     * @param int    $post_id Post ID.
     * @return void
     */
    public static function render_column( $column, $post_id ) {
        $record = WC_Montonio_Withdrawal_Store::get( $post_id );

        if ( ! $record ) {
            return;
        }

        if ( 'request' === $column ) {
            echo '<strong><a href="' . esc_url( self::detail_url( $post_id ) ) . '">' . esc_html( 'Withdrawal for order #' . $record['order_number'] ) . '</a></strong>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        } elseif ( 'order' === $column ) {
            $order = $record['order_id'] ? wc_get_order( $record['order_id'] ) : false;

            echo $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html( $record['order_number'] ) . '</a>' : esc_html( $record['order_number'] );
        } elseif ( 'submitted' === $column ) {
            echo self::list_date( $record['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        } elseif ( 'email' === $column ) {
            echo '<a href="' . esc_url( 'mailto:' . $record['email'] ) . '">' . esc_html( $record['email'] ) . '</a>';
        } elseif ( 'mwd_status' === $column ) {
            echo self::status_pill( $record['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    /**
     * Submission date as WooCommerce's order list shows it: "7 hours ago"
     * within a day, otherwise a short date, with the full date and time on hover.
     *
     * @since 10.4.0
     * @param int      $id  Withdrawal record ID.
     * @param int|null $now Current timestamp; defaults to time().
     * @return string HTML.
     */
    public static function list_date( $id, $now = null ) {
        $now       = null === $now ? time() : (int) $now;
        $timestamp = (int) get_post_time( 'U', true, $id );

        if ( ! $timestamp ) {
            return '&ndash;';
        }

        if ( $timestamp > strtotime( '-1 day', $now ) && $timestamp <= $now ) {
            $shown = sprintf( '%s ago', human_time_diff( $timestamp, $now ) );
        } else {
            // WooCommerce's filter, so a store's custom order date format applies here too.
            $shown = get_the_date( apply_filters( 'woocommerce_admin_order_date_format', 'M j, Y' ), $id );
        }

        return sprintf(
            '<time datetime="%1$s" title="%2$s">%3$s</time>',
            esc_attr( gmdate( 'c', $timestamp ) ),
            esc_attr( get_the_date( '', $id ) . ' ' . get_the_time( '', $id ) ),
            esc_html( $shown )
        );
    }

    /**
     * Row actions: View, the status change, and Trash for completed records.
     *
     * @since 10.4.0
     * @param array   $actions Core row actions.
     * @param WP_Post $post    Row post.
     * @return array
     */
    public static function row_actions( $actions, $post ) {
        if ( WC_Montonio_Withdrawal_Store::POST_TYPE !== $post->post_type ) {
            return $actions;
        }

        // The Trash view keeps core's Restore and Delete Permanently.
        if ( 'trash' === $post->post_status ) {
            return array_intersect_key( $actions, array_flip( array( 'untrash', 'delete' ) ) );
        }

        $rows = array( 'view' => '<a href="' . esc_url( self::detail_url( $post->ID ) ) . '">View</a>' );

        if ( WC_Montonio_Withdrawal_Store::STATUS_COMPLETE === $post->post_status ) {
            $rows['mwd_inbox'] = '<a href="' . esc_url( self::status_url( $post->ID, 'inbox' ) ) . '">Mark as pending</a>';

            if ( isset( $actions['trash'] ) ) {
                $rows['trash'] = $actions['trash'];
            }
        } else {
            $rows['mwd_complete'] = '<a href="' . esc_url( self::status_url( $post->ID, 'complete' ) ) . '">Mark as completed</a>';
        }

        return $rows;
    }

    /**
     * Bulk actions. Core Edit and Trash are replaced: core bulk Trash stops
     * with an error on the first Inbox record, the withdrawal version skips them.
     *
     * @since 10.4.0
     * @param array $actions Core bulk actions.
     * @return array
     */
    public static function bulk_actions( $actions ) {
        if ( isset( $_GET['post_status'] ) && 'trash' === sanitize_key( wp_unslash( $_GET['post_status'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which list view is shown.
            return $actions;
        }

        return array(
            'mwd_complete' => 'Mark as completed',
            'mwd_inbox'    => 'Mark as pending',
            'mwd_trash'    => 'Move completed to Trash'
        );
    }

    /**
     * Apply a withdrawal bulk action. Core has already checked the nonce.
     *
     * @since 10.4.0
     * @param string $redirect Redirect URL.
     * @param string $action   Bulk action.
     * @param array  $ids      Selected post IDs.
     * @return string
     */
    public static function handle_bulk_actions( $redirect, $action, $ids ) {
        if ( 'mwd_complete' === $action || 'mwd_inbox' === $action ) {
            WC_Montonio_Withdrawal_Store::set_status( $ids, 'mwd_complete' === $action ? 'complete' : 'inbox' );
        } elseif ( 'mwd_trash' === $action ) {
            WC_Montonio_Withdrawal_Store::trash( $ids );
        }

        return $redirect;
    }

    /**
     * Point edit links of withdrawal records at the detail page.
     *
     * @since 10.4.0
     * @param string $link    Core edit link.
     * @param int    $post_id Post ID.
     * @return string
     */
    public static function edit_link( $link, $post_id ) {
        return WC_Montonio_Withdrawal_Store::POST_TYPE === get_post_type( $post_id ) ? self::detail_url( $post_id ) : $link;
    }

    /**
     * Detail page URL a post.php request should go to instead, if any.
     *
     * Only the editor redirects; post.php also serves Trash, Restore and
     * Delete Permanently, which must keep working.
     *
     * @since 10.4.0
     * @param array $query Request query arguments.
     * @return string Detail URL, or an empty string.
     */
    public static function edit_redirect_target( $query ) {
        $id     = isset( $query['post'] ) && is_scalar( $query['post'] ) ? absint( $query['post'] ) : 0;
        $action = isset( $query['action'] ) && is_scalar( $query['action'] ) ? (string) $query['action'] : '';

        return $id && in_array( $action, array( '', 'edit' ), true ) && WC_Montonio_Withdrawal_Store::POST_TYPE === get_post_type( $id ) ? self::detail_url( $id ) : '';
    }

    /**
     * Send the core editor of a withdrawal record to its detail page. load-post.php action.
     *
     * @since 10.4.0
     * @return void
     */
    public static function redirect_edit_screen() {
        $url = self::edit_redirect_target( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- edit_redirect_target() reads only integers and a fixed action list.

        if ( $url ) {
            wp_safe_redirect( $url );
            exit;
        }
    }

    /**
     * Register the detail page; hide_detail_page() keeps it out of the menu.
     *
     * @since 10.4.0
     * @return void
     */
    public static function register_detail_page() {
        add_submenu_page( 'woocommerce', 'Withdrawal', 'Withdrawal', 'manage_woocommerce', self::DETAIL_PAGE, array( __CLASS__, 'render_detail_page' ) );
    }

    /**
     * Keep Withdrawals highlighted in the menu while on the detail page. submenu_file filter.
     *
     * @since 10.4.0
     * @param string $submenu_file Current submenu file.
     * @return string
     */
    public static function highlight_menu( $submenu_file ) {
        return isset( $_GET['page'] ) && self::DETAIL_PAGE === sanitize_key( wp_unslash( $_GET['page'] ) ) ? 'edit.php?post_type=' . WC_Montonio_Withdrawal_Store::POST_TYPE : $submenu_file; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- menu highlighting only.
    }

    /**
     * Core menu count bubble markup.
     *
     * @since 10.4.0
     * @param int $count Count to show.
     * @return string
     */
    public static function menu_bubble( $count ) {
        return ' <span class="awaiting-mod count-' . (int) $count . '"><span class="pending-count">' . esc_html( number_format_i18n( $count ) ) . '</span></span>';
    }

    /**
     * Add the Inbox count to the Withdrawals menu item. admin_menu action,
     * after core has added the post type's submenu item.
     *
     * @since 10.4.0
     * @return void
     */
    public static function add_menu_bubble() {
        global $submenu;

        $count = WC_Montonio_Withdrawal_Store::count()['inbox'];

        if ( ! $count || empty( $submenu['woocommerce'] ) ) {
            return;
        }

        $slug = 'edit.php?post_type=' . WC_Montonio_Withdrawal_Store::POST_TYPE;

        foreach ( $submenu['woocommerce'] as $index => $item ) {
            if ( isset( $item[2] ) && $slug === $item[2] ) {
                $submenu['woocommerce'][ $index ][0] .= self::menu_bubble( $count );
            }
        }
    }

    /**
     * Keep the detail page out of the menu. admin_head action: WordPress
     * checks page access against the submenu after admin_menu, so removing
     * it any earlier would lock the page.
     *
     * @since 10.4.0
     * @return void
     */
    public static function hide_detail_page() {
        remove_submenu_page( 'woocommerce', self::DETAIL_PAGE );
    }

    /**
     * Apply a single status change from a row or detail link. admin-post action.
     *
     * @since 10.4.0
     * @return void
     */
    public static function handle_status_action() {
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        $status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

        check_admin_referer( self::STATUS_ACTION . '-' . $id );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Sorry, you are not allowed to change withdrawals.', '', array( 'response' => 403 ) );
        }

        WC_Montonio_Withdrawal_Store::set_status( array( $id ), $status );

        $referer = wp_get_referer();

        wp_safe_redirect( $referer ? $referer : self::list_url() );
        exit;
    }

    /**
     * Status pill in WooCommerce's order-status style.
     *
     * @since 10.4.0
     * @param string $status inbox, complete or trash.
     * @return string HTML.
     */
    public static function status_pill( $status ) {
        $pills = array(
            'inbox'    => array( 'pending', 'Pending' ),
            'complete' => array( 'completed', 'Completed' ),
            'trash'    => array( 'trash', 'Trash' )
        );
        $pill  = isset( $pills[ $status ] ) ? $pills[ $status ] : $pills['inbox'];

        return '<mark class="order-status montonio-withdrawal-status montonio-withdrawal-status--' . esc_attr( $pill[0] ) . '"><span>' . esc_html( $pill[1] ) . '</span></mark>';
    }

    /**
     * Flag the order's withdrawal requests in the order panel, under its
     * General details, in Montonio notices: warning while a request is
     * Pending, neutral once it is Completed.
     * woocommerce_admin_order_data_after_order_details action.
     *
     * @since 10.4.0
     * @param WC_Order $order Order being edited.
     * @return void
     */
    public static function order_notice( $order ) {
        $ids = current_user_can( 'manage_woocommerce' ) ? WC_Montonio_Withdrawal_Store::find_by_order( $order->get_id() ) : array();

        if ( ! $ids ) {
            return;
        }

        echo '<div class="montonio-wdr-order-notices">';

        foreach ( $ids as $id ) {
            $record   = WC_Montonio_Withdrawal_Store::get( $id );
            $returned = (int) array_sum( wp_list_pluck( $record['items'], 'quantity' ) );

            if ( $record['item_count'] && $returned >= $record['item_count'] ) {
                $items = 'the whole order';
            } elseif ( $record['item_count'] ) {
                $items = $returned . ' of ' . $record['item_count'] . ' items';
            } else {
                $items = $returned . ( 1 === $returned ? ' item' : ' items' );
            }

            $pending = 'inbox' === $record['status'];

            WC_Montonio_Admin_Settings_Page::render_banner(
                sprintf(
                    '<strong>%1$s</strong><br>On %2$s the customer asked to return %3$s. <a href="%4$s">View request</a>',
                    $pending ? 'Withdrawal requested.' : 'Withdrawal request completed.',
                    esc_html( get_the_date( '', $id ) . ' ' . get_the_time( '', $id ) ),
                    esc_html( $items ),
                    esc_url( self::detail_url( $id ) )
                ),
                ( $pending ? 'montonio-notice--warning' : 'montonio-notice--neutral' ) . ' montonio-notice--compact'
            );
        }

        echo '</div>';
    }

    /**
     * Output the detail page.
     *
     * @since 10.4.0
     * @return void
     */
    public static function render_detail_page() {
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view, capability checked by the admin page.

        echo '<div class="wrap"><h1 class="wp-heading-inline">Withdrawal</h1><a class="page-title-action" href="' . esc_url( self::list_url() ) . '">Back to withdrawals</a><hr class="wp-header-end">';
        echo self::render_detail( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while building.
        echo '</div>';
    }

    /**
     * Build one record's read-only detail, laid out like WooCommerce's order
     * screen: a summary panel and the returned items, with the actions aside.
     *
     * @since 10.4.0
     * @param int $id Post ID.
     * @return string HTML.
     */
    public static function render_detail( $id ) {
        $record = WC_Montonio_Withdrawal_Store::get( $id );

        if ( ! $record ) {
            return '<p>Withdrawal not found.</p>';
        }

        $order  = $record['order_id'] ? wc_get_order( $record['order_id'] ) : false;
        $number = esc_html( '#' . $record['order_number'] );
        $date   = $record['order_date'] ? esc_html( mysql2date( wc_date_format(), $record['order_date'] ) ) : '&ndash;';
        $reason = '' !== $record['reason'] ? nl2br( esc_html( $record['reason'] ) ) : '<span class="montonio-wdr-muted">None</span>';
        $method = '' !== $record['payment_method'] ? esc_html( $record['payment_method'] ) : '&ndash;';

        $html  = '<div id="poststuff"><div id="post-body" class="metabox-holder columns-2"><div id="post-body-content">';
        $html .= '<div class="postbox montonio-wdr-panel">';
        $html .= '<h2 class="montonio-wdr-title">Withdrawal for order ' . $number . '</h2>';
        $html .= '<p class="montonio-wdr-meta">Submitted on ' . esc_html( get_the_date( '', $id ) . ' ' . get_the_time( '', $id ) ) . '. Request reference: ' . esc_html( $record['reference'] ) . '</p>';
        $html .= '<div class="montonio-wdr-columns">';
        $html .= '<div><h3>General</h3>';
        $html .= '<p><strong>Order number</strong>' . ( $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">' . $number . '</a>' : $number ) . '</p>';
        $html .= '<p><strong>Order date</strong>' . $date . '</p>';
        $html .= '<p><strong>Payment method</strong>' . $method . '</p></div>';
        $html .= '<div><h3>Customer</h3><p><strong>Email</strong><a href="' . esc_url( 'mailto:' . $record['email'] ) . '">' . esc_html( $record['email'] ) . '</a></p></div>';
        $html .= '<div><h3>Additional information</h3><p>' . $reason . '</p></div>';
        $html .= '</div></div>';

        $html .= '<div class="postbox montonio-wdr-items-box"><table class="montonio-wdr-items"><thead><tr><th colspan="2">Items to refund</th><th class="montonio-wdr-num">Price</th><th class="montonio-wdr-num">Qty</th><th class="montonio-wdr-num">Total</th></tr></thead><tbody>';

        $returned = 0;
        $total    = '' !== $record['currency'] ? 0 : null;

        foreach ( $record['items'] as $item ) {
            $item     += array( 'ordered' => $item['quantity'], 'total' => null, 'product_id' => 0, 'variation_id' => 0, 'sku' => '', 'attributes' => array() );
            $line      = null !== $item['total'] && '' !== $record['currency'] ? $item['total'] * $item['quantity'] / max( 1, $item['ordered'] ) : null;
            $returned += $item['quantity'];
            // Only a total of every item is worth showing.
            $total     = null !== $total && null !== $line ? $total + $line : null;
            $html     .= self::render_item( $item, $line, $record['currency'] );
        }

        // Withdrawing from the whole order also refunds its delivery.
        $money    = array( 'currency' => $record['currency'] );
        $shipping = null !== $total && $record['item_count'] && $returned >= $record['item_count'] && $record['shipping_total'] > 0;

        $html .= '</tbody></table><div class="montonio-wdr-totals"><table>';
        $html .= '<tr><th>Items to refund:</th><td>' . esc_html( $record['item_count'] ? $returned . ' of ' . $record['item_count'] : $returned ) . '</td></tr>';

        if ( $shipping ) {
            $label = '' !== $record['shipping_method'] ? 'Shipping (' . $record['shipping_method'] . '):' : 'Shipping:';
            $html .= '<tr><th>' . esc_html( $label ) . '</th><td>' . wc_price( $record['shipping_total'], $money ) . '</td></tr>';
        }

        if ( null !== $total ) {
            $html .= '<tr><th>Items total:</th><td>' . wc_price( $total, $money ) . '</td></tr>';
        }

        if ( $shipping ) {
            $html .= '<tr class="montonio-wdr-grand"><th>Refund total:</th><td>' . wc_price( $total + $record['shipping_total'], $money ) . '</td></tr>';
        }

        $html .= '</table>';

        if ( $shipping ) {
            $html .= '<p class="montonio-wdr-muted montonio-wdr-note">The whole order is being returned, so refund the delivery cost too.</p>';
        }

        if ( '' !== $record['currency'] ) {
            $html .= '<p class="montonio-wdr-muted montonio-wdr-note">Prices are what the customer paid, after discounts and including tax.</p>';
        }

        $html .= '</div></div></div>';

        $html .= '<div id="postbox-container-1" class="postbox-container"><div class="postbox"><div class="postbox-header"><h2 class="hndle">Withdrawal actions</h2></div><div class="inside">';
        $html .= '<p class="montonio-wdr-status"><strong>Status</strong>' . self::status_pill( $record['status'] ) . '</p>';

        // The request is recorded whether or not its emails went out, so show which did.
        $mail = array();

        foreach ( array( 'Store notice' => $record['mail_merchant'], 'Customer acknowledgement' => $record['mail_customer'] ) as $label => $sent ) {
            $mail[] = esc_html( $label . ': ' . ( $sent ? 'Sent' : 'Not sent' ) );
        }

        $html .= '<p class="montonio-wdr-status montonio-wdr-mail"><strong>Emails</strong>' . implode( '<br>', $mail ) . '</p><p class="montonio-wdr-actions">';

        if ( 'complete' === $record['status'] ) {
            $html .= '<a class="button" href="' . esc_url( self::status_url( $record['id'], 'inbox' ) ) . '">Mark as pending</a>';
        } elseif ( 'inbox' === $record['status'] ) {
            $html .= '<a class="button button-primary" href="' . esc_url( self::status_url( $record['id'], 'complete' ) ) . '">Mark as completed</a>';
        }

        if ( $order ) {
            $html .= '<a class="button" href="' . esc_url( $order->get_edit_order_url() ) . '">View order</a>';
        }

        return $html . '</p></div></div></div></div><br class="clear"></div>';
    }

    /**
     * Build one returned item's row: image, name, SKU, attributes, price,
     * quantity and total. The product is looked up live for its image and
     * link; everything else comes from the snapshot taken at submission.
     *
     * @since 10.4.0
     * @param array      $item     Stored item, with defaults for missing fields.
     * @param float|null $line     Paid total for the returned quantity, or null when unknown.
     * @param string     $currency Order currency.
     * @return string HTML.
     */
    private static function render_item( $item, $line, $currency ) {
        $price      = null !== $line ? wc_price( $item['total'] / max( 1, $item['ordered'] ), array( 'currency' => $currency ) ) : '&ndash;';
        $product_id = $item['variation_id'] ? $item['variation_id'] : $item['product_id'];
        $product    = $product_id ? wc_get_product( $product_id ) : false;
        $image      = $product ? $product->get_image( 'thumbnail', array( 'title' => '' ), false ) : wc_placeholder_img( 'thumbnail' );
        $edit       = $product ? get_edit_post_link( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id(), 'raw' ) : '';
        $name       = $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $item['name'] ) . '</a>' : esc_html( $item['name'] );

        $html  = '<tr><td class="montonio-wdr-thumb">' . $image . '</td><td><div class="montonio-wdr-item-name">' . $name . '</div>';

        if ( '' !== $item['sku'] ) {
            $html .= '<div class="montonio-wdr-item-info"><strong>SKU:</strong> ' . esc_html( $item['sku'] ) . '</div>';
        }

        if ( $item['variation_id'] ) {
            $html .= '<div class="montonio-wdr-item-info"><strong>Variation ID:</strong> ' . esc_html( $item['variation_id'] ) . '</div>';
        }

        if ( $item['attributes'] ) {
            $html .= '<table class="montonio-wdr-attributes">';

            foreach ( $item['attributes'] as $attribute ) {
                $html .= '<tr><th>' . esc_html( $attribute['label'] ) . ':</th><td>' . esc_html( $attribute['value'] ) . '</td></tr>';
            }

            $html .= '</table>';
        }

        $html .= '</td><td class="montonio-wdr-num">' . $price . '</td><td class="montonio-wdr-num">&times; ' . esc_html( $item['quantity'] );

        if ( $item['ordered'] > $item['quantity'] ) {
            $html .= '<span class="montonio-wdr-muted">of ' . esc_html( $item['ordered'] ) . ' ordered</span>';
        }

        return $html . '</td><td class="montonio-wdr-num">' . ( null !== $line ? wc_price( $line, array( 'currency' => $currency ) ) : '&ndash;' ) . '</td></tr>';
    }
}
