<?php

/**
 * One process, one network TOPOLOGY.
 *
 * `is_subdomain_install()` is decided by the SUBDOMAIN_INSTALL constant, so the
 * subdirectory branch of HallSiteAddress cannot be exercised in a process that
 * has already booted as a subdomain network. This worker defines the constant
 * before the fixture does — the fixture treats the Multisite topology constants
 * as defaults and keeps a caller's own definition — and prints one JSON line
 * describing what the resolver answered.
 *
 * IT CREATES NOTHING: no site, no user, no table, no option. Address resolution
 * is a read.
 */

putenv( 'BC_FIXTURE_NETWORK_ACTIVE=1' );

define( 'SUBDOMAIN_INSTALL', false );

require __DIR__ . '/fixtures/isolated-multisite.php';
require_once ABSPATH . 'wp-content/plugins/bingocommerce-platform/bingocommerce-platform.php';

use BingoCommerce\Platform\HallSiteAddress;

$report = [
	'subdomain_install' => (bool) is_subdomain_install(),
	'network_domain'    => (string) get_network()->domain,
	'network_path'      => (string) get_network()->path,
];

$valid = HallSiteAddress::forSlug( 'subdirhall' );
if ( $valid instanceof HallSiteAddress ) {
	$report['valid'] = [
		'domain'    => $valid->domain,
		'path'      => $valid->path,
		'subdomain' => $valid->subdomain,
	];
} else {
	$report['valid'] = [ 'error' => $valid->get_error_code() ];
}

/*
 * Reserved names are a SUBDIRECTORY-ONLY restriction: `example.com/feed/` would
 * collide with a core rewrite endpoint, while `feed.example.com` collides with
 * nothing. WordPress's own get_subdirectory_reserved_names() is the authority.
 */
$reserved = [];
foreach ( (array) get_subdirectory_reserved_names() as $name ) {
	if ( ! is_string( $name ) || 1 !== preg_match( '/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $name ) ) {
		// Names that are not valid DNS labels anyway are excluded, so the
		// assertion is about the RESERVATION rather than about the grammar.
		continue;
	}
	$result            = HallSiteAddress::forSlug( $name );
	$reserved[ $name ] = $result instanceof HallSiteAddress ? 'accepted' : $result->get_error_code();
}
$report['reserved'] = $reserved;

fwrite( STDOUT, (string) wp_json_encode( $report ) . "\n" );
