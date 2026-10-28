<?php
/**
 * The version is written in three places, and a release needs all three to agree.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Compares the plugin header, the VERSION constant and readme.txt's stable tag.
 */
final class VersionTest extends TestCase {

	/**
	 * All three name the same version.
	 */
	public function test_header_constant_and_readme_agree(): void {
		$plugin_dir = dirname( __DIR__ );
		require_once $plugin_dir . '/firehouse.php';

		preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', file_get_contents( $plugin_dir . '/firehouse.php' ), $header );
		preg_match( '/^Stable tag:\s*(\S+)/m', file_get_contents( $plugin_dir . '/readme.txt' ), $readme );

		$this->assertSame( \Firehouse\VERSION, $header[1] ?? null, 'Plugin header Version' );
		$this->assertSame( \Firehouse\VERSION, $readme[1] ?? null, 'readme.txt Stable tag' );
	}
}
