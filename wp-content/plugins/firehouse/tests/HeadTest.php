<?php
/**
 * Tests for the Firehouse pages' titles, descriptions and link previews.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Firehouse\Head;

/**
 * Each Firehouse page is titled as the site titles it, then GSL's suffix; it describes
 * itself as the site does, a project's page with the project's own words; and it
 * carries the tags a shared link's preview is built from. Pages outside the Firehouse
 * are left as they are, and so is everything when an SEO plugin takes over.
 */
final class HeadTest extends WordPressTestCase {

	/**
	 * The request: which kind of page, and the post it's for.
	 *
	 * @var array{kind: string, post: \WP_Post|null}
	 */
	private array $request = array(
		'kind' => '',
		'post' => null,
	);

	/**
	 * What each post stores, by post ID and field.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $meta = array();

	/**
	 * Stands in for WordPress: the settings, the landing page (ID 10) and the About page
	 * under it (11), and the request's conditionals, from $request.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		// As WordPress's: a character reference already in the text is left alone.
		Functions\when( 'esc_html' )->alias( static fn( string $text ): string => htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false ) );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component = -1 ): mixed => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- the stand-in for wp_parse_url.
		Functions\when( 'wp_json_file_decode' )->alias(
			static fn( string $filename, array $options = array() ): mixed => json_decode( (string) file_get_contents( $filename ), ! empty( $options['associative'] ) )
		);
		Functions\when( 'get_option' )->justReturn(
			array(
				'siteName'  => 'The Firehouse',
				'navLabels' => array( 'about' => 'About us' ),
			)
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'Global Systems Laboratory' );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'wp_specialchars_decode' )->alias( static fn( string $text ): string => htmlspecialchars_decode( $text, ENT_QUOTES ) );
		Functions\when( 'plugins_url' )->alias( static fn( string $path ): string => 'https://example.test/wp-content/plugins/firehouse/' . $path );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.test/firehouse/projects/smoke-alerts/' );
		Functions\when( 'get_post_status' )->alias( static fn( \WP_Post $post ): string => $post->post_status );
		Functions\when( 'is_embed' )->justReturn( false );
		Functions\when( 'get_posts' )->justReturn( array( 10 ) );
		Functions\when( 'get_post_ancestors' )->alias( static fn( \WP_Post $post ): array => 0 !== $post->post_parent ? array( $post->post_parent ) : array() );

		$request = &$this->request;
		$meta    = &$this->meta;
		Functions\when( 'is_404' )->alias(
			static function () use ( &$request ): bool {
				return 'not-found' === $request['kind'];
			}
		);
		Functions\when( 'is_singular' )->alias(
			static function ( mixed $types = '' ) use ( &$request ): bool {
				$type = array(
					'project' => 'fh_project',
					'topic'   => 'fh_topic',
				)[ $request['kind'] ] ?? '';
				return '' !== $type && ( '' === $types || in_array( $type, (array) $types, true ) );
			}
		);
		Functions\when( 'is_post_type_archive' )->alias(
			static function () use ( &$request ): bool {
				return 'explorer' === $request['kind'];
			}
		);
		Functions\when( 'is_page' )->alias(
			static function () use ( &$request ): bool {
				return 'page' === $request['kind'];
			}
		);
		Functions\when( 'get_queried_object' )->alias(
			static function () use ( &$request ): ?\WP_Post {
				return $request['post'];
			}
		);
		Functions\when( 'metadata_exists' )->alias(
			static function ( string $type, int $id, string $key ) use ( &$meta ): bool {
				return isset( $meta[ $id ][ $key ] );
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $id, string $key ) use ( &$meta ): mixed {
				return $meta[ $id ][ $key ] ?? '';
			}
		);

		global $wp;
		$wp          = new \WP(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- standing in for WordPress's request.
		$wp->request = 'firehouse/nowhere';
	}

	/**
	 * Puts back WordPress's request.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wp'] );
		parent::tearDown();
	}

	/**
	 * Makes the request one for this kind of page.
	 *
	 * @param string                $kind   `not-found`, `project`, `topic`, `explorer` or `page`.
	 * @param array<string, mixed>  $post   The post's fields, if it's for one.
	 * @param array<string, string> $fields Its meta, by key.
	 */
	private function visit( string $kind, array $post = array(), array $fields = array() ): void {
		$this->request = array(
			'kind' => $kind,
			'post' => array() === $post ? null : new \WP_Post( $post ),
		);
		if ( array() !== $post ) {
			$this->meta[ (int) $post['ID'] ] = $fields;
		}
	}

