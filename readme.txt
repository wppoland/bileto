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

One-page ticket checkout (WooCommerce > Settings > Products > Bileto): the event (days, venue, a description with "Read more") and the tickets with quantity buttons sit next to the Checkout block, in two columns or above the form. An empty cart gets one seat of the cheapest ticket, and the home page can open checkout. Each checkout touch is its own switch: "2 x Regular" in the order summary, the coupon field always open, labels above fields, the order total on the pay button, the event name on order and invoice lines. The [bileto_tickets] and [bileto_event] shortcodes place the same parts anywhere.

== Third-party libraries ==

* chillerlan/php-qrcode (MIT): QR code generation, in vendor/.
* jsQR 1.4.0 (Apache-2.0, assets/vendor/jsqr.js, licence in assets/vendor/jsqr-LICENSE.txt): QR scanning in browsers without the BarcodeDetector API, loaded only on the check-in screen.

== Changelog ==

= Unreleased =
* Added: Settings link on the Plugins screen.
* Added: one-page ticket checkout with its settings under WooCommerce > Settings > Products > Bileto.
* Added: [bileto_tickets] (ticket types with quantity buttons bound to the block cart, short description lines as points) and [bileto_event] (days, venue, description).
* Added: attendee reminder e-mail. A paid order with seats that have no attendee gets a reminder every few days (default 3, set in the e-mail's settings) until every seat is filled in or the event starts.
* Added: note under the pay button on the one-page checkout (secure payment, refund rules).
* Added: ticket order lines carry the event name, so a ticket can be named "Regular" in the shop.

= 0.1.0 =
* Added: events and ticket products, with their own tables and a VAT rule by event country.
* Added: tickets issued on payment, a ticket page with a QR code and transfer, a ticket e-mail and a check-in screen with camera scanning (jsQR fallback for Safari).
* Added: seats follow partial refunds and quantity edits in the admin.
* Added: admin list of an event's tickets with a CSV export.
* Added: import of ticket orders from another shop (wp bileto import).
* Added: B2C payments report under Events > B2C payments: paid orders of private buyers in a period, refunds, the Stripe transaction and payment intent, the Stripe fee and net, PLN totals and a CSV export. Filter bileto_buyer_is_business decides who counts as a business.
