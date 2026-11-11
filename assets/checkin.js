/* Bileto check-in: camera scan (BarcodeDetector) or name search, one tap per guest. */
( function () {
	const { __ } = wp.i18n;
	const root = document.querySelector( '.bileto-checkin' );
	if ( ! root ) {
		return;
	}
	const video = root.querySelector( 'video' );
	const startBtn = root.querySelector( '.bileto-checkin__start' );
	const result = root.querySelector( '.bileto-checkin__result' );
	const count = root.querySelector( '.bileto-checkin__count' );
	const search = root.querySelector( '#bileto-search' );
	const list = root.querySelector( '.bileto-checkin__results' );
	const TEXT = {
		ok: __( 'Welcome', 'bileto' ),
		already: __( 'Already checked in', 'bileto' ),
		cancelled: __( 'Ticket cancelled', 'bileto' ),
		unknown: __( 'Unknown ticket', 'bileto' ),
	};
	let busy = false;
	let lastToken = '';
	let hideTimer;

	async function checkIn( token ) {
		if ( busy || token === lastToken ) {
			return;
		}
		busy = true;
		lastToken = token;
		try {
			const res = await wp.apiFetch( { path: '/bileto/v1/checkin', method: 'POST', data: { token } } );
			show( res );
		} catch ( e ) {
			show( { code: 'unknown', name: '', event: '', at: '' } );
		}
		busy = false;
	}

	function show( res ) {
		const good = res.code === 'ok';
		result.dataset.state = good ? 'ok' : 'error';
		result.querySelector( '.bileto-checkin__status' ).textContent = TEXT[ res.code ] || TEXT.unknown;
		result.querySelector( '.bileto-checkin__name' ).textContent = res.name || '';
		result.querySelector( '.bileto-checkin__detail' ).textContent =
			res.code === 'already' ? __( 'First entry today at', 'bileto' ) + ' ' + res.at : res.event || '';
		result.hidden = false;
		if ( res.stats ) {
			count.textContent = __( 'Checked in today:', 'bileto' ) + ' ' + res.stats.in + ' / ' + res.stats.total;
		}
		if ( navigator.vibrate ) {
			navigator.vibrate( good ? 80 : [ 80, 60, 80 ] );
		}
		clearTimeout( hideTimer );
		hideTimer = setTimeout( reset, 1200 );
	}

	function reset() {
		result.hidden = true;
		lastToken = '';
	}
	result.addEventListener( 'click', reset );

	startBtn.addEventListener( 'click', async () => {
		if ( ! ( 'BarcodeDetector' in window ) ) {
			startBtn.textContent = __( 'This browser cannot scan. Use the search below.', 'bileto' );
			startBtn.disabled = true;
			search.focus();
			return;
		}
		try {
			video.srcObject = await navigator.mediaDevices.getUserMedia( { video: { facingMode: 'environment' } } );
		} catch ( e ) {
			startBtn.textContent = __( 'No camera access. Use the search below.', 'bileto' );
			return;
		}
		await video.play();
		startBtn.hidden = true;
		root.classList.add( 'is-scanning' );
		const detector = new window.BarcodeDetector( { formats: [ 'qr_code' ] } );
		const tick = async () => {
			if ( ! result.hidden ) {
				return requestAnimationFrame( tick );
			}
			try {
				const codes = await detector.detect( video );
				const token = codes.length ? codes[ 0 ].rawValue.trim() : '';
				if ( /^[a-f0-9]{32}$/.test( token ) ) {
					await checkIn( token );
				}
			} catch ( e ) {}
			requestAnimationFrame( tick );
		};
		tick();
	} );

	let searchTimer;
	search.addEventListener( 'input', () => {
		clearTimeout( searchTimer );
		const term = search.value.trim();
		if ( term.length < 2 ) {
			list.replaceChildren();
			return;
		}
		searchTimer = setTimeout( async () => {
			const rows = await wp.apiFetch( { path: '/bileto/v1/tickets?search=' + encodeURIComponent( term ) } );
			list.replaceChildren(
				...rows.map( ( row ) => {
					const li = document.createElement( 'li' );
					const btn = document.createElement( 'button' );
					btn.type = 'button';
					btn.className = 'bileto-checkin__row';
					btn.disabled = row.status !== 'valid';
					btn.textContent = row.name + ( row.company ? ', ' + row.company : '' ) + ', ' + row.email;
					btn.addEventListener( 'click', () => checkIn( row.token ) );
					li.append( btn );
					return li;
				} )
			);
		}, 150 );
	} );
	root.querySelector( '.bileto-checkin__search' ).addEventListener( 'submit', ( e ) => e.preventDefault() );
} )();
