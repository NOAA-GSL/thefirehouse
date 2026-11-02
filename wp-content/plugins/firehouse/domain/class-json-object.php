<?php
/**
 * A JSON object that a PHP array would pass off as a list.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An empty object, or one whose keys are "0", "1", and so on.
 *
 * Decoded as a plain array, `{}` would be `[]` and `{"0":"before"}` would be
 * `["before"]`, so a check for a list would pass where JavaScript's Array.isArray()
 * fails. Json::decode() returns these as Json_Object instead. It still reads like an
 * array, but it isn't one, so the domain's list checks refuse it as the TypeScript
 * does. Every other object decodes to an ordinary associative array.
 */
final class Json_Object extends \ArrayObject {
}
