/*
 * [bileto_tickets]: quantity steppers bound to the WooCommerce cart data store.
 * [bileto_event]: the description's "Read more".
 * existing-checked: assets/checkin.js - check-in scanner script, loaded only on the admin check-in screen.
 */
( function () {
	// [bileto_event]: "Read more" shows only when the clamped description actually hides text.
	document.querySelectorAll( '.bileto-event__more' ).forEach( function ( button ) {
		var about = document.getElementById( button.getAttribute( 'aria-controls' ) );
		button.hidden = about.scrollHeight <= about.clientHeight + 1;
		about.classList.toggle( 'is-clamped', ! button.hidden );
		button.addEventListener( 'click', function () {
			var open = button.getAttribute( 'aria-expanded' ) !== 'true';
			button.setAttribute( 'aria-expanded', String( open ) );
			about.classList.toggle( 'is-open', open );
			button.hidden = open;
		} );
	} );
} )();

( function () {
	var data = window.wp && window.wp.data;
	var store = window.wc && window.wc.wcBlocksData && window.wc.wcBlocksData.cartStore;
	var pickers = document.querySelectorAll( '.bileto-picker' );
	if ( ! data || ! store || ! pickers.length ) {
		return;
	}
	// Clicks change a local count at once; the cart gets one request per ticket
	// type once the clicks stop (Store API round trips take half a second).
	var wanted = {}; // Product ID => quantity clicked but not yet in the cart.
	var timers = {};
	var inFlight = {};
	var WAIT = 350;

	function items() {
		return data.select( store ).getCartData().items || [];
	}
	function itemFor( id ) {
		return items().find( function ( item ) { return item.id === id; } );
	}
	function cartQty( id ) {
		var item = itemFor( id );
		return item ? item.quantity : 0;
	}
	function qtyOf( id ) {
		return wanted.hasOwnProperty( id ) ? wanted[ id ] : cartQty( id );
	}

	function paint() {
		var rows = document.querySelectorAll( '.bileto-picker__row' );
		var total = 0;
		rows.forEach( function ( row ) {
			total += qtyOf( Number( row.dataset.product ) );
		} );
		rows.forEach( function ( row ) {
			var qty = qtyOf( Number( row.dataset.product ) );
			var count = row.querySelector( '.bileto-picker__count' );
			if ( count.textContent !== String( qty ) ) {
				count.textContent = qty;
				count.classList.remove( 'is-bumped' );
				void count.offsetWidth; // Restart the bump animation.
				count.classList.add( 'is-bumped' );
			}
			row.classList.toggle( 'is-selected', qty > 0 );
			// The last seat stays: the Checkout block shows no form for an empty cart.
			row.querySelector( '[data-step="-1"]' ).disabled = qty === 0 || total <= 1;
			row.querySelector( '[data-step="1"]' ).disabled = qty >= Number( row.dataset.max );
		} );
	}

	function flush( id ) {
		if ( inFlight[ id ] ) {
			// One request per ticket type at a time; the newest count goes when it returns.
			timers[ id ] = setTimeout( function () { flush( id ); }, WAIT );
			return;
		}
		if ( ! wanted.hasOwnProperty( id ) ) {
			return;
		}
		var qty = wanted[ id ];
		var item = itemFor( id );
		var actions = data.dispatch( store );
		var request;
		if ( item && qty === item.quantity ) {
			delete wanted[ id ];
			paint();
			return;
		}
		if ( ! item ) {
			request = qty > 0 ? actions.addItemToCart( id, qty ) : null;
		} else {
			request = qty > 0 ? actions.changeCartItemQuantity( item.key, qty ) : actions.removeItemFromCart( item.key );
		}
		if ( ! request ) {
			delete wanted[ id ];
			paint();
			return;
		}
		inFlight[ id ] = true;
		Promise.resolve( request ).catch( function () {} ).then( function () {
			inFlight[ id ] = false;
			// Done unless a newer count was clicked meanwhile; a refused change then shows the cart's count.
			if ( wanted[ id ] === qty ) {
				delete wanted[ id ];
			}
			paint();
		} );
	}

	function change( id, step, now ) {
		var max = Number( document.querySelector( '.bileto-picker__row[data-product="' + id + '"]' ).dataset.max );
		wanted[ id ] = Math.max( 0, Math.min( max, qtyOf( id ) + step ) );
		paint();
		clearTimeout( timers[ id ] );
		if ( now ) {
			flush( id );
			return;
		}
		timers[ id ] = setTimeout( function () { flush( id ); }, WAIT );
	}

	pickers.forEach( function ( picker ) {
		picker.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '.bileto-picker__step' );
			if ( ! button || button.disabled ) {
				return;
			}
			change( Number( button.closest( '.bileto-picker__row' ).dataset.product ), Number( button.dataset.step ) );
		} );
	} );

	data.subscribe( paint, store );

	// After the first cart load, an empty cart gets one seat of the first ticket.
	// The cart may already be loaded (hydrated) when this runs, so check once now too.
	var started = false;
	function preselect() {
		if ( started || ! data.select( store ).hasFinishedResolution( 'getCartData' ) ) {
			return;
		}
		started = true;
		stop();
		if ( items().length === 0 && ( ! window.biletoCheckout || window.biletoCheckout.preselect ) ) {
			change( Number( pickers[ 0 ].querySelector( '.bileto-picker__row' ).dataset.product ), 1, true );
		}
	}
	var stop = data.subscribe( preselect, store );
	data.select( store ).getCartData();
	preselect();
	paint();
} )();

