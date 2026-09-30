/* Current-Hall native Media Library; slide edits are saved only by the Core POST boundary. */
( function () {
	'use strict';
	var form = document.getElementById( 'bc-form' );
	if ( ! form || ! window.wp || ! wp.media ) { return; }
	var list = form.querySelector( '[data-slide-list]' );
	var add = form.querySelector( '[data-add-slide]' );
	var template = document.getElementById( 'bc-slide-template' );
	var labels = window.bcHomepageAdmin;
	var rows = function () { return Array.from( list.querySelectorAll( '[data-slide]' ) ); };
	var announce = function () { wp.a11y.speak( labels.changed ); };
	function refresh() {
		var links = new Map();
		rows().forEach( function ( row, index, all ) {
			row.querySelectorAll( '[id], [for], [aria-describedby], [name]' ).forEach( function ( el ) {
				[ 'id', 'for', 'aria-describedby', 'name' ].forEach( function ( attr ) {
					var before = el.getAttribute( attr );
					if ( before === null ) { return; }
					var after = before.replace( /slide-(?:\d+|__INDEX__)-/g, 'slide-' + index + '-' ).replace( /\[slides\]\[(?:\d+|__INDEX__)\]/g, '[slides][' + index + ']' );
					el.setAttribute( attr, after );
					if ( attr === 'id' ) { links.set( '#' + before, '#' + after ); }
				} );
			} );
			row.querySelector( '[data-slide-title]' ).textContent = labels.slide.replace( '%d', index + 1 );
			row.querySelector( '.bc-slide__tools' ).hidden = false;
			row.querySelector( '[data-select-image]' ).hidden = false;
			row.querySelector( '[data-move="up"]' ).disabled = index === 0;
			row.querySelector( '[data-move="down"]' ).disabled = index === all.length - 1;
		} );
		document.querySelectorAll( '.bc-homepage .notice a' ).forEach( function ( a ) {
			if ( links.has( a.getAttribute( 'href' ) ) ) { a.setAttribute( 'href', links.get( a.getAttribute( 'href' ) ) ); }
		} );
		form.elements.bc_slider_count.value = rows().length;
		add.disabled = rows().length >= 6;
		form.querySelector( '[data-slide-count]' ).textContent = rows().length + ' / 6';
	}
	add.hidden = false;
	add.addEventListener( 'click', function () {
		if ( rows().length >= 6 ) { return; }
		list.appendChild( template.content.cloneNode( true ) );
		refresh();
		rows().at( -1 ).querySelector( '[data-select-image]' ).focus();
		announce();
	} );
	list.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( 'button' );
		var row = button && button.closest( '[data-slide]' );
		if ( ! row ) { return; }
		if ( button.hasAttribute( 'data-remove-slide' ) ) {
			if ( ! window.confirm( labels.remove ) ) { return; }
			var target = row.nextElementSibling || row.previousElementSibling;
			row.remove(); refresh();
			( target ? target.querySelector( '[data-select-image]' ) : add ).focus();
			announce();
		} else if ( button.hasAttribute( 'data-move' ) ) {
			var up = button.dataset.move === 'up';
			var sibling = up ? row.previousElementSibling : row.nextElementSibling;
			if ( ! sibling ) { return; }
			if ( up ) { list.insertBefore( row, sibling ); } else { list.insertBefore( sibling, row ); }
			refresh();
			( button.disabled ? row.querySelector( '[data-move="' + ( up ? 'down' : 'up' ) + '"]' ) : button ).focus();
			announce();
		} else if ( button.hasAttribute( 'data-select-image' ) ) {
			var frame = wp.media( { title: labels.select, button: { text: labels.use }, library: { type: 'image' }, multiple: false } );
			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				row.querySelector( '[data-image-id]' ).value = attachment.id;
				var preview = row.querySelector( '[data-image-preview]' );
				preview.src = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
				preview.hidden = false;
				var warning = row.querySelector( '[data-image-warning]' );
				if ( warning ) { warning.remove(); }
				announce();
			} );
			frame.on( 'close', function () { button.focus(); } );
			frame.open();
		}
	} );
	refresh();
}() );
