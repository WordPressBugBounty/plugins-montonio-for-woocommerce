jQuery(document).ready(function($) {
    'use strict';

    // Scoped to our own class: the pickup point search script maintains its own mirror under a
    // different class, and both scripts can be loaded on the same page.
    var MIRROR_SELECTOR = 'input.montonio_pickup_point_value[name="montonio_pickup_point"]';

    $(document).on('updated_checkout', function(){
        initPickupPointDropdown();
    });

    /**
     * Poll for the pickup point dropdown, which may be rendered a few hundred milliseconds
     * after 'updated_checkout'. Calls back with the element once it appears, or with null if
     * it never does (e.g. the customer switched to a shipping method without pickup points).
     */
    function waitForDropdownElement(callback, maxAttempts = 3) {
        var $dropdown = $('.montonio-shipping-pickup-point-dropdown');

        if ($dropdown.length) {
            callback($dropdown);
        } else if (maxAttempts > 0) {
            setTimeout(function() {
                waitForDropdownElement(callback, maxAttempts - 1);
            }, 200);
        } else {
            callback(null);
        }
    }

    function initPickupPointDropdown() {
        waitForDropdownElement(function($dropdown) {
            if ($dropdown && !$dropdown.hasClass('montonio-shipping-pickup-point-dropdown--initialized')) {
                initShippingDropdownSdk($dropdown);
            }

            // Keep the dual-form mirror in sync with the (freshly rendered, or now absent) dropdown.
            syncPickupPointMirror();
        });
    }

    function initShippingDropdownSdk($dropdown) {
        if (typeof MontonioLegacy === 'undefined' || !MontonioLegacy.Checkout || !MontonioLegacy.Checkout.ShippingDropdown) {
            return;
        }

        if (window.montonioShippingDropdown) {
            window.montonioShippingDropdown = null;
        }

        window.montonioShippingDropdown = new MontonioLegacy.Checkout.ShippingDropdown({
            shippingMethod: $dropdown.data('shipping-method'),
            targetId: 'montonio-shipping-pickup-point-dropdown',
            shouldInjectCSS: true,
        });

        window.montonioShippingDropdown.init();

        $dropdown.addClass('montonio-shipping-pickup-point-dropdown--initialized');
    }

    /**
     * Read the currently selected pickup point.
     *
     * A checkout can render more than one dropdown (they are kept in sync by the change handler
     * below), so take the first one that actually holds a value.
     */
    function getPickupPointValue() {
        var value = '';

        $('.montonio-shipping-pickup-point-dropdown').each(function() {
            if ($(this).val()) {
                value = $(this).val();
                return false;
            }
        });

        return value;
    }

    /**
     * Dual-form checkout compatibility.
     *
     * Some themes render a visible form that the customer fills in, but actually submit a
     * separate hidden form with duplicated hidden input fields with values from the visible form.
     *
     * To stay compatible we mirror the selected pickup-point value into a hidden input inside the
     * form that actually submits (the ancestor <form> of the place-order button).
     */
    function syncPickupPointMirror() {
        // Pickup point no longer applies — drop any lingering mirror input.
        if (!$('.montonio-shipping-pickup-point-dropdown').length) {
            removePickupPointMirror();
            return;
        }

        // The place-order button lives inside the form that actually submits
        var $placeOrderButton = $('[name="woocommerce_checkout_place_order"], #place_order').first();
        var $submitForm = $placeOrderButton.length ? $placeOrderButton.closest('form') : $('form[name="checkout"]').first();

        if (!$submitForm.length) {
            return;
        }

        // Single-form theme: the dropdown itself already submits, nothing to mirror
        if ($submitForm.find('.montonio-shipping-pickup-point-dropdown').length) {
            $submitForm.find(MIRROR_SELECTOR).remove();
            return;
        }

        // Reuse the existing mirror if present to avoid duplicate hidden inputs
        var $mirror = $submitForm.find(MIRROR_SELECTOR);
        if (!$mirror.length) {
            $mirror = $('<input type="hidden" class="montonio_pickup_point_value" name="montonio_pickup_point" value="">');
            $submitForm.append($mirror);
        }

        $mirror.val(getPickupPointValue());

        // Final guard: re-sync right before the form submits
        if (!$submitForm.data('montonioMirrorBound')) {
            $submitForm.data('montonioMirrorBound', true);
            $submitForm.on('submit', function() {
                $(this).find(MIRROR_SELECTOR).val(getPickupPointValue());
            });
        }
    }

    /**
     * Remove the dual-form mirror input(s) wherever they ended up.
     */
    function removePickupPointMirror() {
        $(MIRROR_SELECTOR).remove();
    }

    $(document).on('change', '.montonio-shipping-pickup-point-dropdown', function(){
        var selected_pickup_point = $(this).val();

        $('.montonio-shipping-pickup-point-dropdown').not(this).val(selected_pickup_point);

        syncPickupPointMirror();
    });

});