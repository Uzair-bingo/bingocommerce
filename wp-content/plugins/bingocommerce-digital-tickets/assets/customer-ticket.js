/* Uses WooCommerce's bundled QRCode encoder; all pixels stay in the browser. */
( function () {
	'use strict';
	// Waiting checks ask the read-only ticket-status route about this one order. They never write anything.
	// The first check runs as the page opens; each next one 2 s after the previous one finished.
	var ATTEMPTS = 6, DELAY = 2000, TIMEOUT = 10000;
	var SCOPE = /^([1-9][0-9]*):([1-9][0-9]*)$/, REFERENCE = /^BC1\.([1-9][0-9]*)\.[a-f0-9]{64}$/;
	var MESSAGES = { payment: 'statePayment', ticket: 'stateTicket', unavailable: 'stateUnavailable' };

	function draw( root ) {
		root.querySelectorAll( 'canvas[data-bc-ticket]' ).forEach( function ( canvas ) {
			if ( canvas.dataset.bcRendered ) { return; }
			try {
				var qr = new QRCode( -1, QRErrorCorrectLevel.M );
				qr.addData( canvas.dataset.bcTicket );
				qr.make();
				var modules = qr.getModuleCount(), quiet = 4, scale = 6;
				canvas.width = canvas.height = ( modules + quiet * 2 ) * scale;
				var context = canvas.getContext( '2d' );
				context.fillStyle = '#ffffff';
				context.fillRect( 0, 0, canvas.width, canvas.height );
				context.fillStyle = '#000000';
				for ( var row = 0; row < modules; row++ ) {
					for ( var column = 0; column < modules; column++ ) {
						if ( qr.isDark( row, column ) ) { context.fillRect( ( column + quiet ) * scale, ( row + quiet ) * scale, scale, scale ); }
					}
				}
				canvas.dataset.bcRendered = 'true';
			} catch ( error ) {
				// Do not log the bearer reference. A failed render must not show a blank QR.
				canvas.hidden = true;
			}
		} );
	}

	function text( key ) {
		var strings = window.bcCustomerTicket;
		return strings && 'string' === typeof strings[ key ] ? strings[ key ] : '';
	}

	function element( tag, className ) {
		var node = document.createElement( tag );
		if ( className ) { node.className = className; }
		return node;
	}

	/** A small loading sign; screen readers get the text beside it instead. */
	function spinner() {
		var node = element( 'span', 'bc-ticket-spinner' );
		node.setAttribute( 'aria-hidden', 'true' );
		return node;
	}

	/**
	 * The status route, only on this page's own origin and only where a
	 * timed-out request can be cancelled; otherwise the page offers reloading.
	 */
	function endpoint( value ) {
		if ( ! value || ! window.fetch || ! window.Promise || ! window.AbortController ) { return null; }
		try {
			var url = new URL( value, window.location.href );
			return url.origin === window.location.origin && /^https?:$/.test( url.protocol ) ? url.href : null;
		} catch ( error ) { return null; }
	}

	function store() {
		try {
			var storage = window.sessionStorage, probe = 'bc-ticket-wait:probe';
			storage.setItem( probe, '1' );
			storage.removeItem( probe );
			return storage;
		} catch ( error ) { return null; }
	}

	/** One status answer, used only when it is well formed and for this exact Hall and order. */
	function read( data, scope ) {
		if ( ! data || 'object' !== typeof data || data.scope !== scope ) { return { kind: 'unexpected' }; }
		if ( 'ready' === data.state ) {
			var match = 'string' === typeof data.reference ? REFERENCE.exec( data.reference ) : null;
			return match && match[ 1 ] === SCOPE.exec( scope )[ 1 ] ? { kind: 'ready', reference: match[ 0 ] } : { kind: 'unexpected' };
		}
		if ( 'closed' === data.state ) { return { kind: 'closed' }; }
		if ( ! Object.prototype.hasOwnProperty.call( MESSAGES, data.state ) ) { return { kind: 'unexpected' }; }
		return { kind: 'unavailable' === data.state ? 'unavailable' : 'waiting', state: data.state };
	}

	/** One request to the status route; settles as 'error' on any failure or after the timeout. */
	function request( target, scope ) {
		return new Promise( function ( resolve ) {
			var controller = new window.AbortController(), settled = false, timer;
			function finish( result ) {
				if ( settled ) { return; }
				settled = true;
				window.clearTimeout( timer );
				// Release the request: cancels a timed-out request or a response body this answer did not read.
				controller.abort();
				resolve( result );
			}
			timer = window.setTimeout( function () { finish( { kind: 'error' } ); }, TIMEOUT );
			var headers = { Accept: 'application/json', 'Content-Type': 'application/json' };
			if ( target.nonce ) { headers[ 'X-WP-Nonce' ] = target.nonce; }
			// The credential travels only in the body, never in a URL, and no Referer is sent. A redirect is never
			// followed (it would resend the body elsewhere, or load an order page): it fails like any other request.
			window.fetch( target.url, { method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error', referrerPolicy: 'no-referrer', headers: headers, body: JSON.stringify( { credential: target.credential } ), signal: controller.signal } )
				.then( function ( response ) {
					// Refused or invalid: the same request can never succeed from this page.
					if ( 400 === response.status || 401 === response.status || 403 === response.status ) { return { kind: 'denied' }; }
					if ( ! response.ok ) { return { kind: 'error' }; }
					if ( ( response.url && new URL( response.url ).origin !== window.location.origin ) || ! /^application\/json\b/i.test( response.headers.get( 'Content-Type' ) || '' ) ) { return { kind: 'unexpected' }; }
					return response.json().then( function ( data ) { return read( data, scope ); }, function () { return { kind: 'unexpected' }; } );
				} )
				.then( finish, function () { finish( { kind: 'error' } ); } );
		} );
	}

	function wait( section ) {
		if ( section.bcTicketWait ) { return; }
		section.bcTicketWait = true;
		var scope = section.getAttribute( 'data-bc-ticket-scope' ) || '', message = section.querySelector( '[data-bc-ticket-message]' );
		if ( ! SCOPE.test( scope ) || ! message || ! text( 'checkAgain' ) ) { return; }
		var url = endpoint( section.getAttribute( 'data-bc-ticket-status' ) ), credential = section.getAttribute( 'data-bc-ticket-credential' ) || '';
		var target = url && credential ? { url: url, credential: credential, nonce: section.getAttribute( 'data-bc-ticket-nonce' ) || '' } : null;
		var storage = store(), key = 'bc-ticket-wait:' + scope;
		var state = section.getAttribute( 'data-bc-ticket-wait' ), timer = 0, due = false, busy = false, active = false, failed = false;
		var checking = element( 'p' ), guidance = element( 'p' ), action = element( 'p' ), button = element( 'button', 'button' ), status = element( 'p', 'screen-reader-text' );
		var working = spinner();
		// The loading sign shows beside the checking note while automatic checks run, and beside Check again during a manual check.
		checking.appendChild( spinner() );
		checking.appendChild( document.createTextNode( text( 'checking' ) ) );
		working.hidden = true;
		button.type = 'button';
		button.textContent = text( 'checkAgain' );
		status.setAttribute( 'role', 'status' );
		status.setAttribute( 'aria-live', 'polite' );
		checking.hidden = guidance.hidden = action.hidden = true;
		action.appendChild( button );
		action.appendChild( working );
		[ checking, guidance, action, status ].forEach( function ( node ) { section.appendChild( node ); } );

		function announce( value ) {
			// Cleared first so a repeated result of a manual check is still read out.
			status.textContent = '';
			window.setTimeout( function () { status.textContent = value; }, 100 );
		}
		/** Automatic attempts used in this tab for this Hall's order; null when storage is unusable. */
		function used() {
			try {
				var value = storage.getItem( key );
				return null === value ? 0 : ( /^[0-6]$/.test( value ) ? Number( value ) : ATTEMPTS );
			} catch ( error ) { return null; }
		}
		function record( value ) {
			try { storage.setItem( key, String( value ) ); return true; } catch ( error ) { return false; }
		}
		/**
		 * Order Received's thank-you line: "confirming your payment" while it is
		 * pending, WooCommerce's own line once confirmed or closed. Only the two
		 * lines the server wrote for this Hall and order are switched.
		 */
		function notice( confirming ) {
			var lines = document.querySelectorAll( '[data-bc-ticket-notice]' );
			Array.prototype.forEach.call( lines, function ( line ) {
				if ( line.getAttribute( 'data-bc-ticket-for' ) !== scope ) { return; }
				line.hidden = ( 'payment' === line.getAttribute( 'data-bc-ticket-notice' ) ) !== confirming;
			} );
		}
		/** No further checks from this page: drop what they needed. */
		function forget() {
			[ 'data-bc-ticket-status', 'data-bc-ticket-credential', 'data-bc-ticket-nonce' ].forEach( function ( name ) { section.removeAttribute( name ); } );
		}
		/** Check again is being hidden: keep a keyboard user's focus in this section, on its heading, as show() does. */
		function refocus( focused ) {
			var heading = section.querySelector( 'h2' );
			if ( ! focused || ! action.hidden || ! heading ) { return; }
			heading.setAttribute( 'tabindex', '-1' );
			heading.focus( { preventScroll: true } );
		}
		/** Stop automatic checks with guidance; Check again stays available unless it cannot help. */
		function stop( reason, prefix, say, offer ) {
			var focused = action.contains( document.activeElement );
			active = due = false;
			window.clearTimeout( timer );
			checking.hidden = true;
			guidance.textContent = text( target ? reason : 'reload' );
			guidance.hidden = false;
			action.hidden = ! target || false === offer;
			refocus( focused );
			if ( say ) { announce( ( prefix ? prefix + ' ' : '' ) + guidance.textContent ); }
		}
		/** The order can no longer show a ticket here (cancelled, refunded, voided or otherwise closed). */
		function close() {
			var focused = action.contains( document.activeElement );
			active = due = false;
			window.clearTimeout( timer );
			checking.hidden = guidance.hidden = action.hidden = true;
			state = 'closed';
			section.setAttribute( 'data-bc-ticket-wait', state );
			forget();
			notice( false );
			message.textContent = text( 'closed' );
			refocus( focused );
			announce( message.textContent );
		}
		/** One automatic check now, or as soon as the tab is visible again. */
		function run() {
			if ( ! active ) { return; }
			if ( document.hidden ) { due = true; return; }
			check( true );
		}
		function schedule() {
			timer = window.setTimeout( run, DELAY );
		}
		function show( result ) {
			active = due = false;
			window.clearTimeout( timer );
			var root = document.scrollingElement || document.documentElement, before = section.getBoundingClientRect();
			var focused = section.contains( document.activeElement ), anchoring = root.style.overflowAnchor;
			// Measured directly rather than left to (or doubled by) browser scroll anchoring.
			root.style.overflowAnchor = 'none';
			Array.prototype.slice.call( section.childNodes ).forEach( function ( node ) { if ( node !== status ) { section.removeChild( node ); } } );
			section.removeAttribute( 'data-bc-ticket-wait' );
			forget();
			notice( false );
			var heading = element( 'h2' ), intro = element( 'p' ), canvas = element( 'canvas' );
			heading.textContent = text( 'heading' );
			intro.textContent = text( 'intro' );
			canvas.width = canvas.height = 296;
			canvas.setAttribute( 'data-bc-ticket', result.reference );
			canvas.setAttribute( 'role', 'img' );
			canvas.setAttribute( 'aria-label', text( 'label' ) );
			[ heading, intro, canvas ].forEach( function ( node ) { section.insertBefore( node, status ); } );
			// What the QR is for, exactly as the server shows it with a saved ticket.
			if ( text( 'validLead' ) && text( 'validDetail' ) ) {
				var validity = element( 'p' ), lead = element( 'strong' );
				validity.setAttribute( 'data-bc-ticket-validity', '' );
				lead.textContent = text( 'validLead' );
				validity.appendChild( lead );
				validity.appendChild( document.createTextNode( ' ' + text( 'validDetail' ) ) );
				section.insertBefore( validity, status );
			}
			draw( section );
			// Scrolled past the section's start: keep the content below it where it was.
			if ( before.top < 0 ) { window.scrollBy( 0, section.getBoundingClientRect().bottom - before.bottom ); }
			root.style.overflowAnchor = anchoring;
			try { storage.removeItem( key ); } catch ( error ) { /* Nothing to clear without storage. */ }
			if ( focused ) {
				heading.setAttribute( 'tabindex', '-1' );
				heading.focus( { preventScroll: true } );
			}
			announce( text( 'ready' ) );
		}
		function settle( result, automatic, attempt ) {
			if ( 'ready' === result.kind ) { show( result ); return; }
			if ( 'closed' === result.kind ) { close(); return; }
			if ( 'waiting' === result.kind || 'unavailable' === result.kind ) {
				var changed = result.state !== state;
				failed = false;
				if ( changed ) {
					state = result.state;
					section.setAttribute( 'data-bc-ticket-wait', state );
					message.textContent = text( MESSAGES[ state ] );
					if ( 'unavailable' !== state ) { notice( 'payment' === state ); }
				}
				var said = changed ? message.textContent : '';
				if ( 'unavailable' === state ) { stop( 'manual', said, true ); }
				else if ( ! automatic ) { stop( 'still', said, true ); }
				else if ( attempt >= ATTEMPTS ) { stop( 'exhausted', said, true ); }
				else {
					if ( changed ) { announce( said ); }
					schedule();
				}
				return;
			}
			// A refused credential cannot succeed on another try from this page.
			if ( 'denied' === result.kind ) { stop( 'denied', '', true, false ); forget(); return; }
			if ( 'unexpected' === result.kind ) { stop( 'unexpected', '', true ); return; }
			// A failed request, an HTTP error or the timeout proves nothing about the order.
			failed = true;
			if ( automatic && attempt < ATTEMPTS ) { schedule(); return; }
			stop( 'network', '', true );
		}
		function check( automatic ) {
			if ( busy || ! target ) { return; }
			var attempt = 0;
			if ( automatic ) {
				var count = used();
				if ( null === count ) { stop( 'storage', '', true ); return; }
				if ( count >= ATTEMPTS ) { stop( failed ? 'network' : 'exhausted', '', true ); return; }
				attempt = count + 1;
				// Counted before the request, so a reload during it still uses the attempt.
				if ( ! record( attempt ) ) { stop( 'storage', '', true ); return; }
			} else {
				// aria-disabled, not disabled: a focused button keeps keyboard focus.
				button.setAttribute( 'aria-disabled', 'true' );
				button.textContent = text( 'checkingNow' );
				working.hidden = false;
			}
			busy = true;
			request( target, scope ).then( function ( result ) {
				busy = false;
				working.hidden = true;
				button.removeAttribute( 'aria-disabled' );
				button.textContent = text( 'checkAgain' );
				settle( result, automatic, attempt );
			} );
		}

		button.addEventListener( 'click', function () {
			if ( ! busy && ! active ) { check( false ); }
		} );
		document.addEventListener( 'visibilitychange', function () {
			if ( ! document.hidden && due && active && ! busy ) {
				due = false;
				check( true );
			}
		} );
		if ( 'unavailable' === state ) { stop( 'manual', '', false ); return; }
		if ( ! target ) { stop( 'reload', '', false ); return; }
		var spent = storage ? used() : null;
		if ( null === spent ) { stop( 'storage', '', false ); return; }
		if ( spent >= ATTEMPTS ) { stop( 'exhausted', '', false ); return; }
		active = true;
		checking.hidden = false;
		run();
	}

	function init() {
		draw( document );
		document.querySelectorAll( 'section.bc-digital-ticket[data-bc-ticket-wait]' ).forEach( wait );
	}
	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', init ); }
	else { init(); }
}() );
