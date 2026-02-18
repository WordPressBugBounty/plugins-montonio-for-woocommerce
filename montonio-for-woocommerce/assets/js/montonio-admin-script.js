(function ($) {
    'use strict';

    // Reset default email tracking code text
    $(document).on('click', '.montonio-reset-email-tracking-code-text', function (e) {
        e.preventDefault();
        $('#montonio_email_tracking_code_text').val('Track your shipment:');
    });

    // Conditionally toggle order prefix id field visibility
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
        var montonioOptions = $('.montonio-options');
        var montonioOptionsContent = $('.montonio-options .montonio-options__container .montonio-options__content');
        var submitButton = $('p.submit:has(.woocommerce-save-button)');

        if (montonioOptions.length === 0 || submitButton.length === 0) {
            return;
        }

        if (montonioOptions.find('p.submit').length > 0) {
            return;
        }

        var elementsToWrap = montonioOptions.nextUntil(submitButton).add(submitButton);

        elementsToWrap.appendTo(montonioOptionsContent);

        montonioOptions.find('button.woocommerce-save-button').removeClass('components-button is-primary button-primary').addClass('montonio-button');
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

    function togglePricingTypeFieldStates(modal, pricingTypeSelect) {
        var flatRateCostInput = modal.find('input.wc-montonio-flat-rate-cost-field');
        var dynamicOnlyFields = modal.find('.wc-montonio-dynamic-rate-only');
        var dynamicOnlyFieldsHeader = modal.find('h3.wc-montonio-dynamic-rate-only');
        var flatRateOnlyFieldsHeader = modal.find('h3.wc-montonio-flat-rate-only');

        var isDynamic = pricingTypeSelect.val() === 'dynamic';

        // Toggle flat rate only fields
        var flatRateFieldset = flatRateCostInput.closest('fieldset');
        flatRateFieldset.toggle(!isDynamic);
        flatRateFieldset.prev('label').toggle(!isDynamic);

        flatRateOnlyFieldsHeader.toggle(!isDynamic);
        var flatRateNextP = flatRateOnlyFieldsHeader.next('p');
        flatRateNextP.toggle(!isDynamic);
        flatRateNextP.next('.wc-shipping-zone-method-fields').toggle(!isDynamic);
        flatRateNextP.next('.form-table').toggle(!isDynamic);

        // Toggle dynamic price only fields
        var dynamicFieldset = dynamicOnlyFields.closest('fieldset');
        dynamicFieldset.toggle(isDynamic);
        dynamicFieldset.prev('label').toggle(isDynamic);
        dynamicOnlyFields.prop('disabled', !isDynamic);

        dynamicOnlyFieldsHeader.toggle(isDynamic);
        var dynamicNextP = dynamicOnlyFieldsHeader.next('p');
        dynamicNextP.toggle(isDynamic);
        dynamicNextP.next('.wc-shipping-zone-method-fields').toggle(isDynamic);
        dynamicNextP.next('.form-table').toggle(isDynamic);
    }

    function validateRequiredFields(modal, saveButton, pricingTypeSelect) {
        var requiredFields = modal.find('input.wc-montonio-dimension-field');

        if (requiredFields.length === 0) {
            return;
        }

        var allFilled = true;
        var isDynamic = pricingTypeSelect.length === 0 || pricingTypeSelect.val() === 'dynamic';

        requiredFields.each(function () {
            var field = $(this);
            var isDynamicOnly = field.hasClass('wc-montonio-dynamic-rate-only');

            if (isDynamicOnly && !isDynamic) {
                field.css('border', '');
                return true;
            }

            if (!$.trim(field.val())) {
                field.css('border', '2px solid var(--wc-red, #a00)');
                allFilled = false;
            } else {
                field.css('border', '');
            }
        });

        saveButton.prop('disabled', !allFilled).toggleClass('disabled', !allFilled);
    }

    function updateMarkupHint(input) {
        var val = input.val();
        var hint = input.next('.margin-hint');

        if (hint.length === 0) {
            hint = $('<small class="margin-hint" style="display:block;color:#666;"></small>');
            input.after(hint);
        }

        if (val.includes('%')) {
            hint.text('✓ Percentage markup');
        } else if (val && val !== '0') {
            hint.text('✓ Fixed amount markup');
        } else {
            hint.text('');
        }
    }

    $(document.body).on('wc_backbone_modal_loaded', function (e, target) {
        if (target !== 'wc-modal-shipping-method-settings') {
            return;
        }

        var modal = $('#wc-backbone-modal-dialog');
        var saveButton = modal.find('#btn-ok');
        var pricingTypeSelect = modal.find('select.wc-montonio-pricing-type-select');
        var requiredFields = modal.find('input.wc-montonio-dimension-field');

        function updateModal() {
            togglePricingTypeFieldStates(modal, pricingTypeSelect);
            validateRequiredFields(modal, saveButton, pricingTypeSelect);
        }

        if (pricingTypeSelect.length > 0) {
            updateModal();
            pricingTypeSelect.on('change', updateModal);
        }

        requiredFields.on('input change', function () {
            validateRequiredFields(modal, saveButton, pricingTypeSelect);
        });

        $('.wc-montonio-dynamic-rate-markup').on('input', function () {
            updateMarkupHint($(this));
        });
    });
})(jQuery);