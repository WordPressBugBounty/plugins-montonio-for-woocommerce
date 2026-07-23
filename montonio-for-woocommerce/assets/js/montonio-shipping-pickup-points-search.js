class MontonioPickupPointSelector {
    constructor() {
        this.input = document.getElementById('montonio-pickup-point__search-input');
        this.dropdown = document.getElementById('montonio-pickup-point__dropdown');
        this.hiddenInput = document.getElementById('montonio_pickup_point');
        this.errorDiv = document.getElementById('montonio-pickup-point__error');
        this.logosContainer = document.querySelector('.montonio-pickup-point__search-logos');
        this.searchTimeout = null;
        this.currentRequest = null;
        this.selectedPoint = null;
        this.carouselInterval = null;
        
        // Store bound references for cleanup
        this.boundHandleInput = null;
        this.boundHandleFocus = null;
        this.boundHandleBlur = null;
        this.boundDocumentClick = null;
        
        this.init();
    }

    init() {
        // Check if AJAX vars are available
        if (typeof wc_montonio_pickup_points_search === 'undefined') {
            console.error('Montonio AJAX variables not loaded');
            return;
        }

         this.initLogoCarousel();
        
        // Create bound event handlers
        this.boundHandleInput = this.handleInput.bind(this);
        this.boundHandleFocus = this.handleFocus.bind(this);
        this.boundHandleBlur = this.handleBlur.bind(this);
        this.boundDocumentClick = (e) => {
            if (!this.input.contains(e.target) && !this.dropdown.contains(e.target)) {
                this.hideDropdown();
            }
        };
        
        // Bind event listeners
        this.input.addEventListener('input', this.boundHandleInput);
        this.input.addEventListener('focus', this.boundHandleFocus);
        this.input.addEventListener('blur', this.boundHandleBlur);
        
        // Close dropdown when clicking outside
        document.addEventListener('click', this.boundDocumentClick);

        // Keep the dual-form mirror in sync with the (freshly rendered) source input.
        this.syncPickupPointMirror();
    }

    /**
     * Dual-form checkout compatibility.
     *
     * Some themes render a visible form that the customer fills in, but actually submit
     * a separate hidden form with duplicated hidden input fields with values form the visible form.
     *
     * To stay compatible we mirror the selected pickup-point value into a hidden input inside the
     * form that actually submits (the ancestor <form> of the place-order button).
     */
    syncPickupPointMirror() {
        if (!this.hiddenInput) {
            return;
        }

        const sourceForm = this.hiddenInput.closest('form');

        // The place-order button lives inside the form that actually submits
        const placeOrderButton = document.querySelector('[name="woocommerce_checkout_place_order"], #place_order');
        const submitForm = ( placeOrderButton && placeOrderButton.closest('form') ) || document.querySelector('form[name="checkout"]');

        // Single-form theme (or no separate submit form): the field already submits, nothing to mirror
        if (!submitForm || submitForm === sourceForm) {
            return;
        }

        // Reuse the existing mirror if present to avoid duplicate hidden inputs
        let mirror = submitForm.querySelector('input.montonio-pickup-point__mirror[name="montonio_pickup_point"]');
        if (!mirror) {
            mirror = document.createElement('input');
            mirror.type = 'hidden';
            mirror.name = 'montonio_pickup_point';
            mirror.className = 'montonio-pickup-point__mirror';
            submitForm.appendChild(mirror);
        }

        mirror.value = this.hiddenInput.value;

        // Final guard: re-sync right before the form submits
        if (!submitForm.dataset.montonioMirrorBound) {
            submitForm.dataset.montonioMirrorBound = 'true';
            submitForm.addEventListener('submit', () => {
                const source = document.getElementById('montonio_pickup_point');
                const target = submitForm.querySelector('input.montonio-pickup-point__mirror[name="montonio_pickup_point"]');
                if (source && target) {
                    target.value = source.value;
                }
            });
        }
    }

    initLogoCarousel() {
        if (!this.logosContainer) return;
        
        // Clear existing content first
        this.logosContainer.innerHTML = '';
        
        // Define supported operators (operators that have logo files)
        const supportedOperators = ['dpd', 'inpost', 'latvian_post', 'omniva', 'orlen', 'smartpost', 'unisend', 'venipak', 'dhl', 'chronopost', 'gls', 'alzabox', 'expressone'];
        
        try {
            const operatorsData = this.logosContainer.dataset.operators;
            const operators = operatorsData ? JSON.parse(operatorsData) : [];
            
            // Filter to only supported operators
            const filteredOperators = operators.filter(op => 
                supportedOperators.includes(op.toLowerCase())
            );
            
            if (!filteredOperators || filteredOperators.length === 0) {
                // If no supported operators, show default
                const defaultLogo = document.createElement('div');
                defaultLogo.className = 'montonio-pickup-point__search-logo montonio-pickup-point__search-logo--default';
                this.logosContainer.appendChild(defaultLogo);
                return;
            }
            
            // Create logo elements only for supported operators
            filteredOperators.forEach((operator) => {
                const logo = document.createElement('div');
                logo.className = `montonio-pickup-point__search-logo montonio-pickup-point__search-logo--${operator.toLowerCase()}`;
                this.logosContainer.appendChild(logo);
            });
            
            // If multiple operators, start carousel
            if (filteredOperators.length > 1) {
                this.startLogoCarousel();
            }
            
        } catch (error) {
            console.error('Error initializing logo carousel:', error);
        }
    }

    startLogoCarousel() {
        const logos = this.logosContainer.querySelectorAll('.montonio-pickup-point__search-logo');
        if (logos.length <= 1) return;
        
        let currentIndex = 0;
        
        // Show first logo
        logos[currentIndex].classList.add('montonio-pickup-point__search-logo--active');
        
        // Cycle through logos
        this.carouselInterval = setInterval(() => {
            logos[currentIndex].classList.remove('montonio-pickup-point__search-logo--active');
            currentIndex = (currentIndex + 1) % logos.length;
            logos[currentIndex].classList.add('montonio-pickup-point__search-logo--active');
        }, 2500); // Change logo every 2.5 seconds
    }

    destroy() {
        // Cancel any pending operations
        if (this.searchTimeout) {
            clearTimeout(this.searchTimeout);
            this.searchTimeout = null;
        }
        
        if (this.currentRequest) {
            this.currentRequest.abort();
            this.currentRequest = null;
        }
        
        if (this.carouselInterval) {
            clearInterval(this.carouselInterval);
            this.carouselInterval = null;
        }
        
        // Remove event listeners
        if (this.input && this.boundHandleInput) {
            this.input.removeEventListener('input', this.boundHandleInput);
            this.input.removeEventListener('focus', this.boundHandleFocus);
            this.input.removeEventListener('blur', this.boundHandleBlur);
        }
        
        if (this.boundDocumentClick) {
            document.removeEventListener('click', this.boundDocumentClick);
        }
        
        // Clear references
        this.boundHandleInput = null;
        this.boundHandleFocus = null;
        this.boundHandleBlur = null;
        this.boundDocumentClick = null;
        
        // Clear UI state
        this.hideDropdown();
        this.hideError();
        if (this.dropdown) {
            this.dropdown.innerHTML = '';
        }
    }

    handleInput(e) {
        const query = e.target.value.trim();
        
        // Clear previous timeout
        if (this.searchTimeout) {
            clearTimeout(this.searchTimeout);
        }
        
        // Abort current request
        if (this.currentRequest) {
            this.currentRequest.abort();
            this.currentRequest = null;
        }
        
        // Clear error
        this.hideError();
        
        // Reset selection if input changes
        if (this.selectedPoint && this.input.value !== this.selectedPoint.name) {
            this.clearSelection();
        }
        
        if (query.length < 3) {
            this.hideDropdown();
            return;
        }
        
        // Debounce search
        this.searchTimeout = setTimeout(() => {
            this.searchPickupPoints(query);
        }, 300);
    }

    handleFocus() {
        if (this.dropdown.children.length > 0) {
            this.showDropdown();
        }
    }

    handleBlur() {
        // Delay hiding to allow option selection
        setTimeout(() => {
            this.hideDropdown();
        }, 200);
    }

    async searchPickupPoints(query) {
        this.showLoading();
        
        try {
            const country = this.input.dataset.country || '';
            const carrier = this.input.dataset.carrier || '';
            const type = this.input.dataset.type || '';

            // Create FormData for WordPress AJAX
            const formData = new FormData();
            formData.append('action', 'montonio_pickup_points_search');
            formData.append('nonce', wc_montonio_pickup_points_search.nonce);
            formData.append('search', query);
            formData.append('country', country);
            formData.append('carrier', carrier);
            formData.append('type', type);

            // Create AbortController for request cancellation
            const controller = new AbortController();
            this.currentRequest = controller;
            
            // Make AJAX request
            const response = await fetch(wc_montonio_pickup_points_search.ajax_url, {
                method: 'POST',
                body: formData,
                signal: controller.signal
            });
            
            if (!response.ok) {
                throw new Error(`Request failed: ${response.status}`);
            }
            
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.data?.message || 'Unknown error occurred');
            }
            
            this.displayResults(data.data.pickupPoints);
            
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error(error);
                this.hideDropdown();
                this.showError(wc_montonio_pickup_points_search.error_text);
            }
        } finally {
            this.currentRequest = null;
        }
    }

    displayResults(data) {
        this.dropdown.innerHTML = '';
        
        if (!data || !Array.isArray(data) || data.length === 0) {
            this.dropdown.innerHTML = `<div class="montonio-pickup-point__no-results">${wc_montonio_pickup_points_search.no_results}</div>`;
            this.showDropdown();
            return;
        }
        
        data.forEach(point => {
            const option = this.createOptionElement(point);
            this.dropdown.appendChild(option);
        });
        
        this.showDropdown();
    }

    createOptionElement(point) {
        const option = document.createElement('div');
        option.className = 'montonio-pickup-point__option';
        option.dataset.pointId = point.id;
        
        const addressParts = [];
        if (point.streetAddress) addressParts.push(point.streetAddress);
        if (point.locality) addressParts.push(point.locality);
        if (point.postalCode) addressParts.push(point.postalCode);
        
        const address = addressParts.length > 0 ? addressParts.join(', ') : '-';
        
        let innerHTML = `<div class="montonio-pickup-point__option-name">${this.escapeHtml(point.name || 'Unknown Location')}</div>`;
        
        if (wc_montonio_pickup_points_search.show_address !== 'no') {
            innerHTML += `<div class="montonio-pickup-point__option-address">${this.escapeHtml(address)}</div>`;
        }
        
        option.innerHTML = innerHTML;
        
        option.addEventListener('click', () => {
            this.selectOption(point);
        });
        
        return option;
    }

    selectOption(point) {
        this.selectedPoint = point;
        this.input.value = point.name || 'Selected Pickup Point';
        this.hiddenInput.value = point.id;
        this.syncPickupPointMirror();
        this.hideDropdown();

        // Trigger custom event
        this.input.dispatchEvent(new CustomEvent('montonio-pickup-point-selected', {
            detail: point,
            bubbles: true
        }));
    }

    clearSelection() {
        this.selectedPoint = null;
        this.input.value = '';
        this.hiddenInput.value = '';
        this.syncPickupPointMirror();
    }

    showLoading() {
        this.dropdown.innerHTML = `
            <div class="montonio-pickup-point__loading">
                <span class="montonio-spinner montonio-spinner--xs"></span>
                ${wc_montonio_pickup_points_search.loading_text}
            </div>
        `;
        this.showDropdown();
    }

    showDropdown() {
        this.dropdown.classList.add('montonio-pickup-point__dropdown--show');
    }

    hideDropdown() {
        this.dropdown.classList.remove('montonio-pickup-point__dropdown--show');
    }

    showError(message) {
        this.errorDiv.textContent = message;
        this.errorDiv.classList.remove('montonio-pickup-point__error--hidden');
    }

    hideError() {
        this.errorDiv.classList.add('montonio-pickup-point__error--hidden');
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Remove the dual-form mirror input(s) when the pickup point selector is no longer present
// (e.g. the customer switched to a shipping method that has no pickup point).
function removePickupPointMirror() {
    document.querySelectorAll('input.montonio-pickup-point__mirror[name="montonio_pickup_point"]').forEach(function(mirror) {
        mirror.remove();
    });
}

// Helper function to safely initialize
function initializePickupPointSelector() {
    // Destroy existing instance if it exists
    if (window.montonioPickupPointSelector && typeof window.montonioPickupPointSelector.destroy === 'function') {
        window.montonioPickupPointSelector.destroy();
    }

    // Create new instance
    if (document.getElementById('montonio-pickup-point__search-input')) {
        window.montonioPickupPointSelector = new MontonioPickupPointSelector();
    } else {
        // Pickup point no longer applies — drop any lingering dual-form mirror input.
        removePickupPointMirror();
    }
}

// Initialize on WooCommerce checkout updates
jQuery(document).on('updated_checkout', initializePickupPointSelector);

// Initialize on page load
jQuery(document).ready(initializePickupPointSelector);