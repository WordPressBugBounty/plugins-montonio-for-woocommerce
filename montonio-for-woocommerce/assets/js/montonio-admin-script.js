(function ($) {
    'use strict';

    // Reset default email tracking code text
    $(document).on('click', '.montonio-reset-email-tracking-code-text', function (e) {
        e.preventDefault();

        $('#montonio_email_tracking_code_text').val('Track your shipment:');
    });

    // Conditionaliy toggle order prefix id field visibility
    function togglePrefixfield() {
        var selectedVal = $('#woocommerce_wc_montonio_api_merchant_reference_type').val();

        if (selectedVal === 'add_prefix') {
            $('#woocommerce_wc_montonio_api_order_prefix').closest('tr').show();
        } else {
            $('#woocommerce_wc_montonio_api_order_prefix').closest('tr').hide();
        }
    }

    togglePrefixfield();

    $(document).on('change', '#woocommerce_wc_montonio_api_merchant_reference_type', function () {
        togglePrefixfield();
    });


    // Add loader when settings are saved
    var currentUrl = window.location.href;
    if (currentUrl.indexOf('tab=montonio_shipping') !== -1) {
        $('button.woocommerce-save-button').on('click', function () {
            $(this).css('pointer-events', 'none');

            if ($('#montonio_shipping_enabled').is(':checked')) {
                $(this).after('<div class="montonio-options-loader">Syncing pickup points, please wait!</div>');
            }
        });

    }

    // Move the p.submit element inside .montonio-options__content
    function adjustOptionsLayout() {
        var $montonioOptions = $('.montonio-options');
        var $montonioOptionsContent = $('.montonio-options .montonio-options__container .montonio-options__content');
        var $submitButton = $('p.submit');

        if ($montonioOptions.length === 0 || $submitButton.length === 0) {
            return;
        }

        // Check if submit button is already inside .montonio-options
        if ($montonioOptions.find('p.submit').length > 0) {
            return;
        }

        // Find all elements between .montonio-options and p.submit (inclusive)
        var $elementsToWrap = $montonioOptions.nextUntil('p.submit').add($submitButton);

        // Move elements into .montonio-options
        $elementsToWrap.appendTo($montonioOptionsContent);

        $montonioOptions.find('button.woocommerce-save-button').removeClass('components-button is-primary button-primary').addClass('montonio-button');
    }

    adjustOptionsLayout();

    // Hide the Montonio banner when the dismiss button or close button is clicked
    $('.montonio-banner__close').on('click', function (e) {
        e.preventDefault();

        var bannerId = $(this).closest('.montonio-banner').attr('id');

        $(this).closest('.montonio-banner').addClass('hidden');

        updateBannerVisibility(bannerId);
    });

    function updateBannerVisibility(bannerId) {
        $.post(
            ajaxurl,
            {
                action: 'update_montonio_banner_visibility',
                id: bannerId,
                _ajax_nonce: $('#' + bannerId + '_nonce_field').val()
            },
            function () {
                console.log('Banner visibility updated');
            }
        );
    }

    function togglePricingTypeFieldStates($modal, $pricingTypeSelect) {
        var $flatRateCostInput = $modal.find('input.wc-montonio-flat-rate-cost-field');
        var $dynamicOnlyFields = $modal.find('.wc-montonio-dynamic-rate-only');
        var $dynamicOnlyFieldsHeader = $modal.find('h3.wc-montonio-dynamic-rate-only');
        var $flatRateOnlyFieldsHeader = $modal.find('h3.wc-montonio-flat-rate-only');

        var isDynamic = $pricingTypeSelect.val() === 'dynamic';

        // Flat rate cost field
        var $fieldset = $flatRateCostInput.closest('fieldset');
        $fieldset.toggle(!isDynamic);
        $fieldset.prev('label').toggle(!isDynamic);

        // Flat rate cost field
        $flatRateOnlyFieldsHeader.toggle(!isDynamic);
        var $flatRateNextP = $flatRateOnlyFieldsHeader.next('p');
        $flatRateNextP.toggle(!isDynamic);
        $flatRateNextP.next('.wc-shipping-zone-method-fields').toggle(!isDynamic);
        $flatRateNextP.next('.form-table').toggle(!isDynamic);

        // Dynamic pricing fields
        $dynamicOnlyFieldsHeader.toggle(isDynamic);
        var $dynamicNextP = $dynamicOnlyFieldsHeader.next('p');
        $dynamicNextP.toggle(isDynamic);
        $dynamicNextP.next('.wc-shipping-zone-method-fields').toggle(isDynamic);
        $dynamicNextP.next('.form-table').toggle(isDynamic);

        // Dynamic-only dimension fields
        $dynamicOnlyFields.prop('disabled', !isDynamic);
    }

    function validateRequiredFields($modal, $saveButton, $pricingTypeSelect) {
        var $requiredFields = $modal.find('input.wc-montonio-dimension-field');
        
        if ($requiredFields.length === 0) {
            return;
        }

        var allFilled = true;
        var isDynamic = $pricingTypeSelect.length === 0 || $pricingTypeSelect.val() === 'dynamic';

        $requiredFields.each(function () {
            var $field = $(this);
            var isDynamicOnly = $field.hasClass('wc-montonio-dynamic-rate-only');

            if (isDynamicOnly && !isDynamic) {
                $field.css('border', '');
                return true;
            }

            if (!$.trim($field.val())) {
                $field.css('border', '2px solid var(--wc-red, #a00)');
                allFilled = false;
            } else {
                $field.css('border', '');
            }
        });

        $saveButton.prop('disabled', !allFilled).toggleClass('disabled', !allFilled);
    }

    $(document.body).on('wc_backbone_modal_loaded', function (e, target) {
        if (target !== 'wc-modal-shipping-method-settings') {
            return;
        }

        var $modal = $('#wc-backbone-modal-dialog');
        var $saveButton = $modal.find('#btn-ok');
        var $pricingTypeSelect = $modal.find('select.wc-montonio-pricing-type-select');
        var $requiredFields = $modal.find('input.wc-montonio-dimension-field');

        function updateModal() {
            togglePricingTypeFieldStates($modal, $pricingTypeSelect);
            validateRequiredFields($modal, $saveButton, $pricingTypeSelect);
        }

        if ($pricingTypeSelect.length > 0) {
            updateModal();

            // Update on pricing type change
            $pricingTypeSelect.on('change', updateModal);
        }

        // Validate on field input
        $requiredFields.on('input change', function () {
            validateRequiredFields($modal, $saveButton, $pricingTypeSelect);
        });
    });

})(jQuery);