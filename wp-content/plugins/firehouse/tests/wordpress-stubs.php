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
	 * A post: its ID, type, parent and slug.
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
	 * A user: their ID and roles.
	 */
	final class WP_User {

		/**
		 * Sets the user's ID and roles.
		 *
		 * @param int      $ID    The user's ID.
		 * @param string[] $roles The user's roles.
		 */
		public function __construct( public int $ID = 0, public array $roles = array() ) {}
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
	}
}
