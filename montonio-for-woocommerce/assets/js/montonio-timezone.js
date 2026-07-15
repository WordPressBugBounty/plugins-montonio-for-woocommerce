/**
 * Captures the visitor's IANA timezone (e.g. "Europe/Helsinki") and stores it
 * in a cookie so server-side payment availability checks can read it.
 *
 * The timezone is only available in the browser, so this runs on every
 * front-end page to make sure the cookie is set before the customer reaches
 * checkout.
 */
(function () {
    'use strict';

    try {
        var timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

        if (!timezone) {
            return;
        }

        var cookieValue = encodeURIComponent(timezone);

        // Skip writing if the cookie already holds the same value.
        if (document.cookie.indexOf('montonio_tz=' + cookieValue) !== -1) {
            return;
        }

        document.cookie = 'montonio_tz=' + cookieValue + '; path=/; max-age=' + (60 * 60 * 24 * 30) + '; SameSite=Lax';
    } catch (e) {
        // Intl unavailable or blocked — leave the cookie unset.
    }
})();
