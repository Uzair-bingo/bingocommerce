/* Presentation only. Links navigate normally; this script never calls a cart API. */
( function () {
	'use strict';
	document.querySelectorAll( '[data-homepage-slider]' ).forEach( function ( slider ) {
		var slides = Array.from( slider.querySelectorAll( '[data-slide]' ) );
		slider.querySelectorAll( '.bc-home-slider__image' ).forEach( function ( image ) {
			function failed() { image.hidden = true; }
			image.addEventListener( 'error', failed );
			if ( image.complete && image.naturalWidth === 0 ) { failed(); }
		} );
		if ( slides.length < 2 ) { return; }
		var dots = Array.from( slider.querySelectorAll( '[data-dot]' ) );
		var rotation = slider.querySelector( '[data-rotation]' );
		var status = slider.querySelector( '[data-slider-status]' );
		var motion = window.matchMedia( '(prefers-reduced-motion: reduce)' );
		var index = 0;
		var timer = null;
		var paused = motion.matches || slider.contains( document.activeElement );
		var hovered = slider.matches( ':hover' );
		var pointerPaused = null;
		function canRotate() { return ! paused && ! hovered && ! document.hidden && ! motion.matches; }

		function schedule() {
			window.clearTimeout( timer );
			rotation.hidden = motion.matches;
			rotation.textContent = paused ? rotation.dataset.resume : rotation.dataset.pause;
			if ( canRotate() ) {
				timer = window.setTimeout( function () { if ( canRotate() ) { show( index + 1, false ); } }, 6000 );
			}
		}
		function show( next, manual ) {
			index = ( next + slides.length ) % slides.length;
			if ( manual ) { paused = true; }
			slides.forEach( function ( slide, i ) {
				var active = i === index;
				slide.classList.toggle( 'is-active', active );
				slide.inert = ! active;
				slide.setAttribute( 'aria-hidden', String( ! active ) );
				if ( active ) {
					var image = slide.querySelector( 'img' );
					if ( image ) { image.loading = 'eager'; }
				}
				dots[i].toggleAttribute( 'aria-current', active );
				if ( active ) { dots[i].setAttribute( 'aria-current', 'true' ); }
			} );
			status.textContent = manual ? slides[index].getAttribute( 'aria-label' ) + ': ' + slides[index].querySelector( 'h1' ).textContent : '';
			schedule();
		}
		slides.forEach( function ( slide ) { slide.hidden = false; } );
		slider.querySelector( '[data-slider-controls]' ).hidden = false;
		slider.querySelector( '[data-prev]' ).addEventListener( 'click', function () { show( index - 1, true ); } );
		slider.querySelector( '[data-next]' ).addEventListener( 'click', function () { show( index + 1, true ); } );
		dots.forEach( function ( dot, i ) { dot.addEventListener( 'click', function () { show( i, true ); } ); } );
		// Capture intent before pointer focus pauses rotation; a click on Pause must not resume it.
		rotation.addEventListener( 'pointerdown', function () { pointerPaused = paused; } );
		rotation.addEventListener( 'pointercancel', function () { pointerPaused = null; } );
		rotation.addEventListener( 'click', function ( event ) {
			paused = ! ( event.detail > 0 && pointerPaused !== null ? pointerPaused : paused );
			pointerPaused = null;
			schedule();
		} );
		slider.addEventListener( 'focusin', function () {
			// Also stop when tabbing from Resume to a link within the slider.
			paused = true; schedule();
		} );
		slider.addEventListener( 'pointerenter', function ( event ) {
			if ( event.pointerType !== 'touch' ) { hovered = true; schedule(); }
		} );
		slider.addEventListener( 'pointerleave', function () { hovered = false; schedule(); } );
		slider.addEventListener( 'keydown', function ( event ) {
			if ( event.altKey || event.ctrlKey || event.metaKey || event.shiftKey || ! [ 'ArrowLeft', 'ArrowRight' ].includes( event.key ) ) { return; }
			event.preventDefault();
			// A link on the outgoing slide must never retain focus inside an inert slide.
			if ( event.target.closest( '[data-slide]' ) ) {
				slider.querySelector( event.key === 'ArrowLeft' ? '[data-prev]' : '[data-next]' ).focus();
			}
			show( index + ( event.key === 'ArrowLeft' ? -1 : 1 ), true );
		} );
		document.addEventListener( 'visibilitychange', schedule );
		motion.addEventListener( 'change', function () {
			paused = true;
			if ( motion.matches && document.activeElement === rotation ) { slider.querySelector( '[data-next]' ).focus(); }
			schedule();
		} );
		show( 0, false );
	} );
}() );
