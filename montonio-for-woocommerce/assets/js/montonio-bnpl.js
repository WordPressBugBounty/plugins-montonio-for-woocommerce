jQuery(document).ready(function($) {
	'use strict';

	var params = window.wc_montonio_bnpl;

	// The SDK keeps the country the customer picks under this key and selects it over defaultRegion
	var REGION_STORAGE_KEY = 'montonioBnplRegion';
	var DEFAULT_REGION_STORAGE_KEY = 'montonioBnplDefaultRegion';

	if (!params) {
		return;
	}

	// Classic checkout and the pay for order page only: the Pay Later block starts its own widget in a container with
	// the same id, outside #payment
	function getContainer() {
		return $('#payment #montonio-bnpl-widget-container[data-grand-total]').get(0);
	}

	function isSdkLoaded() {
		return typeof MontonioLegacy !== 'undefined' && MontonioLegacy.Checkout && MontonioLegacy.Checkout.Bnpl;
	}

	// A country the customer picks by hand stays selected until the server selects a different one first, as it does
	// when preselecting by billing country and the customer changes it
	function forgetPickedRegionIfDefaultChanged(defaultRegion) {
		try {
			if (sessionStorage.getItem(DEFAULT_REGION_STORAGE_KEY) !== defaultRegion) {
				sessionStorage.removeItem(REGION_STORAGE_KEY);
				sessionStorage.setItem(DEFAULT_REGION_STORAGE_KEY, defaultRegion);
			}
		} catch (e) {
			// Storage can be blocked; the SDK then can't keep a picked country either
		}
	}

	// Without the SDK (blocked or failed to load), the periods payment_fields() renders stay; this keeps the
	// customer's period across checkout updates, as the widget does
	function restoreFallbackPeriod() {
		var selectedPeriod = sessionStorage.getItem('montonioBnplPeriod');
		var item = $('#payment .montonio-bnpl-item[data-bnpl-period="' + selectedPeriod + '"]');

		if (selectedPeriod && item.length && !item.hasClass('montonio-bnpl-item--disabled')) {
			item.addClass('active').siblings().removeClass('active');
			$('#montonio_bnpl_period').val(selectedPeriod);
		}
	}

	$(document).on('click', '#payment .montonio-bnpl-item', function() {
		if (isSdkLoaded()) {
			return;
		}

		var selectedPeriod = $(this).data('bnpl-period');

		sessionStorage.setItem('montonioBnplPeriod', selectedPeriod);
		$(this).addClass('active').siblings().removeClass('active');
		$('#montonio_bnpl_period').val(selectedPeriod);
	});

	// WooCommerce replaces the payment fields on every checkout update, so the widget starts again each time
	function initWidget() {
		var container = getContainer();

		if (!container) {
			return;
		}

		if (!isSdkLoaded()) {
			restoreFallbackPeriod();
			return;
		}

		var defaultRegion = container.getAttribute('data-default-region') || params.regions[0];

		forgetPickedRegionIfDefaultChanged(defaultRegion);

		var widget = new MontonioLegacy.Checkout.Bnpl({
			targetId: 'montonio-bnpl-widget-container',
			shouldInjectCSS: false,
			hideOptionIfNotAvailable: false,
			bnplOptions: params.bnplOptions,
			addMoreMessage: params.addMoreMessage,
			overMaxAmountMessage: params.overMaxAmountMessage,
			grandTotal: parseFloat(container.getAttribute('data-grand-total')) || 0,
			defaultRegion: defaultRegion,
			regions: params.hideCountrySelect ? [defaultRegion] : params.regions,
			regionNames: params.regionNames,
			regionSelectLabel: params.regionSelectLabel
		});

		widget.init();
	}

	initWidget();

	$(document).on('updated_checkout', initWidget);
});
