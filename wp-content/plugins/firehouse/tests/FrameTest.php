<?php
/**
 * Tests for the Firehouse frame.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Firehouse\Frame;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Firehouse requests get the frame, its stylesheets in order and the body class;
 * every other request is left to the theme. Whether a request is a Firehouse request
 * comes from the query WordPress ran, which each case stands in for.
 */
final class FrameTest extends WordPressTestCase {

	/**
	 * The Firehouse root page's ID in these cases.
	 */
	private const ROOT = 7;

	/**
	 * Clears the main request after each test.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wp'] );
		parent::tearDown();
	}

	/**
	 * Stands in for the query WordPress ran.
	 *
	 * @param array<string, mixed> $query What the query found: `singular` (a post type),
	 *                                    `archive` (a post type), `page` (the page's ID
	 *                                    and `ancestors`), `missing` (the path of a
	 *                                    404), `embed`, and the request's raw `vars`.
	 */
	private static function resolve( array $query ): void {
		$singular = $query['singular'] ?? null;
		$archive  = $query['archive'] ?? null;
		$page     = isset( $query['page'] ) ? new \WP_Post(
			array(
				'ID'        => $query['page'],
				'post_type' => 'page',
			)
		) : null;

		Functions\when( 'is_embed' )->justReturn( $query['embed'] ?? false );
		Functions\when( 'is_singular' )->alias( static fn( string|array $types ): bool => in_array( $singular, (array) $types, true ) );
		Functions\when( 'is_post_type_archive' )->alias( static fn( string $type ): bool => $archive === $type );
		Functions\when( 'is_page' )->justReturn( null !== $page );
		Functions\when( 'get_queried_object' )->justReturn( $page ?? ( null !== $singular ? new \WP_Post( array( 'post_type' => $singular ) ) : null ) );
		Functions\when( 'get_posts' )->justReturn( array( self::ROOT ) );
		Functions\when( 'get_post_ancestors' )->justReturn( $query['ancestors'] ?? array() );
		Functions\when( 'is_404' )->justReturn( isset( $query['missing'] ) );

		$wp             = new \WP();
		$wp->request    = $query['missing'] ?? '';
		$wp->query_vars = $query['vars'] ?? array();
		$GLOBALS['wp']  = $wp; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the main request, stood in for.
	}

	/**
	 * A Firehouse request of each kind, missing addresses under the root included.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function firehouse_requests(): array {
		return array(
			'the project archive'              => array( array( 'archive' => 'fh_project' ) ),
			'a project'                        => array( array( 'singular' => 'fh_project' ) ),
			'a topic'                          => array( array( 'singular' => 'fh_topic' ) ),
			'the landing page'                 => array( array( 'page' => self::ROOT ) ),
			'a page under it, previewed by ID' => array(
				array(
					'page'      => 12,
					'ancestors' => array( self::ROOT ),
					'vars'      => array( 'page_id' => '12' ),
				),
			),
			'a page two levels down'           => array(
				array(
					'page'      => 13,
					'ancestors' => array( 12, self::ROOT ),
				),
			),
			'a missing page under the root'    => array( array( 'missing' => 'firehouse/nothing-here' ) ),
			'a missing project'                => array( array( 'missing' => 'firehouse/projects/no-such-project' ) ),
			'the root, when it isn’t there'    => array( array( 'missing' => 'firehouse' ) ),
		);
	}

	/**
	 * Requests the theme keeps, including ones whose raw query variables look like
	 * Firehouse's.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function other_requests(): array {
		return array(
			'the front page'                        => array( array() ),
			'a page of the theme'                   => array( array( 'page' => 3 ) ),
			'a page under another page'             => array(
				array(
					'page'      => 4,
					'ancestors' => array( 3 ),
				),
			),
			'a page with an empty topic variable'   => array(
				array(
					'page' => 3,
					'vars' => array(
						'pagename' => 'about',
						'fh_topic' => '',
					),
				),
			),
			'the posts with a project variable'     => array( array( 'vars' => array( 'fh_project' => '0' ) ) ),
			'a missing address that only starts so' => array( array( 'missing' => 'firehouses' ) ),
			'a missing address elsewhere'           => array( array( 'missing' => 'about/firehouse' ) ),
			'a project’s embed'                     => array(
				array(
					'singular' => 'fh_project',
					'embed'    => true,
				),
			),
			'a topic’s embed'                       => array(
				array(
					'singular' => 'fh_topic',
					'embed'    => true,
				),
			),
		);
	}

	/**
	 * A Firehouse request gets the plugin's frame template and the body class.
	 *
	 * @param array<string, mixed> $query What the query found.
	 */
	#[DataProvider( 'firehouse_requests' )]
	public function test_a_firehouse_request_gets_the_frame( array $query ): void {
		self::resolve( $query );

		$template = Frame::template( '/theme/page.php' );
		$this->assertSame( dirname( __DIR__ ) . '/templates/frame.php', $template );
		$this->assertFileExists( $template );
		$this->assertSame( array( 'page', Frame::BODY_CLASS ), Frame::body_class( array( 'page' ) ) );
	}

