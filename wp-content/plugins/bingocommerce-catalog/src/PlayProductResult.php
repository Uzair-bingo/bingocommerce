<?php

namespace BingoCommerce\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Successful Bingo Play Product mutation result, including non-atomic audit warnings. */
final class PlayProductResult {

	public string $status;

	/** @var array<string,mixed> */
	public array $play_product;

	/** @var array<int,array<string,string>> */
	public array $warnings;

	/**
	 * @param array<string,mixed>             $play_product
	 * @param array<int,array<string,string>> $warnings
	 */
	public function __construct( string $status, array $play_product, array $warnings = [] ) {
		$this->status       = $status;
		$this->play_product = $play_product;
		$this->warnings     = $warnings;
	}
}
