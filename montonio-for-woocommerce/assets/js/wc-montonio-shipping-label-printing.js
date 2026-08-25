jQuery(document).ready(function($) {
    'use strict'; 

    const { __, _x, _n, _nx } = wp.i18n;


    var pollDelays = [2000, 2000, 2000, 2000, 4000, 4000, 4000, 4000];
    var pollMaxDelay = 8000; // Used for every poll once pollDelays is exhausted
    var pollMaxDuration = 300000; // Stop waiting after 5 minutes
    var pollMaxConsecutiveErrors = 5;
    var labelPolling = null; // The single in-flight polling job, if any
    var shippingPanel = $('.montonio-shipping-panel');
    
    $(document).on('click', '.wc-action-button-montonio_print_label', function(event) {
        event.preventDefault();

        var order_id = $(this).attr('href').replace('#', '');

        var data = {
            order_ids: [order_id]
        };
        
        createMontonioShippingLabels(data);
    });

    $(document).on('click', '#doaction', function(event) {
        if ($('#bulk-action-selector-top').val() !== 'wc_montonio_print_labels') {
            return;
        }
    
        var formId = $(this).closest('form').attr('id');
    
        if (formId == 'wc-orders-filter') {
            var orderIds = $('#wc-orders-filter').serializeArray()
            .filter(param => { return param.name === 'id[]' })
            .map(param => { return param.value });
    
        } else {
            var orderIds = $('#posts-filter').serializeArray()
            .filter(param => { return param.name === 'post[]' })
            .map(param => { return param.value });
    
        }
    
        if (orderIds.length === 0) {
            return;
        }
    
        event.preventDefault();
    
        var data = {
            order_ids: orderIds
        };
    
        createMontonioShippingLabels(data);
    });

    // This is used in the order details page
    $(document).on('click', '#montonio-shipping-print-label', function(event) {
        if (!wcMontonioShippingLabelPrintingData || !wcMontonioShippingLabelPrintingData.orderId) {
            showNotice('error', __('Montonio: Failed to print labels, missing wcMontonioShippingLabelPrintingData', 'montonio-for-woocommerce'));

            return;
        }

        event.preventDefault();

        var data = {
            order_ids: [wcMontonioShippingLabelPrintingData.orderId]
        };

        createMontonioShippingLabels(data);
        
    });

    function createMontonioShippingLabels(data) {
        if (!wcMontonioShippingLabelPrintingData || !wcMontonioShippingLabelPrintingData.restUrl) {
            showNotice('error', __('Montonio: Failed to print labels, missing wcMontonioShippingLabelPrintingData', 'montonio-for-woocommerce'));

            return;
        }

        showNotice('info', __('Montonio: Started downloading Shipping labels', 'montonio-for-woocommerce'));

        shippingPanel.addClass('montonio-shipping-panel--loading');

        $.ajax({
            url: wcMontonioShippingLabelPrintingData.restUrl + '/labels/create',
            type: 'POST',
            data: data,
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcMontonioShippingLabelPrintingData.nonce);
            },
            success: function(response) {
                var label = response && response.data ? response.data : null;

                if (!label || !label.id) {
                    shippingPanel.removeClass('montonio-shipping-panel--loading');

                    showNotice('error', __('Montonio: Failed to print labels', 'montonio-for-woocommerce'));

                    return;
                }

                if (handleFinishedLabelFile(label)) {
                    return;
                }

                startLabelPolling(label.id);
            },
            error: function(response) {
                console.error(response);
                shippingPanel.removeClass('montonio-shipping-panel--loading');

                showNotice('error', __('Montonio: Failed to print labels', 'montonio-for-woocommerce'));
            }
        });
    }

    /**
     * Begin polling for a label file. Any job still polling is superseded.
     */
    function startLabelPolling(labelFileId) {
        stopLabelPolling();

        labelPolling = {
            labelFileId: labelFileId,
            deadline: Date.now() + pollMaxDuration,
            polls: 0,
            consecutiveErrors: 0,
            timeoutId: null,
            request: null
        };

        scheduleNextPoll();
    }

    function stopLabelPolling() {
        if (!labelPolling) {
            return;
        }

        if (labelPolling.timeoutId) {
            clearTimeout(labelPolling.timeoutId);
        }

        if (labelPolling.request) {
            labelPolling.request.abort();
        }

        labelPolling = null;
    }

    function finishLabelPolling(type, message) {
        stopLabelPolling();

        shippingPanel.removeClass('montonio-shipping-panel--loading');

        showNotice(type, message);
    }

    /**
     * Handle a label file that has reached a final state, downloading it when it
     * is ready. Shared by the create and poll responses, which return the same
     * payload, so the two cannot drift apart.
     *
     * @param object label The label file payload.
     * @return bool True if the label file was finished and no polling is needed.
     */
    function handleFinishedLabelFile(label) {
        if (label.labelFileUrl) {
            downloadLabelFile(label);

            finishLabelPolling('success', __('Montonio: Labels downloaded. Refresh the browser for updated order statuses', 'montonio-for-woocommerce'));

            return true;
        }

        if (label.status === 'failed') {
            finishLabelPolling('error', __('Montonio: Failed to print labels', 'montonio-for-woocommerce'));

            return true;
        }

        return false;
    }

    /**
     * Queue the next poll. Called only once the previous poll has settled, so
     * there is never more than one label status request in flight.
     */
    function scheduleNextPoll() {
        if (!labelPolling) {
            return;
        }

        if (Date.now() >= labelPolling.deadline) {
            finishLabelPolling('error', __('Montonio: Labels are taking longer than expected to generate. They may still complete - please refresh the page and try again in a moment.', 'montonio-for-woocommerce'));

            return;
        }

        var delay = labelPolling.polls < pollDelays.length ? pollDelays[labelPolling.polls] : pollMaxDelay;

        labelPolling.polls++;

        labelPolling.timeoutId = setTimeout(pollMontonioShippingLabels, delay);
    }

    function pollMontonioShippingLabels() {
        if (!labelPolling) {
            return;
        }

        // Keep a reference to the job this request belongs to, so a late response
        // from a superseded job cannot affect the current one.
        var job = labelPolling;
        job.timeoutId = null;

        job.request = $.ajax({
            url: wcMontonioShippingLabelPrintingData.restUrl + '/labels?label_file_id=' + encodeURIComponent(job.labelFileId),
            type: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcMontonioShippingLabelPrintingData.nonce);
            },
            success: function(response) {
                if (job !== labelPolling) {
                    return;
                }

                var label = response && response.data ? response.data : null;

                if (label && handleFinishedLabelFile(label)) {
                    return;
                }

                // Still generating - keep waiting.
                job.consecutiveErrors = 0;

                scheduleNextPoll();
            },
            error: function(xhr, textStatus) {
                if (job !== labelPolling || textStatus === 'abort') {
                    return;
                }

                console.error(xhr);

                if (xhr.status === 400 || xhr.status === 403) {
                    finishLabelPolling('error', __('Montonio: Failed to print labels', 'montonio-for-woocommerce'));

                    return;
                }

                job.consecutiveErrors++;

                // Timeouts and 5xx responses - retry rather than abandoning the job on the first transient failure.
                if (job.consecutiveErrors >= pollMaxConsecutiveErrors) {
                    finishLabelPolling('error', __('Montonio: Failed to print labels', 'montonio-for-woocommerce'));

                    return;
                }

                scheduleNextPoll();
            },
            complete: function() {
                if (job === labelPolling) {
                    job.request = null;
                }
            }
        });
    }

    function downloadLabelFile(label) {
        var anchor = document.createElement('a');
        anchor.href = label.labelFileUrl;
        anchor.download = 'labels-' + label.id + '.pdf';

        document.body.appendChild(anchor);
        anchor.click();
        document.body.removeChild(anchor);
    }

    function showNotice(type, message) {
        if (wp && wp.data && wp.data.dispatch) {
            wp.data.dispatch('core/notices').createNotice(
                type,
                message
            );
        } else {
            alert(message);
        }
    }
});
