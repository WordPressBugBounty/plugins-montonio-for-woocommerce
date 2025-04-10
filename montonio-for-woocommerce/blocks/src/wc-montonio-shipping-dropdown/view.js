import metadata from './block.json';
import { useEffect, useState, useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { registerCheckoutBlock } from '@woocommerce/blocks-checkout';
import { useSelect, useDispatch } from '@wordpress/data';
import { debounce } from 'lodash';

const { getSetting } = wc.wcSettings;
const settings = getSetting('wc-montonio-shipping-dropdown_data', {});

const MontonioShippingDropdown = ({ checkoutExtensionData }) => {
    const [selectedPickupPoint, setSelectedPickupPoint] = useState('');
    const [pickupPoints, setPickupPoints] = useState([]);
    const [isLoading, setIsLoading] = useState(false);
    const [showDropdown, setShowDropdown] = useState(false);
    const [hasInteracted, setHasInteracted] = useState(false);
    const { setExtensionData } = checkoutExtensionData;

    const lastRequestRef = useRef(null);
    const prevShippingRateRef = useRef(null);
    
    const validationErrorId = 'montonio-pickup-point';

    const { setValidationErrors, clearValidationError } = useDispatch(
        'wc/store/validation'
    );

    const validationError = useSelect((select) => {
        const store = select('wc/store/validation');
        return store.getValidationError(validationErrorId);
    });

    const { selectedShippingRate, shippingAddress, isCustomerDataUpdating } = useSelect((select) => {
        const cartStore = select('wc/store/cart');
        const cartData = cartStore.getCartData();

        return {
            selectedShippingRate: cartData.shippingRates?.[0]?.shipping_rates?.find(rate => rate.selected)?.rate_id || null,
            shippingAddress: cartData.shippingAddress || {},
            isCustomerDataUpdating: cartStore.isCustomerDataUpdating(),
        };
    });

    const debouncedSetExtensionData = useCallback(
        debounce((namespace, key, value) => {
            setExtensionData(namespace, key, value);
        }, 1000),
        [setExtensionData]
    );

    const updateSelectedPickupPoint = useCallback((event) => {
        const value = event.target.value;
        setSelectedPickupPoint(value);
        debouncedSetExtensionData('montonio-for-woocommerce', 'selected_pickup_point', value);
        setHasInteracted(true);
    }, [debouncedSetExtensionData]);

    const fetchPickupPoints = useCallback(async (shippingMethodId, country) => {
        if (lastRequestRef.current) {
            lastRequestRef.current.abort();
        }

        const controller = new AbortController();
        lastRequestRef.current = controller;

        setIsLoading(true);
        try {
            const response = await fetch(`${settings.getShippingMethodItemsUrl}?shipping_method=${shippingMethodId}&country=${country}`, {
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': settings.nonce
                },
                signal: controller.signal
            });
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            const data = await response.json();
            return data;
        } catch (error) {
            if (error.name === 'AbortError') {
                console.log('Fetch aborted');
            } else {
                console.error('Error fetching pickup points:', error);
            }
            return {};
        } finally {
            setIsLoading(false);
            lastRequestRef.current = null;
        }
    }, []);

    const organizePickupPoints = useCallback((pickupPointsData) => {
        return Object.entries(pickupPointsData).map(([locality, items]) => ({
            label: locality,
            options: items.map(item => ({
                value: item.id,
                label: `${item.name}${settings.includeAddress === 'yes' && item.address?.trim() ? ` - ${item.address}, ${locality}` : ''}`
            }))
        }));
    }, []);

    const fetchAndSetupPickupPoints = useCallback(
        debounce(async (selectedShippingRate, shippingMethodId, country) => {    
            const pickupPointsData = await fetchPickupPoints(shippingMethodId, country);
            const organizedPoints = organizePickupPoints(pickupPointsData);
            setPickupPoints(organizedPoints);
            setSelectedPickupPoint('');
            setHasInteracted(false);
            setIsLoading(false);
        }, 400), 
        [fetchPickupPoints, organizePickupPoints, updateSelectedPickupPoint]
    );

    const updateShippingMethod = useCallback((selectedShippingRate, country) => {
        const [shippingMethodId] = (selectedShippingRate || ':').split(':');
        
        const isMontonioPickupPoint = shippingMethodId.startsWith('montonio_') && 
            (shippingMethodId.endsWith('parcel_machines') || 
             shippingMethodId.endsWith('post_offices'));
        
        setShowDropdown(isMontonioPickupPoint);
    
        if (isMontonioPickupPoint) {
            setIsLoading(true);
            fetchAndSetupPickupPoints(selectedShippingRate, shippingMethodId, country);
        } else {
            setPickupPoints([]);
            setSelectedPickupPoint('');
        }
    }, [fetchAndSetupPickupPoints]);

    useEffect(() => {
        if (selectedShippingRate !== prevShippingRateRef.current || isCustomerDataUpdating) {
            updateShippingMethod(selectedShippingRate, shippingAddress.country);
            prevShippingRateRef.current = selectedShippingRate;
        }
    }, [isCustomerDataUpdating, selectedShippingRate, shippingAddress.country, updateShippingMethod]);

    const initializeMontonioDropdown = useCallback(() => {
        if (!showDropdown) return;
        
        const targetElement = document.getElementById('montonio-shipping-pickup-point-dropdown');
        if (!targetElement) return;
        
        if (typeof Montonio === 'undefined' || !Montonio.Checkout || !Montonio.Checkout.ShippingDropdown) {
            console.warn('Montonio SDK not available');
            return;
        }
        
        if (window.montonioShippingDropdown) {
            window.montonioShippingDropdown.destroy();
        }
        
        window.montonioShippingDropdown = new Montonio.Checkout.ShippingDropdown({
            shippingMethod: selectedShippingRate,
            accessKey: settings.accessKey,
            targetId: 'montonio-shipping-pickup-point-dropdown',
            shouldInjectCSS: true,
            onLoaded: function() {
                const preselectedValue = targetElement.value;
                if (preselectedValue) {
                    updateSelectedPickupPoint({ target: { value: preselectedValue } });
                }
            }
        });
        
        window.montonioShippingDropdown.init();
    }, [showDropdown, selectedShippingRate, updateSelectedPickupPoint]);

    useEffect(() => {
        if (pickupPoints.length > 0 && showDropdown) {
            requestAnimationFrame(() => {
                initializeMontonioDropdown();
            });
        }
    }, [pickupPoints, showDropdown, initializeMontonioDropdown]);

    useEffect(() => {
        if (!showDropdown || selectedPickupPoint !== '') {
            if (validationError) {
                clearValidationError(validationErrorId);
            }
            return;
        }

        setValidationErrors({
            [validationErrorId]: {
                message: __('Please select a pickup point', 'montonio-for-woocommerce'),
                hidden: !hasInteracted,
            },
        });
    }, [showDropdown, selectedPickupPoint, setValidationErrors, clearValidationError, validationErrorId, hasInteracted, validationError]);

    const renderOptions = useCallback((options) => {
        return [
            <option key="default" value="">
                {__('Select a pickup point', 'montonio-for-woocommerce')}
            </option>,
            ...options.map((group, groupIndex) => (
                <optgroup key={groupIndex} label={group.label}>
                    {group.options.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </optgroup>
            ))
        ];
    }, []);

    return (
        <div id="montonio-shipping-pickup-point-dropdown-wrapper">
            {showDropdown && (
                <>
                    <h2 className="wc-block-components-title wc-block-components-checkout-step__title">
                        {__('Pickup point', 'montonio-for-woocommerce')}
                    </h2>
                    {isLoading ? (
                        <p>{__('Loading pickup points...', 'montonio-for-woocommerce')}</p>
                    ) : (
                        <div className={validationError?.hidden === false ? 'has-error' : ''}>
                            <input type="text" className="montonio-pickup-point-id" name="montonio-pickup-point-id" value={selectedPickupPoint} />
                            <select
                                id="montonio-shipping-pickup-point-dropdown"
                                onChange={updateSelectedPickupPoint}
                                value={selectedPickupPoint}
                                className={validationError?.hidden === false ? 'has-error' : ''}
                            >
                                {renderOptions(pickupPoints)}
                            </select>
                            {validationError?.hidden === false && (
                                <div className="wc-block-components-validation-error">
                                    <p>{validationError.message}</p>
                                </div>
                            )}
                        </div>
                    )}
                </>
            )}
        </div>
    );
};

const options = {
    metadata,
    component: MontonioShippingDropdown
};

registerCheckoutBlock(options);