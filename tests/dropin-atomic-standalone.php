<?php
/**
 * Standalone tests for the atomic drop-in write (GH #34).
 *
 * advanced-cache.php is included by WordPress on every request while WP_CACHE
 * is on. The installer used to write it with file_put_contents(), which
 * truncates the target before writing — a request bootstrapping inside that
 * window included a half-written file and died with a PHP parse error
 * (production: 14-Oct-2026). The install must therefore go through a temp
 * file plus an atomic same-filesystem rename().
 *
 * @package ExtraChillCache
 */

// phpcs:disable -- Standalone test harness intentionally stubs WordPress functions and writes CLI output directly.

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CONTENT_DIR', __DIR__ . '/dropin-atomic-fixture' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'EXTRACHILL_CACHE_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'EXTRACHILL_CACHE_VERSION', '0.3.7' );

$GLOBALS['extrachill_cache_test_is_multisite'] = false;

function is_multisite() {
	return $GLOBALS['extrachill_cache_test_is_multisite'];
}

function get_sites( $args = array() ) {
	return array();
}

function wp_json_encode( $value, $flags = 0, $depth = 512 ) {
	return json_encode( $value, $flags, $depth );
}

function get_site_transient( $key ) {
	return false;
}

function set_site_transient( $key, $value, $ttl = 0 ) {
	return true;
}

function apply_filters( $hook, $value ) {
	return $value;
}

require dirname( __DIR__ ) . '/inc/dropin-installer.php';

$failures = 0;

function da_assert( $ok, $label ) {
	global $failures;
	echo ( $ok ? 'PASS' : 'FAIL' ) . ": {$label}\n";
	if ( ! $ok ) {
		++$failures;
	}
}

/**
 * List leftover temp files in the fixture directory.
 */
function da_temp_files() {
	return glob( WP_CONTENT_DIR . '/advanced-cache.php.tmp-*' ) ?: array();
}

$fixture_dir = WP_CONTENT_DIR;
@mkdir( $fixture_dir, 0777, true );
$dropin = $fixture_dir . '/advanced-cache.php';

// ---------------------------------------------------------------------------
// Baseline: a fresh install writes a complete drop-in through the temp+rename
// path. The inode changes across installs — truncate-in-place would keep it,
// rename() replaces the file with a new one. That is the observable that
// distinguishes the atomic write from the old one without needing real
// concurrency.
// ---------------------------------------------------------------------------
@unlink( $dropin );
da_assert( true === extrachill_cache_install_dropin( false ), 'fresh install succeeds' );
da_assert( (bool) @is_file( $dropin ), 'fresh install writes the drop-in' );
$contents = (string) @file_get_contents( $dropin );
da_assert(
	false !== strpos( $contents, 'Extra Chill Cache' ) && false !== strpos( $contents, 'GENERATED' ),
	'the installed drop-in is the complete composed file'
);
$inode_first = (int) @fileinode( $dropin );
da_assert( $inode_first > 0, 'fixture: drop-in has an inode' );

$inode_second = 0;
for ( $i = 0; $i < 3; $i++ ) {
	da_assert( true === extrachill_cache_install_dropin( false ), "re-install {$i} over an existing drop-in succeeds" );
	$inode_second = (int) @fileinode( $dropin );
	da_assert(
		$inode_second > 0 && $inode_second !== $inode_first,
		"re-install {$i} replaces the file via rename (new inode), not truncate-in-place"
	);
	$inode_first = $inode_second;
}

da_assert(
	(string) @file_get_contents( $dropin ) === (string) extrachill_cache_compose_dropin(),
	'the file on disk after the swap is byte-identical to the composed contents'
);
da_assert(
	array() === da_temp_files(),
	'no temp files are left behind after successful installs'
);

// ---------------------------------------------------------------------------
// A foreign drop-in must never be touched — the atomic rewrite must not loosen
// the ownership guard.
// ---------------------------------------------------------------------------
file_put_contents( $dropin, "<?php\n// Breeze generated drop-in\n" );
da_assert(
	false === extrachill_cache_install_dropin( false ),
	'a foreign drop-in is refused rather than overwritten'
);
da_assert(
	false !== strpos( (string) file_get_contents( $dropin ), 'Breeze' ),
	'the foreign drop-in is left byte-intact'
);
da_assert(
	array() === da_temp_files(),
	'the refused install never created a temp file'
);

// ---------------------------------------------------------------------------
// Write failure: the temp file cannot be created when the directory is not
// writable. The install must fail closed, leave no temp file, and leave the
// existing drop-in intact.
// ---------------------------------------------------------------------------
file_put_contents( $dropin, (string) extrachill_cache_compose_dropin() );
$before = (string) file_get_contents( $dropin );
chmod( $fixture_dir, 0555 );
da_assert(
	false === extrachill_cache_install_dropin( false ),
	'an unwritable directory fails the install'
);
chmod( $fixture_dir, 0755 );
da_assert(
	array() === da_temp_files(),
	'a failed write leaves no temp file behind'
);
da_assert(
	(string) file_get_contents( $dropin ) === $before,
	'a failed write leaves the existing drop-in byte-intact'
);

// ---------------------------------------------------------------------------
// Rename failure: when the target cannot be renamed over (here: the target
// path is a directory), the already-written temp file must still be cleaned
// up. This is the path where a naive implementation strands temp files.
// ---------------------------------------------------------------------------
@unlink( $dropin );
@mkdir( $dropin, 0777, true );
da_assert( (bool) is_dir( $dropin ), 'fixture: drop-in path is a directory so rename fails' );
da_assert(
	false === extrachill_cache_install_dropin( false ),
	'a rename failure fails the install'
);
da_assert(
	array() === da_temp_files(),
	'a failed rename cleans up the temp file'
);
@rmdir( $dropin );

@unlink( $dropin );
@rmdir( $fixture_dir );

if ( $failures > 0 ) {
	echo "{$failures} failure(s).\n";
	exit( 1 );
}
echo "All atomic drop-in write checks passed.\n";
