<?php
/**
 * Stand-ins for the WordPress classes the tests of includes/ pass around, with only
 * the fields and methods the plugin reads. The tests run without WordPress.
 *
 * @package Firehouse
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- WordPress's own classes and names, stood in for.

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * A post: its ID, type, parent, slug, status and title.
	 */
	final class WP_Post {

		/**
		 * The post's ID.
		 *
		 * @var int
		 */
		public int $ID = 0;

		/**
		 * The post type.
		 *
		 * @var string
		 */
		public string $post_type = 'post';

		/**
		 * The parent's ID, or 0.
		 *
		 * @var int
		 */
		public int $post_parent = 0;

		/**
		 * The slug.
		 *
		 * @var string
		 */
		public string $post_name = '';

		/**
		 * The status.
		 *
		 * @var string
		 */
		public string $post_status = 'publish';

		/**
		 * The title.
		 *
		 * @var string
		 */
		public string $post_title = '';

		/**
		 * Sets the fields given.
		 *
		 * @param array<string, mixed> $fields The fields.
		 */
		public function __construct( array $fields = array() ) {
			foreach ( $fields as $name => $value ) {
				$this->$name = $value;
			}
		}
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	/**
	 * A user: their ID, roles, and the capabilities those roles and the user hold.
	 */
	final class WP_User {

		/**
		 * Sets the user's ID, roles and capabilities.
		 *
		 * @param int                 $ID      The user's ID.
		 * @param string[]            $roles   The user's roles.
		 * @param array<string, bool> $allcaps What the roles and the user hold.
		 */
		public function __construct( public int $ID = 0, public array $roles = array(), public array $allcaps = array() ) {}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * A REST request: its route.
	 */
	final class WP_REST_Request {

		/**
		 * Sets the route.
		 *
		 * @param string $route The route, such as "/wp/v2/pages/12".
		 */
		public function __construct( private string $route = '' ) {}

		/**
		 * The route.
		 */
		public function get_route(): string {
			return $this->route;
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * An error: its code, message and data.
	 */
	final class WP_Error {

		/**
		 * Sets the error.
		 *
		 * @param string $code    The code.
		 * @param string $message The message.
		 * @param mixed  $data    The data.
		 */
		public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}

		/**
		 * The code.
		 */
		public function get_error_code(): string {
			return $this->code;
		}

		/**
		 * The message.
		 */
		public function get_error_message(): string {
			return $this->message;
		}
	}
}
