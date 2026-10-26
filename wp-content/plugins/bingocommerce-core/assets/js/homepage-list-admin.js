/* Trust row and Testimonials: add and remove text items. Changes are saved only by each form's Core POST boundary. */
( function () {
	'use strict';
	document.querySelectorAll( 'form[data-bc-list]' ).forEach( function ( form ) {
		var editor = form.closest( '.bc-list-editor' );
		var list = form.querySelector( '[data-list]' );
		var add = form.querySelector( '[data-list-add]' );
		var status = form.querySelector( '[data-list-status]' );
		var template = editor.querySelector( 'template[data-list-template]' );
		var max = parseInt( form.dataset.max, 10 );
		var rows = function () { return Array.from( list.querySelectorAll( '[data-list-item]' ) ); };
		var announce = function () { if ( window.wp && wp.a11y ) { wp.a11y.speak( form.dataset.changed ); } };
		function refresh() {
			var links = new Map();
			rows().forEach( function ( row, index ) {
				row.querySelectorAll( '[id], [for], [aria-describedby], [name]' ).forEach( function ( el ) {
					[ 'id', 'for', 'aria-describedby', 'name' ].forEach( function ( attr ) {
						var before = el.getAttribute( attr );
						if ( before === null ) { return; }
						var after = before.replace( /item-(?:\d+|__INDEX__)-/g, 'item-' + index + '-' ).replace( /\[items\]\[(?:\d+|__INDEX__)\]/g, '[items][' + index + ']' );
						el.setAttribute( attr, after );
						if ( attr === 'id' ) { links.set( '#' + before, '#' + after ); }
					} );
				} );
				row.querySelector( '[data-list-legend]' ).textContent = form.dataset.legend.replace( '%d', index + 1 );
				row.querySelector( '[data-list-remove]' ).hidden = false;
			} );
			// WordPress moves notices above the tabs, outside the editor; only one editor is on the page.
			document.querySelectorAll( '.bc-homepage .notice a' ).forEach( function ( link ) {
				if ( links.has( link.getAttribute( 'href' ) ) ) { link.setAttribute( 'href', links.get( link.getAttribute( 'href' ) ) ); }
			} );
			form.querySelector( '[data-list-count]' ).value = rows().length;
			add.disabled = rows().length >= max;
			status.textContent = rows().length + ' / ' + max;
		}
		add.hidden = false;
		add.addEventListener( 'click', function () {
			if ( rows().length >= max ) { return; }
			list.appendChild( template.content.cloneNode( true ) );
			refresh();
			rows().at( -1 ).querySelector( 'select, textarea, input' ).focus();
			announce();
		} );
		list.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-list-remove]' );
			var row = button && button.closest( '[data-list-item]' );
			if ( ! row ) { return; }
			var target = row.nextElementSibling || row.previousElementSibling;
			row.remove();
			refresh();
			( target ? target.querySelector( '[data-list-remove]' ) : add ).focus();
			announce();
		} );
		refresh();
	} );
}() );
