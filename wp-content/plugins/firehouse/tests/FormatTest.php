<?php
/**
 * Runs shared/cases/format.json against domain/class-format.php.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Firehouse\Domain\Format;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Format, against the same cases as src/content/format.ts.
 */
final class FormatTest extends DomainTestCase {

	/**
	 * One shared case.
	 *
	 * @param array<string, mixed> $shared_case The case: `fn`, `args` and `expected`.
	 */
	#[DataProvider( 'cases' )]
	public function test_shared_case( array $shared_case ): void {
		$args   = $shared_case['args'];
		$result = match ( $shared_case['fn'] ) {
			'formatReviewDate' => Format::review_date( ...$args ),
			'formatByline'     => Format::byline( ...$args ),
			'splitNeedLead'    => Format::need_lead( ...$args ),
			'linkLabel'        => Format::link_label( ...$args ),
		};

		$this->assertSame( Cases::canonical( $shared_case['expected'] ), Cases::canonical( $result ) );
	}

	/**
	 * The cases.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function cases(): array {
		return Cases::provider( 'format.json' );
	}
}
