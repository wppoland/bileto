/* Bileto check-in: camera scan (BarcodeDetector) or name search, one tap per guest. */
( function () {
	const { __, sprintf } = wp.i18n;
	const root = document.querySelector( '.bileto-checkin[data-event]' );
	if ( ! root || root.dataset.event === '0' ) {
		return;
	}
	const event = Number( root.dataset.event );
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
		other_event: __( 'Ticket for another event', 'bileto' ),
	};
	let busy = false;
	let lastKey = '';
	let hideTimer;

	async function checkIn( data ) {
		const key = JSON.stringify( data );
		if ( busy || key === lastKey ) {
			return;
		}
		busy = true;
		lastKey = key;
		try {
			const res = await wp.apiFetch( { path: '/bileto/v1/checkin', method: 'POST', data: { event, ...data } } );
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
			/* translators: %s: time of the first entry */
			res.code === 'already' ? sprintf( __( 'First entry today at %s', 'bileto' ), res.at ) : res.event || '';
		result.hidden = false;
		if ( res.stats ) {
			/* translators: 1: checked in today, 2: valid tickets */
			count.textContent = sprintf( __( 'Checked in today: %1$d of %2$d', 'bileto' ), res.stats.in, res.stats.total );
		}
		if ( navigator.vibrate ) {
			navigator.vibrate( good ? 80 : [ 80, 60, 80 ] );
		}
		clearTimeout( hideTimer );
		hideTimer = setTimeout( reset, 1200 );
	}

	function reset() {
		result.hidden = true;
		lastKey = '';
	}
	result.addEventListener( 'click', reset );

	// Safari (iPhone, Mac) has no BarcodeDetector: decode frames with jsQR, loaded only then.
	function loadJsQr() {
		return new Promise( ( resolve, reject ) => {
			if ( window.jsQR ) {
				return resolve( window.jsQR );
			}
			const script = document.createElement( 'script' );
			script.src = root.dataset.jsqr;
			script.onload = () => resolve( window.jsQR );
			script.onerror = reject;
			document.head.append( script );
		} );
	}

	async function makeDetector() {
		if ( 'BarcodeDetector' in window ) {
			const native = new window.BarcodeDetector( { formats: [ 'qr_code' ] } );
			return async () => {
				const codes = await native.detect( video );
				return codes.length ? codes[ 0 ].rawValue : '';
			};
		}
		const jsQR = await loadJsQr();
		const canvas = document.createElement( 'canvas' );
		const ctx = canvas.getContext( '2d', { willReadFrequently: true } );
		return async () => {
			const scale = Math.min( 1, 640 / ( video.videoWidth || 640 ) );
			canvas.width = Math.round( video.videoWidth * scale );
			canvas.height = Math.round( video.videoHeight * scale );
			if ( ! canvas.width ) {
				return '';
			}
			ctx.drawImage( video, 0, 0, canvas.width, canvas.height );
			const frame = ctx.getImageData( 0, 0, canvas.width, canvas.height );
			const found = jsQR( frame.data, frame.width, frame.height, { inversionAttempts: 'dontInvert' } );
			return found ? found.data : '';
		};
	}

	startBtn.addEventListener( 'click', async () => {
		let detect;
		try {
			detect = await makeDetector();
			video.srcObject = await navigator.mediaDevices.getUserMedia( { video: { facingMode: 'environment' } } );
		} catch ( e ) {
			startBtn.textContent = __( 'No camera access. Use the search below.', 'bileto' );
			search.focus();
			return;
		}
		await video.play();
		startBtn.hidden = true;
		root.classList.add( 'is-scanning' );
		const tick = async () => {
			if ( result.hidden ) {
				try {
					const code = ( await detect() ).trim();
					if ( /^[a-f0-9]{32}$/.test( code ) ) {
						await checkIn( { code } );
					}
				} catch ( e ) {}
			}
			setTimeout( () => requestAnimationFrame( tick ), 120 );
		};
		tick();
	} );

	let searchTimer;
	search.addEventListener( 'input', () => {
		clearTimeout( searchTimer );
		const term = search.value.trim();
		if ( term.length < 3 ) {
			list.replaceChildren();
			return;
		}
		searchTimer = setTimeout( async () => {
			const rows = await wp.apiFetch( { path: '/bileto/v1/tickets?event=' + event + '&search=' + encodeURIComponent( term ) } );
			list.replaceChildren(
				...rows.map( ( row ) => {
					const li = document.createElement( 'li' );
					const btn = document.createElement( 'button' );
					btn.type = 'button';
					btn.className = 'bileto-checkin__row';
					btn.disabled = row.status !== 'valid';
					btn.textContent = row.name + ( row.company ? ', ' + row.company : '' ) + ', ' + row.email;
					btn.addEventListener( 'click', () => checkIn( { ticket_id: row.id } ) );
					li.append( btn );
					return li;
				} )
			);
		}, 150 );
	} );
	root.querySelector( '.bileto-checkin__search' ).addEventListener( 'submit', ( e ) => e.preventDefault() );
} )();
