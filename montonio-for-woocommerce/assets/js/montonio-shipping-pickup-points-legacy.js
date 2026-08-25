jQuery(document).ready(function($) {
    'use strict';

    // Scoped to our own class: the pickup point search script maintains its own mirror under a
    // different class, and both scripts can be loaded on the same page.
    var MIRROR_SELECTOR = 'input.montonio_pickup_point_value[name="montonio_pickup_point"]';

    function setupMontonioPickupPoints() {
        if ($().selectWoo) {
            var select = $('.montonio-shipping-pickup-point-dropdown');
            select.selectWoo({
                width: '100%',
            });
        }
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

    $(document).on('updated_checkout', function(){
        setupMontonioPickupPoints();
        syncPickupPointMirror();

        if ($('input[name="shipping_method[0]"]').is(':radio')) {
            var selected_service = $('input[name^="shipping_method"]:checked').val();
        } else {
            var selected_service = $('input[name="shipping_method[0]"]').val();
        }

        if (selected_service && sessionStorage.getItem('montonioPreferredPickupPoint')){
            try {
                if(JSON.parse(sessionStorage.getItem('montonioPreferredPickupPoint'))[selected_service]) {
                    $('.montonio-shipping-pickup-point-dropdown').val(JSON.parse(sessionStorage.getItem('montonioPreferredPickupPoint'))[selected_service]).change();
                }
            } catch(err) {}
        }
    });

    $(document).on('change', '.montonio-shipping-pickup-point-dropdown', function(){
        try {
            let storage = JSON.parse(sessionStorage.getItem('montonioPreferredPickupPoint')) || {}
            var selected_pickup_point = $(this).find(':selected').map(function(){ return $(this).val(); }).get(0);

            if ($('input[name="shipping_method[0]"]').is(':radio')) {
                var selected_service = $('input[name^="shipping_method"]:checked').val();
            } else {
                var selected_service = $('input[name="shipping_method[0]"]').val();
            }

            storage[selected_service] = selected_pickup_point;
            sessionStorage.setItem('montonioPreferredPickupPoint', JSON.stringify(storage));
        } catch(err) {}

        $('.montonio-shipping-pickup-point-dropdown').not(this).val(selected_pickup_point).selectWoo();

        syncPickupPointMirror();
    });

    $(document).on('select2:open', '.montonio-pickup-point', function() {
        setTimeout(function() {
            $('.select2-container--open').addClass('montonio-pickup-point-container');
            document.querySelector('.select2-container--open .select2-search__field').focus();
        }, 10);
    });
});