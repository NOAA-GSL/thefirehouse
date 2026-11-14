<?php
/**
 * Tests for the landing page's coverage map.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Coverage_Map;
use Firehouse\Domain\Derive;
use Firehouse\Domain\Map_Colors;

/**
 * The coverage map counts what the site's `RegionMap` counts, in the site's markup, and
 * its region list works without JavaScript: each button submits one GET form, for
 * `?region=` and its key, or for none. With JavaScript, the view script takes the same
 * markup over: every part a choice can show is printed, hidden unless the selection
 * shows it, with the directives and the state the script reads.
 */
final class CoverageMapTest extends WordPressTestCase {

	private const URL = 'https://example.test/firehouse/';

	/**
	 * Stands in for WordPress's addresses, escaping and shared JSON, as ExplorerPageTest
	 * does: esc_html() and esc_attr() leave a character reference alone, as WordPress's
	 * do.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\stubEscapeFunctions();
		$escape = static fn( string $text ): string => htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false );
		Functions\when( 'esc_html' )->alias( $escape );
		Functions\when( 'esc_attr' )->alias( $escape );
		Functions\when( 'esc_url' )->alias( static fn( string $url ): string => str_replace( array( '&', "'" ), array( '&#038;', '&#039;' ), $url ) );
		Functions\when( 'wp_json_file_decode' )->alias(
			static fn( string $filename, array $options = array() ): mixed => json_decode( (string) file_get_contents( $filename ), ! empty( $options['associative'] ) )
		);
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://example.test' . $path );
		// As ExplorerPageTest's: only an http or https address is kept.
		Functions\when( 'esc_url_raw' )->alias( static fn( string $url ): string => 1 === preg_match( '#^https?://#i', $url ) ? $url : '' );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component = -1 ): mixed => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- the stand-in for wp_parse_url.
	}

	/**
	 * Cleans up the address a test set.
	 */
	protected function tearDown(): void {
		unset( $_SERVER['REQUEST_URI'] );
		parent::tearDown();
	}

	/**
	 * The shared site's published projects as Explorer_Page::projects() has them, each
	 * with its address, in the site's order: smoke alerts in NWCC and ONCC, a national
	 * study, a Pacific one, one with no region, and one in RMCC.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function all(): array {
		return array_map(
			static fn( array $project ): array => array( 'url' => 'https://example.test/firehouse/projects/' . $project['slug'] . '/' ) + $project,
			Derive::published_projects( Cases::site() )
		);
	}

	/**
	 * The section as a DOM, for XPath.
	 *
	 * @param string|null                     $selected The selected region, or null.
	 * @param list<array<string, mixed>>|null $all      The projects, or null for the shared site's.
	 */
	private static function dom( ?string $selected = null, ?array $all = null ): \DOMXPath {
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="UTF-8"><body>' . Coverage_Map::render( Coverage_Map::projects( $all ?? self::all() ), $selected, self::URL ) . '</body>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return new \DOMXPath( $document );
	}