	/**
	 * Any other request keeps the theme's template and classes, and loads nothing.
	 *
	 * @param array<string, mixed> $query What the query found.
	 */
	#[DataProvider( 'other_requests' )]
	public function test_any_other_request_is_left_to_the_theme( array $query ): void {
		self::resolve( $query );
		Functions\expect( 'wp_enqueue_style' )->never();

		$this->assertSame( '/theme/page.php', Frame::template( '/theme/page.php' ) );
		$this->assertSame( array( 'page' ), Frame::body_class( array( 'page' ) ) );
		Frame::enqueue();
	}

	/**
	 * Stands in for a missing address: the query WordPress ran, and the published
	 * projects and topics the guess may find, in slug order.
	 *
	 * @param string $path The address, without its slashes.
	 * @param mixed  $type The post_type query variable.
	 * @param mixed  $name The name query variable.
	 */
	private static function missing( string $path, mixed $type, mixed $name ): void {
		self::resolve( array( 'missing' => $path ) );
		Functions\when( 'get_query_var' )->alias(
			static fn( string $query_var ): mixed => match ( $query_var ) {
				'post_type' => $type,
				'name'      => $name,
				default     => '',
			}
		);
		$slugs = array(
			'fh_project' => array( 'air-quality', 'california-tribal', 'california-wildfire' ),
			'fh_topic'   => array( 'forecast', 'governance', 'observe', 'warning' ),
		);
		Functions\when( 'get_posts' )->alias(
			static function ( array $args ) use ( $slugs ): array {
				// Every published post without a password, of the address's own type, in
				// slug order, with no meta or terms loaded.
				$expected = array(
					'post_status'            => 'publish',
					'has_password'           => false,
					'numberposts'            => -1,
					'orderby'                => 'name',
					'order'                  => 'ASC',
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				);
				if ( array_intersect_key( $args, $expected ) !== $expected ) {
					return array();
				}
				// Projects, only those a visitor may see.
				if ( 'fh_project' === $args['post_type'] && ( $args['meta_query'] ?? null ) !== \Firehouse\Store::public_projects_query()['meta_query'] ) {
					return array();
				}
				return array_map(
					static fn( string $slug, int $index ): \WP_Post => new \WP_Post(
						array(
							'ID'        => 100 + $index,
							'post_type' => $args['post_type'],
							'post_name' => $slug,
						)
					),
					$slugs[ $args['post_type'] ] ?? array(),
					array_keys( $slugs[ $args['post_type'] ] ?? array() )
				);
			}
		);
		Functions\when( 'get_post_meta' )->justReturn( '1' );
		Functions\when( 'get_permalink' )->alias(
			static fn( \WP_Post $post ): string => 'https://example.test/firehouse/' . ( 'fh_topic' === $post->post_type ? 'topics' : 'projects' ) . '/' . $post->post_name . '/'
		);
	}

	/**
	 * Under /firehouse/, a project's or topic's address is guessed among its own type,
	 * so a link cut short still finds its project, and the first match by slug wins.
	 * Any other missing address there gets no guess, which could land outside the
	 * Firehouse. Anywhere else WordPress guesses as before.
	 */
	public function test_a_guess_at_a_missing_firehouse_address_stays_in_the_firehouse(): void {
		$projects = 'https://example.test/firehouse/projects/';
		$cases    = array(
			array( 'firehouse', '', '', false ),
			array( 'firehouse/about', '', 'about', false ),
			array( 'firehouse/a/b', 'page', 'b', false ),
			array( 'firehouse/projects/california', 'fh_project', 'california', $projects . 'california-tribal/' ),
			array( 'firehouse/projects/california-w', 'fh_project', 'california-w', $projects . 'california-wildfire/' ),
			array( 'firehouse/projects/Air', 'fh_project', 'Air', $projects . 'air-quality/' ),
			array( 'firehouse/topics/gov', 'fh_topic', 'gov', 'https://example.test/firehouse/topics/governance/' ),
			array( 'firehouse/projects/smoke', 'fh_project', 'smoke', false ),
			array( 'firehouse/topics/fire', 'fh_topic', 'fire', false ),
			array( 'firehouse/projects/x', array( 'fh_project' ), 'x', false ),
			array( 'firehouse/projects/cal', 'fh_project', array( 'cal' ), false ),
			array( 'firehouse/projects/cal', 'fh_project', '', false ),
			array( 'about', '', 'about', null ),
			array( 'firehouses', '', 'firehouses', null ),
			array( 'about/firehouse', 'post', 'firehouse', null ),
		);
		foreach ( $cases as list( $path, $type, $name, $guess ) ) {
			self::missing( $path, $type, $name );
			$this->assertSame( $guess, Frame::guess_404( null ), $path );
		}
	}

