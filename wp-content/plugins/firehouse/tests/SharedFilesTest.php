<?php
/**
 * The files in shared/ are written for both PHP and JavaScript, so PHP has to be able
 * to read every one of them.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Decodes the vocabulary, the project schema and the shared test cases.
 */
final class SharedFilesTest extends TestCase {

	/**
	 * Decodes one file in shared/, failing on invalid JSON.
	 *
	 * @param string $path Path inside shared/.
	 * @return array<mixed>
	 */
	private static function decode( string $path ): array {
		$json = file_get_contents( dirname( __DIR__ ) . '/shared/' . $path );
		return json_decode( (string) $json, true, 512, JSON_THROW_ON_ERROR );
	}

	/**
	 * The vocabulary has the keys the plugin will rely on.
	 */
	public function test_vocabulary_has_its_keys(): void {
		$vocabulary = self::decode( 'vocabulary.json' );
		$keys       = static fn( array $items ): array => array_column( $items, 'key' );

		$this->assertSame( array( 'before', 'during', 'after', 'long-term' ), $keys( $vocabulary['firePhases'] ) );
		$this->assertSame( array( 'observe', 'forecast', 'warning', 'governance' ), $keys( $vocabulary['topics'] ) );
		$this->assertCount( 10, array_filter( array_column( $vocabulary['regions'], 'mapped' ) ) );
		$this->assertContains( 'Northwest -- OR, WA (NWCC)', array_column( $vocabulary['regions'], 'formLabel' ) );
		foreach ( $vocabulary['topics'] as $topic ) {
			$this->assertContains( $topic['icon'], $vocabulary['icons'], $topic['key'] );
		}
	}

	/**
	 * The schema describes a project.
	 */
	public function test_project_schema_decodes(): void {
		$schema = self::decode( 'project.schema.json' );

		$this->assertSame( 'object', $schema['type'] );
		$this->assertContains( 'slug', $schema['required'] );
	}

	/**
	 * Every case file holds named cases.
	 *
	 * @param string $file A file in shared/cases/.
	 */
	#[DataProvider( 'case_files' )]
	public function test_case_file_decodes( string $file ): void {
		$cases = self::decode( 'cases/' . $file )['cases'];

		$this->assertNotEmpty( $cases );
		foreach ( $cases as $case ) {
			$this->assertIsString( $case['name'] );
		}
	}

	/**
	 * The case files, by name.
	 *
	 * @return array<string, array{string}>
	 */
	public static function case_files(): array {
		$files = array( 'format.json', 'derive.json', 'search.json', 'normalize.json' );
		return array_combine( $files, array_map( static fn( string $file ): array => array( $file ), $files ) );
	}
}
