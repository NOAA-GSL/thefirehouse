<?php
/**
 * Tests domain/class-json.php.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Firehouse\Domain\Json;
use Firehouse\Domain\Json_Object;

/**
 * Json::decode() keeps the objects a PHP array would pass off as lists.
 */
final class JsonTest extends DomainTestCase {

	/**
	 * An empty object and a numbered one stay objects; lists and other objects become
	 * arrays, all the way down.
	 */
	public function test_objects_that_look_like_lists_stay_objects(): void {
		$decoded = Json::decode( '{"empty":{},"numbered":{"0":"before"},"list":["before"],"none":[],"nested":[{"a":{}}]}' );

		$this->assertInstanceOf( Json_Object::class, $decoded['empty'] );
		$this->assertInstanceOf( Json_Object::class, $decoded['numbered'] );
		$this->assertSame( array( 'before' ), $decoded['numbered']->getArrayCopy() );
		$this->assertSame( array( 'before' ), $decoded['list'] );
		$this->assertSame( array(), $decoded['none'] );
		$this->assertInstanceOf( Json_Object::class, $decoded['nested'][0]['a'] );
	}

	/**
	 * What isn't JSON is an error, not a null.
	 */
	public function test_what_isnt_json_throws(): void {
		$this->expectException( \JsonException::class );
		Json::decode( '{not json' );
	}
}
