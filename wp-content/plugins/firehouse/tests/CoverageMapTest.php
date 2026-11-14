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
 * `?region=` and its key, or for none.
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
		$this->assertSame( 'fh-map__region fh-map__region--on', self::one( $xpath, '//button[@aria-pressed="true"]' )->getAttribute( 'class' ) );
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
		$this->assertSame( '<p class="fh-visually-hidden" role="status"></p>', $status->ownerDocument->saveHTML( $status ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the DOM's own name.
		$this->assertSame( $status, $results->lastChild ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- as above.
		$this->assertSame( 0, $xpath->query( '//h3[@class="fh-map__results-title"]' )->length );
	}

	/**
	 * Every region button, "All submissions" and "Clear" submit the one form, a hidden
	 * GET form back to the section's heading on the landing page. A button that selects
	 * a region brings the page back at the results instead (its `formaction`); one that
	 * selects none, beside the list, keeps the heading.
	 */
	public function test_every_button_submits_the_one_form(): void {
		$xpath = self::dom( 'GBCC' );

		$form = self::one( $xpath, '//form' );
		$this->assertSame(
			array( Coverage_Map::FORM, 'get', self::URL . '#coverage-heading', true ),
			array( $form->getAttribute( 'id' ), $form->getAttribute( 'method' ), $form->getAttribute( 'action' ), $form->hasAttribute( 'hidden' ) )
		);
		$this->assertSame( 0, $form->childNodes->length ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the DOM's own name.
		$buttons = $xpath->query( '//button' );
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
		$this->assertSame( 'fh-map__region fh-map__region--on', self::one( $xpath, '//button[@aria-pressed="true"]' )->getAttribute( 'class' ) );
		$this->assertSame( 0, $xpath->query( '//p[@class="fh-map__hint"]' )->length );

		$head = self::one( $xpath, '//div[@class="fh-map__results"]/div[@class="fh-map__results-head"]' );
		$this->assertSame( '2 projects in NorthwestClear', self::text( $head ) );
		$this->assertSame( 'svg', $head->firstChild->nodeName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the DOM's own name.
		$title = self::one( $xpath, '//div[@class="fh-map__results-head"]/h3[@class="fh-map__results-title"]' );
		$this->assertSame( '2 projects in Northwest', self::text( $title ) );
		$this->assertSame( 'Northwest', self::text( self::one( $xpath, '//h3[@class="fh-map__results-title"]/strong' ) ) );
		$this->assertSame( 'Clear', self::text( self::one( $xpath, '//div[@class="fh-map__results-head"]/button[@class="fh-map__clear"]' ) ) );
		$status = self::one( $xpath, '//p[@role="status"]' );
		$this->assertSame( '<p class="fh-visually-hidden" role="status"></p>', $status->ownerDocument->saveHTML( $status ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the DOM's own name.

		$links = array();
		foreach ( $xpath->query( '//ul[@class="fh-map__projects"]/li/a[@class="fh-map__project"]' ) as $link ) {
			$links[] = array( $link->getAttribute( 'href' ), self::text( $link ), $xpath->query( 'span[@class="fh-map__project-tag"]', $link )->length, $xpath->query( 'svg', $link )->length );
		}
		$this->assertSame(
			array(
				array( 'https://example.test/firehouse/projects/smoke-alerts-for-evacuation/', 'Smoke alerts for evacuation decisions2025', 0, 1 ),
				array( 'https://example.test/firehouse/projects/national-outlook-study/', 'National outlook studyNational2024', 1, 1 ),
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
		$this->assertSame( 0, $national->query( '//span[@class="fh-map__project-tag"]' )->length );
		$this->assertSame( 1, $national->query( '//a[@class="fh-map__project"]' )->length );

		$empty = self::dom( 'INTL' );
		$this->assertSame( '0 projects in International', self::text( self::one( $empty, '//h3[@class="fh-map__results-title"]' ) ) );
		$this->assertSame( 'No published research is filed under this region yet.', self::text( self::one( $empty, '//p[@class="fh-map__empty"]' ) ) );
		$this->assertSame( 0, $empty->query( '//ul[@class="fh-map__projects"]' )->length );
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

		$this->assertSame( 'Fire & <smoke> "plumes"2026', self::text( self::one( $xpath, '//a[@class="fh-map__project"]' ) ) );
		$this->assertSame( '1 submission', self::text( self::one( $xpath, '//p[@class="fh-map__side-total"]' ) ) );
		$this->assertSame( 'Great BasinGBCC0', self::buttons( $xpath )[6][0] );
		$this->assertSame( array( 'SWCC' ), Coverage_Map::projects( array( $project ) )[0]['regions'] );

		$nothing = self::dom( null, array( array( 'geo' => 'none' ) + $project ) );
		$this->assertSame( '0 submissions', self::text( self::one( $nothing, '//p[@class="fh-map__side-total"]' ) ) );
	}

	/**
	 * The request's region is read from its address as the browser sent it: the first of
	 * a repeated name, and only a region's key, in its case; anything else selects none.
	 */
	public function test_the_region_comes_from_the_address(): void {
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		$pressed = static function ( string $uri ): string {
			$_SERVER['REQUEST_URI'] = $uri;
			$document               = new \DOMDocument();
			$previous               = libxml_use_internal_errors( true );
			$document->loadHTML( '<?xml encoding="UTF-8"><body>' . Coverage_Map::for_request() . '</body>' );
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
			return self::text( self::one( new \DOMXPath( $document ), '//button[@aria-pressed="true"]' ) );
		};

		$this->assertSame( 'Great BasinGBCC0', $pressed( '/firehouse/?region=GBCC' ) );
		$this->assertSame( 'Great BasinGBCC0', $pressed( '/firehouse/?region=GBCC&region=AICC' ) );
		$this->assertSame( 'National0', $pressed( '/firehouse/?q=x&region=NATIONAL' ) );
		foreach ( array( '/firehouse/', '/firehouse/?region=gbcc', '/firehouse/?region=', '/firehouse/?region[]=GBCC', '/firehouse/?region=GBCC%20' ) as $uri ) {
			$this->assertSame( 'All submissions0', $pressed( $uri ), $uri );
		}
	}
}
