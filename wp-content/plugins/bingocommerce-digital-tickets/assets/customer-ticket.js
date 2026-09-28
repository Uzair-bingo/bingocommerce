/* Uses WooCommerce's bundled QRCode encoder; all pixels stay in the browser. */
( function () {
	'use strict';
	function render() {
		document.querySelectorAll( 'canvas[data-bc-ticket]' ).forEach( function ( canvas ) {
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
	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', render ); }
	else { render(); }
}() );
