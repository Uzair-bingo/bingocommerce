<?php

namespace BingoCommerce\Sessions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Successful Session mutation result, including non-atomic settlement warnings. */
final class SessionResult {

	public string $status;

	/** @var array<string,mixed> */
	public array $session;

	/** @var array<int,array<string,string>> */
	public array $warnings;

	/**
	 * @param array<string,mixed>             $session
	 * @param array<int,array<string,string>> $warnings
	 */
	public function __construct( string $status, array $session, array $warnings = [] ) {
		$this->status   = $status;
		$this->session  = $session;
		$this->warnings = $warnings;
	}
}
