<?php
/**
 * Runs shared/cases/notebooklm-source.json against domain/class-notebook-source.php.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Firehouse\Domain\Notebook_Source;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Notebook_Source, against the document `scripts/notebooklm-source.mjs` builds from
 * the same projects and topics.
 */
final class NotebookSourceTest extends DomainTestCase {

	/**
	 * One shared case, byte for byte.
	 *
	 * @param array<string, mixed> $shared_case The case: `projects`, `topics` and `expected`.
	 */
	#[DataProvider( 'cases' )]
	public function test_shared_case( array $shared_case ): void {
		$this->assertSame( $shared_case['expected'], Notebook_Source::build( $shared_case['projects'], $shared_case['topics'] ) );
	}

	/**
	 * A year stored as a whole number in a float, as JSON can give it, prints without a
	 * decimal point, as JavaScript prints it.
	 */
	public function test_a_whole_number_prints_without_a_decimal_point(): void {
		$project = array(
			'published'      => true,
			'id'             => 'R_1',
			'slug'           => 'a',
			'title'          => 'A',
			'authors'        => array(),
			'completionYear' => 2024.0,
		);
		$this->assertStringContainsString( "- **Completed:** 2024\n", Notebook_Source::build( array( $project ), array() ) );
	}

	/**
	 * The cases.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function cases(): array {
		return Cases::provider( 'notebooklm-source.json' );
	}
}
