/*
 * [bileto_tickets]: quantity steppers bound to the WooCommerce cart data store.
 * existing-checked: assets/checkin.js - check-in scanner script, loaded only on the admin check-in screen.
 */
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
		if ( seats() === 0 ) {
			change( Number( pickers[ 0 ].querySelector( '.bileto-picker__row' ).dataset.product ), 1 );
		}
	}, store );
	data.select( store ).getCartData();
	paint();
} )();
