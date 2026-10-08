<?php
/** Private, owner-checked files shared by isolated manual/test runners only. */
declare(strict_types=1);

final class QRManualState {
	private const ROOT = '/private/tmp/';
	private const PREFIX = 'bc-qr-manual-';
	private const SIGNATURE = 'bingocommerce-qr-manual-v1';

	public static function create( string $owner ): string {
		if ( 1 !== preg_match( '/\A[a-f0-9-]{36}\z/', $owner ) ) { throw new RuntimeException( 'Invalid fixture owner.' ); }
		$dir = self::ROOT . self::PREFIX . $owner;
		if ( file_exists( $dir ) || ! mkdir( $dir, 0700 ) ) { throw new RuntimeException( 'Fixture directory must be new.' ); }
		self::writePrivate( $dir . '/.owner', self::SIGNATURE . "\n" . $owner );
		return self::directory( $dir );
	}

	public static function directory( ?string $path = null ): string {
		$path ??= (string) getenv( 'BC_QR_FIXTURE_DIR' );
		$dir = realpath( $path );
		if ( false === $dir || is_link( $path ) || ! is_dir( $dir )
			|| 1 !== preg_match( '#\A/private/tmp/bc-qr-manual-([a-f0-9-]{36})\z#', $dir, $parts )
			|| ( fileperms( $dir ) & 0077 ) !== 0 || ( function_exists( 'posix_geteuid' ) && fileowner( $dir ) !== posix_geteuid() ) ) {
			throw new RuntimeException( 'Owned private fixture directory required.' );
		}
		self::privateFile( $dir . '/.owner' );
		if ( file_get_contents( $dir . '/.owner' ) !== self::SIGNATURE . "\n" . $parts[1] ) { throw new RuntimeException( 'Fixture directory ownership refused.' ); }
		return $dir;
	}

	public static function owner( string $dir ): string { return substr( basename( self::directory( $dir ) ), strlen( self::PREFIX ) ); }

	/** Install before WordPress/WooCommerce boot: no development uploads or logs. */
	public static function isolateWordPressFiles( string $dir ): void {
		$dir = self::directory( $dir );
		if ( defined( 'ABSPATH' ) || defined( 'WC_LOG_DIR' ) ) { throw new RuntimeException( 'Fixture file isolation must precede WordPress.' ); }
		foreach ( [ 'woocommerce-logs', 'uploads' ] as $name ) {
			$path = $dir . '/' . $name;
			if ( ! file_exists( $path ) && ! mkdir( $path, 0700 ) ) { throw new RuntimeException( 'Fixture file directory creation failed.' ); }
			if ( is_link( $path ) || ! is_dir( $path ) ) { throw new RuntimeException( 'Fixture file directory refused.' ); }
		}
		define( 'WC_LOG_DIR', $dir . '/woocommerce-logs/' );
		$GLOBALS['wp_filter']['upload_dir'][999]['bc_qr_manual_uploads'] = [
			'function' => static function ( array $uploads ) use ( $dir ): array {
				$base = $dir . '/uploads/' . get_current_blog_id();
				$url = 'https://fixture.invalid/uploads/' . get_current_blog_id();
				$uploads['basedir'] = $base; $uploads['path'] = $base . $uploads['subdir'];
				$uploads['baseurl'] = $url; $uploads['url'] = $url . $uploads['subdir'];
				return $uploads;
			},
			'accepted_args' => 1,
		];
	}

	public static function read( string $dir, string $name ): array {
		$path = self::path( $dir, $name ); self::privateFile( $path );
		if ( filesize( $path ) > 2000000 ) { throw new RuntimeException( 'Oversized fixture state.' ); }
		$value = json_decode( file_get_contents( $path ), true, 128, JSON_THROW_ON_ERROR );
		if ( ! is_array( $value ) || ( $value['owner'] ?? null ) !== self::owner( $dir ) ) { throw new RuntimeException( 'Fixture state owner mismatch.' ); }
		return $value;
	}

	public static function write( string $dir, string $name, array $value ): void {
		if ( ( $value['owner'] ?? null ) !== self::owner( $dir ) ) { throw new RuntimeException( 'Fixture state owner mismatch.' ); }
		self::writePrivate( self::path( $dir, $name ), json_encode( $value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR ) );
	}

	public static function privateFile( string $path ): void {
		if ( ! is_file( $path ) || is_link( $path ) || ( fileperms( $path ) & 0077 ) !== 0
			|| ( function_exists( 'posix_geteuid' ) && fileowner( $path ) !== posix_geteuid() ) ) { throw new RuntimeException( 'Private fixture file ownership refused.' ); }
	}

	public static function writePrivate( string $path, string $bytes ): void {
		if ( file_exists( $path ) ) { self::privateFile( $path ); }
		$temp = $path . '.new';
		if ( file_exists( $temp ) || is_link( $temp ) ) { throw new RuntimeException( 'Refusing an existing temporary fixture file.' ); }
		$old = umask( 0077 ); $stream = fopen( $temp, 'x' ); umask( $old );
		if ( false === $stream ) { throw new RuntimeException( 'Fixture file creation failed.' ); }
		try { if ( strlen( $bytes ) !== fwrite( $stream, $bytes ) || ! fflush( $stream ) ) { throw new RuntimeException( 'Fixture file write failed.' ); } }
		finally { fclose( $stream ); }
		if ( ! rename( $temp, $path ) ) { throw new RuntimeException( 'Fixture file replacement failed.' ); }
	}

	public static function removeDirectory( string $dir ): void {
		$dir = self::directory( $dir );
		$entries = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $entries as $entry ) {
			if ( $entry->getPathname() === $dir . '/.owner' ) { continue; }
			if ( $entry->isLink() || ( function_exists( 'posix_geteuid' ) && $entry->getOwner() !== posix_geteuid() ) ) { throw new RuntimeException( 'Foreign/symlink fixture cleanup refused.' ); }
			if ( $entry->isDir() ? ! rmdir( $entry->getPathname() ) : ! unlink( $entry->getPathname() ) ) { throw new RuntimeException( 'Fixture file cleanup failed.' ); }
		}
		if ( ! unlink( $dir . '/.owner' ) ) { throw new RuntimeException( 'Fixture marker cleanup failed.' ); }
		if ( ! rmdir( $dir ) ) { throw new RuntimeException( 'Fixture directory cleanup failed.' ); }
	}

	public static function loopbackOrigin( string $url ): bool { return 1 === preg_match( '#\Ahttp://127\.0\.0\.1:([1-9][0-9]{3,4})\z#', $url, $parts ) && (int) $parts[1] <= 65535; }
	private static function path( string $dir, string $name ): string {
		if ( 1 !== preg_match( '/\A[a-z][a-z0-9.-]*\.json\z/', $name ) ) { throw new RuntimeException( 'Invalid fixture state filename.' ); }
		return self::directory( $dir ) . '/' . $name;
	}
}