	/**
	 * A project's page, its title as the researcher wrote it.
	 */
	private function visit_project(): void {
		$this->visit(
			'project',
			array(
				'ID'          => 21,
				'post_type'   => 'fh_project',
				'post_name'   => 'smoke-alerts',
				'post_status' => 'publish',
			),
			array(
				'fh_title'    => 'R&amp;D on "smoke" <alerts>',
				'fh_abstract' => 'What the researchers studied, in their words & no one else\'s.',
				'fh_summary'  => 'An editorial summary.',
			)
		);
	}

	/**
	 * Each page's title is the site's for it, the site's name from the settings, then
	 * GSL's suffix; the About page is named as the About link is.
	 */
	public function test_each_page_is_titled_as_the_site_titles_it(): void {
		$cases = array(
			'Page not found — The Firehouse'       => array( 'not-found' ),
			'Explore Projects — The Firehouse'     => array( 'explorer' ),
			'Forecasts & Modeling — The Firehouse' => array(
				'topic',
				array(
					'ID'         => 31,
					'post_type'  => 'fh_topic',
					'post_name'  => 'forecast',
					'post_title' => 'Forecasts &amp; Modeling',
				),
			),
			'The Firehouse — Fire Weather Social Science Hub' => array(
				'page',
				array(
					'ID'        => 10,
					'post_type' => 'page',
					'post_name' => 'firehouse',
				),
			),
			'About us — The Firehouse'             => array(
				'page',
				array(
					'ID'          => 11,
					'post_type'   => 'page',
					'post_name'   => 'about',
					'post_parent' => 10,
					'post_title'  => 'About — The Firehouse',
				),
			),
		);
		foreach ( $cases as $expected => $request ) {
			$this->visit( ...$request );
			$this->assertSame( $expected, Head::title(), $expected );
			$this->assertSame( htmlspecialchars( $expected, ENT_QUOTES ) . ' &#8211; Global Systems Laboratory', Head::document_title( '' ), $expected );
		}
	}

	/**
	 * A project's title shows as the researcher wrote it: every `&` escaped, so a stored
	 * "&amp;" isn't read as "&".
	 */
	public function test_a_projects_title_shows_as_written(): void {
		$this->visit_project();

		$this->assertSame( 'R&amp;D on "smoke" <alerts> — The Firehouse', Head::title() );
		$this->assertSame( 'R&amp;amp;D on &quot;smoke&quot; &lt;alerts&gt; — The Firehouse &#8211; Global Systems Laboratory', Head::document_title( '' ) );
	}

