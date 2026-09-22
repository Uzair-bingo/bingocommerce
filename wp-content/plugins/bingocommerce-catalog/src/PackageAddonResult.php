<?php

declare(strict_types=1);

namespace BingoCommerce\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Confirmed result; settlement warnings never change the committed status. */
final class PackageAddonResult {
	/** @param array<string,mixed> $package_addon @param array<int,array<string,string>> $warnings */
	public function __construct(
		public string $status,
		public array $package_addon,
		public array $warnings = []
	) {}
}