	/**
	 * A project the query let through is still passed over if the site's own test
	 * fails it, as a second approval row written around Meta could make it.
	 */
	public function test_the_guess_checks_a_project_as_the_site_does(): void {
		self::missing( 'firehouse/projects/california', 'fh_project', 'california' );
		Functions\when( 'get_post_meta' )->alias( static fn( int $id ): string => 101 === $id ? '' : '1' );

		$this->assertSame( 'https://example.test/firehouse/projects/california-wildfire/', Frame::guess_404( null ) );
	}

	/**
	 * WordPress's strict setting guesses only a whole slug, which the query has already
	 * looked for, so under it the Firehouse makes no guess.
	 */
	public function test_a_strict_guess_finds_nothing_more(): void {
		Filters\expectApplied( 'strict_redirect_guess_404_permalink' )->andReturn( true );
		self::resolve( array( 'missing' => 'firehouse/projects/california' ) );
		Functions\expect( 'get_posts' )->never();
		Functions\when( 'get_query_var' )->alias( static fn( string $query_var ): string => 'post_type' === $query_var ? 'fh_project' : 'california' );
		$this->assertFalse( Frame::guess_404( null ) );
	}

	/**
	 * An answer an earlier filter gave stands, under /firehouse/ and anywhere else, and
	 * with no main request to read, nothing counts as under /firehouse/.
	 */
	public function test_another_filters_answer_stands(): void {
		foreach ( array( 'firehouse/projects/california', 'about' ) as $path ) {
			self::missing( $path, 'fh_project', 'california' );
			foreach ( array( false, '', 'https://example.test/elsewhere/', 0 ) as $value ) {
				$this->assertSame( $value, Frame::guess_404( $value ), $path );
			}
		}

		unset( $GLOBALS['wp'] );
		$this->assertNull( Frame::guess_404( null ) );
	}

	/**
	 * The fonts, the site's stylesheet and the frame load in that order, from the
	 * plugin's assets/css/, each at the plugin's version and the file's modified time.
	 * Only the frame depends on another; nothing depends on the fonts.
	 */
	public function test_the_stylesheets_load_in_order(): void {
		self::resolve( array( 'singular' => 'fh_topic' ) );
		$plugin = dirname( __DIR__ );
		Functions\when( 'plugins_url' )->alias(
			function ( string $path, string $file ) use ( $plugin ): string {
				$this->assertSame( $plugin . '/firehouse.php', $file );
				return 'https://example.test/wp-content/plugins/firehouse/' . $path;
			}
		);
		$loaded = array();
		Functions\when( 'wp_enqueue_style' )->alias(
			static function ( string $handle, string $src, array $deps, string $version ) use ( &$loaded ): void {
				$loaded[] = array( $handle, $src, $deps, $version );
			}
		);

		Frame::enqueue();

		$expected = array();
		foreach ( array(
			array( Frame::FONTS, 'fonts.css', array() ),
			array( Frame::STYLE, 'firehouse.css', array() ),
			array( Frame::FRAME, 'frame.css', array( Frame::STYLE ) ),
		) as list( $handle, $file, $deps ) ) {
			$path = $plugin . '/assets/css/' . $file;
			$this->assertFileExists( $path );
			$expected[] = array( $handle, 'https://example.test/wp-content/plugins/firehouse/assets/css/' . $file, $deps, \Firehouse\VERSION . '-' . filemtime( $path ) );
		}
		$this->assertSame( $expected, $loaded );
	}

