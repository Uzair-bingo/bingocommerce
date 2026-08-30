<?php

namespace BingoCommerce\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Successful Bingo Package mutation result, including non-atomic settlement warnings. */
final class PackageResult {

	public string $status;

	/** @var array<string,mixed> */
	public array $package;

	/** @var array<int,array<string,string>> */
	public array $warnings;

	/**
	 * @param array<string,mixed>             $package
	 * @param array<int,array<string,string>> $warnings
	 */
	public function __construct( string $status, array $package, array $warnings = [] ) {
		$this->status   = $status;
		$this->package  = $package;
		$this->warnings = $warnings;
	}
}
