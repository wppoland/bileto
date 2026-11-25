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
	var busy = false;

	function items() {
		return data.select( store ).getCartData().items || [];
	}
	function itemFor( id ) {
		return items().find( function ( item ) { return item.id === id; } );
	}
	function seats() {
		return items().reduce( function ( sum, item ) { return sum + item.quantity; }, 0 );
	}

	function paint() {
		var total = seats();
		document.querySelectorAll( '.bileto-picker__row' ).forEach( function ( row ) {
			var item = itemFor( Number( row.dataset.product ) );
			var qty = item ? item.quantity : 0;
			var count = row.querySelector( '.bileto-picker__count' );
			if ( count.textContent !== String( qty ) ) {
				count.textContent = qty;
				count.classList.remove( 'is-bumped' );
				void count.offsetWidth; // Restart the bump animation.
				count.classList.add( 'is-bumped' );
			}
			row.classList.toggle( 'is-selected', qty > 0 );
			// The last seat stays: the Checkout block shows no form for an empty cart.
			row.querySelector( '[data-step="-1"]' ).disabled = busy || qty === 0 || total <= 1;
			row.querySelector( '[data-step="1"]' ).disabled = busy || qty >= Number( row.dataset.max );
		} );
	}

	function run( promise ) {
		busy = true;
		paint();
		Promise.resolve( promise ).catch( function () {} ).then( function () {
			busy = false;
			paint();
		} );
	}

	function change( id, step ) {
		var item = itemFor( id );
		var actions = data.dispatch( store );
		if ( ! item ) {
			return run( actions.addItemToCart( id, 1 ) );
		}
		var qty = item.quantity + step;
		run( qty > 0 ? actions.changeCartItemQuantity( item.key, qty ) : actions.removeItemFromCart( item.key ) );
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
	var started = false;
	var stop = data.subscribe( function () {
		if ( started || ! data.select( store ).hasFinishedResolution( 'getCartData' ) ) {
			return;
		}
		started = true;
		stop();
		if ( seats() === 0 && ( ! window.biletoCheckout || window.biletoCheckout.preselect ) ) {
			change( Number( pickers[ 0 ].querySelector( '.bileto-picker__row' ).dataset.product ), 1 );
		}
	}, store );
	data.select( store ).getCartData();
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