	/**
	 * A missing address gets the not-found block, a topic's address the topic block,
	 * and any other page, until its own is built, the placeholder.
	 */
	public function test_the_content_is_the_pages_block_or_the_placeholder(): void {
		Functions\when( 'is_404' )->justReturn( true );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\expect( 'do_blocks' )->once()->with( '<!-- wp:firehouse/not-found /-->' )->andReturn( '[not found]' );
		ob_start();
		Frame::content();
		$this->assertSame( '[not found]', ob_get_clean() );

		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'is_singular' )->alias( static fn( string $type ): bool => 'fh_topic' === $type );
		Functions\expect( 'do_blocks' )->once()->with( '<!-- wp:firehouse/topic /-->' )->andReturn( '[topic]' );
		ob_start();
		Frame::content();
		$this->assertSame( '[topic]', ob_get_clean() );

		Functions\when( 'is_singular' )->justReturn( false );
		ob_start();
		Frame::content();
		$this->assertSame( '<section class="fh-section"><div class="fh-container"><h1>The Firehouse</h1><p>This page is being built.</p></div></section>', ob_get_clean() );
	}

	/**
	 * The disclaimer is the site's own, word for word: the `disclaimer` in the site's
	 * settings.json, which Stephanie and Emily cleared, in the site's markup.
	 */
	public function test_the_disclaimer_is_the_sites_word_for_word(): void {
		$settings = json_decode( (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/content/data/settings.json' ), true );
		ob_start();
		Frame::disclaimer();

		$this->assertSame( '<div class="fh-disclaimer fh-container"><p class="fh-disclaimer__text">' . htmlspecialchars( $settings['disclaimer'], ENT_QUOTES ) . '</p></div>', ob_get_clean() );
	}

	/**
	 * The not-found block, typed into a post, renders nothing but on a missing
	 * Firehouse address.
	 *
	 * @param array<string, mixed> $query What the query found.
	 */
	#[DataProvider( 'other_requests' )]
	public function test_the_not_found_block_renders_only_on_a_missing_firehouse_address( array $query ): void {
		self::resolve( $query );

		ob_start();
		require dirname( __DIR__ ) . '/blocks/not-found/render.php';
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * The topic block, typed into a post anywhere else, renders nothing, nor on a
	 * Firehouse page that isn't a topic's.
	 *
	 * @param array<string, mixed> $query What the query found.
	 */
	#[DataProvider( 'requests_but_a_topics' )]
	public function test_the_topic_block_renders_only_on_a_topic( array $query ): void {
		self::resolve( $query );

		ob_start();
		require dirname( __DIR__ ) . '/blocks/topic/render.php';
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * Every request but a topic's own page, whose block plugin:compare checks.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function requests_but_a_topics(): array {
		$requests = self::other_requests() + self::firehouse_requests();
		unset( $requests['a topic'] );
		return $requests;
	}

	/**
	 * The bar's block, typed into a post anywhere else, renders nothing.
	 *
	 * @param array<string, mixed> $query What the query found.
	 */
	#[DataProvider( 'other_requests' )]
	public function test_the_bar_renders_only_on_a_firehouse_page( array $query ): void {
		self::resolve( $query );

		ob_start();
		require dirname( __DIR__ ) . '/blocks/subnav/render.php';
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * The template is the theme's header, then the wrapper: the skip link, the Firehouse
	 * bar and the page's one main landmark, which the skip link leads to; then the
	 * theme's footer.
	 */
	public function test_the_template_orders_the_page(): void {
		Functions\when( 'get_header' )->alias( static fn() => print '[theme header]' );
		Functions\when( 'get_footer' )->alias( static fn() => print '[theme footer]' );
		Functions\expect( 'do_blocks' )->once()->with( '<!-- wp:firehouse/subnav /-->' )->andReturn( '[bar]' );
		Functions\when( 'esc_html' )->alias( static fn( string $text ): string => htmlspecialchars( $text, ENT_QUOTES ) );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( false );

		ob_start();
		require dirname( __DIR__ ) . '/templates/frame.php';
		$html = (string) ob_get_clean();

		$at = 0;
		foreach ( array( '[theme header]', '<div class="fh-app">', '<a class="fh-skip-link" href="#fh-content">', '[bar]', '<main id="fh-content" tabindex="-1">', '<h1>The Firehouse</h1>', '<p class="fh-disclaimer__text">', '</main>', '</div>', '[theme footer]' ) as $part ) {
			$found = strpos( $html, $part, $at );
			$this->assertNotFalse( $found, $part . ' should come next in ' . $html );
			$at = $found + strlen( $part );
		}
		$this->assertSame( 1, substr_count( $html, '<main' ) );
	}
}
