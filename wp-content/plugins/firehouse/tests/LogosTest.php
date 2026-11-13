<?php
/**
 * The logos the plugin prints are the ones it ships.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every assets/logos/ file the plugin's PHP names is in assets/logos/, which
 * scripts/plugin-assets.mjs fills with the images the site's BrandMark imports. The PHP
 * names its files by hand, so a logo renamed or moved to another format in src/ without
 * the PHP following would print a missing image.
 */
final class LogosTest extends TestCase {

	/**
	 * Each logo named in includes/, blocks/ or templates/ exists.
	 */
	public function test_every_logo_the_plugin_names_ships(): void {
		$root  = dirname( __DIR__ );
		$named = array();
		foreach ( array( 'includes', 'blocks', 'templates' ) as $folder ) {
			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/' . $folder, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $files as $file ) {
				if ( 'php' !== $file->getExtension() ) {
					continue;
				}
				preg_match_all( '#assets/logos/([\w.-]+)#', (string) file_get_contents( $file->getPathname() ), $matches );
				foreach ( $matches[1] as $logo ) {
					$named[ $logo ] = substr( $file->getPathname(), strlen( $root ) + 1 );
				}
			}
		}

		$this->assertGreaterThanOrEqual( 3, count( $named ), 'The bar and the hero name their logos.' );
		foreach ( $named as $logo => $where ) {
			$this->assertFileExists( $root . '/assets/logos/' . $logo, $where . ' names it.' );
		}
	}
}
