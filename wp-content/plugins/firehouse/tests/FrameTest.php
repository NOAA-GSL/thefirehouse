<?php
/**
 * Tests for the Firehouse frame.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

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
		Functions\when( 'is_singular' )->alias( static fn( array $types ): bool => in_array( $singular, $types, true ) );
		Functions\when( 'is_post_type_archive' )->alias( static fn( string $type ): bool => $archive === $type );
		Functions\when( 'is_page' )->justReturn( null !== $page );
		Functions\when( 'get_queried_object' )->justReturn( $page );
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
	 * A missing address under /firehouse/ isn't redirected to a guess of any type, which
	 * could land outside the Firehouse; a project's or topic's address keeps the guess,
	 * which stays among its own type. Anywhere else WordPress decides, as before.
	 */
	public function test_a_guess_at_a_missing_firehouse_address_stays_in_the_firehouse(): void {
		$cases = array(
			array( 'firehouse', '', false ),
			array( 'firehouse/about', '', false ),
			array( 'firehouse/a/b', 'page', false ),
			array( 'firehouse/projects/california-tribal', 'fh_project', true ),
			array( 'firehouse/topics/gov', 'fh_topic', true ),
			array( 'firehouse/projects/x', array( 'fh_project' ), false ),
			array( 'about', '', true ),
			array( 'firehouses', '', true ),
			array( 'about/firehouse', 'post', true ),
		);
		foreach ( $cases as list( $path, $type, $guess ) ) {
			self::resolve( array( 'missing' => $path ) );
			Functions\when( 'get_query_var' )->alias( static fn( string $name ): mixed => 'post_type' === $name ? $type : '' );
			$this->assertSame( $guess, Frame::guess_404_permalink( true ), $path );
			$this->assertFalse( Frame::guess_404_permalink( false ), $path );
		}
	}

	/**
	 * Whatever an earlier filter left, the answer is a boolean and nothing throws.
	 */
	public function test_the_guess_filter_takes_any_value(): void {
		self::resolve( array( 'missing' => 'about' ) );
		Functions\when( 'get_query_var' )->justReturn( '' );
		foreach ( array( null, 0, '', array() ) as $value ) {
			$this->assertFalse( Frame::guess_404_permalink( $value ) );
		}
		foreach ( array( 1, '1', array( 'x' ) ) as $value ) {
			$this->assertTrue( Frame::guess_404_permalink( $value ) );
		}
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
	 * The placeholder says what it is, and a missing address says so.
	 */
	public function test_the_placeholder(): void {
		Functions\when( 'esc_html' )->alias( static fn( string $text ): string => htmlspecialchars( $text, ENT_QUOTES ) );
		Functions\when( 'is_404' )->justReturn( true );
		ob_start();
		Frame::placeholder();
		$this->assertStringContainsString( '<h1>Page not found</h1>', (string) ob_get_clean() );

		Functions\when( 'is_404' )->justReturn( false );
		ob_start();
		Frame::placeholder();
		$this->assertStringContainsString( 'This page is being built.', (string) ob_get_clean() );
	}

	/**
	 * A translation is printed as text, never as markup.
	 */
	public function test_the_placeholder_escapes_its_translations(): void {
		Functions\when( 'esc_html' )->alias( static fn( string $text ): string => htmlspecialchars( $text, ENT_QUOTES ) );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( '__' )->justReturn( '<script>x</script>' );
		ob_start();
		Frame::placeholder();
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringContainsString( '<h1>&lt;script&gt;x&lt;/script&gt;</h1>', $output );
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

		ob_start();
		require dirname( __DIR__ ) . '/templates/frame.php';
		$html = (string) ob_get_clean();

		$at = 0;
		foreach ( array( '[theme header]', '<div class="fh-app">', '<a class="fh-skip-link" href="#fh-content">', '[bar]', '<main id="fh-content" tabindex="-1">', '<h1>The Firehouse</h1>', '</main>', '</div>', '[theme footer]' ) as $part ) {
			$found = strpos( $html, $part, $at );
			$this->assertNotFalse( $found, $part . ' should come next in ' . $html );
			$at = $found + strlen( $part );
		}
		$this->assertSame( 1, substr_count( $html, '<main' ) );
	}
}
