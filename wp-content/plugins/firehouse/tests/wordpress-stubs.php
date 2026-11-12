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
		 * The password, or "" for none.
		 *
		 * @var string
		 */
		public string $post_password = '';

		/**
		 * The title.
		 *
		 * @var string
		 */
		public string $post_title = '';

		/**
		 * The content.
		 *
		 * @var string
		 */
		public string $post_content = '';

		/**
		 * Who wrote or uploaded it, by user ID, as WordPress holds it: a numeric string.
		 *
		 * @var int|string
		 */
		public int|string $post_author = '0';

		/**
		 * When it was published or uploaded, in the site's time: "Y-m-d H:i:s".
		 *
		 * @var string
		 */
		public string $post_date = '0000-00-00 00:00:00';

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

if ( ! class_exists( 'WP_Query' ) ) {
	/**
	 * A query: only what it is for.
	 */
	final class WP_Query {

		/**
		 * A query that is, or isn't, each of these.
		 *
		 * @param bool $main     Whether it's the page's main query.
		 * @param bool $singular Whether it's for one post.
		 * @param bool $embed    Whether it's an embed.
		 * @param bool $feed     Whether it's a feed.
		 */
		public function __construct( private bool $main = false, private bool $singular = false, private bool $embed = false, private bool $feed = false ) {}

		/**
		 * Whether it's the page's main query.
		 */
		public function is_main_query(): bool {
			return $this->main;
		}

		/**
		 * Whether it's for one post.
		 */
		public function is_singular(): bool {
			return $this->singular;
		}

		/**
		 * Whether it's an embed.
		 */
		public function is_embed(): bool {
			return $this->embed;
		}

		/**
		 * Whether it's a feed.
		 */
		public function is_feed(): bool {
			return $this->feed;
		}
	}
}

if ( ! class_exists( 'WP' ) ) {
	/**
	 * The main request: its parsed query variables.
	 */
	final class WP {

		/**
		 * The request's query variables.
		 *
		 * @var array<string, mixed>
		 */
		public array $query_vars = array();

		/**
		 * The request's path, without its leading or trailing slash.
		 *
		 * @var string
		 */
		public string $request = '';
	}
}
