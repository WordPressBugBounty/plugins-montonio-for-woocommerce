jQuery(document).ready(function($) {
	'use strict';

	// WooCommerce renders the payment fields again on every checkout update, which selects the server's country. The
	// customer's own choice is kept for the session and put back, until the server selects a different country first,
	// as it does when preselecting by billing country and the customer changes it. Classic checkout only: the block
	// renders its dropdown outside #payment.
	var COUNTRY_SELECT = '#payment .montonio-hire-purchase-country-select';
	var COUNTRY_STORAGE_KEY = 'montonioHirePurchaseCountry';
	var DEFAULT_COUNTRY_STORAGE_KEY = 'montonioHirePurchaseDefaultCountry';

	function restoreCountry() {
		var select = $(COUNTRY_SELECT);
		var defaultCountry = select.attr('data-default-country');
		var country;

		if (!select.length || !defaultCountry) {
			return;
		}

		try {
			if (window.sessionStorage.getItem(DEFAULT_COUNTRY_STORAGE_KEY) !== defaultCountry) {
				window.sessionStorage.removeItem(COUNTRY_STORAGE_KEY);
				window.sessionStorage.setItem(DEFAULT_COUNTRY_STORAGE_KEY, defaultCountry);

				return;
			}

			country = window.sessionStorage.getItem(COUNTRY_STORAGE_KEY);
		} catch (e) {
			return;
		}

		if (country && select.find('option[value="' + country + '"]').length) {
			select.val(country);
		}
	}

	$(document).on('change', COUNTRY_SELECT, function() {
		try {
			window.sessionStorage.setItem(COUNTRY_STORAGE_KEY, this.value);
		} catch (e) {
			// Storage can be blocked; the choice then lasts until the next checkout update
		}
	});

	restoreCountry();

	$(document).on('updated_checkout', restoreCountry);
});
