/**
 * Sends the shop a short note when the shopper clicks a product card on a single-product or cart
 * page: the page's product (or "cart"), the card's product when the card exposes one, the section the
 * card sits in, and the cards shown in its list. The shop keeps it with the shopper's session and reads
 * it on the next add-to-cart to decide the surface by link membership. Browsers without fetch or
 * URLSearchParams send nothing.
 */
(function () {
    'use strict';

    var cfg = window.montonioRecNote;

    if (!cfg || !cfg.anchor || !cfg.endpoint) {
        return;
    }

    var VERSION = String(cfg.version);
    // The server's limits, sent with the config; the fallbacks are its values.
    var MAX_ID = parseInt(cfg.maxId, 10) || 2147483647;
    var MAX_SHOWN = parseInt(cfg.maxShown, 10) || 24;
    var REPEAT_WITHIN = 10000;
    var last = null;

    // A WordPress post id that fits a 32-bit integer; the server applies the same rule.
    function isId(value) {
        return typeof value === 'string' && /^[1-9][0-9]{0,9}$/.test(value) && Number(value) <= MAX_ID;
    }

    var page = isId(String(cfg.anchor)) ? String(cfg.anchor) : '';

    function classes(node) {
        return node.nodeType === 1 && typeof node.className === 'string' ? ' ' + node.className + ' ' : '';
    }

    function productAttr(node) {
        return node.getAttribute('data-product_id') || node.getAttribute('data-product-id') || '';
    }

    function isProductNode(node) {
        return node.nodeType === 1 && (/ product | cross-sells-product /.test(classes(node)) || productAttr(node) !== '');
    }

    function nearestProduct(node) {
        while (node && node !== document.body) {
            if (isProductNode(node)) {
                return node;
            }
            node = node.parentNode;
        }
        return null;
    }

    // The single-product page's own wrapper carries the "product" class too (block themes give it no id):
    // the nearest such ancestor of the add-to-cart form or gallery, classic or block, or of a recommendation list.
    var wrapper = page ? nearestProduct(document.querySelector('form.cart, .single_add_to_cart_button, .wc-block-add-to-cart-form, .wc-block-add-to-cart-with-options, .woocommerce-product-gallery, .wp-block-woocommerce-product-image-gallery, .up-sells, .related, .cross-sells')) : null;

    // The nearest product card above the click; none for the page's own product, a cart item remove
    // link, or the page wrapper.
    function cardOf(target, map) {
        var node = target;
        while (node && node !== document.body) {
            if (node === wrapper || / remove | wc-block-cart-item__remove-link /.test(classes(node))) {
                return null;
            }
            if (isProductNode(node)) {
                return page && (node.id === 'product-' + page || idOf(node, map) === page) ? null : node;
            }
            node = node.parentNode;
        }
        return null;
    }

    // One spelling per piece between the separators: decoded, then encoded as the URL parser encodes.
    function normalizeEncoding(value, separators) {
        return value.split(new RegExp('([' + separators.replace(/[\]\\^-]/g, '\\$&') + '])')).map(function (piece) {
            if (piece.length === 1 && separators.indexOf(piece) !== -1) {
                return piece;
            }
            try {
                return encodeURIComponent(decodeURIComponent(piece));
            } catch (e) {
                return piece;
            }
        }).join('');
    }

    // The key the server builds for the same link: host (punycode, no default port), path without a
    // trailing slash, query, with one percent-encoding of every piece.
    function linkKey(href) {
        try {
            var url = new URL(href, location.href);
            return url.host.toLowerCase() + normalizeEncoding(url.pathname, '/').replace(/\/+$/, '') +
                (url.search ? '?' + normalizeEncoding(url.search.slice(1), '&=') : '');
        } catch (e) {
            return '';
        }
    }

    // FNV-1a 32-bit over the key's UTF-8 bytes, eight hex characters, as the server's hash('fnv1a32').
    function hashKey(key) {
        var bytes = unescape(encodeURIComponent(key));
        var hash = 0x811c9dc5;
        for (var i = 0; i < bytes.length; i++) {
            hash ^= bytes.charCodeAt(i);
            hash = Math.imul(hash, 0x01000193) >>> 0;
        }
        return ('0000000' + hash.toString(16)).slice(-8);
    }

    // The hash of a card's link, carried when the card shows no id so the add of the product it leads to can be told apart.
    function linkOf(card) {
        var link = card.tagName === 'A' ? card : card.querySelector('a[href]');
        var key = link ? linkKey(link.getAttribute('href')) : '';
        return key ? hashKey(key) : '';
    }

    // Link key to product id for the cart's cross-sells: the Blocks cart store, which follows the cart
    // live, else the map the server built at page load.
    function linkMap() {
        var map = {};
        try {
            var cart = window.wp && window.wp.data && window.wp.data.select('wc/store/cart');
            var sells = cart && cart.getCartData ? cart.getCartData().crossSells : null;
            if (sells && sells.length) {
                for (var i = 0; i < sells.length; i++) {
                    var key = linkKey(sells[i].permalink);
                    // The first product wins a shared link, as in the server's map.
                    if (!(key in map)) {
                        map[key] = String(sells[i].id);
                    }
                }
                return map;
            }
        } catch (e) {
            // The store is absent or not ready; the server's map stands in.
        }
        return cfg.links && typeof cfg.links === 'object' ? cfg.links : map;
    }

    // The first valid id among the card's post class, its data attribute, its add button, and, last, its link through the map.
    function idOf(card, map) {
        var button = card.querySelector('[data-product_id], [data-product-id], a[href*="add-to-cart="]');
        var link = card.tagName === 'A' ? card : card.querySelector('a[href]');
        var candidates = [
            (classes(card).match(/ post-(\d+) /) || [])[1],
            productAttr(card),
            button && productAttr(button),
            button && ((button.getAttribute('href') || '').match(/[?&]add-to-cart=(\d+)/) || [])[1],
            link && map ? String(map[linkKey(link.getAttribute('href'))] || '') : ''
        ];
        for (var i = 0; i < candidates.length; i++) {
            if (isId(candidates[i])) {
                return candidates[i];
            }
        }
        return '';
    }

    // The ids of the cards in the clicked card's list or section, in page order, deduplicated and capped
    // as the server caps them; cards with no id are skipped.
    function shownOf(card, map) {
        var list = card.parentNode;
        while (list && list !== document.body && !/^(UL|OL|SECTION)$/.test(list.tagName) &&
            !/products|cross-?sells?|up-?sells?|related|product-collection/.test(classes(list))) {
            list = list.parentNode;
        }
        if (!list || list === document.body) {
            return '';
        }
        var ids = [];
        var nodes = list.querySelectorAll('.product, .cross-sells-product, [data-product_id], [data-product-id]');
        for (var i = 0; i < nodes.length && ids.length < MAX_SHOWN; i++) {
            // An add button inside a card carries the id too; only the card counts.
            var outer = nearestProduct(nodes[i].parentNode);
            var id = outer && list.contains(outer) ? '' : idOf(nodes[i], map);
            if (id && ids.indexOf(id) === -1) {
                ids.push(id);
            }
        }
        return ids.join(',');
    }

    // A Product Collection block names its collection only in its interactivity context, not in a class.
    var COLLECTION_HINTS = { 'cross-sells': 'cross_sell', upsells: 'upsell', related: 'related' };

    function collectionHint(node) {
        try {
            var name = JSON.parse(node.getAttribute('data-wp-context') || '{}').collection;
            var match = typeof name === 'string' ? name.match(/^woocommerce\/product-collection\/(.+)$/) : null;
            return match && COLLECTION_HINTS.hasOwnProperty(match[1]) ? COLLECTION_HINTS[match[1]] : '';
        } catch (e) {
            return '';
        }
    }

    // The section word on a layout class; a product's own category or tag classes never count.
    function sectionHint(node) {
        if (node.nodeType === 1 && node.classList && node.classList.contains('wp-block-woocommerce-product-collection')) {
            return collectionHint(node);
        }
        var tokens = node.nodeType === 1 && node.classList ? node.classList : [];
        for (var i = 0; i < tokens.length; i++) {
            var token = tokens[i].toLowerCase();
            if (token.indexOf('product_') === 0 || token.indexOf('post-') === 0) {
                continue;
            }
            if (/cross-?sells?/.test(token)) {
                return 'cross_sell';
            }
            if (/up-?sells?/.test(token)) {
                return 'upsell';
            }
            if (/related/.test(token)) {
                return 'related';
            }
        }
        return '';
    }

    function hintOf(card) {
        for (var node = card; node && node !== document.body; node = node.parentNode) {
            var hint = sectionHint(node);
            if (hint) {
                return hint;
            }
        }
        return 'unknown';
    }

    // Whether the click adds to the cart without leaving the page or goes straight to the add: an Ajax add button, a Blocks
    // add button, an add-to-cart link, or an add form's submit. "Select options", classic or Blocks, is a link to the product.
    // Such an add is classified by the page it is made on, and a note sent with it would race the add it describes.
    function isAdd(target, card) {
        for (var node = target; node && node.nodeType === 1; node = node.parentNode) {
            if (/ ajax_add_to_cart /.test(classes(node)) ||
                (node.tagName === 'BUTTON' && / add_to_cart_button /.test(classes(node))) ||
                (node.tagName === 'A' && /[?&]add-to-cart=/.test(node.getAttribute('href') || '')) ||
                (/^(BUTTON|INPUT)$/.test(node.tagName) && node.type === 'submit' && node.form && node.form.querySelector('[name="add-to-cart"]'))) {
                return true;
            }
            if (node === card) {
                return false;
            }
        }
        return false;
    }

    // Fetch with keepalive outlives the navigation the click starts and is not a ping-type request, which
    // content blockers target; sendBeacon is the fallback where keepalive is unsupported.
    function send(note) {
        var body = new URLSearchParams(note);
        var key = body.toString();
        if (last && last.key === key && Date.now() - last.at < REPEAT_WITHIN) {
            return;
        }
        last = { key: key, at: Date.now() };
        if (typeof Request !== 'undefined' && 'keepalive' in Request.prototype) {
            fetch(cfg.endpoint, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin' })['catch'](function () {});
        } else if (navigator.sendBeacon) {
            navigator.sendBeacon(cfg.endpoint, body);
        }
    }

    function onClick(event) {
        try {
            var map = linkMap();
            var card = cardOf(event.target, map);
            if (!card) {
                return;
            }
            if (isAdd(event.target, card)) {
                return;
            }
            var id = idOf(card, map);
            send({ v: VERSION, anchor: String(cfg.anchor), clicked: id, hint: hintOf(card), shown: shownOf(card, map), link: id ? '' : linkOf(card) });
        } catch (e) {
            // A failed note costs an attribution, never the click.
        }
    }

    // auxclick covers a middle-click that opens the card in a new tab; it fires for the right button too, which opens no page.
    document.addEventListener('click', onClick, true);
    document.addEventListener('auxclick', function (event) {
        if (event.button === 1) {
            onClick(event);
        }
    }, true);
})();
