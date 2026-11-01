<?php
/**
 * The error content validation raises.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A deliberate refusal of content: an unknown topic key, a project with no year.
 *
 * Its own type so callers can tell a content problem, which an editor can fix, from a
 * bug. The Import screen shows it in its dry run, and the edit screens show it as a
 * notice at save. Messages arrive escaped for HTML, and start "[content] " as the
 * TypeScript ones do.
 */
final class Content_Error extends \RuntimeException {

	/**
	 * Wraps a message that has already been escaped.
	 *
	 * @param string $message The escaped message, without the "[content] " prefix.
	 */
	public function __construct( string $message ) {
		parent::__construct( '[content] ' . $message );
	}
}