	/**
	 * The one element an XPath expression finds; the test fails if there isn't exactly one.
	 *
	 * @param \DOMXPath $xpath      The section.
	 * @param string    $expression The expression.
	 * @throws \RuntimeException If there isn't exactly one.
	 */
	private static function one( \DOMXPath $xpath, string $expression ): \DOMElement {
		$found = $xpath->query( $expression );
		if ( false === $found || 1 !== $found->length || ! $found->item( 0 ) instanceof \DOMElement ) {
			throw new \RuntimeException( ( false === $found ? 0 : $found->length ) . ' found at ' . $expression ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a test's message.
		}
		return $found->item( 0 );
	}

	/**
	 * A node's text, its spaces collapsed.
	 *
	 * @param \DOMNode $node The node.
	 */
	private static function text( \DOMNode $node ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', (string) $node->textContent ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the DOM's own name.
	}

	/**
	 * An element's shape: its tag and class, then each child element's, all the way
	 * down, leaving out what the script binds and whether it's hidden.
	 *
	 * @param \DOMElement $element The element.
	 * @return array<int, mixed>
	 */
	private static function shape( \DOMElement $element ): array {
		$shape = array( $element->tagName . '.' . $element->getAttribute( 'class' ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the DOM's own name.
		foreach ( $element->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- as above.
			if ( $child instanceof \DOMElement ) {
				$shape[] = self::shape( $child );
			}
		}
		return $shape;
	}

	/**
	 * An element's children's tag names, in order.
	 *
	 * @param \DOMXPath   $xpath   The section.
	 * @param \DOMElement $element The element.
	 * @return list<string>
	 */
	private static function tags( \DOMXPath $xpath, \DOMElement $element ): array {
		$tags = array();
		foreach ( $xpath->query( '*', $element ) as $child ) {
			$tags[] = $child->tagName; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the DOM's own name.
		}
		return $tags;
	}

	/**
	 * Whether each part of the results is hidden: the hint, the heading, the list (and
	 * how many projects it prints) and the empty message.
	 *
	 * @param \DOMXPath $xpath The section.
	 * @return array{hint: bool, head: bool, list: bool, items: int, empty: bool}
	 */
	private static function parts( \DOMXPath $xpath ): array {
		$hidden = static fn( string $part ): bool => self::one( $xpath, '//div[@class="fh-map__results"]/*[@class="' . $part . '"]' )->hasAttribute( 'hidden' );
		return array(
			'hint'  => $hidden( 'fh-map__hint' ),
			'head'  => $hidden( 'fh-map__results-head' ),
			'list'  => $hidden( 'fh-map__projects' ),
			'items' => $xpath->query( '//ul[@class="fh-map__projects"]/li' )->length,
			'empty' => $hidden( 'fh-map__empty' ),
		);
	}

	/**
	 * Each region button, in order: its text, then whether it's pressed, and the name
	 * and value it submits ("" for none).
	 *
	 * @param \DOMXPath $xpath The section.
	 * @return list<array{string, string, string}>
	 */
	private static function buttons( \DOMXPath $xpath ): array {
		$out = array();
		foreach ( $xpath->query( '//button[contains(@class, "fh-map__region")]' ) as $button ) {
			$out[] = array(
				self::text( $button ),
				$button->getAttribute( 'aria-pressed' ),
				$button->hasAttribute( 'name' ) ? $button->getAttribute( 'name' ) . '=' . $button->getAttribute( 'value' ) : '',
			);
		}
		return $out;
	}

	/**
	 * With none selected: the heading and its words, the count of projects that name a
	 * region (the one with none left out), the note on the national study, every region
	 * in the vocabulary's order with its count (a national study in every GACC), and the
	 * hint. "All submissions" is pressed. The results are no live region: they end in a
	 * status line, empty when the page loads.
	 */
	public function test_the_counts_with_none_selected(): void {
		$xpath = self::dom();

		$this->assertSame( 'coverage-heading', self::one( $xpath, '//section[@class="fh-coverage-section fh-container"]' )->getAttribute( 'aria-labelledby' ) );
		$this->assertSame( 'Where the research comes from', self::text( self::one( $xpath, '//h2[@id="coverage-heading"]' ) ) );
		$this->assertSame( 'Submissions by Geographic Area Coordination Center (GACC). Select one to open the projects filed there.', self::text( self::one( $xpath, '//p[@class="fh-coverage__sub"]' ) ) );
		$this->assertSame( 'Coverage by Geographic Area Coordination Center', self::text( self::one( $xpath, '//h3[@id="fh-map-list"]' ) ) );
		$this->assertSame( '4 submissions', self::text( self::one( $xpath, '//p[@class="fh-map__side-total"]' ) ) );
		$this->assertSame( 'GACC counts include 1 national study, counted once in every area.', self::text( self::one( $xpath, '//p[@class="fh-map__note"]' ) ) );
		$this->assertSame( 'fh-map-list', self::one( $xpath, '//div[@class="fh-map__side"]/ul' )->getAttribute( 'aria-labelledby' ) );
		$this->assertSame( 'Not on the map', self::text( self::one( $xpath, '//h4[@class="fh-map__offmap-title"]' ) ) );

		$this->assertSame(
			array(
				array( 'All submissions4', 'true', '' ),
				array( 'AlaskaAICC1', 'false', 'region=AICC' ),
				array( 'NorthwestNWCC2', 'false', 'region=NWCC' ),
				array( 'N. CaliforniaONCC2', 'false', 'region=ONCC' ),
				array( 'S. CaliforniaOSCC1', 'false', 'region=OSCC' ),
				array( 'N. RockiesNRCC1', 'false', 'region=NRCC' ),
				array( 'Great BasinGBCC1', 'false', 'region=GBCC' ),
				array( 'SouthwestSWCC1', 'false', 'region=SWCC' ),
				array( 'Rocky MountainRMCC2', 'false', 'region=RMCC' ),
				array( 'Eastern AreaEACC1', 'false', 'region=EACC' ),
				array( 'Southern AreaSACC1', 'false', 'region=SACC' ),
				array( 'Hawaii / Pacific1', 'false', 'region=PACIFIC' ),
				array( 'National1', 'false', 'region=NATIONAL' ),
				array( 'International0', 'false', 'region=INTL' ),
				array( 'Not sure0', 'false', 'region=UNKNOWN' ),
			),
			self::buttons( $xpath )
		);
		$this->assertSame( 'fh-map__region fh-map__region--on', self::one( $xpath, '//button[contains(@class, "fh-map__region")][@aria-pressed="true"]' )->getAttribute( 'class' ) );
		$this->assertSame( 10, $xpath->query( '//button[@class="fh-map__region"]/span[@class="fh-map__region-name"]/span[@class="fh-map__region-code"]' )->length );
		$this->assertSame( 4, $xpath->query( '//div[@class="fh-map__offmap"]//button[@class="fh-map__region fh-map__region--sm"]' )->length );
		$this->assertSame( 0, $xpath->query( '//div[@class="fh-map__offmap"]//span[@class="fh-map__swatch" or @class="fh-map__region-code"]' )->length );
		$this->assertSame( 'Select a GACC on the map or in the list to see the research filed there.', self::text( self::one( $xpath, '//div[@class="fh-map__results"]/p[@class="fh-map__hint"]' ) ) );
		$results    = self::one( $xpath, '//div[@class="fh-map__results"]' );
		$attributes = array();
		foreach ( $results->attributes as $attribute ) {
			$attributes[] = $attribute->name;
		}
		$this->assertSame( array( 'class', 'id' ), $attributes );
		$status = self::one( $xpath, '//div[@class="fh-map__results"]/p[@role="status"]' );
		$this->assertSame( '<p class="fh-visually-hidden" role="status" data-wp-text="state.announced"></p>', $status->ownerDocument->saveHTML( $status ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the DOM's own name.
		$this->assertSame( $status, $results->lastChild ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- as above.
		$this->assertSame(
			array(
				'hint'  => false,
				'head'  => true,
				'list'  => true,
				'items' => 0,
				'empty' => true,
			),
			self::parts( $xpath )
		);
	}

	/**
	 * Every region button, "All submissions" and "Clear" submit the one form, a hidden
	 * GET form back to the section's heading on the landing page. A button that selects
	 * a region brings the page back at the results instead (its `formaction`); one that
	 * selects none, beside the list, keeps the heading. The map's labels toggle, which
	 * works only with the script, submits nothing.
	 */
	public function test_every_button_submits_the_one_form(): void {
		$xpath = self::dom( 'GBCC' );

		$form = self::one( $xpath, '//form' );
		$this->assertSame(
			array( Coverage_Map::FORM, 'get', self::URL . '#coverage-heading', true ),
			array( $form->getAttribute( 'id' ), $form->getAttribute( 'method' ), $form->getAttribute( 'action' ), $form->hasAttribute( 'hidden' ) )
		);
		$this->assertSame( 0, $form->childNodes->length ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the DOM's own name.
		$this->assertSame( 'button', self::one( $xpath, '//button[@class="fh-map__codes-toggle"]' )->getAttribute( 'type' ) );
		$buttons = $xpath->query( '//button[not(@class="fh-map__codes-toggle")]' );
		$this->assertSame( 16, $buttons->length );
		foreach ( $buttons as $button ) {
			$this->assertSame( array( 'submit', Coverage_Map::FORM ), array( $button->getAttribute( 'type' ), $button->getAttribute( 'form' ) ), self::text( $button ) );
		}
		$this->assertFalse( self::one( $xpath, '//button[@class="fh-map__clear"]' )->hasAttribute( 'name' ) );

		$this->assertSame( Coverage_Map::RESULTS, self::one( $xpath, '//div[@class="fh-map__results"]' )->getAttribute( 'id' ) );
		foreach ( $buttons as $button ) {
			$this->assertSame(
				$button->hasAttribute( 'name' ) ? self::URL . '#' . Coverage_Map::RESULTS : '',
				$button->getAttribute( 'formaction' ),
				self::text( $button )
			);
		}
		// Every region but the one selected.
		$this->assertSame( 13, $xpath->query( '//button[@formaction]' )->length );
	}

	/**
	 * Selected, a region's button is pressed and submits nothing, so pressing it again
	 * selects none; "All submissions" isn't pressed. Its projects follow a heading that
	 * counts them, in order, each linking to its page, a national study tagged so, with
	 * its year. The status line stays empty: nothing has changed since the page loaded.
	 */
	public function test_a_selected_region(): void {
		$xpath = self::dom( 'NWCC' );

		$this->assertSame( array( 'NorthwestNWCC2', 'true', '' ), self::buttons( $xpath )[2] );
		$this->assertSame( array( 'All submissions4', 'false', '' ), self::buttons( $xpath )[0] );
		$this->assertSame( 'fh-map__region fh-map__region--on', self::one( $xpath, '//button[contains(@class, "fh-map__region")][@aria-pressed="true"]' )->getAttribute( 'class' ) );
		$this->assertSame(
			array(
				'hint'  => true,
				'head'  => false,
				'list'  => false,
				'items' => 2,
				'empty' => true,
			),
			self::parts( $xpath )
		);

		$head = self::one( $xpath, '//div[@class="fh-map__results"]/div[@class="fh-map__results-head"]' );
		$this->assertSame( '2 projects in NorthwestClear', self::text( $head ) );
		$this->assertSame( 'svg', $head->firstChild->nodeName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the DOM's own name.
		$title = self::one( $xpath, '//div[@class="fh-map__results-head"]/h3[@class="fh-map__results-title"]' );
		$this->assertSame( '2 projects in Northwest', self::text( $title ) );
		$this->assertSame( 'Northwest', self::text( self::one( $xpath, '//h3[@class="fh-map__results-title"]/strong' ) ) );
		$this->assertSame( 'Clear', self::text( self::one( $xpath, '//div[@class="fh-map__results-head"]/button[@class="fh-map__clear"]' ) ) );
		$status = self::one( $xpath, '//div[@class="fh-map__results"]/p[@role="status"]' );
		$this->assertSame( '<p class="fh-visually-hidden" role="status" data-wp-text="state.announced"></p>', $status->ownerDocument->saveHTML( $status ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the DOM's own name.

		$links = array();
		foreach ( $xpath->query( '//ul[@class="fh-map__projects"]/li/a[@class="fh-map__project"]' ) as $link ) {
			$tag     = self::one( $xpath, '//a[@href="' . $link->getAttribute( 'href' ) . '"]/span[@class="fh-map__project-tag"]' );
			$links[] = array( $link->getAttribute( 'href' ), self::text( $link->firstChild ), $tag->hasAttribute( 'hidden' ), self::text( $tag ), self::text( $link->lastChild->previousSibling ), $xpath->query( 'svg', $link )->length ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the DOM's own names.
		}
		$this->assertSame(
			array(
				array( 'https://example.test/firehouse/projects/smoke-alerts-for-evacuation/', 'Smoke alerts for evacuation decisions', true, 'National', '2025', 1 ),
				array( 'https://example.test/firehouse/projects/national-outlook-study/', 'National outlook study', false, 'National', '2024', 1 ),
			),
			$links
		);
	}

	/**
	 * Under National itself, a national study isn't tagged; a region with nothing filed
	 * says so; and one project is counted in the singular.
	 */
	public function test_national_empty_and_one(): void {
		$national = self::dom( 'NATIONAL' );
		$this->assertSame( '1 project in National', self::text( self::one( $national, '//h3[@class="fh-map__results-title"]' ) ) );
		$this->assertSame( 1, $national->query( '//ul[@class="fh-map__projects"]/li//span[@class="fh-map__project-tag"][@hidden]' )->length );
		$this->assertSame( 0, $national->query( '//ul[@class="fh-map__projects"]/li//span[@class="fh-map__project-tag"][not(@hidden)]' )->length );
		$this->assertSame( 1, $national->query( '//ul[@class="fh-map__projects"]/li/a[@class="fh-map__project"]' )->length );

		$empty = self::dom( 'INTL' );
		$this->assertSame( '0 projects in International', self::text( self::one( $empty, '//h3[@class="fh-map__results-title"]' ) ) );
		$this->assertSame( 'No published research is filed under this region yet.', self::text( self::one( $empty, '//p[@class="fh-map__empty"]' ) ) );
		$this->assertSame(
			array(
				'hint'  => true,
				'head'  => false,
				'list'  => true,
				'items' => 0,
				'empty' => false,
			),
			self::parts( $empty )
		);
	}

	/**
	 * Each GACC's swatch carries its fill for both themes, from Map_Colors against the
	 * busiest GACC; a GACC with nothing filed has no colour, and without a national
	 * study there's no note.
	 */
	public function test_swatches_and_no_note(): void {
		$xpath = self::dom();
		$style = static fn( string $key ): string => self::one( $xpath, '//button[@value="' . $key . '"]/span[@class="fh-map__swatch"]' )->getAttribute( 'style' );
		$this->assertSame( '--fh-swatch-light: ' . Map_Colors::heat( 2, 2, 'light' )['fill'] . '; --fh-swatch-dark: ' . Map_Colors::heat( 2, 2, 'dark' )['fill'], $style( 'NWCC' ) );
		$this->assertSame( '--fh-swatch-light: #8fb8e6; --fh-swatch-dark: #3d6fb0', $style( 'AICC' ) );
		$this->assertSame( 'true', self::one( $xpath, '//button[@value="AICC"]/span[@class="fh-map__swatch"]' )->getAttribute( 'aria-hidden' ) );

		$without = self::dom( null, array_values( array_filter( self::all(), static fn( array $project ): bool => 'national-outlook-study' !== $project['slug'] ) ) );
		$this->assertFalse( self::one( $without, '//button[@value="AICC"]/span[@class="fh-map__swatch"]' )->hasAttribute( 'style' ) );
		$this->assertSame( 0, $without->query( '//p[@class="fh-map__note"]' )->length );
		$this->assertSame( '3 submissions', self::text( self::one( $without, '//p[@class="fh-map__side-total"]' ) ) );
	}

	/**
	 * The swatches scale against the busiest GACC, not the busiest region: three
	 * projects in Hawaii / Pacific and one in the Great Basin give the Great Basin the
	 * top of the ramp, as on the site.
	 */
	public function test_swatches_scale_against_the_busiest_gacc(): void {
		$project = static fn( string $slug, string $region ): array => array(
			'title'          => $slug,
			'completionYear' => 2025,
			'url'            => 'https://example.test/firehouse/projects/' . $slug . '/',
			'geo'            => array( 'regions' => array( $region ) ),
		);
		$xpath   = self::dom( null, array( $project( 'a', 'PACIFIC' ), $project( 'b', 'PACIFIC' ), $project( 'c', 'PACIFIC' ), $project( 'd', 'GBCC' ) ) );

		$this->assertSame( '--fh-swatch-light: #0b3d86; --fh-swatch-dark: #c4e2ff', self::one( $xpath, '//button[@value="GBCC"]/span[@class="fh-map__swatch"]' )->getAttribute( 'style' ) );
		$this->assertSame( 'Hawaii / Pacific3', self::buttons( $xpath )[11][0] );
	}

	/**
	 * A title is the researcher's, as written, escaped; anything not a string in a
	 * project's regions is left out; and one project naming a region is a submission
	 * in the singular.
	 */
	public function test_a_title_as_written_and_odd_regions(): void {
		$project = array(
			'title'          => 'Fire & <smoke> "plumes"',
			'completionYear' => 2026,
			'url'            => 'https://example.test/firehouse/projects/odd/',
			'geo'            => array( 'regions' => array( 'SWCC', 7, null, array( 'GBCC' ) ) ),
		);
		$xpath   = self::dom( 'SWCC', array( $project ) );

		$link = self::one( $xpath, '//ul[@class="fh-map__projects"]/li/a[@class="fh-map__project"]' );
		$this->assertSame( array( 'Fire & <smoke> "plumes"', '2026' ), array( self::text( $link->firstChild ), self::text( $link->lastChild->previousSibling ) ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the DOM's own names.
		$this->assertSame( '1 submission', self::text( self::one( $xpath, '//p[@class="fh-map__side-total"]' ) ) );
		$this->assertSame( 'Great BasinGBCC0', self::buttons( $xpath )[6][0] );
		$this->assertSame( array( 'SWCC' ), Coverage_Map::projects( array( $project ) )[0]['regions'] );

		$nothing = self::dom( null, array( array( 'geo' => 'none' ) + $project ) );
		$this->assertSame( '0 submissions', self::text( self::one( $nothing, '//p[@class="fh-map__side-total"]' ) ) );
	}

	/**
	 * The request's region is read from its address as the browser sent it: the first of
	 * a repeated name, and only a region's key, in its case; anything else selects none.
	 * The view script's state, for the store the markup names, starts from it.
	 */
	public function test_the_region_comes_from_the_address(): void {
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		$states = array();
		Functions\when( 'wp_interactivity_state' )->alias(
			static function ( string $store, array $state ) use ( &$states ): array {
				$states[] = array( $store, $state['selected'] );
				return $state;
			}
		);
		$pressed = static function ( string $uri ): string {
			$_SERVER['REQUEST_URI'] = $uri;
			$document               = new \DOMDocument();
			$previous               = libxml_use_internal_errors( true );
			$document->loadHTML( '<?xml encoding="UTF-8"><body>' . Coverage_Map::for_request() . '</body>' );
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
			return self::text( self::one( new \DOMXPath( $document ), '//button[contains(@class, "fh-map__region")][@aria-pressed="true"]' ) );
		};

		$this->assertSame( 'Great BasinGBCC0', $pressed( '/firehouse/?region=GBCC' ) );
		$this->assertSame( 'Great BasinGBCC0', $pressed( '/firehouse/?region=GBCC&region=AICC' ) );
		$this->assertSame( 'National0', $pressed( '/firehouse/?q=x&region=NATIONAL' ) );
		foreach ( array( '/firehouse/', '/firehouse/?region=gbcc', '/firehouse/?region=', '/firehouse/?region[]=GBCC', '/firehouse/?region=GBCC%20' ) as $uri ) {
			$this->assertSame( 'All submissions0', $pressed( $uri ), $uri );
		}
		$this->assertSame( array( Coverage_Map::STORE, 'GBCC' ), $states[0] );
		$this->assertSame( array( Coverage_Map::STORE, 'NATIONAL' ), $states[2] );
		$this->assertSame( array( Coverage_Map::STORE, null ), $states[3] );
	}

	/**
	 * The map's stage and credit come first, as on the site: the canvas the script draws
	 * into, hidden from assistive technology; the decorative scan; the labels toggle,
	 * pressed; the basemap notice, hidden until the tiles fail; and the credit, with the
	 * basemap's attribution from the shared file. The script hides both if the map can't
	 * be drawn.
	 */
	public function test_the_map_stage_and_credit(): void {
		$xpath = self::dom();
		$map   = self::one( $xpath, '//div[@class="fh-map"]' );
		$this->assertSame( array( 'div', 'p', 'div' ), array_slice( self::tags( $xpath, $map ), 0, 3 ) );

		$stage = self::one( $xpath, '//div[@class="fh-map"]/div[@class="fh-map__stage"]' );
		$this->assertSame( 'state.failed', $stage->getAttribute( 'data-wp-bind--hidden' ) );
		$this->assertFalse( $stage->hasAttribute( 'hidden' ) );
		$canvas = self::one( $xpath, '//div[@class="fh-map__stage"]/div[@class="fh-map__canvas"]' );
		$this->assertSame( array( 'true', 'callbacks.draw', '' ), array( $canvas->getAttribute( 'aria-hidden' ), $canvas->getAttribute( 'data-wp-init' ), $canvas->textContent ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the DOM's own name.
		$this->assertSame( 'true', self::one( $xpath, '//div[@class="fh-map__stage"]/div[@class="fh-map__scan"]' )->getAttribute( 'aria-hidden' ) );

		$toggle = self::one( $xpath, '//div[@class="fh-map__stage"]/button[@class="fh-map__codes-toggle"]' );
		$this->assertSame(
			array( 'GACC labels', 'true', 'state.showCodes', 'actions.toggleCodes', true, '!state.drawn' ),
			array( self::text( $toggle ), $toggle->getAttribute( 'aria-pressed' ), $toggle->getAttribute( 'data-wp-bind--aria-pressed' ), $toggle->getAttribute( 'data-wp-on--click' ), $toggle->hasAttribute( 'hidden' ), $toggle->getAttribute( 'data-wp-bind--hidden' ) )
		);
		$this->assertSame( 'true', self::one( $xpath, '//button[@class="fh-map__codes-toggle"]/span[@class="fh-map__codes-box"]' )->getAttribute( 'aria-hidden' ) );

		$offline = self::one( $xpath, '//div[@class="fh-map__stage"]/p[@class="fh-map__offline"]' );
		$this->assertSame(
			array( 'Basemap unavailable — region boundaries and counts are still shown.', 'status', true, '!state.tilesFailed' ),
			array( self::text( $offline ), $offline->getAttribute( 'role' ), $offline->hasAttribute( 'hidden' ), $offline->getAttribute( 'data-wp-bind--hidden' ) )
		);

		$credit = self::one( $xpath, '//div[@class="fh-map"]/p[@class="fh-map__credit"]' );
		$this->assertSame( 'Boundaries: NIFC National GACC Boundaries. Basemap: ' . Map_Colors::attribution() . '.', self::text( $credit ) );
		$this->assertSame( 'Boundaries: NIFC National GACC Boundaries. Basemap: Esri, HERE, Garmin, © OpenStreetMap contributors.', self::text( $credit ) );
		$this->assertSame( 'state.failed', $credit->getAttribute( 'data-wp-bind--hidden' ) );
	}

	/**
	 * The script's hooks: the map is the region it takes over, named but not yet
	 * interactive; each region's button carries its key and the directives that press it
	 * and choose with it; Clear clears; the heading's parts, the results' parts and the
	 * status line are bound to the state; and the list's projects stand in for the
	 * template the script draws them from, which follows them.
	 */
	public function test_the_script_takes_over(): void {
		$xpath = self::dom( 'NWCC' );
		$map   = self::one( $xpath, '//div[@class="fh-map"]' );
		$this->assertSame( array( Coverage_Map::STORE, false ), array( $map->getAttribute( 'data-fh-interactive' ), $map->hasAttribute( 'data-wp-interactive' ) ) );

		$keys = array();
		foreach ( $xpath->query( '//button[contains(@class, "fh-map__region")]' ) as $button ) {
			$keys[] = json_decode( $button->getAttribute( 'data-wp-context' ), true )['key'];
			$this->assertSame(
				array( 'actions.select', 'state.pressed', 'state.pressed' ),
				array( $button->getAttribute( 'data-wp-on--click' ), $button->getAttribute( 'data-wp-bind--aria-pressed' ), $button->getAttribute( 'data-wp-class--fh-map__region--on' ) )
			);
		}
		$this->assertSame( array_merge( array( null ), \Firehouse\Domain\Vocabulary::regions() ), $keys );
		$this->assertSame( 'actions.clear', self::one( $xpath, '//button[@class="fh-map__clear"]' )->getAttribute( 'data-wp-on--click' ) );

		$bound = static fn( string $expression, string $attribute ): string => self::one( $xpath, $expression )->getAttribute( $attribute );
		$this->assertSame(
			array( '!state.none', 'state.none', '!state.shown.length', '!state.empty', 'state.before', 'state.name', 'state.after', 'state.announced' ),
			array(
				$bound( '//p[@class="fh-map__hint"]', 'data-wp-bind--hidden' ),
				$bound( '//div[@class="fh-map__results-head"]', 'data-wp-bind--hidden' ),
				$bound( '//ul[@class="fh-map__projects"]', 'data-wp-bind--hidden' ),
				$bound( '//p[@class="fh-map__empty"]', 'data-wp-bind--hidden' ),
				$bound( '//h3[@class="fh-map__results-title"]/span[1]', 'data-wp-text' ),
				$bound( '//h3[@class="fh-map__results-title"]/strong', 'data-wp-text' ),
				$bound( '//h3[@class="fh-map__results-title"]/span[2]', 'data-wp-text' ),
				$bound( '//div[@class="fh-map__results"]/p[@role="status"]', 'data-wp-text' ),
			)
		);

		$list = self::one( $xpath, '//ul[@class="fh-map__projects"]' );
		$this->assertSame( array( 'li', 'li', 'template' ), self::tags( $xpath, $list ) );
		foreach ( $xpath->query( '//ul[@class="fh-map__projects"]/li' ) as $item ) {
			$this->assertSame( Coverage_Map::STORE . '::state.shown', $item->getAttribute( 'data-wp-each-child' ) );
		}
		$template = self::one( $xpath, '//ul[@class="fh-map__projects"]/template' );
		$this->assertSame( array( 'state.shown', 'context.project.url' ), array( $template->getAttribute( 'data-wp-each--project' ), $template->getAttribute( 'data-wp-each-key' ) ) );
		// WordPress takes the server's projects over element by element: each has the
		// template's shape, at a region with a national study and one without, and at
		// National, where no tag shows.
		$item = self::shape( self::one( $xpath, '//template/li' ) );
		foreach ( array( 'NWCC', 'ONCC', 'NATIONAL' ) as $key ) {
			$at = self::dom( $key );
			$this->assertGreaterThan( 0, $at->query( '//ul[@class="fh-map__projects"]/li' )->length, $key );
			foreach ( $at->query( '//ul[@class="fh-map__projects"]/li' ) as $server ) {
				$this->assertSame( $item, self::shape( $server ), $key );
			}
		}

		$link = self::one( $xpath, '//template/li/a[@class="fh-map__project"]' );
		$this->assertSame( 'context.project.url', $link->getAttribute( 'data-wp-bind--href' ) );
		$this->assertSame(
			array( 'context.project.title', '!state.tagged', 'National', 'context.project.year', 1 ),
			array(
				$bound( '//template/li/a/span[1]', 'data-wp-text' ),
				$bound( '//template//span[@class="fh-map__project-tag"]', 'data-wp-bind--hidden' ),
				self::text( self::one( $xpath, '//template//span[@class="fh-map__project-tag"]' ) ),
				$bound( '//template//span[@class="fh-map__project-year"]', 'data-wp-text' ),
				$xpath->query( '//template/li/a/svg' )->length,
			)
		);
	}

	/**
	 * What the script starts from: the selection, each project as the results list it,
	 * the counts that colour the map, the regions' names, and the words, the shared
	 * cases' own.
	 */
	public function test_the_state(): void {
		$projects = Coverage_Map::projects( self::all() );
		$state    = Coverage_Map::state( $projects, 'NWCC' );
		$this->assertSame( array( 'selected', 'projects', 'counts', 'names', 'tileHost', 'strings' ), array_keys( $state ) );
		$this->assertSame( 'https://server.arcgisonline.com', $state['tileHost'] );
		$this->assertSame( 'NWCC', $state['selected'] );
		$this->assertSame(
			array(
				'title'    => 'Smoke alerts for evacuation decisions',
				'year'     => '2025',
				'url'      => 'https://example.test/firehouse/projects/smoke-alerts-for-evacuation/',
				'regions'  => array( 'NWCC', 'ONCC' ),
				'national' => false,
			),
			$state['projects'][0]
		);
		$this->assertCount( count( $projects ), $state['projects'] );
		$this->assertSame( Coverage_Map::counts( $projects ), $state['counts'] );
		$this->assertSame( 2, $state['counts']['NWCC'] );
		$this->assertSame( 'Great Basin', $state['names']['GBCC'] );
		$this->assertSame( \Firehouse\Domain\Vocabulary::regions(), array_keys( $state['names'] ) );
		$this->assertSame( Cases::read( 'coverage-map-view.json' )['strings'], $state['strings'] );
		$this->assertNull( Coverage_Map::state( $projects, null )['selected'] );

		// An address WordPress wouldn't keep doesn't reach the script, which binds it as it is.
		$odd = array( array( 'url' => 'javascript:alert(1)' ) + $projects[0] );
		$this->assertSame( '', Coverage_Map::state( $odd, null )['projects'][0]['url'] );
	}

	/**
	 * The heading splits around the region's name as the script splits it, for each
	 * count, and for a translation that puts the name first.
	 */
	public function test_the_heading_splits_as_the_script_does(): void {
		$cases = Cases::read( 'coverage-map-view.json' );
		foreach ( $cases['headings'] as $case ) {
			$this->assertSame( $case['parts'], Coverage_Map::heading( $cases['strings']['heading'][ 1 === $case['count'] ? 0 : 1 ], $case['count'] ), (string) $case['count'] );
		}
		foreach ( $cases['reordered'] as $case ) {
			$this->assertSame( $case['parts'], Coverage_Map::heading( $case['format'], $case['count'] ), $case['format'] );
		}
	}
}
