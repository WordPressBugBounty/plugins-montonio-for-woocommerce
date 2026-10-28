class MontonioPickupPointSelector {
    /**
     * @param {Object|null} previousState State of the instance this one replaces (see getState()),
     *                                    used to keep search results when the checkout re-renders.
     */
    constructor(previousState) {
        this.selectBox = document.getElementById('montonio-pickup-point__toggle');
        this.selectBoxValue = this.selectBox ? this.selectBox.querySelector('.montonio-pickup-point__toggle-value') : null;
        this.container = this.selectBox ? this.selectBox.closest('.montonio-pickup-point__container') : null;
        this.label = document.getElementById('montonio-pickup-point__label');
        this.panel = document.getElementById('montonio-pickup-point__panel');
        this.input = document.getElementById('montonio-pickup-point__search-input');
        this.dropdown = document.getElementById('montonio-pickup-point__dropdown');
        this.hiddenInput = document.getElementById('montonio_pickup_point');
        this.logosContainer = document.querySelector('.montonio-pickup-point__search-logos');
        this.searchTimeout = null;
        this.currentRequest = null;
        this.selectedPoint = null;
        this.carouselInterval = null;

        // List state. Results are kept while the panel is closed, so reopening shows the last search again.
        this.isOpen = false;
        this.listState = 'idle'; // 'idle' | 'loading' | 'empty' | 'error'
        this.searchResults = [];
        this.preloadedResults = []; // Points in the customer's city, shown when there is no search yet
        this.preloadedCity = ''; // City the preloaded points were searched for
        this.lastQuery = '';
        this.renderLimit = 200;
        this.optionElements = []; // Rendered options, in list order
        this.activeIndex = -1; // Option highlighted with the arrow keys

        // Store bound references for cleanup
        this.boundHandleInput = null;
        this.boundHandleInputKeydown = null;
        this.boundHandleSelectBoxClick = null;
        this.boundHandleSelectBoxKeydown = null;
        this.boundHandleLabelClick = null;
        this.boundHandleFocusOut = null;
        this.boundHandlePanelMousedown = null;
        this.boundDocumentClick = null;

        this.storageKey = 'montonio_pickup_point_selections';

        this.init(previousState || null);
    }

    init(previousState) {
        // Check if AJAX vars are available
        if (typeof wc_montonio_pickup_points_search === 'undefined') {
            console.error('Montonio AJAX variables not loaded');
            return;
        }

        if (!this.selectBox || !this.panel || !this.input || !this.dropdown) {
            return;
        }

        this.initLogoCarousel();

        // Create bound event handlers
        this.boundHandleInput = this.handleInput.bind(this);
        this.boundHandleInputKeydown = this.handleInputKeydown.bind(this);
        this.boundHandleSelectBoxClick = this.togglePanel.bind(this);
        this.boundHandleSelectBoxKeydown = this.handleSelectBoxKeydown.bind(this);
        this.boundHandleLabelClick = () => {
            this.selectBox.focus();
        };
        this.boundHandleFocusOut = this.handleFocusOut.bind(this);
        this.boundHandlePanelMousedown = (e) => {
            // Keep focus in the search field when pressing an option. Otherwise focus moves to the
            // nearest focusable ancestor (some themes make the page wrapper focusable) and the panel
            // closes before the click is registered.
            if (e.target !== this.input) {
                e.preventDefault();
            }
        };
        this.boundDocumentClick = (e) => {
            if (this.isOpen && !this.container.contains(e.target)) {
                this.close();
            }
        };

        // Bind event listeners
        this.input.addEventListener('input', this.boundHandleInput);
        this.input.addEventListener('keydown', this.boundHandleInputKeydown);
        this.selectBox.addEventListener('click', this.boundHandleSelectBoxClick);
        this.selectBox.addEventListener('keydown', this.boundHandleSelectBoxKeydown);
        this.container.addEventListener('focusout', this.boundHandleFocusOut);
        this.panel.addEventListener('mousedown', this.boundHandlePanelMousedown);

        if (this.label) {
            this.label.addEventListener('click', this.boundHandleLabelClick);
        }

        this.selectBox.setAttribute('aria-expanded', 'false');

        // Close panel when clicking or tapping outside. pointerup catches taps on elements iOS sends
        // no click for; a touch that turns into a scroll ends in pointercancel instead, so scrolling
        // the page keeps the panel open.
        document.addEventListener('click', this.boundDocumentClick);
        document.addEventListener('pointerup', this.boundDocumentClick);

        // Keep the dual-form mirror in sync with the (freshly rendered) source input.
        this.syncPickupPointMirror();

        this.restoreState(previousState);
    }

    /**
     * Carrier, country and type the selector is rendered for, which is also what the pickup
     * points are searched by, and the customer's city the list is prefilled with.
     */
    getContext() {
        return {
            carrier: this.input.dataset.carrier || '',
            country: this.input.dataset.country || '',
            type: this.input.dataset.type || '',
            city: (this.input.dataset.city || '').trim()
        };
    }

    /**
     * Key of the carrier, country and type combination, e.g. "omniva|EE|parcelMachine". A kept
     * selection or search only applies to the same key. Empty when the carrier is missing, so
     * nothing is kept for an incomplete context.
     */
    getContextKey() {
        const context = this.getContext();

        if (!context.carrier) {
            return '';
        }

        return [context.carrier, context.country, context.type].join('|');
    }

    /**
     * Snapshot handed to the instance that replaces this one when the checkout re-renders.
     */
    getState() {
        if (!this.input) {
            return null;
        }

        return {
            contextKey: this.getContextKey(),
            selectedPoint: this.selectedPoint,
            searchResults: this.searchResults.slice(),
            preloadedResults: this.preloadedResults.slice(),
            preloadedCity: this.preloadedCity,
            lastQuery: this.lastQuery
        };
    }

    /**
     * Keep the selection when the checkout re-renders (classic `updated_checkout`, Blocks
     * re-init) or the page reloads. Search results are only kept in memory; the selected
     * point is also kept in sessionStorage so it survives a reload.
     */
    restoreState(previousState) {
        const key = this.getContextKey();

        if (!key) {
            return;
        }

        const hasPreviousState = !!previousState && previousState.contextKey === key;

        if (hasPreviousState) {
            this.searchResults = Array.isArray(previousState.searchResults) ? previousState.searchResults : [];
            this.preloadedResults = Array.isArray(previousState.preloadedResults) ? previousState.preloadedResults : [];
            this.preloadedCity = previousState.preloadedCity || '';
            this.lastQuery = previousState.lastQuery || '';
        }

        const saved = this.readSavedSelections()[key];
        let point = null;

        if (saved && saved.id) {
            point = saved;
        } else if (hasPreviousState && previousState.selectedPoint) {
            point = previousState.selectedPoint;
        }

        if (point) {
            this.setSelectedPoint(point);
            this.dispatchSelected(point);
        }
    }

    /**
     * Selected points kept in sessionStorage, one per context key (see getContextKey()), so
     * every shipping method remembers its own point.
     */
    readSavedSelections() {
        try {
            const value = JSON.parse(window.sessionStorage.getItem(this.storageKey));
            return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
        } catch (error) {
            return {};
        }
    }

    saveSelection() {
        const key = this.getContextKey();

        if (!key) {
            return;
        }

        try {
            const selections = this.readSavedSelections();
            selections[key] = this.selectedPoint;
            window.sessionStorage.setItem(this.storageKey, JSON.stringify(selections));
        } catch (error) {
            // Storage unavailable (e.g. private mode): the selection is just not kept on reload
        }
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
        const supportedOperators = ['dpd', 'inpost', 'latvian_post', 'omniva', 'orlen', 'smartpost', 'unisend', 'venipak', 'dhl', 'chronopost', 'gls', 'alzabox', 'expressone', 'postnord', 'posti'];

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
        if (this.boundHandleInput) {
            this.input.removeEventListener('input', this.boundHandleInput);
            this.input.removeEventListener('keydown', this.boundHandleInputKeydown);
            this.selectBox.removeEventListener('click', this.boundHandleSelectBoxClick);
            this.selectBox.removeEventListener('keydown', this.boundHandleSelectBoxKeydown);
            this.container.removeEventListener('focusout', this.boundHandleFocusOut);
            this.panel.removeEventListener('mousedown', this.boundHandlePanelMousedown);

            if (this.label) {
                this.label.removeEventListener('click', this.boundHandleLabelClick);
            }
        }

        if (this.boundDocumentClick) {
            document.removeEventListener('click', this.boundDocumentClick);
            document.removeEventListener('pointerup', this.boundDocumentClick);
        }

        // Clear references
        this.boundHandleInput = null;
        this.boundHandleInputKeydown = null;
        this.boundHandleSelectBoxClick = null;
        this.boundHandleSelectBoxKeydown = null;
        this.boundHandleLabelClick = null;
        this.boundHandleFocusOut = null;
        this.boundHandlePanelMousedown = null;
        this.boundDocumentClick = null;

        // Clear UI state
        this.close();
        this.searchResults = [];
        this.preloadedResults = [];
        if (this.dropdown) {
            this.dropdown.innerHTML = '';
        }
    }

    togglePanel() {
        if (this.isOpen) {
            this.close(true);
        } else {
            this.open();
        }
    }

    open() {
        if (this.isOpen || !this.panel) {
            return;
        }

        this.isOpen = true;

        // Start with an empty search field, but keep showing the previous results
        this.input.value = '';

        if (this.listState !== 'loading') {
            this.listState = 'idle';
        }

        this.renderList();
        this.panel.classList.add('montonio-pickup-point__panel--open');
        this.selectBox.classList.add('montonio-pickup-point__toggle--open');
        this.selectBox.setAttribute('aria-expanded', 'true');

        // Start keyboard navigation from the selected point when it is in the list
        this.setActiveIndex(this.getSelectedIndex());

        this.preloadCityPoints();

        // Focus synchronously inside the user gesture so mobile browsers open the keyboard
        this.input.focus();
    }

    /**
     * Before the customer has searched, list the pickup points in their shipping (or billing)
     * city. Searched again only when the city changes.
     */
    preloadCityPoints() {
        const city = this.getContext().city;

        if (city.length < 3 || city === this.preloadedCity || this.searchResults.length > 0 || this.currentRequest) {
            return;
        }

        this.searchPickupPoints(city, true);
    }

    close(returnFocus) {
        if (!this.isOpen) {
            return;
        }

        // Drop a search still waiting to run, the search field is emptied when the panel reopens
        if (this.searchTimeout) {
            clearTimeout(this.searchTimeout);
            this.searchTimeout = null;
        }

        this.isOpen = false;
        this.panel.classList.remove('montonio-pickup-point__panel--open');
        this.selectBox.classList.remove('montonio-pickup-point__toggle--open');
        this.selectBox.setAttribute('aria-expanded', 'false');
        this.setActiveIndex(-1);

        if (returnFocus) {
            this.selectBox.focus();
        }
    }

    handleSelectBoxKeydown(e) {
        const key = e.key;

        // 'Spacebar', 'Down' and 'Esc' are the key names used by older browsers
        if (key === 'Enter' || key === ' ' || key === 'Spacebar' || key === 'ArrowDown' || key === 'Down') {
            e.preventDefault();
            this.open();
        } else if ((key === 'Escape' || key === 'Esc') && this.isOpen) {
            e.preventDefault();
            this.close(true);
        }
    }

    handleInputKeydown(e) {
        const key = e.key;
        const count = this.optionElements.length;

        if (key === 'ArrowDown' || key === 'Down') {
            e.preventDefault();
            if (count > 0) {
                this.setActiveIndex(this.activeIndex < count - 1 ? this.activeIndex + 1 : 0);
            }
        } else if (key === 'ArrowUp' || key === 'Up') {
            e.preventDefault();
            if (count > 0) {
                this.setActiveIndex(this.activeIndex > 0 ? this.activeIndex - 1 : count - 1);
            }
        } else if (key === 'Enter') {
            // Never let Enter submit the checkout form from the search field
            e.preventDefault();
            if (this.activeIndex > -1 && this.activeIndex < count) {
                this.selectOption(this.getListedPoints()[this.activeIndex]);
            }
        } else if (key === 'Escape' || key === 'Esc') {
            e.preventDefault();
            this.close(true);
        } else if (key === 'Tab') {
            // Let focus move on naturally, just close the panel
            this.close();
        }
    }

    getSelectedIndex() {
        if (!this.selectedPoint) {
            return -1;
        }

        for (let i = 0; i < this.optionElements.length; i++) {
            if (this.optionElements[i].dataset.pointId === String(this.selectedPoint.id)) {
                return i;
            }
        }

        return -1;
    }

    setActiveIndex(index) {
        if (this.activeIndex > -1 && this.optionElements[this.activeIndex]) {
            this.optionElements[this.activeIndex].classList.remove('montonio-pickup-point__option--active');
        }

        this.activeIndex = index;

        const option = index > -1 ? this.optionElements[index] : null;

        if (!option) {
            this.activeIndex = -1;
            if (this.input) {
                this.input.removeAttribute('aria-activedescendant');
            }
            return;
        }

        option.classList.add('montonio-pickup-point__option--active');
        this.input.setAttribute('aria-activedescendant', option.id);

        // Keep the active option visible inside the scrollable list without scrolling the page
        const optionTop = option.offsetTop;
        const optionBottom = optionTop + option.offsetHeight;

        if (optionTop < this.dropdown.scrollTop) {
            this.dropdown.scrollTop = optionTop;
        } else if (optionBottom > this.dropdown.scrollTop + this.dropdown.clientHeight) {
            this.dropdown.scrollTop = optionBottom - this.dropdown.clientHeight;
        }
    }

    handleFocusOut(e) {
        // Focus moved to an element outside the selector (e.g. Tab). A missing relatedTarget
        // (tapping a non-focusable option) is left to the document click handler.
        if (this.isOpen && e.relatedTarget && !this.container.contains(e.relatedTarget)) {
            this.close();
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

        if (query.length < 3) {
            this.listState = 'idle';
            this.renderList();
            return;
        }

        // Debounce search
        this.searchTimeout = setTimeout(() => {
            this.searchPickupPoints(query);
        }, 300);
    }

    /**
     * @param {string}  query     Search text, or the customer's city when preloading.
     * @param {boolean} isPreload Keep the results as the preloaded points instead of search results.
     */
    async searchPickupPoints(query, isPreload = false) {
        if (!isPreload) {
            this.lastQuery = query;
        }

        this.listState = 'loading';
        this.renderList();

        // Create AbortController for request cancellation
        const controller = new AbortController();
        this.currentRequest = controller;

        try {
            const context = this.getContext();

            // Create FormData for WordPress AJAX
            const formData = new FormData();
            formData.append('action', 'montonio_pickup_points_search');
            formData.append('nonce', wc_montonio_pickup_points_search.nonce);
            formData.append('search', query);
            formData.append('country', context.country);
            formData.append('carrier', context.carrier);
            formData.append('type', context.type);

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

            if (isPreload) {
                this.displayPreloadedResults(data.data.pickupPoints, query);
            } else {
                this.displayResults(data.data.pickupPoints);
            }

        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error(error);

                // A failed preload just leaves the search hint, the customer can still search
                if (isPreload) {
                    this.displayPreloadedResults([], '');
                } else {
                    this.listState = 'error';
                    this.renderList();
                }
            }
        } finally {
            // A newer request may have replaced this one in the meantime
            if (this.currentRequest === controller) {
                this.currentRequest = null;
            }
        }
    }

    displayResults(data) {
        this.searchResults = Array.isArray(data) ? data : [];
        this.listState = this.searchResults.length > 0 ? 'idle' : 'empty';
        this.renderList();
    }

    /**
     * The search also matches names and street addresses (e.g. "Rīgas iela" in another town),
     * so points located in the city are listed first. No points in the city is not an error:
     * the search hint is shown instead.
     */
    displayPreloadedResults(data, city) {
        const points = Array.isArray(data) ? data : [];
        const isInCity = (point) => !!point.locality && point.locality.localeCompare(city, undefined, { sensitivity: 'base' }) === 0;

        this.preloadedResults = points.filter(isInCity).concat(points.filter((point) => !isInCity(point)));
        this.preloadedCity = city;
        this.listState = 'idle';
        this.renderList();

        if (this.isOpen) {
            this.setActiveIndex(this.getSelectedIndex());
        }
    }

    /**
     * Points listed when there is no active message state: the last search results,
     * or preloaded points before the customer has searched.
     */
    getListedPoints() {
        return this.searchResults.length > 0 ? this.searchResults : this.preloadedResults;
    }

    renderList() {
        if (!this.dropdown) {
            return;
        }

        const strings = wc_montonio_pickup_points_search;

        this.setActiveIndex(-1);
        this.optionElements = [];
        this.dropdown.innerHTML = '';

        if (this.listState === 'loading') {
            const loading = this.createMessageElement('montonio-pickup-point__loading', strings.loading_text);
            const spinner = document.createElement('span');
            spinner.className = 'montonio-spinner montonio-spinner--xs';
            loading.prepend(spinner);
            this.dropdown.appendChild(loading);
            return;
        }

        if (this.listState === 'error') {
            this.dropdown.appendChild(this.createMessageElement('montonio-pickup-point__list-error', strings.error_text));
            return;
        }

        if (this.listState === 'empty') {
            this.dropdown.appendChild(this.createMessageElement('montonio-pickup-point__no-results', strings.no_results));
            return;
        }

        const points = this.getListedPoints();

        if (points.length === 0) {
            this.dropdown.appendChild(this.createMessageElement('montonio-pickup-point__hint', strings.search_hint));
            return;
        }

        const visibleCount = Math.min(points.length, this.renderLimit);

        for (let i = 0; i < visibleCount; i++) {
            const option = this.createOptionElement(points[i], i);
            this.optionElements.push(option);
            this.dropdown.appendChild(option);
        }

        if (points.length > visibleCount && strings.more_results) {
            const text = strings.more_results.replace('%1$s', visibleCount).replace('%2$s', points.length);
            this.dropdown.appendChild(this.createMessageElement('montonio-pickup-point__more', text));
        }
    }

    createMessageElement(className, text) {
        const message = document.createElement('div');
        message.className = className;
        message.textContent = text || '';
        return message;
    }

    shouldShowAddress() {
        return wc_montonio_pickup_points_search.show_address !== 'no';
    }

    formatAddress(point) {
        const addressParts = [];
        if (point.streetAddress) addressParts.push(point.streetAddress);
        if (point.locality) addressParts.push(point.locality);
        if (point.postalCode) addressParts.push(point.postalCode);

        return addressParts.length > 0 ? addressParts.join(', ') : '-';
    }

    createOptionElement(point, index) {
        const option = document.createElement('div');
        const isSelected = !!(this.selectedPoint && this.selectedPoint.id === point.id);

        option.className = 'montonio-pickup-point__option';
        option.id = 'montonio-pickup-point__option-' + index;
        option.dataset.pointId = point.id;
        option.setAttribute('role', 'option');
        option.setAttribute('aria-selected', isSelected ? 'true' : 'false');

        if (isSelected) {
            option.classList.add('montonio-pickup-point__option--selected');
        }

        let innerHTML = `<div class="montonio-pickup-point__option-name">${this.escapeHtml(point.name || 'Unknown Location')}</div>`;

        if (this.shouldShowAddress()) {
            innerHTML += `<div class="montonio-pickup-point__option-address">${this.escapeHtml(this.formatAddress(point))}</div>`;
        }

        option.innerHTML = innerHTML;

        option.addEventListener('click', () => {
            this.selectOption(point);
        });

        return option;
    }

    selectOption(point) {
        this.setSelectedPoint(point);
        this.saveSelection();
        this.close(true);
        this.dispatchSelected(point);
    }

    setSelectedPoint(point) {
        this.selectedPoint = point;
        this.hiddenInput.value = point.id;
        this.syncPickupPointMirror();
        this.renderSelection();
    }

    dispatchSelected(point) {
        // Blocks checkout listens to this to update validation and checkout extension data
        this.input.dispatchEvent(new CustomEvent('montonio-pickup-point-selected', {
            detail: point,
            bubbles: true
        }));
    }

    clearSelection() {
        this.selectedPoint = null;

        if (this.input) {
            this.input.value = '';
        }

        if (this.hiddenInput) {
            this.hiddenInput.value = '';
        }

        this.syncPickupPointMirror();
        this.renderSelection();
    }

    /**
     * Show the selected point in the closed select box, or the placeholder when nothing is selected.
     */
    renderSelection() {
        if (!this.selectBox || !this.selectBoxValue) {
            return;
        }

        this.selectBoxValue.innerHTML = '';

        if (!this.selectedPoint) {
            this.selectBox.classList.remove('montonio-pickup-point__toggle--has-value');
            return;
        }

        const name = document.createElement('span');
        name.className = 'montonio-pickup-point__toggle-name';
        name.textContent = this.selectedPoint.name || 'Selected Pickup Point';
        this.selectBoxValue.appendChild(name);

        if (this.shouldShowAddress()) {
            const address = document.createElement('span');
            address.className = 'montonio-pickup-point__toggle-address';
            address.textContent = this.formatAddress(this.selectedPoint);
            this.selectBoxValue.appendChild(address);
        }

        this.selectBox.classList.add('montonio-pickup-point__toggle--has-value');
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
    let previousState = null;

    // Destroy existing instance if it exists, keeping its state for the new one
    if (window.montonioPickupPointSelector && typeof window.montonioPickupPointSelector.destroy === 'function') {
        if (typeof window.montonioPickupPointSelector.getState === 'function') {
            previousState = window.montonioPickupPointSelector.getState();
        }

        window.montonioPickupPointSelector.destroy();
    }

    // Create new instance
    if (document.getElementById('montonio-pickup-point__search-input')) {
        window.montonioPickupPointSelector = new MontonioPickupPointSelector(previousState);
    } else {
        // Pickup point no longer applies — drop any lingering dual-form mirror input.
        removePickupPointMirror();
    }
}

// Initialize on WooCommerce checkout updates
jQuery(document).on('updated_checkout', initializePickupPointSelector);

// Initialize on page load
jQuery(document).ready(initializePickupPointSelector);