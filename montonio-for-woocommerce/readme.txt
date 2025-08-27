=== Montonio for WooCommerce ===
Version: 9.1.0
Date: 2019-09-04
Contributors: Montonio
Tags: payments, payment gateway, shipping, montonio, woocommerce
Requires at least: 5.0
Tested up to: 6.8
Stable tag: 9.1.0
Requires PHP: 7.0
Minimum requirements: WooCommerce 3.2 or greater
License: GPLv3
License URL: http://www.gnu.org/licenses/gpl-3.0.html



== Description ==

Montonio is a complete checkout solution for online stores that includes all popular payment methods (local banks, card payments, Apple Pay, Google Pay) plus financing and shipping. Montonio offers you everything you need in your online store checkout.
 
= Payments =
The easiest way to collect payments in your online store. Montonio payment initiation service offers integrations with all major banks in Estonia, Finland, Latvia, Lithuania and Poland, additionally Apple Pay, Google Pay, Revolut (available everywhere) and Blik in Poland.
 
All funds are immediately deposited to your bank account and an overview of the transactions can be found in our [partner system](https://partner.montonio.com).

= Card Payments =
Give your customers more ways to pay. In addition to payment links, Montonio lets your users pay by credit card.

= Apple Pay, Google Pay =
Want to offer an even easier way of paying? We also have Apple Pay and Google Pay! You can add these popular mobile wallets to your online store’s checkout. Your customers can pay faster since their credit card info is stored in the digital wallet and they don’t need to enter card details with each purchase.

= Refunds =
You can do a partial or full refund with a couple of clicks in the Montonio Partner System. Just open the order, check what items your customer returned and refund the amount needed.
 
= Financing (Hire purchase) =
Montonio Financing is just the right solution for financing larger purchases. You customers can choose a payment schedule that exactly suits their needs. Shoppers pay in equal instalments but you will get the full payment amount upfront. Plus, there's no service fee for the merchant.
 
= Pay Later =
Give your visitors the most convenient ways to pay – with Montonio 'Pay later' your customers can pay later or split purchase into two or three payments. All this without any additional interest or contract fees for them. Shoppers pay in equal instalments but you will get the full payment amount upfront.
 
= Shipping =
Handle everything from one system: automatically generate, edit and print shipping labels without having to ever leave the Montonio dashboard. Labels are automatically retrieved from providers after order creation. You can start printing with just 2 clicks. With Montonio you can add order tracking codes with a link to the providers’ tracking page.
 
= How to get started =
Adding Montonio to your store is only a matter of minutes.
1. Sign up at [montonio.com](https://montonio.com)
2. Verify your identity and confirm your account with Montonio
3. Set up the plugin, insert API keys and start using Montonio. More details on how to install and set up the plugin can be found in the Installation tab.

= Availability = 
Montonio currently offers services in these countries:
* Payments: Estonia, Finland, Latvia, Lithuania, Poland
* Card payments: Estonia, Finland, Latvia, Lithuania, Poland
* Financing: Estonia
* Pay Later: Estonia
* Shipping: Estonia, Latvia, Lithuania
We are also working on adding new countries.

= Support =
Any questions? Just drop us an email at support@montonio.com.

= WANT TO KNOW MORE? =
More information about our solutions can be found on our [website](https://montonio.com).

== Installation ==

= Automatic installation =
1. Log in to your WordPress dashboard, navigate to the Plugins menu and click Add New. Search for "Montonio for WooCommerce" and click "Install Now", then "Activate".
2. After activating the plugin, you need to connect your Montonio API keys to your WooCommerce store. To do this, go to WooCommerce > Settings > Payments > Montonio Bank Payments (2023). Under API settings tab you can enter your API keys that are easily accessible through [https://partner.montonio.com](https://partner.montonio.com). For step-by-step instructions on where to find API keys [click here](https://help.montonio.com/en/articles/79609-how-to-find-api-keys-for-integration).
3. Save changes, and enable payment methods you want to use in your store.


= Manual installation =
1. Download the "Montonio for WooCommerce" plugin zip file from the WordPress Plugin Directory and unzip it locally.
2. Transfer the extracted folder to the wp-content/plugins directory of your WordPress site via SFTP or remote file manager.
3. From the Plugins menu in the Administration Screen, click Activate for the "Montonio for WooCommerce" plugin.
3. After activating the plugin, you need to connect your Montonio API keys to your WooCommerce store. To do this, go to WooCommerce > Settings > Payments > Montonio Bank Payments (2023). Under API settings tab you can enter your API keys that are easily accessible through [https://partner.montonio.com](https://partner.montonio.com). For step-by-step instructions on where to find API keys [click here](https://help.montonio.com/en/articles/79609-how-to-find-api-keys-for-integration).
4. Save changes, and enable payment methods you want to use in your store.

== External Services ==

This plugin connects to multiple Montonio services to provide payment processing, shipping management, and service improvements:

= Montonio Payment Gateway (Stargate) =
What it does: Processes various payment methods including bank payments, card payments, BLIK payments, hire purchase and pay later options.

Data transmitted: Order information (total amount, currency, order items), customer details (name, email, billing/shipping addresses), payment method selection, and merchant identification.

When transmitted: During checkout when a payment is initiated, when checking payment status, and when processing refunds.

Service URLs:
* Production: https://stargate.montonio.com/api
* Sandbox: https://sandbox-stargate.montonio.com/api

Service information: [Terms of Service](https://s3.eu-central-1.amazonaws.com/public.montonio.com/terms_and_conditions/montonio_general/v3.0/montonio_general_ee.pdf) | [Privacy Policy](https://montonio.com/legal/privacy-policy/)

= Montonio Shipping API =
What it does: Manages shipping methods, pickup points, courier services, label generation and shipment tracking.

Data transmitted: Shipping addresses, order details, selected shipping methods, parcel information (weight, dimensions), and shipment tracking information.

When transmitted: When retrieving available shipping methods, displaying pickup points, creating shipments, and generating shipping labels.

Service URLs:
* Production: https://shipping.montonio.com/api
* Sandbox: https://sandbox-shipping.montonio.com/api

Service information: [Terms of Service](https://s3.eu-central-1.amazonaws.com/public.montonio.com/terms_and_conditions/shipping/v3.0/shipping_international.pdf) | [Privacy Policy](https://montonio.com/legal/privacy-policy/)

= Montonio JavaScript SDK =
What it does: Client-side library that renders payment forms, handles payment method selection and processes transactions.

Data transmitted: Payment form inputs, selected payment method details, transaction authentication data.

When transmitted: During checkout when payment forms are displayed and when customers interact with payment elements.

Service URLs:
* Montonio JS: https://js.montonio.com/1.x.x/montonio.umd.js
* Montonio JS (Legacy): https://public.montonio.com/assets/montonio-js/3.x/montonio.bundle.js
* Card payments API (Production): https://api.card-payments.montonio.com/payment-intents
* Card payments API (Sandbox): https://api.sandbox-card-payments.montonio.com/payment-intents
* Payment intents API (Production): https://stargate.montonio.com/api/payment-intents
* Payment intents API (Sandbox): https://sandbox-stargate.montonio.com/api/payment-intents

Service information: [Bank Payment Terms of Service](https://s3.eu-central-1.amazonaws.com/public.montonio.com/terms_and_conditions/payment_initiation/v3.0/payment_initiation_international.pdf) | [Card Payment Terms of Service](https://s3.eu-central-1.amazonaws.com/public.montonio.com/terms_and_conditions/card_payments/v3.0/card_payments_international.pdf) | [Privacy Policy](https://montonio.com/legal/privacy-policy/)

= Montonio Telemetry Service =
What it does: Sends Store URL, WordPress/WooCommerce version information and plugin configuration settings to offer better customer support when troubleshooting issues. No sensitive or private data is collected.

Data transmitted: Store URL, WordPress/WooCommerce version information, plugin configuration settings (with sensitive data removed).

When transmitted: Upon plugin activation, deactivation, settings changes, and periodically (once per day).

Service URL: https://plugin-telemetry.montonio.com/api

Service information: [Terms of Service](https://s3.eu-central-1.amazonaws.com/public.montonio.com/terms_and_conditions/montonio_general/v3.0/montonio_general_ee.pdf) | [Privacy Policy](https://montonio.com/legal/privacy-policy/)


== Changelog ==
= 9.1.0 =
* Added – Integration of new card payment processor flow
* Fix – Financing calculator not displaying in Estonian language when locale is set to Estonian
* Dev - Renamed montonio-inline-card.js to montonio-embedded-card-legacy.js
* Dev - Renamed montonio-inline-blik.js to montonio-embedded-blik-legacy.js
* Dev - Renamed script handle from montonio-sdk to montonio-js-legacy
* Dev - Renamed script handle from montonio-inline-card to montonio-embedded-card-legacy
* Dev - Renamed script handle from montonio-inline-blik to montonio-embedded-blik-legacy

= 9.0.6 =
* Added – Financing calculator widget
* Tweak – Improved admin options saving function for better compatibility
* Fix – Input field validation error causing embedded BLIK modal infinite loading
* Fix – Smartposti logo not displaying in the shipping panel

= 9.0.5 =
* Added – 'wc_montonio_shipping_shipment_status_update' action hook (via do_action) to allow custom handling of shipment status updates received via webhook
* Added – Product announcement banner in the admin interface
* Tweak – Refactored pickup point dropdown initialization for improved compatibility

= 9.0.4 =
* Fix - Embedded payment fields not working properly in checkout

= 9.0.3 =
* Fix - Payment test mode badge now displays correctly in admin settings page

= 9.0.2 =
* Added - Option to calculate free shipping threshold using cart total before coupon codes are applied
* Added - WPML configuration file (wpml-config.xml) for improved translation management
* Tweak - Enhanced options page and shipment widget UI/UX design
* Tweak - Implemented two-digit decimal rounding for finalPrice within lineItems array
* Tweak - Renamed sandbox_mode to test_mode for better WooCommerce compatibility

= 9.0.1 =
* Added - "Print label" action button in the order list view
* Added - Product price and currency to the shipment 'products' array data
* Tweak - Improved shipping method dropdown compatibility with custom checkout layouts
* Fix - Order status now correctly updates when the label is printed through the Partner system

= 9.0.0 =
* Tweak - Code refactoring, compatibility and security improvements

= 8.1.3 =
* Tweak - Refactored "labels printed" order status update logic to trigger after labels have been printed
* Tweak - Always send the full 'products' array in shipment data, without filtering or removing items
* Fix - Free shipping threshold calculation issue when using an incorrect decimal separator

= 8.1.2 =
* Added - Option to exclude virtual products from the cart total when determining eligibility for free shipping
* Added - Option to automatically update the order status when a shipment is marked as delivered
* Added - 'orderComment' parameter in shipment data, populated with the customer checkout note
* Added - Support for WPML-translated shipping classes
* Tweak - Improved payment callback functionality
* Tweak - Combine products with the same ID in the shipment data 'products' array

= 8.1.1 =
* Fix - Display shipment registration failure error messages correctly

= 8.1.0 =
* Added - Refund status updates from API
* Added - Shipment status updates from API
* Added - Shipment status column in the order list view for better tracking
* Tweak - Improved shipment error handling and display for a better user experience

= 8.0.5 =
* Added - Embedded BLIK payment 'direct' flow integration
* Tweak - Improved user-side error messages for better readability

= 8.0.4 =
* Tweak - Refactored the embedded payment flow to prevent unexpected redirects
* Fix - Resolved an issue where pickup points from the previous country were still displayed after changing the shipping address in block checkout
* Fix - Fixed an issue where the shipping sandbox mode was not properly decoding webhook payloads
* Fix - Fixed address validation failing for countries that have the "State/County" field

= 8.0.3 =
* Added – Sandbox mode for shipping
* Tweak – Enabled printing for orders with the labelsCreated status
* Tweak – Renamed Smartpost to SmartPosti
* Fix – 'State/County' field validation error in block checkout
* Fix – "TEST MODE" notice not displayed for some payment methods in block checkout

= 8.0.2 =
* Fix - Resolved an issue where B2B-only courier services were incorrectly included in order processing

= 8.0.1 =
* Added - Latvian Post shipping methods
* Fix - Issue where shipping method item sync was skipping couriers in certain scenarios
* Fix - Pickup point address missing in the order details on the Thank You page

= 8.0.0 =
* Removed - Old V1 shipping
* Removed - Old V1 payment methods
* Added - Enhanced shipment data with product details (SKU, name, quantity) when all order items have valid required data
* Added - Configurable minimum cart amount threshold for the Financing payment method
* Tweak - Use the new shipping API endpoints for faster synchronization of shipping method items
* Tweak - Replaced webhook registration functions with a 'notificationUrl' parameter sent in the API request
* Tweak - Refactored the file and folder structure
* Fix - Skip pickup-point validation for orders where shipping is not required

= 7.1.7 =
* Fix - Reverted module state to version 7.1.5

= 7.1.6 =
* Added - Include product information in shipment data when SKU is used
* Tweak - Use the new shipping API endpoints for faster synchronization of shipping method items

= 7.1.5 =
* Fix - WC_Settings_API dependency error

= 7.1.4 =
* Fix - Delay load_plugin_textdomain execution until the init action

= 7.1.3 =
* Added - Validation to ensure the selected pickup-point carrier matches the correct carrier
* Added - "Test Mode" message to block payment methods in the frontend
* Tweak - Improved label request process to verify if the shipment status is "registered" before requesting a label
* Tweak - "1. eelistus Omnivas" pickup point moved to the top if present
* Tweak - Pickup-point address format now includes locality for improved filtering
* Fix - Resolved compatibility issue in block integration with older WooCommerce versions

= 7.1.2 =
* Added - Option to disable the shipping method for selected shipping classes
* Added - New bulk action: "Change status to Label Printed" for easier status management
* Added - OTA (Over-The-Air) service to receive updates from Montonio for configuration and other data
* Tweak - Updated "Label Printed" status badge color for better visual distinction
* Fix - Resolved an issue where the checkout blocks were deactivated due to an unregistered dependency ‘montonio-sdk’

= 7.1.1 =
* Fix - Save tracking codes in '_montonio_tracking_info' order meta key

= 7.1.0 =
* Added - WooCommerce block-based checkout compatibility for payment methods and shipping methods

= 7.0.3 =
* Fix - Optimize AJAX shipping method sync to reduce server load
* Fix - Shipment creation failing if customer last name is empty
* Fix - "Preselect country by user data" setting not working if bank logos are hidden
* Fix - Prevent duplicate shipping label file generation

= 7.0.2 =
* Added - Unisend parcel machine shipping method
* Tweak - Update existing shipment (if it already exists) instead of creating a new one
* Tweak - Choices dropdown CSS improvements for compatibility
* Tweak - Improved selected pickup-point validation compatibility
* Tweak - Improved error message readability in order notes
* Added - Order list action buttons for orders with status 'Label printed'
* Tweak - Refactored 'get_payment_description' function for better usage
* Bugfix - Sending many requests regarding syncing shipping methods
* Bugfix - Default parcel item weight being multiplied causing max weight to be exceeded

= 7.0.1 =
* Added - Support for custom order numbers
* Added - Ability to use order prefix
* Fix - Shipping method being asked from WC_Coupon class causing error in some cases
* Tweak - Shipping V2 pickup points now fetched when shipping method is added to a zone
* Tweak - Periodic pickup points sync is now checked in better areas of the site
* Removed - Sync pickup points via JavaScript requests

= 7.0.0 =
* Added - Montonio Shipping V2 with new features and improvements
* Added - Telemetry data collection service
* Tweak - "wc_montonio_merchant_reference" filter changed to accept order object as a second parameter instead of payment method
* Tweak - "wc_montonio_merchant_reference_display" filter changed to accept order object as a second parameter instead of payment method

= 6.4.9 =
* Fix - Print labels on single order page throwing error
* Fix - Older WooCommerce installations not having shipping under the order object

= 6.4.8 =
* Fix - Print labels function throwing error

= 6.4.7 =
* Added - API key retrieval helper function and new 'wc_montonio_api_keys' filter
* Added - Pickup point dropdown in admin order view for manually created orders
* Tweak - Hide BLIK payment method if min cart amount is not reached
* Removed - Old "Financing" payment method
* Removed - Old "Pay Later" payment method
* Fix - Free shipping rate text not displayed

= 6.4.6 =
* Tweak - Reduce webhook notification delay to 10 seconds

= 6.4.5 =
* Fix - Revert order retrieval function changes

= 6.4.4 =
* Fix - wc_get_orders() not retrieving order by meta key in some cases
* Fix - Shipping method MAX_WEIGHT constat causing error in some cases

= 6.4.3 =
* Tweak - Automatically update shipping carriers list when new shipping zone method is added
* Tweak - "Paid amount" added to order notes
* Tweak - Refactoring of the shipping method files
* Removed - "shipping_method_identifier" variable that was previously utilized to transmit the shipping method key to Montonio. Instead, it now utilizes the shipping method's ID to construct the key.
* Bugfix

= 6.4.2 =
* Added - "Pay later" custom minimum cart amount setting
* Removed - Old Financing calculator widget
* Tweak - Improved wording and translations
* Fixed - "Bulk edit" failed to create shipments in Partner System
* Bugfix

= 6.4.1 =
* Tweak - "Pay later" min required cart amount adjustment
* Tweak - Require 'paymentIntentUuid' parameter to be set, when embedded payment flow is used

= 6.4.0 =
* Added - New "Pay Later" payment method
* Added - New "Financing" payment method
* Tweak - Payment initiation bank selection UI improvements

= 6.3.1 =
* Inline Blik and Card payment bugfix

= 6.3.0 =
* Feature - Create a separate shipping label for each of the selected products
* Feature - Added support for AUTHORIZED and VOIDED payment statuses
* Tweak - Improved error handling for inline checkout methods
* Bugfix

= 6.2.1 =
* Pickup point dropdown compatibility improvements
* Bugfix

= 6.2.0 =
* HPOS compatibility improvements
* Bugfix

= 6.1.9 =
* Inline Blik and Card payment bugfix

= 6.1.8 =
* Added - Option to enable the Card fields in checkout
* Code improvements 
* Bugfix

= 6.1.7 =
* Added - Venipak shipping methods
* Added - Option to show parcel machine address in dropdown in checkout

= 6.1.6 =
* Added - Option to enable the BLIK in checkout feature
* Bugfix

= 6.1.5 =
* Added - Option to choose between displaying custom text and 0.00 price for free shipping rate methods
* Added - Notice in frontend and backend if test mode enabled
* Added - Montonio activity logger
* Added - Ability to filter orders by shipping provider
* Admin UI improvements
* Bugfix

= 6.1.4 =
* Added - "Shipping classes" support for Montonio shipping methods
* Added - Option to turn off parcel machines support per product
* Added - Update order status if payment sesion ABANDONED
* Added - Tag support for shipping cost field, use [qty] for the number of items, [cost] for the total cost of items, and [fee percent="10" min_fee="20" max_fee=""] for percentage based fees. e.g. 3.00 * [qty]
* Changed - Shipping now uses API keys from "API Settings" page
* Code improvements 

= 6.1.3 =
* Added - Make refunds for orders via Woocommerce (only for orders after this update)
* Added - Free shipping based on product quantity in the cart
* Fixed - Free shipping threshold amount now includes VAT
* Code improvements


= 6.1.2 =
* API HTTP request timeout increased to 30s (this prevents error when downloading a large number of shipping labels)
* "1. eelistus Omnivas" set as first option in dropdown for EE Omniva parcel machine
* Fixed - Pay Later not changing order status to "Completed" for virtual products after sucesfull payment
* Pickup point select style adjustments
* Gpay & Apple Pay icons added to card payments

= 6.1.1 =
* Pickup point dropdown bugfix for small screens

= 6.1.0 =
* Pickup point dropdown styling improvements
* Admin settings page UI tweaks

= 6.0.9 =
* Selected pickup point gets saved to session storage
* Replace some post meta methods with equivalent methods compatible with HPOS
* Declare compatibility with High-Performance Order Storage (HPOS)
* Bugfix

= 6.0.8 =
* Shipping SDK file_get_contents() changed to wp_remote_request() for HTTP requests
* Pickup point dropdown styling fix for small screens

= 6.0.7 =
* PHP 8.1 compatibility fix

= 6.0.6 =
* New payment method added - "Montonio Card Payments (2023)"
* New "Custom payment description" option added to Montonio Bank Payments (2023)
* Pay Later "Min order total" fix

= 6.0.5 =
* Bugfix

= 6.0.4 =
* Bugfix

= 6.0.3 =
* New admin UI for Montonio Bank Payments (2023) and Blik
* API settings moved to standalone page (for methods that use API v2)
* New option to preselect bank based on client selected billing country in checkout
* Code improvements
* Bugfix

= 6.0.2 =
* CSSTidy library removed

= 6.0.1 =
* Bugfix

= 6.0.0 =
* New payment method added - "Montonio Bank Payments (2023)" that utilizes new API
* Germany added to bank list in "Montonio Bank Payments (2023)" payment method
* Split payment rebranding
* Financing payment rebranding
* Code improvements

= 5.0.7 =
* Bugfix

= 5.0.6 =
* Bugfix

= 5.0.5 =
* Pickup point selection bugfix

= 5.0.4 =
* Bugfix

= 5.0.3 =
* Code improvements
* Set virtual product status to "Completed" after successful Financing
* Pickup point select compatibility improvements
* Blik payment method logo sizing fix

= 5.0.2 =
* Code improvements
* Pickup point selection is now a template

= 5.0.1 =
* Code improvements

= 5.0.0 =
* Introduced Montonio Blik

= 4.2.2 =
* Code improvements

= 4.2.1 =
* Reverted version

= 4.2.0 =
* Code improvements

= 4.1.9 =
* Code improvements
* Pending parcel labels now show a warning

= 4.1.8 =
* Improved parcel search dropdown styling on some themes

= 4.1.7 =
* Added Omniva Courier

= 4.1.6 =
* Added Itella Courier
* Other minor fixes and improvements

= 4.1.5 =
* Added ability to add shipping provider logos to checkout
* Made other various improvements to shipping UI in checkout
* Other minor fixes and improvements

= 4.1.4 =
* Added ability to configure minimum cart total for financing and split
* Other code improvements

= 4.1.3 =
* Code improvements

= 4.1.2 =
* Code improvements

= 4.1.1 =
* A shipment will now be created in Montonio Partner System when manually changing order to 'processing' status
* Added some advanced configuration options for Montonio Shipping
* Other minor fixes and improvements

= 4.1.0 =
* Added jQuery as dependency for checkout JS script for better coverage across stores

= 4.0.9 =
* Added ability to configure maximum weight for Montonio's shipping options
* Shipment's tracking code will now be available after it has been registered with provider
* Other code improvements and fixes

= 4.0.8 =
* Better Shipment measurements support

= 4.0.7 =
* Code improvements

= 4.0.6 =
* Code improvements

= 4.0.5 =
* Removed dependency while querying list of payment options which was causing problems on some installations

= 4.0.4 =
* Improvements to Montonio Shipping

= 4.0.3 =
* More hooks for custom solutions

= 4.0.2 =
* Code improvements

= 4.0.1 =
* Launched Montonio Shipping

= 4.0.0 =
* Introduced Montonio Shipping
* Improved support for multiple currency mode

= 3.0.9 =
* Finalized payments that were timed out by WooCommerce no longer get the on-hold status, they are set to processing instead

= 3.0.8 =
* Added support for WooCommerce Deposits plugin
* Code improvements

= 3.0.7 =
* Code improvements
* Made constraining Split by Shopping Cart total optional

= 3.0.6 =
* Updated Card Payment description field

= 3.0.5 =
* Bumped compatibility version

= 3.0.4 =
* Fixed small error on older WooCommerce systems

= 3.0.3 =
* Added option to always show description on top of the bank selection
* Payment instructions are now editable without WPML
* Code improvements

= 3.0.2 =
* Fixed ordering Split methods at checkout
* Restricted showing split at checkout when order total too low
* Code improvements

= 3.0.1 =
* Introduced Montonio Split

= 2.3.3 =
* Made Card Payment title translatable

= 2.3.2 =
* Removed callback and notification URL automatic redirect from https to http

= 2.3.1 =
* Added card payment as a separate payment option
* Added a note to the order about which bank was used to pay for the order

= 2.3.0 =
* Orders containing of only virtual products now get "Completed" status upon successful payment

= 2.2.2 =
* Added more translations

= 2.2.0 =
* Minor update: Added translations for [et, lv, lt, fi, ru]. Better WPML support. Removed iframe as a display option for Montonio Financing

= 2.1.1 =
* Bumped supported WooCommerce version to 5.0.0 

= 2.1.0 =
* Scalability updates

= 2.0.9 =
* Added Order Prefix configuration option to Montonio Financing

= 2.0.7 =
* Code Improvements

= 2.0.6 =
* Introduced country selection at checkout and better multistore support with order prefixes and other settings

= 2.0.5 =
* Code improvements

= 2.0.3 =
* Ability to configure Montonio Payments title and description

= 2.0.2 =
* Montonio Payment Initiation Service introduced 
* Overall code improvements

= 1.2.1 =
* Reliability upgrade

= 1.2.0 =
* Changed customer journey type to redirect by default.
* Overall Improvements

= 1.1.6 =
* Added option to change payment handle logo

= 1.1.5 =
* Code improvements

= 1.1.4 =
* Added a feature to loan calculator

= 1.1.3 =
* Bugfix

= 1.1.2 =
* Repaired loan calculator widget shortcode attributes

= 1.1.1 =
* Modified loan calculator widget data

= 1.1.0 =
* Added a loan calculator widget feature.

= 1.0.7 =
* Bugfixes

= 1.0.6 =
* Bugfixes

= 1.0.5 =
* Fix - Support WordPress installations in subdirectories

= 1.0.4 =
* White label payment handle style added for white-label customers

= 1.0.3 =
* Fix - Temporarily translate "Apply for hire purchase" to Estonian hardcoded

= 1.0.2 =
* Fix - Payment handle style !important

= 1.0.1 =
* Fix - Made js compatible with ES3