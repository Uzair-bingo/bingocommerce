/**
 * BingoThemev1 — header behaviour only: sticky-header state, the accessible
 * mobile menu, and keeping the header cart count in step with WooCommerce.
 * No purchase logic.
 */
( function () {
	'use strict';

	var header = document.getElementById( 'siteHeader' );
	var toggle = document.getElementById( 'menuToggle' );
	var nav = document.getElementById( 'mainNav' );
	var backdrop = document.getElementById( 'mobileNavBackdrop' );
	var mobile = window.matchMedia( '(max-width: 860px)' );

	/* ---- Header shadow once the page scrolls ---- */
	if ( header ) {
		var onScroll = function () {
			header.classList.toggle( 'is-scrolled', window.scrollY > 8 );
		};
		onScroll();
		window.addEventListener( 'scroll', onScroll, { passive: true } );
	}

	/* ---- Mobile menu ---- */
	if ( toggle && nav && backdrop ) {
		var isOpen = function () {
			return nav.classList.contains( 'is-open' );
		};
		var setOpen = function ( open, returnFocus ) {
			nav.classList.toggle( 'is-open', open );
			toggle.classList.toggle( 'is-open', open );
			toggle.setAttribute( 'aria-expanded', String( open ) );
			toggle.setAttribute( 'aria-label', toggle.getAttribute( open ? 'data-label-close' : 'data-label-open' ) );
			backdrop.hidden = ! open;
			document.body.classList.toggle( 'has-open-nav', open );
			if ( open ) {
				var first = nav.querySelector( 'a' );
				if ( first ) {
					// Wait for the panel to become visible before moving focus into it.
					window.setTimeout( function () { first.focus(); }, 50 );
				}
			} else if ( returnFocus ) {
				toggle.focus();
			}
		};

		toggle.addEventListener( 'click', function () {
			setOpen( ! isOpen(), false );
		} );
		backdrop.addEventListener( 'click', function () {
			setOpen( false, true );
		} );
		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && isOpen() ) {
				setOpen( false, true );
			}
		} );
		// Close when keyboard focus leaves the open panel for the rest of the page.
		nav.addEventListener( 'focusout', function ( event ) {
			var next = event.relatedTarget;
			if ( mobile.matches && isOpen() && next && ! nav.contains( next ) && next !== toggle ) {
				setOpen( false, false );
			}
		} );
		var reset = function () {
			if ( ! mobile.matches && isOpen() ) {
				setOpen( false, false );
			}
		};
		if ( mobile.addEventListener ) {
			mobile.addEventListener( 'change', reset );
		}
	}

	/* ---- Header cart count ---- */
	var setCount = function ( count ) {
		var links = document.querySelectorAll( '.bc-cart-link' );
		Array.prototype.forEach.call( links, function ( link ) {
			var badge = link.querySelector( '.cart-count' );
			if ( badge ) {
				badge.textContent = String( count );
			}
			var label = link.getAttribute( 1 === count ? 'data-label-one' : 'data-label-many' );
			if ( label ) {
				link.setAttribute( 'aria-label', label.replace( '%d', String( count ) ) );
			}
		} );
	};

	// Ask WooCommerce's cart-fragments script to re-render the header link and
	// refresh its stored copy, so the next page load does not show a stale count.
	var refreshTimer = null;
	var refreshFragments = function () {
		window.clearTimeout( refreshTimer );
		refreshTimer = window.setTimeout( function () {
			if ( window.jQuery ) {
				window.jQuery( document.body ).trigger( 'wc_fragment_refresh' );
			}
		}, 300 );
	};

	// Cart and Checkout blocks change the cart through the Store API rather than
	// the classic AJAX events, so follow their data store directly.
	var followBlockCart = function () {
		var data = window.wp && window.wp.data;
		if ( ! data || 'function' !== typeof data.select || 'function' !== typeof data.subscribe ) {
			return false;
		}
		var store;
		try {
			store = data.select( 'wc/store/cart' );
		} catch ( error ) {
			return false;
		}
		if ( ! store || 'function' !== typeof store.getCartData ) {
			return false;
		}
		var last = null;
		data.subscribe( function () {
			if ( 'function' === typeof store.hasFinishedResolution && ! store.hasFinishedResolution( 'getCartData' ) ) {
				return;
			}
			var cart = store.getCartData();
			if ( ! cart || 'number' !== typeof cart.itemsCount ) {
				return;
			}
			if ( null === last ) {
				last = cart.itemsCount;
				return;
			}
			if ( cart.itemsCount !== last ) {
				last = cart.itemsCount;
				setCount( cart.itemsCount );
				refreshFragments();
			}
		} );
		return true;
	};

	document.body.addEventListener( 'wc-blocks_added_to_cart', refreshFragments );
	document.body.addEventListener( 'wc-blocks_removed_from_cart', refreshFragments );

	if ( 'complete' === document.readyState ) {
		followBlockCart();
	} else {
		window.addEventListener( 'load', followBlockCart );
	}
}() );
