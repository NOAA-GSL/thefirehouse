<?php
/**
 * Tests for the About page's blocks.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\About_Page;

/**
 * The About page is the site's: the introduction, why it exists, who it serves, how it
 * works, who is behind it, and the closing band. Its words are blocks' attributes,
 * built from the bundle's about content; each member's portrait is the Media Library
 * image titled as their `photo`, or their initials until there is one.
 */
final class AboutPageTest extends WordPressTestCase {

	/**
	 * The survey address the settings and the fixture hold.
	 */
	private const SURVEY = 'https://forms.example.org/firehouse';

	/**
	 * What get_posts() was asked for.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $queries = array();

	/**
	 * The Media Library images the stand-in has, by title.
	 *
	 * @var array<string, int>
	 */
	private array $images = array();

	/**
	 * What wp_get_attachment_image() was asked for.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $printed = array();

	/**
	 * Stands in for WordPress: the settings, with a survey address; the Media Library,
	 * holding $images; and wp_get_attachment_image(), which says what it was asked for.
	 */
	protected function setUp(): void {
		parent::setUp();
		self::stand_in_serialize_blocks();
		Functions\stubEscapeFunctions();
		Functions\when( 'esc_html' )->alias( static fn( string $text ): string => htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false ) );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_json_file_decode' )->alias(
			static fn( string $filename, array $options = array() ): mixed => json_decode( (string) file_get_contents( $filename ), ! empty( $options['associative'] ) )
		);
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://example.test' . $path );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component = -1 ): mixed => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- the stand-in for wp_parse_url.
		Functions\when( 'get_option' )->justReturn( array( 'submitFormUrl' => self::SURVEY ) );

		$this->queries = array();
		$this->images  = array();
		$this->printed = array();
		$queries       = &$this->queries;
		$images        = &$this->images;
		$printed       = &$this->printed;
		Functions\when( 'get_posts' )->alias(
			static function ( array $args ) use ( &$queries, &$images ): array {
				$queries[] = $args;
				return isset( $images[ $args['title'] ] )
					? array(
						new \WP_Post(
							array(
								'ID'         => $images[ $args['title'] ],
								'post_type'  => 'attachment',
								'post_title' => $args['title'],
							)
						),
					)
					: array();
			}
		);
		Functions\when( 'wp_get_attachment_image' )->alias(
			static function ( int $id, string $size, bool $icon, array $attributes ) use ( &$printed ): string {
				$printed[] = array( $id, $size, $icon, $attributes );
				return '<img src="/portrait-' . $id . '.webp" class="' . $attributes['class'] . '" alt="' . $attributes['alt'] . '">';
			}
		);
	}

	/**
	 * The bundle's about content, normalized: the shared fixture's, with a mailbox and a
	 * second member who has a portrait.
	 *
	 * @return array<string, mixed>
	 */
	private static function about(): array {
		$about                       = Cases::site()['about'];
		$about['hero']['eyebrow']    = 'About The Firehouse';
		$about['hero']['body']       = 'What the hub is.';
		$about['team']['emailLead']  = ' Write to the team at ';
		$about['team']['email']      = ' team@example.gov ';
		$about['team']['members'][]  = array(
			'name'  => 'Dr. Ida Member',
			'photo' => 'ida-member',
			'bio'   => array( 'First paragraph.', 'Second paragraph.' ),
		);
		$about['process']['links'][] = array(
			'label' => 'How the synthesis works',
			'to'    => '/#needs-source',
		);
		return $about;
	}

	/**
	 * Each block's attributes, by name, from the page's blocks.
	 *
	 * @param string $blocks The page's blocks.
	 * @return array<string, array<string, mixed>>
	 */
	private static function attributes( string $blocks ): array {
		preg_match_all( '#<!-- wp:firehouse/([a-z-]+) (\{.*?\}) /?-->#', $blocks, $found, PREG_SET_ORDER );
		$attributes = array();
		foreach ( $found as $block ) {
			$attributes[ $block[1] ] = json_decode( $block[2], true );
		}
		return $attributes;
	}

	/**
	 * The page's blocks are the site's sections in its order, the four in the middle
	 * inside the body; a link to the survey address is stored empty, so it follows the
	 * settings, and nothing in them holds markup.
	 */
	public function test_the_blocks_are_the_sites_sections(): void {
		$blocks = About_Page::blocks( self::about(), self::SURVEY );

		$this->assertMatchesRegularExpression( '#^<!-- wp:firehouse/about-intro \{.*?\} /--><!-- wp:firehouse/about-sections --><!-- wp:firehouse/about-mission \{.*?\} /--><!-- wp:firehouse/about-audiences \{.*?\} /--><!-- wp:firehouse/about-process \{.*?\} /--><!-- wp:firehouse/about-team \{.*?\} /--><!-- /wp:firehouse/about-sections --><!-- wp:firehouse/about-cta \{.*?\} /-->$#', $blocks );
		$this->assertStringNotContainsString( self::SURVEY, $blocks );

		$attributes = self::attributes( $blocks );
		$this->assertSame(
			array(
				'eyebrow' => 'About The Firehouse',
				'heading' => 'About',
				'body'    => 'What the hub is.',
			),
			$attributes['about-intro']
		);
		$this->assertSame(
			array(
				array(
					'label' => 'Submit a finding',
					'href'  => '',
				),
				array(
					'label' => 'Projects',
					'to'    => '/projects',
				),
				array(
					'label' => 'How the synthesis works',
					'to'    => '/#needs-source',
				),
			),
			$attributes['about-process']['links']
		);
		$this->assertSame(
			array(
				'name' => 'Hal Member',
				'bio'  => array( 'A one-paragraph biography.' ),
			),
			$attributes['about-team']['members'][0]
		);
		$this->assertSame( ' team@example.gov ', $attributes['about-team']['email'], 'Kept as written; trimmed when shown.' );
		$this->assertSame(
			array(
				'label' => 'Submit a Finding',
				'href'  => '',
			),
			$attributes['about-cta']['cta']
		);
	}

	/**
	 * Only text reaches the page: a key the site doesn't have, or a value that isn't
	 * text, is left out, and a missing section's words are empty.
	 */
	public function test_only_text_reaches_the_page(): void {
		$about                                    = self::about();
		$about['hero']['heading']                 = array( 'not text, so not for the page' );
		$about['audiences']['items'][0]['onload'] = 'not for the page';
		$about['team']['members'][0]['photoUrl']  = 'https://example.org/not-for-the-page.jpg';
		$about['team']['members'][0]['bio'][]     = array( 'nor this' );
		$about['process']['steps'][]              = 'not an object';
		unset( $about['mission'] );

		$blocks     = About_Page::blocks( $about, self::SURVEY );
		$attributes = self::attributes( $blocks );

		$this->assertStringNotContainsString( 'not for the page', $blocks );
		$this->assertStringNotContainsString( 'nor this', $blocks );
		$this->assertSame( '', $attributes['about-intro']['heading'] );
		$this->assertSame(
			array(
				'heading'    => '',
				'paragraphs' => array(),
			),
			$attributes['about-mission']
		);
		$this->assertCount( 1, $attributes['about-process']['steps'] );
	}

	/**
	 * The page renders as the site's does: the introduction with the page's heading,
	 * each section named by its heading, the audiences with their icons, the steps
	 * numbered, the links after them, and the closing band's Submit button; stored text
	 * shows exactly as stored.
	 */
	public function test_the_page_renders_as_the_site_does(): void {
		$attributes                                  = self::attributes( About_Page::blocks( self::about(), self::SURVEY ) );
		$attributes['about-mission']['paragraphs'][] = 'R&amp;D <b>';

		$intro     = About_Page::intro( $attributes['about-intro'] );
		$mission   = About_Page::mission( $attributes['about-mission'] );
		$audiences = About_Page::audiences( $attributes['about-audiences'] );
		$process   = About_Page::process( $attributes['about-process'] );
		$cta       = About_Page::cta( $attributes['about-cta'] );

		$this->assertSame( '<section class="fh-about__hero"><div class="fh-about__hero-inner fh-container"><span class="fh-eyebrow">About The Firehouse</span><h1 class="fh-about__heading">About</h1><p class="fh-about__lede">What the hub is.</p></div></section>', $intro );
		$this->assertSame( '<div class="fh-about__body fh-container"><section>…</section></div>', About_Page::sections( '<section>…</section>' ) );
		$this->assertSame( '<section class="fh-about__section" aria-labelledby="about-mission"><h2 id="about-mission" class="fh-about__section-heading">Mission</h2><div class="fh-about__prose"><p>A made-up mission.</p><p>R&amp;amp;D &lt;b&gt;</p></div></section>', $mission );
		$this->assertMatchesRegularExpression( '#^<section class="fh-about__section" aria-labelledby="about-audiences"><div class="fh-about__section-intro"><h2 id="about-audiences" class="fh-about__section-heading">Who it is for</h2></div><ul class="fh-about__audiences"><li class="fh-audience fh-glass"><span class="fh-audience__icon" aria-hidden="true"><svg[^>]*width="20"[^>]*>.*</svg></span><h3 class="fh-audience__title">Forecasters</h3><p class="fh-audience__body">People who forecast.</p></li></ul></section>$#s', $audiences );
		$this->assertStringContainsString( '<ol class="fh-about__steps"><li class="fh-step"><span class="fh-step__rank" aria-hidden="true">1</span><div class="fh-step__main"><h3 class="fh-step__title">Submit</h3><p class="fh-step__body">Researchers submit.</p></div></li></ol><div class="fh-about__process-links">', $process );
		$this->assertMatchesRegularExpression( '#<a href="https://forms.example.org/firehouse" class="fh-btn fh-btn--link fh-btn--md fh-about__process-link" target="_blank" rel="noopener noreferrer">Submit a finding<svg[^>]*width="15"#', $process );
		$this->assertStringContainsString( '<a href="https://example.test/firehouse/projects/" class="fh-btn fh-btn--link fh-btn--md fh-about__process-link">Projects<svg', $process );
		$this->assertStringContainsString( '<a href="https://example.test/firehouse/#needs-source" class="fh-btn fh-btn--link fh-btn--md fh-about__process-link">How the synthesis works<svg', $process );
		$this->assertMatchesRegularExpression( '#^<section class="fh-about__cta-section fh-container" aria-labelledby="about-cta"><div class="fh-about__cta fh-glass"><span class="fh-about__cta-glow" aria-hidden="true"></span><div><h2 id="about-cta" class="fh-about__cta-heading">Submit</h2><p class="fh-about__cta-body">Share your findings.</p></div><a href="https://forms.example.org/firehouse" class="fh-btn fh-btn--accent fh-btn--lg fh-btn--block-mobile" target="_blank" rel="noopener noreferrer"><svg#', $cta );
	}

	/**
	 * An empty eyebrow or body is left out, as the site leaves it out; links that go
	 * nowhere are left out, and with none left, so is their row; so is the closing
	 * band's button with no survey address set.
	 */
	public function test_empty_parts_are_left_out(): void {
		Functions\when( 'get_option' )->justReturn( array( 'submitFormUrl' => '' ) );
		$attributes       = self::attributes( About_Page::blocks( self::about(), self::SURVEY ) );
		$process          = $attributes['about-process'];
		$process['links'] = array( $process['links'][0] );

		$this->assertSame( '<section class="fh-about__hero"><div class="fh-about__hero-inner fh-container"><h1 class="fh-about__heading">About</h1></div></section>', About_Page::intro( array( 'heading' => 'About' ) ) );
		$this->assertStringNotContainsString( 'fh-about__process-links', About_Page::process( $process ) );
		$this->assertStringNotContainsString( 'fh-btn', About_Page::cta( $attributes['about-cta'] ) );
	}

	/**
	 * The team's one mailbox is said once, trimmed, after its lead; with no address,
	 * there's no line for it.
	 */
	public function test_the_team_mailbox(): void {
		$team = self::attributes( About_Page::blocks( self::about(), self::SURVEY ) )['about-team'];

		$this->assertStringContainsString( '<h2 id="about-team" class="fh-about__section-heading">Team</h2><p class="fh-about__section-body">Write to the team at <a class="fh-about__team-email" href="mailto:team@example.gov">team@example.gov</a></p></div><ul class="fh-about__team">', About_Page::team( $team ) );

		$team['emailLead'] = '';
		$this->assertStringContainsString( '<p class="fh-about__section-body"><a class="fh-about__team-email" href="mailto:team@example.gov">', About_Page::team( $team ) );

		$team['email'] = '  ';
		$this->assertStringNotContainsString( 'mailto:', About_Page::team( $team ) );
	}

	/**
	 * A member whose portrait is in the Media Library gets it, as WordPress prints it:
	 * offered at the width it's drawn, loaded lazily, its alternative text empty since
	 * the name follows. A member with none gets their initials, hidden from screen
	 * readers. Each image is the newest with the portrait's title.
	 */
	public function test_portraits_and_initials(): void {
		$this->images = array( 'ida-member' => 9 );
		$team         = self::attributes( About_Page::blocks( self::about(), self::SURVEY ) )['about-team'];

		$html = About_Page::team( $team );

		$this->assertStringContainsString( '<li class="fh-person fh-glass"><div class="fh-person__head"><div class="fh-person__portrait"><span class="fh-person__monogram" aria-hidden="true">HM</span></div><div class="fh-person__id"><h3 class="fh-person__name">Hal Member</h3></div></div><div class="fh-person__bio"><p>A one-paragraph biography.</p></div></li>', $html );
		$this->assertStringContainsString( '<div class="fh-person__portrait"><img src="/portrait-9.webp" class="fh-person__photo" alt=""></div><div class="fh-person__id"><h3 class="fh-person__name">Dr. Ida Member</h3></div></div><div class="fh-person__bio"><p>First paragraph.</p><p>Second paragraph.</p></div>', $html );
		$this->assertSame(
			array(
				array(
					9,
					'medium',
					false,
					array(
						'class'    => 'fh-person__photo',
						'alt'      => '',
						'loading'  => 'lazy',
						'decoding' => 'async',
						'sizes'    => '168px',
					),
				),
			),
			$this->printed
		);
		$this->assertCount( 1, $this->queries, 'Only a member who names a portrait is looked up.' );
		$this->assertSame( 'attachment', $this->queries[0]['post_type'] );
		$this->assertSame( 'image', $this->queries[0]['post_mime_type'] );
		$this->assertSame( 'ida-member', $this->queries[0]['title'] );
		$this->assertSame( array( 1, 'ID', 'DESC' ), array( $this->queries[0]['numberposts'], $this->queries[0]['orderby'], $this->queries[0]['order'] ), 'The newest image with the title.' );

		// A portrait that carries information of its own says so.
		$team['members'][1]['photoAlt'] = 'Ida at the forecast desk';
		About_Page::team( $team );
		$this->assertSame( 'Ida at the forecast desk', $this->printed[1][3]['alt'] );
	}

	/**
	 * With no member naming a portrait, the Media Library isn't asked.
	 */
	public function test_no_portraits_no_query(): void {
		$this->assertSame( array(), About_Page::portraits( array( '', '' ) ) );
		$this->assertSame( array(), $this->queries );
	}

	/**
	 * Each portrait is looked up once, by its title, however many members name it.
	 */
	public function test_each_portrait_is_looked_up_once(): void {
		$this->images = array( 'b' => 4 );

		$this->assertSame( array( 'b' => 4 ), About_Page::portraits( array( 'a', '', 'b', 'a' ) ) );
		$this->assertSame( array( 'a', 'b' ), array_column( $this->queries, 'title' ) );
	}

	/**
	 * Initials as the site draws them: a leading honorific dropped, the first letters
	 * of the first two words, in capitals.
	 */
	public function test_initials(): void {
		$this->assertSame( 'EW', About_Page::initials( 'Dr. Emily Wells' ) );
		$this->assertSame( 'SH', About_Page::initials( 'Dr Stephanie Hoekstra' ) );
		$this->assertSame( 'AL', About_Page::initials( 'mrs. ann lee' ) );
		$this->assertSame( 'ÉÖ', About_Page::initials( '  Émile  Öz  Third ' ) );
		$this->assertSame( 'C', About_Page::initials( 'Cher' ) );
		$this->assertSame( '', About_Page::initials( '' ) );
	}
}
