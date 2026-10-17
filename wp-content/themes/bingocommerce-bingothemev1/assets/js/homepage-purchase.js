/*
 * Optional purchase step for the selected Session (ADR-045 / BR-084 addition
 * 2026-10-02). Presentation only: the server rendered every Package, add-on,
 * price and setting, and decides every purchase rule again when it receives the
 * selection. This script reveals the hidden controls in their two-column
 * layout, keeps the selected-Package panel and the summary in step with the
 * chosen Package, filters that Package's add-on cards by name locally (hidden
 * cards keep their choices, quantities and place in the summary), and sends
 * that one selection to WooCommerce's existing cart-extension route with the
 * page's Store API nonce.
 *
 * It sends only the Session ID, Package ID, add-on relationship IDs and
 * quantities. It never retries a purchase except once after a confirmed nonce
 * rejection (which WooCommerce returns before any cart change), with the
 * identical captured request. It reports success only when the returned cart
 * contains exactly the captured selection, and it never removes, replaces or
 * reduces anything in the cart.
 */
( function () {
	'use strict';
	var form = document.querySelector( 'form[data-bingo-purchase]' );
	if ( ! form || 'function' !== typeof window.fetch || 'function' !== typeof window.Promise || ! window.JSON
		|| ! window.Element || ! Element.prototype.closest || 'function' !== typeof window.DOMParser ) { return; }

	var text = window.bingothemev1Purchase || {};
	var ID = /^[1-9][0-9]*$/;
	var QUANTITY = /^[1-9][0-9]*$/;
	var USABLE_NONCE = /^[^\s\x00-\x1F\x7F]{1,128}$/;
	var TIMEOUT = 30000;
	// Store API nonce rejections (AbstractCartRoute::check_nonce()); only these may be retried.
	var NONCE_REJECTIONS = { 401: 'woocommerce_rest_missing_nonce', 403: 'woocommerce_rest_invalid_nonce' };
	// Refusal codes the existing cart path returns (CustomerCart, CartSelection, CustomerCartCatalog,
	// ElectronicLimit and the Catalog reads it passes through), each with the theme's own wording and
	// its recovery links. bc_cart_selection_invalid is generic: it never says which rule failed.
	var REFUSALS = {
		bc_cart_package_limit: [ 'packageLimit', [ 'cart' ] ],
		bc_cart_selection_invalid: [ 'selectionInvalid', [ 'cart', 'refresh' ] ],
		bc_cart_addon_ineligible: [ 'addonIneligible', [ 'refresh' ] ],
		bc_electronic_limit_exceeded: [ 'electronicLimit', [] ],
		bc_cart_catalog_unavailable: [ 'unchecked', [ 'refresh' ] ],
		bc_electronic_limit_unavailable: [ 'unchecked', [ 'refresh' ] ],
		bc_electronic_faces_unknown: [ 'unchecked', [ 'refresh' ] ],
		invalid_hall_context: [ 'unchecked', [ 'refresh' ] ],
		catalog_schema_unavailable: [ 'unchecked', [ 'refresh' ] ],
		package_not_found: [ 'unchecked', [ 'refresh' ] ],
		persistence_failed: [ 'unchecked', [ 'refresh' ] ]
	};
	var CART_KEY = /^[a-f0-9]{32}$/;

	function int( value ) {
		if ( ! ID.test( String( value ) ) ) { return 0; }
		var number = Number( value );
		return Number.isSafeInteger( number ) ? number : 0;
	}
	function format( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var position = 0;
		return String( template || '' ).replace( /%(?:(\d+)\$)?([sd])/g, function ( match, index ) {
			var value = args[ index ? Number( index ) - 1 : position++ ];
			return undefined === value ? '' : String( value );
		} );
	}

	var config = {
		endpoint: form.getAttribute( 'data-endpoint' ) || '',
		cartEndpoint: form.getAttribute( 'data-cart-endpoint' ) || '',
		namespace: form.getAttribute( 'data-namespace' ) || '',
		cartUrl: form.getAttribute( 'data-cart-url' ) || '',
		refreshUrl: form.getAttribute( 'data-refresh-url' ) || '',
		sessionId: int( form.getAttribute( 'data-session-id' ) ),
		sessionName: form.getAttribute( 'data-session-name' ) || '',
		sessionWhen: form.getAttribute( 'data-session-when' ) || ''
	};
	var nonce = ( form.getAttribute( 'data-nonce' ) || '' ).trim();
	if ( ! config.endpoint || ! config.cartEndpoint || ! config.namespace || ! config.cartUrl || ! config.sessionId || ! USABLE_NONCE.test( nonce ) ) { return; }

	var block = form.closest( '[data-session-packages]' ) || form;
	var status = document.querySelector( '[data-purchase-status]' );
	var packagesFieldset = form.querySelector( '[data-purchase-packages]' );
	var radios = Array.prototype.slice.call( form.querySelectorAll( 'input[name="bc_package"]' ) );
	var panels = {};
	Array.prototype.forEach.call( form.querySelectorAll( '[data-addons-for]' ), function ( panel ) {
		panels[ panel.getAttribute( 'data-addons-for' ) ] = panel;
	} );
	var packageError = form.querySelector( '[data-purchase-package-error]' );
	var hint = form.querySelector( '[data-purchase-hint]' );
	var selectedName = form.querySelector( '[data-selected-name]' );
	var selectedMeta = form.querySelector( '[data-selected-meta]' );
	var selectedBadge = form.querySelector( '[data-selected-badge]' );
	var summaryPackage = form.querySelector( '[data-summary-package]' );
	var summaryAddons = form.querySelector( '[data-summary-addons]' );
	var submit = form.querySelector( '[data-purchase-submit]' );
	var viewCart = form.querySelector( '[data-purchase-view-cart]' );
	var result = form.querySelector( '[data-purchase-result]' );
	if ( ! radios.length || ! submit || ! result || ! summaryPackage || ! summaryAddons ) { return; }

	var busy = false;
	var completed = false;

	/* ---- Reveal: from here the cards are a purchase form, in its two-column layout. ---- */
	Array.prototype.forEach.call( form.querySelectorAll( '[data-purchase-reveal]' ), function ( element ) { element.hidden = false; } );
	radios.forEach( function ( radio ) { radio.disabled = ! int( radio.value ); } );
	var nojs = form.querySelector( '[data-purchase-nojs]' );
	if ( nojs ) { nojs.hidden = true; }
	form.classList.add( 'is-ready' );
	submit.disabled = false;

	function checkedRadio() {
		for ( var i = 0; i < radios.length; i++ ) {
			if ( radios[ i ].checked && ! radios[ i ].disabled ) { return radios[ i ]; }
		}
		return null;
	}
	function quantityInput( checkbox ) {
		var id = checkbox.getAttribute( 'data-quantity' );
		return id ? document.getElementById( id ) : null;
	}
	/* The typed quantity when it is a whole number, otherwise 0. */
	function typed( input ) {
		var raw = String( input.value ).trim();
		return QUANTITY.test( raw ) && Number.isSafeInteger( Number( raw ) ) ? Number( raw ) : 0;
	}
	/* Zero is the initial choice; the −/+ buttons stay between zero and the maximum. */
	function syncStepper( input ) {
		var stepper = input.closest( '.addon-card__stepper' );
		if ( ! stepper ) { return; }
		var max = int( input.getAttribute( 'max' ) ) || 1;
		var value = typed( input );
		Array.prototype.forEach.call( stepper.querySelectorAll( 'button[data-step]' ), function ( button ) {
			var down = '-1' === button.getAttribute( 'data-step' );
			button.disabled = input.disabled || ( down ? value <= 0 : value >= max );
		} );
	}
	/* One step from the typed value, clamped to 0…maximum; + from an unusable value starts at 1. */
	function step( button ) {
		var stepper = button.closest( '.addon-card__stepper' );
		var input = stepper ? stepper.querySelector( 'input[type="number"]' ) : null;
		if ( ! input || input.disabled ) { return; }
		var max = int( input.getAttribute( 'max' ) ) || 1;
		var value = typed( input );
		input.value = String( Math.max( 0, Math.min( max, Math.min( value, max ) + Number( button.getAttribute( 'data-step' ) ) ) ) );
		quantityChanged( input );
	}
	function cardOf( element ) {
		return element ? element.closest( '[data-addon-card]' ) : null;
	}

	/* Local name filtering inside one Package's panel. Hidden cards keep their choices and stay in the summary. */
	function fold( value ) {
		var folded = String( value || '' ).toLowerCase();
		if ( 'function' === typeof folded.normalize ) { folded = folded.normalize( 'NFKD' ).replace( /[\u0300-\u036f]/g, '' ); }
		return folded.replace( /\s+/g, ' ' ).trim();
	}
	function filter( search ) {
		var panel = search.closest( '[data-addons-for]' );
		if ( ! panel ) { return; }
		var query = fold( search.value );
		var shown = 0;
		Array.prototype.forEach.call( panel.querySelectorAll( '[data-addon-card]' ), function ( card ) {
			var checkbox = card.querySelector( 'input[data-addon-id]' );
			var match = '' === query || ( !! checkbox && -1 !== fold( checkbox.getAttribute( 'data-addon-name' ) ).indexOf( query ) );
			card.hidden = ! match;
			shown += match ? 1 : 0;
		} );
		var message = panel.querySelector( '[data-addons-no-match]' );
		if ( message ) {
			var value = shown ? '' : format( text.noMatches, search.value.replace( /\s+/g, ' ' ).trim() );
			if ( message.textContent !== value ) { message.textContent = value; }
		}
	}
	/* A choice that needs attention is never left hidden by the search: clear that panel's search first. */
	function uncover( element ) {
		var card = cardOf( element );
		var panel = card && card.hidden ? card.closest( '[data-addons-for]' ) : null;
		var search = panel ? panel.querySelector( '[data-addon-search]' ) : null;
		if ( search ) {
			search.value = '';
			filter( search );
		}
	}

	/* The selected-Package panel repeats the chosen card's own name, type and server-formatted price. */
	function renderSelected( chosen ) {
		var card = chosen ? chosen.closest( '.session-package' ) : null;
		if ( selectedName ) { selectedName.textContent = chosen ? ( chosen.getAttribute( 'data-package-name' ) || '' ) : ( text.noPackage || '' ); }
		if ( selectedMeta ) {
			selectedMeta.textContent = '';
			var type = card ? card.querySelector( '.session-package__type' ) : null;
			var price = priceOf( card );
			if ( type && '' !== type.textContent.trim() ) { selectedMeta.appendChild( document.createTextNode( type.textContent.trim() + ( price ? ' · ' : '' ) ) ); }
			if ( price ) { selectedMeta.appendChild( price ); }
			selectedMeta.hidden = ! card;
		}
		if ( selectedBadge ) { selectedBadge.hidden = ! chosen; }
	}

	/* Only the chosen Package's panel is shown and usable; every other panel keeps its own choices, disabled. */
	function applyState() {
		var chosen = checkedRadio();
		Object.keys( panels ).forEach( function ( id ) {
			var panel = panels[ id ];
			var active = !! chosen && chosen.value === id;
			panel.hidden = ! active;
			panel.disabled = ! active || busy || completed;
			Array.prototype.forEach.call( panel.querySelectorAll( 'input[data-addon-id]' ), function ( checkbox ) {
				var input = quantityInput( checkbox );
				if ( input ) {
					input.disabled = ! checkbox.checked;
					syncStepper( input );
				}
				var card = cardOf( checkbox );
				if ( card ) { card.classList.toggle( 'is-selected', checkbox.checked ); }
			} );
		} );
		radios.forEach( function ( radio ) {
			var card = radio.closest( '.session-package' );
			if ( card ) { card.classList.toggle( 'is-selected', radio === chosen ); }
		} );
		renderSelected( chosen );
		if ( hint ) { hint.hidden = !! chosen; }
		packagesFieldset.disabled = busy || completed;
	}

	/*
	 * The one reading of the current selection. Summary and request both come
	 * from it, and it reads only the chosen Package's panel.
	 */
	function selection() {
		var chosen = checkedRadio();
		if ( ! chosen ) { return null; }
		var panel = panels[ chosen.value ] || null;
		var picked = { packageId: int( chosen.value ), packageName: chosen.getAttribute( 'data-package-name' ) || '', state: panel ? panel.getAttribute( 'data-addons-state' ) : 'unavailable', addons: [], invalid: null, radio: chosen };
		if ( panel ) {
			Array.prototype.forEach.call( panel.querySelectorAll( 'input[data-addon-id]:checked' ), function ( checkbox ) {
				var max = int( checkbox.getAttribute( 'data-max' ) ) || 1;
				var input = quantityInput( checkbox );
				var raw = input ? String( input.value ).trim() : '0';
				var quantity = QUANTITY.test( raw ) && Number( raw ) <= max ? Number( raw ) : 0;
				var addon = { id: int( checkbox.getAttribute( 'data-addon-id' ) ), name: checkbox.getAttribute( 'data-addon-name' ) || '', quantity: quantity, max: max, input: input, checkbox: checkbox };
				if ( ( ! quantity || ! addon.id ) && ! picked.invalid ) { picked.invalid = addon; }
				picked.addons.push( addon );
			} );
		}
		return picked;
	}

	function priceOf( element ) {
		var price = element ? element.querySelector( '[data-purchase-price]' ) : null;
		return price ? price.cloneNode( true ) : null;
	}
	function line( content ) {
		var span = document.createElement( 'span' );
		span.className = 'bc-purchase__item';
		content.forEach( function ( part ) {
			if ( ! part ) { return; }
			span.appendChild( 'string' === typeof part ? document.createTextNode( part ) : part );
			span.appendChild( document.createTextNode( ' ' ) );
		} );
		return span;
	}
	function renderSummary() {
		var picked = selection();
		summaryPackage.textContent = '';
		summaryAddons.textContent = '';
		if ( ! picked ) {
			summaryPackage.textContent = text.notChosen || '';
			summaryAddons.textContent = text.none || '';
			return;
		}
		summaryPackage.appendChild( line( [ picked.packageName, priceOf( picked.radio.closest( '.session-package' ) ) ] ) );
		if ( ! picked.addons.length ) {
			summaryAddons.textContent = 'unavailable' === picked.state ? text.noneUnavailable : ( 'empty' === picked.state ? text.noneEmpty : text.none ) || '';
			return;
		}
		var list = document.createElement( 'ul' );
		list.className = 'bc-purchase__addon-list';
		picked.addons.forEach( function ( addon ) {
			var item = document.createElement( 'li' );
			item.appendChild( addon.quantity
				? line( [ format( text.addonQuantity, addon.name, addon.quantity ), priceOf( cardOf( addon.checkbox ) ), text.each ] )
				: line( [ format( text.checkQuantity, addon.name ) ] ) );
			list.appendChild( item );
		} );
		summaryAddons.appendChild( list );
	}

	/* ---- Messages ---- */
	function announce( message ) {
		if ( ! status ) { return; }
		status.textContent = '';
		window.setTimeout( function () { status.textContent = message; }, 60 );
	}
	function visible() {
		return ! block.hidden && null !== form.offsetParent;
	}
	function clearValidation() {
		if ( packageError ) { packageError.hidden = true; packageError.textContent = ''; }
		Array.prototype.forEach.call( form.querySelectorAll( '.addon-card__error' ), function ( error ) { error.hidden = true; error.textContent = ''; } );
		Array.prototype.forEach.call( form.querySelectorAll( '[aria-invalid="true"]' ), function ( input ) { input.removeAttribute( 'aria-invalid' ); } );
	}
	/* Client-side checks: the message is the control's description, read when it takes focus. */
	function invalid( element, control, message ) {
		element.textContent = message;
		element.hidden = false;
		if ( control ) {
			control.setAttribute( 'aria-invalid', 'true' );
			control.focus();
		}
	}
	function link( href, label ) {
		var anchor = document.createElement( 'a' );
		anchor.href = href;
		anchor.textContent = label;
		return anchor;
	}
	/* Server outcomes: one visible message and one announcement, never both by focus. */
	function report( kind, message, links, confirmation ) {
		result.textContent = '';
		result.className = 'bc-purchase__result is-' + kind;
		if ( 'success' === kind && confirmation ) {
			var icon = document.createElement( 'span' );
			icon.className = 'bc-purchase__success-icon';
			icon.setAttribute( 'aria-hidden', 'true' );
			result.appendChild( icon );
			var content = document.createElement( 'div' );
			content.className = 'bc-purchase__success-content';
			var title = document.createElement( 'strong' );
			title.className = 'bc-purchase__success-title';
			title.textContent = text.added || '';
			content.appendChild( title );
			var summary = document.createElement( 'p' );
			summary.className = 'bc-purchase__success-summary';
			summary.textContent = confirmation.summary;
			content.appendChild( summary );
			var guidance = document.createElement( 'p' );
			guidance.className = 'bc-purchase__success-guidance';
			guidance.textContent = text.locked || '';
			content.appendChild( guidance );
			result.appendChild( content );
			var dismiss = document.createElement( 'button' );
			dismiss.type = 'button';
			dismiss.className = 'bc-purchase__success-dismiss';
			dismiss.setAttribute( 'aria-label', text.dismissAdded || '' );
			dismiss.textContent = '×';
			dismiss.addEventListener( 'click', function () {
				result.hidden = true;
				if ( viewCart && visible() ) { viewCart.focus(); }
			} );
			result.appendChild( dismiss );
		} else {
			var paragraph = document.createElement( 'p' );
			paragraph.textContent = message;
			result.appendChild( paragraph );
		}
		if ( links && links.length ) {
			var actions = document.createElement( 'p' );
			actions.className = 'bc-purchase__links';
			links.forEach( function ( name ) {
				if ( 'cart' === name ) {
					var cart = link( config.cartUrl, text.viewCart || '' );
					cart.className = 'bc-purchase__cart-link';
					actions.appendChild( cart );
				}
				if ( 'refresh' === name && config.refreshUrl ) { actions.appendChild( link( config.refreshUrl, text.refresh || '' ) ); }
			} );
			result.appendChild( actions );
		}
		result.hidden = false;
		announce( message );
	}
	/* A server message, as plain text only: tags and entities never execute or render. */
	function plain( message ) {
		if ( 'string' !== typeof message ) { return ''; }
		var parsed = new DOMParser().parseFromString( message, 'text/html' );
		var value = ( parsed.body ? parsed.body.textContent : '' ).replace( /\s+/g, ' ' ).trim();
		return value.length > 400 ? '' : value;
	}

	/* ---- Requests ---- */
	function adopt( response ) {
		var value = response && response.headers ? response.headers.get( 'Nonce' ) : null;
		value = 'string' === typeof value ? value.trim() : '';
		if ( ! USABLE_NONCE.test( value ) ) { return false; }
		nonce = value;
		return true;
	}
	function request( url, init ) {
		var controller = 'function' === typeof window.AbortController ? new window.AbortController() : null;
		var timer = controller ? window.setTimeout( function () { controller.abort(); }, TIMEOUT ) : 0;
		init.credentials = 'same-origin';
		init.cache = 'no-store';
		if ( controller ) { init.signal = controller.signal; }
		return window.fetch( url, init ).then( function ( response ) {
			var adopted = adopt( response );
			return response.text().then( function ( body ) {
				var json = null;
				try { json = JSON.parse( body ); } catch ( error ) { json = null; }
				return { status: response.status, headers: response.headers, json: json, adopted: adopted };
			} );
		} ).then( function ( outcome ) {
			window.clearTimeout( timer );
			return outcome;
		}, function ( error ) {
			window.clearTimeout( timer );
			throw error;
		} );
	}
	function post( body ) {
		return request( config.endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', Nonce: nonce }, body: body } );
	}
	/* The same Hall's cart read, with the same session cookies; it needs no nonce. */
	function refresh() {
		return request( config.cartEndpoint, { method: 'GET', headers: { Accept: 'application/json' } } ).then( function ( outcome ) {
			return 200 === outcome.status && outcome.adopted;
		}, function () { return false; } );
	}
	function isObject( value ) {
		return !! value && 'object' === typeof value && ! Array.isArray( value );
	}
	/* Exactly 401 woocommerce_rest_missing_nonce or 403 woocommerce_rest_invalid_nonce. */
	function nonceRejected( outcome ) {
		return isObject( outcome.json ) && 'string' === typeof outcome.json.code
			&& Object.prototype.hasOwnProperty.call( NONCE_REJECTIONS, outcome.status ) && NONCE_REJECTIONS[ outcome.status ] === outcome.json.code;
	}
	/* HallCommerceGate::refuse(): 503, HTML, Retry-After and noindex. Nothing ran. */
	function hallRefused( outcome ) {
		var type = outcome.headers.get( 'Content-Type' ) || '';
		var robots = outcome.headers.get( 'X-Robots-Tag' ) || '';
		return 503 === outcome.status && 0 === type.indexOf( 'text/html' ) && !! outcome.headers.get( 'Retry-After' ) && -1 !== robots.indexOf( 'noindex' );
	}
	function count( value ) {
		return Number.isSafeInteger( value ) && value > 0;
	}
	/*
	 * One cart line's Bingo data as the existing Store API extension exposes it
	 * (CustomerCart::item_data(): session_id, package_id, addon_id, role and
	 * parent_key; an empty array for ordinary merchandise). null = ordinary line;
	 * false = a Bingo line that does not have that shape.
	 */
	function bingo( item ) {
		if ( ! isObject( item ) ) { return false; }
		if ( undefined === item.extensions ) { return null; }
		if ( ! isObject( item.extensions ) ) { return false; }
		var data = item.extensions[ config.namespace ];
		if ( undefined === data || ( Array.isArray( data ) && 0 === data.length ) ) { return null; }
		if ( ! isObject( data ) || ! count( data.session_id ) || ! count( data.package_id ) || ! Number.isSafeInteger( data.addon_id )
			|| 'string' !== typeof data.parent_key || ! count( item.quantity ) ) { return false; }
		if ( 'package' === data.role && 0 === data.addon_id && '' === data.parent_key ) { return data; }
		if ( 'addon' === data.role && data.addon_id > 0 && CART_KEY.test( data.parent_key ) ) { return data; }
		return false;
	}
	/*
	 * Success is only a WooCommerce cart holding exactly the captured selection:
	 * one Bingo Package line (quantity one, this Session and Package, a usable cart
	 * key), exactly the captured add-on relationships and quantities under it, each
	 * naming the same Session, Package and parent, and no other Bingo line of any
	 * kind. Ordinary merchandise may be there too.
	 */
	function confirmed( outcome, captured ) {
		if ( 200 !== outcome.status || ! isObject( outcome.json ) || ! Array.isArray( outcome.json.items ) ) { return false; }
		var packages = [];
		var children = [];
		for ( var i = 0; i < outcome.json.items.length; i++ ) {
			var item = outcome.json.items[ i ];
			var data = bingo( item );
			if ( false === data ) { return false; }
			if ( null === data ) { continue; }
			( 'package' === data.role ? packages : children ).push( { item: item, data: data } );
		}
		if ( 1 !== packages.length ) { return false; }
		var parent = packages[ 0 ];
		if ( config.sessionId !== parent.data.session_id || captured.packageId !== parent.data.package_id || 1 !== parent.item.quantity
			|| 'string' !== typeof parent.item.key || ! CART_KEY.test( parent.item.key ) || children.length !== captured.addons.length ) { return false; }
		var seen = {};
		for ( var j = 0; j < children.length; j++ ) {
			var child = children[ j ];
			if ( parent.item.key !== child.data.parent_key || config.sessionId !== child.data.session_id || captured.packageId !== child.data.package_id
				|| Object.prototype.hasOwnProperty.call( seen, child.data.addon_id ) ) { return false; }
			seen[ child.data.addon_id ] = child.item.quantity;
		}
		return captured.addons.every( function ( addon ) { return seen[ addon.id ] === addon.quantity; } );
	}

	/* ---- Submission ---- */
	function setBusy( value ) {
		busy = value;
		form.setAttribute( 'aria-busy', value ? 'true' : 'false' );
		submit.disabled = value || completed;
		submit.textContent = value ? ( text.adding || '' ) : ( text.addToCart || '' );
		applyState();
	}
	function succeed( captured ) {
		completed = true;
		var summary = captured.addons.length
			? format( 1 === captured.addons.length ? text.addedWithAddon : text.addedWithAddons, captured.packageName, captured.addons.length )
			: captured.packageName;
		var message = ( text.added || '' ) + '. ' + summary + '.';
		if ( captured.addons.length ) {
			message += ' ' + format( text.addedAddons, captured.addons.map( function ( addon ) { return format( text.addonQuantity, addon.name, addon.quantity ); } ).join( ', ' ) );
		}
		setBusy( false );
		submit.hidden = true;
		if ( viewCart ) { viewCart.hidden = false; }
		report( 'success', message + ' ' + ( text.locked || '' ), [], { summary: summary } );
		if ( viewCart && visible() ) { viewCart.focus(); }
		// The theme's existing header refresh (theme.js) re-renders the cart count.
		document.body.dispatchEvent( new window.CustomEvent( 'wc-blocks_added_to_cart', { bubbles: true } ) );
	}
	function fail( kind, message, links ) {
		setBusy( false );
		report( kind, message, links );
		if ( visible() ) { submit.focus(); }
	}
	/* A definite answer from the server or the Hall gate, or an uncertain one. */
	function settle( outcome, captured ) {
		if ( confirmed( outcome, captured ) ) { succeed( captured ); return; }
		if ( nonceRejected( outcome ) ) { fail( 'error', text.expired || '', [] ); return; }
		if ( hallRefused( outcome ) ) { fail( 'error', text.unavailable || '', [] ); return; }
		var code = isObject( outcome.json ) && 'string' === typeof outcome.json.code ? outcome.json.code : '';
		// 5xx, unexpected 2xx/3xx, non-JSON bodies and the extension route's own error (it can
		// follow a completed callback) leave the outcome unknown.
		if ( outcome.status >= 500 || outcome.status < 400 || ! code || 'woocommerce_rest_cart_extensions_error' === code ) {
			fail( 'error', text.uncertain || '', [ 'cart' ] );
			return;
		}
		if ( Object.prototype.hasOwnProperty.call( REFUSALS, code ) ) {
			fail( 'error', text[ REFUSALS[ code ][ 0 ] ] || text.fallback || '', REFUSALS[ code ][ 1 ] );
			return;
		}
		if ( 0 === code.indexOf( 'rest_' ) ) { fail( 'error', text.notSent || '', [ 'refresh' ] ); return; }
		// Any other refusal (WooCommerce's own, e.g. rate_limit_exceeded): its message, as text only.
		var message = plain( outcome.json.message );
		fail( 'error', message || text.fallback || '', [ 'cart', 'refresh' ] );
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		if ( busy || completed ) { return; }
		clearValidation();
		var picked = selection();
		if ( ! picked ) {
			invalid( packageError, radios[ 0 ], text.choosePackage || '' );
			return;
		}
		if ( picked.invalid ) {
			var error = picked.invalid.input ? document.getElementById( picked.invalid.input.id + '-error' ) : null;
			uncover( picked.invalid.checkbox );
			if ( error ) {
				invalid( error, picked.invalid.input, format( text.quantityRange, picked.invalid.name, picked.invalid.max ) );
			} else {
				report( 'error', text.notSent || '', [ 'refresh' ] );
			}
			return;
		}
		if ( ! picked.packageId ) {
			report( 'error', text.notSent || '', [ 'refresh' ] );
			return;
		}
		// Captured once: the request body, and every later message, describe this selection.
		var captured = Object.freeze( {
			packageId: picked.packageId,
			packageName: picked.packageName,
			addons: Object.freeze( picked.addons.map( function ( addon ) { return Object.freeze( { id: addon.id, name: addon.name, quantity: addon.quantity } ); } ) )
		} );
		var body = JSON.stringify( {
			namespace: config.namespace,
			data: {
				session_id: config.sessionId,
				package_id: captured.packageId,
				addons: captured.addons.map( function ( addon ) { return { package_addon_id: addon.id, quantity: addon.quantity }; } )
			}
		} );
		result.hidden = true;
		setBusy( true );
		post( body ).then( function ( first ) {
			if ( ! nonceRejected( first ) ) { return first; }
			return refresh().then( function ( renewed ) {
				return renewed ? post( body ) : first;
			} );
		} ).then( function ( outcome ) {
			settle( outcome, captured );
		}, function () {
			fail( 'error', text.uncertain || '', [ 'cart' ] );
		} );
	} );

	/* A typed or stepped quantity: its own message clears, the buttons follow, the summary updates. */
	function quantityChanged( input ) {
		var error = document.getElementById( input.id + '-error' );
		if ( error && ! error.hidden ) { error.hidden = true; error.textContent = ''; input.removeAttribute( 'aria-invalid' ); }
		syncStepper( input );
		renderSummary();
	}

	form.addEventListener( 'change', function ( event ) {
		if ( busy || completed ) { return; }
		if ( 'bc_package' === event.target.name ) { clearValidation(); }
		if ( event.target.matches( 'input[data-addon-id]' ) ) {
			var input = quantityInput( event.target );
			if ( input ) {
				var error = document.getElementById( input.id + '-error' );
				if ( error ) { error.hidden = true; error.textContent = ''; }
				input.removeAttribute( 'aria-invalid' );
			}
		}
		applyState();
		renderSummary();
	} );
	form.addEventListener( 'input', function ( event ) {
		if ( busy || completed ) { return; }
		if ( event.target.matches( '[data-addon-search]' ) ) { filter( event.target ); return; }
		if ( event.target.matches( '.addon-card__qty' ) ) { quantityChanged( event.target ); }
	} );
	// Some browsers clear a search field (its clear button, Escape) with only a `search` event.
	form.addEventListener( 'search', function ( event ) {
		if ( ! busy && ! completed && event.target.matches( '[data-addon-search]' ) ) { filter( event.target ); }
	} );
	// Enter in the search field filters; it never submits the selection.
	form.addEventListener( 'keydown', function ( event ) {
		if ( 'Enter' === event.key && event.target.matches( '[data-addon-search]' ) ) { event.preventDefault(); }
	} );
	form.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( 'button[data-step]' ) : null;
		if ( ! button || ! form.contains( button ) || busy || completed || button.disabled ) { return; }
		event.preventDefault();
		step( button );
	} );

	// A browser may restore earlier field values on reload or back navigation: start from them.
	Array.prototype.forEach.call( form.querySelectorAll( '[data-addon-search]' ), function ( search ) { filter( search ); } );
	applyState();
	renderSummary();
}() );
