=== Bileto - Event Tickets for WooCommerce ===
Contributors: motylanogha
Tags: woocommerce, event tickets, tickets, check-in, qr code
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell event tickets as WooCommerce products: attendees, QR codes, ticket transfer and check-in.

== Description ==

Bileto turns WooCommerce products into event tickets. WooCommerce keeps the cart, checkout, payments, coupons and taxes. Bileto issues a ticket for each paid seat, lets buyers assign attendees and transfer tickets, gives your door staff a check-in screen, and lists every ticket of an event in the admin with a CSV export.

The B2C payments report lists every paid order of a private buyer (no NIP or EU VAT ID) in a month, quarter, year or custom range, with refunds, the gateway transaction ID and the Stripe fee and net stored on the order, plus totals and a CSV export. It is evidence that each sale to a consumer was paid through a bank or payment institution. Filter `bileto_buyer_is_business` decides who counts as a business.

The [bileto_tickets] shortcode lists ticket types with quantity buttons that edit the cart in place. Put it above the Checkout block and the purchase fits on one page: an empty cart gets one seat of the cheapest ticket, the order summary follows each change. Optional attribute event="ID" limits it to one event. Add `add_filter( 'woocommerce_checkout_redirect_empty_cart', '__return_false' );` so an empty cart is not sent to the cart page.

== Third-party libraries ==

* chillerlan/php-qrcode (MIT): QR code generation, in vendor/.
* jsQR 1.4.0 (Apache-2.0, assets/vendor/jsqr.js, licence in assets/vendor/jsqr-LICENSE.txt): QR scanning in browsers without the BarcodeDetector API, loaded only on the check-in screen.

== Changelog ==

= Unreleased =
* Added: [bileto_tickets] shortcode, ticket types with quantity buttons bound to the block cart, for a one-page checkout.

= 0.1.0 =
* Added: events and ticket products, with their own tables and a VAT rule by event country.
* Added: tickets issued on payment, a ticket page with a QR code and transfer, a ticket e-mail and a check-in screen with camera scanning (jsQR fallback for Safari).
* Added: seats follow partial refunds and quantity edits in the admin.
* Added: admin list of an event's tickets with a CSV export.
* Added: import of ticket orders from another shop (wp bileto import).
* Added: B2C payments report under Events > B2C payments: paid orders of private buyers in a period, refunds, the Stripe transaction and payment intent, the Stripe fee and net, PLN totals and a CSV export. Filter bileto_buyer_is_business decides who counts as a business.
