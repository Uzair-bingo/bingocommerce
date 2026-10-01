/*
 * Presentation only, and optional: every control is a real link the server
 * resolves. This script switches between panels the server already rendered from
 * the same eligibility result. It never reads a clock, builds or parses a date,
 * calls an API, or changes a selection by itself. Only an explicit date choice
 * replaces the displayed Sessions and any recovery message, and it ends any
 * Session selection; month arrows only re-word a month-only message for the
 * month shown. Uncertainty notices are never touched, and Packages still need a
 * Session's server link.
 */
( function () {
	'use strict';
	var root = document.querySelector( '[data-session-calendar]' );
	if ( ! root || ! window.Element || ! Element.prototype.closest ) { return; }
	var months = Array.prototype.slice.call( root.querySelectorAll( '[data-calendar-month]' ) );
	var panels = Array.prototype.slice.call( root.querySelectorAll( '[data-calendar-date-panel]' ) );
	var status = root.querySelector( '[data-calendar-status]' );
	var packages = root.querySelector( '[data-session-packages]' );

	function find( list, attribute, value ) {
		for ( var i = 0; i < list.length; i++ ) {
			if ( list[ i ].getAttribute( attribute ) === value ) { return list[ i ]; }
		}
		return null;
	}
	function announce( text ) {
		if ( status ) { status.textContent = text || ''; }
	}

	/*
	 * Month arrows change only the visible month and never select anything. The
	 * one message they may re-word is a still-visible month-only message: it
	 * follows whether the shown month has server-rendered highlighted dates.
	 * Recovery, stale, mismatch and requested-date messages are left alone, and
	 * once a date has been chosen there is no month-only message to bring back.
	 */
	function showMonth( link ) {
		var target = find( months, 'data-calendar-month', link.getAttribute( 'data-calendar-nav' ) );
		if ( ! target ) { return false; }
		months.forEach( function ( month ) { month.hidden = month !== target; } );
		var monthOnly = root.querySelector( '[data-calendar-month-only]' );
		if ( monthOnly && ! monthOnly.hidden ) {
			var kind = target.querySelector( 'a[data-calendar-date]' ) ? 'month' : 'month_empty';
			Array.prototype.forEach.call( monthOnly.querySelectorAll( '[data-calendar-month-message]' ), function ( message ) {
				message.hidden = message.getAttribute( 'data-calendar-month-message' ) !== kind;
			} );
		}
		var direction = link.getAttribute( 'data-calendar-direction' );
		var focus = target.querySelector( '[data-calendar-direction="' + direction + '"]' ) || target.querySelector( '[data-calendar-nav]' );
		if ( focus ) { focus.focus(); }
		announce( link.getAttribute( 'data-calendar-announce' ) );
		return true;
	}

	/* An explicit choice of a highlighted date, already rendered by the server. */
	function selectDate( link ) {
		var date = link.getAttribute( 'data-calendar-date' );
		var panel = find( panels, 'data-calendar-date-panel', date );
		if ( ! panel ) { return false; }
		panels.forEach( function ( item ) { item.hidden = item !== panel; } );
		Array.prototype.forEach.call( root.querySelectorAll( 'a[data-calendar-date]' ), function ( day ) {
			var chosen = day === link;
			day.classList.toggle( 'is-selected', chosen );
			if ( chosen ) { day.setAttribute( 'aria-current', 'date' ); } else { day.removeAttribute( 'aria-current' ); }
		} );
		// This choice replaces the earlier request's message and requested-date marker.
		Array.prototype.forEach.call( root.querySelectorAll( '[data-calendar-message]' ), function ( message ) { message.hidden = true; } );
		Array.prototype.forEach.call( root.querySelectorAll( '.calendar-day.is-requested' ), function ( day ) {
			day.classList.remove( 'is-requested' );
			day.removeAttribute( 'aria-current' );
		} );
		// A date choice, even of the same date, ends any Session selection: Packages
		// return only through a Session's own server-rendered "View Packages" link.
		Array.prototype.forEach.call( root.querySelectorAll( '.calendar-session-row.is-selected, .calendar-session-row[aria-current]' ), function ( row ) {
			row.classList.remove( 'is-selected' );
			row.removeAttribute( 'aria-current' );
		} );
		if ( packages ) { packages.hidden = true; }
		// The date link's own URL (date only, never bc_session) keeps reload and bookmarks consistent.
		if ( window.history && window.history.replaceState ) { window.history.replaceState( null, '', link.href ); }
		announce( link.getAttribute( 'data-calendar-announce' ) );
		return true;
	}

	root.addEventListener( 'click', function ( event ) {
		if ( event.defaultPrevented || 0 !== event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) { return; }
		var link = event.target.closest( 'a[data-calendar-nav], a[data-calendar-date]' );
		if ( ! link || ! root.contains( link ) ) { return; }
		if ( link.hasAttribute( 'data-calendar-nav' ) ? showMonth( link ) : selectDate( link ) ) { event.preventDefault(); }
	} );
}() );
