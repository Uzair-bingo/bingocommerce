<?php

namespace BingoCommerce\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Successful Game Type mutation result, including non-atomic audit warnings. */
final class GameTypeResult {

	public string $status;

	/** @var array<string,mixed> */
	public array $game_type;

	/** @var array<int,array<string,string>> */
	public array $warnings;

	/**
	 * @param array<string,mixed>             $game_type
	 * @param array<int,array<string,string>> $warnings
	 */
	public function __construct( string $status, array $game_type, array $warnings = [] ) {
		$this->status    = $status;
		$this->game_type = $game_type;
		$this->warnings  = $warnings;
	}
}