	/**
	 * The separator before the site's name is the en dash the site's other titles show
	 * for WordPress's "-"; another a theme sets is kept, escaped. The site's name is
	 * escaped too.
	 */
	public function test_the_suffix_reads_as_the_sites_other_titles(): void {
		$this->visit( 'explorer' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Lab <&> Co' );
		Filters\expectApplied( 'document_title_separator' )->once()->andReturn( '<|>' );

		$this->assertSame( 'Explore Projects — The Firehouse &lt;|&gt; Lab &lt;&amp;&gt; Co', Head::document_title( '' ) );
	}

	/**
	 * An earlier title filter that returned nothing at all, as one with a missing
	 * return does, fails no page: off the Firehouse it's passed on as it was, and on a
	 * Firehouse page the Firehouse's title takes its place.
	 */
	public function test_an_earlier_filters_nothing_fails_no_page(): void {
		$this->visit(
			'page',
			array(
				'ID'        => 40,
				'post_type' => 'page',
				'post_name' => 'research',
			)
		);
		$this->assertNull( Head::document_title( null ) );

		$this->visit( 'explorer' );
		$this->assertSame( 'Explore Projects — The Firehouse &#8211; Global Systems Laboratory', Head::document_title( null ) );
	}

	/**
	 * A page outside the Firehouse, a missing address outside it included, or one of its
	 * pages the site doesn't title, keeps WordPress's title and gets no tags; so does a
	 * title an earlier filter gave.
	 */
	public function test_other_titles_are_left_alone(): void {
		$this->visit(
			'page',
			array(
				'ID'        => 40,
				'post_type' => 'page',
				'post_name' => 'research',
			)
		);
		$this->assertSame( '', Head::document_title( '' ) );
		ob_start();
		Head::tags();
		$this->assertSame( '', ob_get_clean() );

		$this->visit( 'not-found' );
		$this->assertSame( 'Chosen elsewhere', Head::document_title( 'Chosen elsewhere' ) );

		// A missing address elsewhere on the site isn't a Firehouse page.
		$GLOBALS['wp']->request = 'research/missing';
		$this->assertSame( '', Head::document_title( '' ) );
		ob_start();
		Head::tags();
		$this->assertSame( '', ob_get_clean() );

		// A page in the tree other than the two the site titles.
		$this->visit(
			'page',
			array(
				'ID'          => 12,
				'post_type'   => 'page',
				'post_name'   => 'more',
				'post_parent' => 10,
			)
		);
		$this->assertSame( '', Head::document_title( '' ) );
	}

	/**
	 * A project's page describes it in the researcher's own words, its abstract, and
	 * its preview names its address; each value is escaped.
	 */
	public function test_a_projects_tags(): void {
		$this->visit_project();

		ob_start();
		Head::tags();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<meta name="description" content="What the researchers studied, in their words &amp; no one else&#039;s." />', $html );
		$this->assertStringContainsString( '<meta property="og:title" content="R&amp;amp;D on &quot;smoke&quot; &lt;alerts&gt; — The Firehouse" />', $html );
		$this->assertStringContainsString( '<meta property="og:description" content="What the researchers studied, in their words &amp; no one else&#039;s." />', $html );
		$this->assertStringContainsString( '<meta property="og:url" content="https://example.test/firehouse/projects/smoke-alerts/" />', $html );
		$this->assertStringContainsString( '<meta property="og:image" content="https://example.test/wp-content/plugins/firehouse/assets/images/og-image.png" />', $html );
		$this->assertStringContainsString( '<meta name="twitter:card" content="summary_large_image" />', $html );
		$this->assertSame( 15, substr_count( $html, '<meta ' ), 'Description, eleven Open Graph tags and four Twitter tags, less nothing.' );

		// A project that isn't published has no address in its preview.
		$this->request['post']->post_status = 'draft';
		ob_start();
		Head::tags();
		$this->assertStringNotContainsString( 'og:url', (string) ob_get_clean() );
		$this->request['post']->post_status = 'publish';

		// No abstract: the summary.
		$this->meta[21]['fh_abstract'] = ' ';
		ob_start();
		Head::tags();
		$this->assertStringContainsString( '<meta name="description" content="An editorial summary." />', (string) ob_get_clean() );
	}

	/**
	 * Every other Firehouse page has the site's description, and the shorter one the
	 * site gives previews; a page that isn't one post has no address in its preview.
	 */
	public function test_the_sites_descriptions_elsewhere(): void {
		$this->visit( 'explorer' );

		ob_start();
		Head::tags();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<meta name="description" content="' . Head::DESCRIPTION . '" />', $html );
		$this->assertStringContainsString( '<meta property="og:description" content="' . Head::PREVIEW . '" />', $html );
		$this->assertStringContainsString( '<meta property="og:site_name" content="The Firehouse" />', $html );
		$this->assertStringNotContainsString( 'og:url', $html );
	}

	/**
	 * With the filter saying no, as it would for a theme or plugin that prints its own,
	 * the Firehouse prints nothing and keeps WordPress's titles.
	 */
	public function test_it_steps_aside_when_told(): void {
		$this->visit_project();
		Filters\expectApplied( 'firehouse_head_tags' )->andReturn( false );

		$this->assertSame( '', Head::document_title( '' ) );
		ob_start();
		Head::tags();
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * The words are the site's, from index.html: its description, the preview's, the
	 * image's alternative text, and what its title calls the hub.
	 */
	public function test_the_words_are_the_sites(): void {
		$html = (string) file_get_contents( dirname( __DIR__, 4 ) . '/index.html' );
		$meta = static function ( string $attribute, string $name ) use ( $html ): string {
			preg_match( '#<meta\s+' . $attribute . '="' . preg_quote( $name, '#' ) . '"\s+content="([^"]*)"#', $html, $match );
			return html_entity_decode( $match[1] ?? '', ENT_QUOTES, 'UTF-8' );
		};

		$this->assertSame( Head::DESCRIPTION, $meta( 'name', 'description' ) );
		$this->assertSame( Head::PREVIEW, $meta( 'property', 'og:description' ) );
		$this->assertSame( Head::IMAGE_ALT, $meta( 'property', 'og:image:alt' ) );
		$this->assertStringContainsString( '<title>The Firehouse — ' . Head::HUB . ' | NOAA GSL</title>', $html );
		$this->assertSame( 'The Firehouse — ' . Head::HUB, $meta( 'property', 'og:title' ) );
	}
}