/* Checkout touches switched on in WooCommerce > Settings > Products > Bileto (window.biletoCheckout). */
( function () {
	var opts = window.biletoCheckout;
	var blocks = window.wc && window.wc.blocksCheckout;
	var data = window.wp && window.wp.data;
	if ( ! opts || ! blocks || ! blocks.registerCheckoutFilters ) {
		return;
	}
	var filters = {};
	if ( opts.qtyNames ) {
		filters.itemName = function ( name, extensions, args ) {
			return args && args.context === 'summary' && args.cartItem ? args.cartItem.quantity + ' \u00d7 ' + name : name;
		};
	}
	var payLabel = function ( label ) {
		var cart = data.select( 'wc/store/cart' );
		var totals = cart.getCartTotals();
		if ( ! cart.hasFinishedResolution( 'getCartData' ) || ! totals || ! Number( totals.total_price ) ) {
			return label;
		}
		var minor = totals.currency_minor_unit;
		var amount = ( Number( totals.total_price ) / Math.pow( 10, minor ) ).toFixed( minor ).replace( '.', totals.currency_decimal_separator );
		return opts.payLabel.replace( '%s', totals.currency_prefix + amount + totals.currency_suffix );
	};
	if ( opts.payLabel ) {
		filters.placeOrderButtonLabel = payLabel;
	}
	// Registered once every footer script ran, so it applies after other plugins' labels (Polski sets one too).
	document.addEventListener( 'DOMContentLoaded', function () {
		blocks.registerCheckoutFilters( 'bileto', filters );
	} );

	// The button asks the label filter only when it re-renders, and the coupon panel renders its field
	// only once opened, so both are kept in step from DOM changes (cart loads, quantity edits, re-renders).
	var sync = function () {
		var text = opts.payLabel && document.querySelector( '.wc-block-components-checkout-place-order-button__text' );
		if ( text && text.textContent !== payLabel( text.textContent ) ) {
			text.textContent = payLabel( text.textContent );
		}
		var couponLabel = opts.couponLabel && document.querySelector( '.wc-block-components-totals-coupon label' );
		if ( couponLabel && couponLabel.textContent !== opts.couponLabel ) {
			couponLabel.textContent = opts.couponLabel;
		}
		var toggle = opts.coupon && document.querySelector( '.wc-block-components-totals-coupon .wc-block-components-panel__button[aria-expanded="false"]' );
		if ( toggle && ! toggle.dataset.biletoOpened ) {
			toggle.dataset.biletoOpened = '1';
			// Opening the panel focuses its input, which would scroll the page down to it on load.
			var x = window.scrollX;
			var y = window.scrollY;
			toggle.click();
			window.requestAnimationFrame( function () {
				var input = document.querySelector( '.wc-block-components-totals-coupon input' );
				if ( input && document.activeElement === input ) {
					input.blur();
				}
				window.scrollTo( x, y );
			} );
		}
	};
	if ( opts.payLabel || opts.coupon || opts.couponLabel ) {
		new MutationObserver( sync ).observe( document.body, { childList: true, subtree: true, characterData: true } );
		data.subscribe( sync, 'wc/store/cart' );
	}
} )();
