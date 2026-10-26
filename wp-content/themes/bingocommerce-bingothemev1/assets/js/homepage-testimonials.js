/* Presentation only: dots choose the visible testimonial. No timer, no cart calls. */
( function () {
	'use strict';
	document.querySelectorAll( '[data-homepage-testimonials]' ).forEach( function ( section ) {
		var items = Array.from( section.querySelectorAll( '[data-testimonial]' ) );
		var dots = Array.from( section.querySelectorAll( '[data-testimonial-dot]' ) );
		var status = section.querySelector( '[data-testimonial-status]' );
		if ( items.length < 2 || items.length !== dots.length ) { return; }
		function show( index, manual ) {
			items.forEach( function ( item, i ) {
				var active = i === index;
				item.classList.toggle( 'is-active', active );
				item.inert = ! active;
				item.setAttribute( 'aria-hidden', String( ! active ) );
				if ( active ) { dots[i].setAttribute( 'aria-current', 'true' ); } else { dots[i].removeAttribute( 'aria-current' ); }
			} );
			if ( manual && status ) { status.textContent = items[index].textContent.replace( /\s+/g, ' ' ).trim(); }
		}
		// Every quote shares one grid cell, so the section keeps the height of the longest.
		items.forEach( function ( item ) { item.hidden = false; } );
		section.classList.add( 'is-enhanced' );
		section.querySelector( '[data-testimonial-dots]' ).hidden = false;
		dots.forEach( function ( dot, i ) { dot.addEventListener( 'click', function () { show( i, true ); } ); } );
		show( 0, false );
	} );
}() );
