(function ($) {
    'use strict';

    var $triggerButton = null;
    var $backdrop = null;
    var $modal = null;
    var storedPlan = null;
    var currentState = null;

    function init() {
        if (typeof montonioAdminShippingSetup === 'undefined') {
            return;
        }

        $triggerButton = $('#montonio-setup-routes-btn');

        if (!$triggerButton.length) {
            return;
        }

        $triggerButton.on('click', openModal);
    }

    /**
     * Create modal DOM, attach to body, fire preview AJAX.
     */
    function openModal(e) {
        e.preventDefault();
        $triggerButton.prop('disabled', true);

        var title = montonioAdminShippingSetup.modal_title || 'Setup Shipping Routes';

        $('body').addClass('montonio-modal-open').append(
            '<div class="montonio-shipping-setup-modal-backdrop">' +
                '<div class="montonio-shipping-setup-modal" role="dialog" aria-modal="true">' +
                    '<div class="montonio-shipping-setup-modal__header">' +
                        '<h2></h2>' +
                        '<button type="button" class="montonio-shipping-setup-modal__close" aria-label="Close">&times;</button>' +
                    '</div>' +
                    '<div class="montonio-shipping-setup-modal__body" aria-live="polite"></div>' +
                    '<div class="montonio-shipping-setup-modal__footer"></div>' +
                '</div>' +
            '</div>'
        );

        $backdrop = $('.montonio-shipping-setup-modal-backdrop');
        $modal = $backdrop.find('.montonio-shipping-setup-modal');

        // Set title via text() to avoid escaping concerns
        $modal.find('.montonio-shipping-setup-modal__header h2').text(title);

        // Bind closing handlers
        $backdrop.on('click', function (ev) {
            if (ev.target === $backdrop[0] && canClose()) {
                closeModal();
            }
        });

        $modal.find('.montonio-shipping-setup-modal__close').on('click', function () {
            if (canClose()) {
                closeModal();
            }
        });

        $(document).on('keydown.montonioSetupModal', function (ev) {
            if (ev.key === 'Escape' && canClose()) {
                closeModal();
            }
        });

        // Show loading state
        setState('loading');
        $modal.find('.montonio-shipping-setup-modal__body').html(
            '<div class="montonio-setup-loading"><span class="spinner is-active"></span></div>'
        );

        $.ajax({
            url: montonioAdminShippingSetup.ajax_url,
            method: 'POST',
            dataType: 'json',
            timeout: 30000,
            data: {
                action: 'montonio_setup_routes_preview_html',
                _ajax_nonce: montonioAdminShippingSetup.nonce
            },
            success: function (response) {
                if (response.success) {
                    storedPlan = response.data.plan;
                    injectContent(response.data.html);
                    setState('preview');
                    $modal.find('#montonio-php-cancel-btn').on('click', function () {
                        closeModal();
                    });

                    var hasCreatable = storedPlan && storedPlan.zones && storedPlan.zones.some(function (z) {
                        return !z.skipped;
                    });

                    if (hasCreatable) {
                        $modal.find('#montonio-php-confirm-btn').on('click', onConfirmClick);
                    } else {
                        $modal.find('#montonio-php-confirm-btn').prop('disabled', true);
                    }
                } else {
                    injectContent(response.data && response.data.html ? response.data.html : '');
                    setState('error');
                    bindBackButton();
                }
            },
            error: function () {
                injectContent(montonioAdminShippingSetup.network_error_html || '');
                setState('error');
                bindBackButton();
            }
        });
    }

    /**
     * Remove modal DOM and clean up.
     */
    function closeModal() {
        $(document).off('keydown.montonioSetupModal');

        if ($backdrop) {
            $backdrop.remove();
            $backdrop = null;
            $modal = null;
        }

        $('body').removeClass('montonio-modal-open');
        storedPlan = null;
        currentState = null;
        $triggerButton.prop('disabled', false);
    }

    function setState(state) {
        currentState = state;

        if ($modal) {
            $modal.find('.montonio-shipping-setup-modal__close').prop('disabled', !canClose());
        }
    }

    function canClose() {
        return currentState === 'preview' || currentState === 'error' || currentState === 'summary';
    }

    /**
     * Inject PHP-rendered HTML into modal body and move action buttons to footer.
     */
    function injectContent(html) {
        var $body = $modal.find('.montonio-shipping-setup-modal__body');
        var $footer = $modal.find('.montonio-shipping-setup-modal__footer');

        $body.html(html);
        $footer.empty();

        // Move action buttons from body to sticky footer
        var $actions = $body.find('.montonio-setup-actions');

        if ($actions.length) {
            $footer.append($actions);
        }

        // Scroll to top and focus the modal body for screen readers
        $body.scrollTop(0);
    }

    /**
     * Handle "Confirm and create" click.
     */
    function onConfirmClick(e) {
        e.preventDefault();

        var $error = $modal.find('#montonio-php-dim-error');

        // Validate dimensions if dynamic methods are present
        if (storedPlan && storedPlan.has_dynamic_methods) {
            var length = parseFloat($modal.find('#montonio-php-dim-length').val());
            var width = parseFloat($modal.find('#montonio-php-dim-width').val());
            var height = parseFloat($modal.find('#montonio-php-dim-height').val());
            var weight = parseFloat($modal.find('#montonio-php-dim-weight').val());

            if (!(length > 0) || !(width > 0) || !(height > 0) || !(weight > 0)) {
                $error.removeClass('hidden');
                return;
            }

            $error.addClass('hidden');

            storedPlan.default_dimensions = {
                default_length: length,
                default_width: width,
                default_height: height,
                default_weight: weight
            };
        }

        setState('creating');
        $modal.find('#montonio-php-confirm-btn').prop('disabled', true);
        $modal.find('#montonio-php-cancel-btn').prop('disabled', true);
        $modal.find('.montonio-shipping-setup-modal__footer').prepend(
            '<span class="spinner is-active"></span>'
        );

        $.ajax({
            url: montonioAdminShippingSetup.ajax_url,
            method: 'POST',
            dataType: 'json',
            timeout: 60000,
            data: {
                action: 'montonio_setup_routes_execute_html',
                _ajax_nonce: montonioAdminShippingSetup.nonce,
                plan: JSON.stringify(storedPlan)
            },
            success: function (response) {
                if (response.success) {
                    injectContent(response.data.html);
                    setState('summary');
                    $modal.find('#montonio-php-done-btn').on('click', function () {
                        window.location.href = montonioAdminShippingSetup.shipping_settings_url || window.location.href;
                    });
                } else {
                    injectContent(response.data && response.data.html ? response.data.html : '');
                    setState('error');
                    bindBackButton();
                }
            },
            error: function () {
                injectContent(montonioAdminShippingSetup.network_error_html || '');
                setState('error');
                bindBackButton();
            }
        });
    }

    function bindBackButton() {
        $modal.find('#montonio-php-back-btn').on('click', function () {
            closeModal();
        });
    }

    $(document).ready(init);
})(jQuery);
