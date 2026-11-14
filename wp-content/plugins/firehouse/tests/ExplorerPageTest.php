<?php
/**
 * Tests for the project explorer.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Domain\Derive;
use Firehouse\Domain\Js;
use Firehouse\Domain\Search;
use Firehouse\Explorer_Page;
use Firehouse\Store;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The explorer lists what the site's explorer lists for the same address, in the
 * site's markup, with every control in one form that works without JavaScript, and
 * carries what its view script needs to take the page over.
 *
 * The page is read as a browser without the script shows it: through the DOM, without
 * what's hidden or inside a template.
 */
final class ExplorerPageTest extends WordPressTestCase {

	private const URL = 'https://example.test/firehouse/projects/';

	/**
	 * Stands in for WordPress's addresses, escaping and shared JSON. esc_html() and
	 * esc_attr() leave a character reference alone, as WordPress's do, where Brain
	 * Monkey's would escape it again; esc_url() drops an encoded line break and writes
	 * an & as &#038;, as WordPress's does.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\stubEscapeFunctions();
		$escape = static fn( string $text ): string => htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false );
		Functions\when( 'esc_html' )->alias( $escape );
		Functions\when( 'esc_attr' )->alias( $escape );
		Functions\when( 'esc_url' )->alias(
			static function ( string $url ): string {
				do {
					$before = $url;
					$url    = str_ireplace( array( '%0d', '%0a' ), '', $url );
				} while ( $before !== $url );
				return str_replace( array( '&', "'" ), array( '&#038;', '&#039;' ), $url );
			}
		);
		Functions\when( 'wp_json_file_decode' )->alias(
			static fn( string $filename, array $options = array() ): mixed => json_decode( (string) file_get_contents( $filename ), ! empty( $options['associative'] ) )
		);
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://example.test' . $path );
		Functions\when( 'wp_interactivity_data_wp_context' )->alias(
			static fn( array $context ): string => "data-wp-context='" . json_encode( $context, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP ) . "'" // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the stand-in for WordPress's.
		);
	}

	/**
	 * The shared site's published projects, each with its address, in the site's order.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function projects(): array {
		return array_map(
			static fn( array $project ): array => array( 'url' => self::URL . $project['slug'] . '/' ) + $project,
			Derive::published_projects( Cases::site() )
		);
	}

	/**
	 * The page for a query string.
	 *
	 * @param string                          $query    The query string.
	 * @param list<array<string, mixed>>|null $projects The projects, or null for the shared site's.
	 */
	private static function render( string $query = '', ?array $projects = null ): string {
		return Explorer_Page::render( $projects ?? self::projects(), Search::parse_query( $query ), self::URL );
	}

	/**
	 * The page as a DOM, for XPath.
	 *
	 * @param string $html The page.
	 */
	private static function dom( string $html ): \DOMXPath {
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="UTF-8"><body>' . $html . '</body>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return new \DOMXPath( $document );
	}

	/**
	 * The elements an XPath expression finds that a browser without the script shows:
	 * not hidden, nor inside anything hidden or a template.
	 *
	 * @param \DOMXPath $xpath      The page.
	 * @param string    $expression The expression.
	 * @return list<\DOMElement>
	 */
	private static function shown( \DOMXPath $xpath, string $expression ): array {
		$found = $xpath->query( $expression . '[not(ancestor-or-self::*[@hidden]) and not(ancestor::template)]' );
		return false === $found ? array() : array_values( array_filter( iterator_to_array( $found ), static fn( mixed $node ): bool => $node instanceof \DOMElement ) );
	}

	/**
	 * The one element an XPath expression finds that a browser shows; the test fails
	 * if there isn't exactly one.
	 *
	 * @param \DOMXPath $xpath      The page.
	 * @param string    $expression The expression.
	 * @throws \RuntimeException If there isn't exactly one.
	 */
	private static function one( \DOMXPath $xpath, string $expression ): \DOMElement {
		$found = self::shown( $xpath, $expression );
		if ( 1 !== count( $found ) ) {
			throw new \RuntimeException( count( $found ) . ' shown at ' . $expression ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a test's message.
		}
		return $found[0];
	}

	/**
	 * The text a browser shows of a node: without what's hidden or in a template, its
	 * spaces collapsed.
	 *
	 * @param \DOMNode $node The node.
	 */
	private static function text( \DOMNode $node ): string {
		$read = static function ( \DOMNode $node ) use ( &$read ): string {
			if ( $node instanceof \DOMElement && ( $node->hasAttribute( 'hidden' ) || 'template' === $node->nodeName ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the DOM's own name.
				return '';
			}
			if ( $node instanceof \DOMText ) {
				return $node->data;
			}
			$text = '';
			foreach ( $node->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- as above.
				$text .= $read( $child );
			}
			return $text;
		};
		return trim( (string) preg_replace( '/\s+/u', ' ', $read( $node ) ) );
	}

	/**
	 * A select's options as a browser without the script shows them: each one's value,
	 * label and whether it's chosen, as "value|label|*" for chosen.
	 *
	 * @param \DOMXPath $xpath The page.
	 * @param string    $name  The parameter the select sets.
	 * @return list<string>
	 */
	private static function options( \DOMXPath $xpath, string $name ): array {
		return array_map(
			static fn( \DOMElement $option ): string => $option->getAttribute( 'value' ) . '|' . self::text( $option ) . ( $option->hasAttribute( 'selected' ) ? '|*' : '' ) . ( $option->hasAttribute( 'disabled' ) ? '|disabled' : '' ),
			self::shown( $xpath, "//select[@name='$name']//option" )
		);
	}

	/**
	 * The results line as a browser shows it.
	 *
	 * @param \DOMXPath $xpath The page.
	 */
	private static function results_line( \DOMXPath $xpath ): string {
		return self::text( self::one( $xpath, '//p[@role="status"]' ) );
	}

	/**
	 * With nothing asked, every project shows, newest first, under a count, with the
	 * search and the five selects in one GET form and a button that sends it.
	 */
	public function test_the_page_with_no_query(): void {
		$projects = self::projects();
		$xpath    = self::dom( self::render() );

		$this->assertSame( 'Completed research projects', self::text( self::one( $xpath, '//h1' ) ) );
		$this->assertSame( 'Search and filter projects', self::one( $xpath, '//search' )->getAttribute( 'aria-label' ) );
		$form = self::one( $xpath, '//form' );
		$this->assertSame( array( 'fh-explorer-form', 'get', self::URL . '#fh-content', 'off' ), array( $form->getAttribute( 'id' ), $form->getAttribute( 'method' ), $form->getAttribute( 'action' ), $form->getAttribute( 'autocomplete' ) ) );
		$input = self::one( $xpath, '//input[@name="q"]' );
		$this->assertSame( array( 'search', '', 'Search projects', 'fh-explorer-q-hint' ), array( $input->getAttribute( 'type' ), $input->getAttribute( 'value' ), $input->getAttribute( 'placeholder' ), $input->getAttribute( 'aria-describedby' ) ) );
		$this->assertSame( 'Searches titles, authors, abstracts, and findings.', self::text( self::one( $xpath, '//*[@id="fh-explorer-q-hint"]' ) ) );
		$this->assertSame( array(), self::shown( $xpath, '//a[contains(@class, "fh-search__clear")]' ) );
		foreach ( array( 'phase', 'region', 'year', 'type', 'sort' ) as $name ) {
			$this->assertSame( 'fh-explorer-form', self::one( $xpath, "//select[@name='$name']" )->getAttribute( 'form' ), $name );
		}
		$button = self::one( $xpath, '//button[@type="submit"]' );
		$this->assertSame( array( 'fh-explorer-form', 'Update results' ), array( $button->getAttribute( 'form' ), self::text( $button ) ) );
		$this->assertSame( array(), self::shown( $xpath, '//button[contains(@class, "fh-explorer__facets-toggle")]' ) );
		$this->assertSame( count( $projects ) . ' projects', self::results_line( $xpath ) );
		$this->assertSame( array(), self::shown( $xpath, '//ul[contains(@class, "fh-explorer__applied")]' ) );
		$this->assertSame( array(), self::shown( $xpath, '//*[contains(@class, "fh-filter--set")]' ) );
		$this->assertSame( array(), self::shown( $xpath, '//*[contains(@class, "fh-explorer__empty")]' ) );

		$links = array_map( static fn( \DOMElement $link ): string => $link->getAttribute( 'href' ), self::shown( $xpath, '//a[@class="fh-tile__link"]' ) );
		$this->assertSame( array_column( Search::filter_projects( $projects, Search::parse_query( '' ) ), 'url' ), $links );
		$this->assertCount( count( $projects ), $links );
	}

	/**
	 * The view script takes the page over: the explorer is its store's, each list it
	 * redraws has its template, and what the server printed of each list says it stands
	 * in for it.
	 */
	public function test_the_script_takes_the_page_over(): void {
		$html  = self::render( 'q=smoke&phase=during' );
		$xpath = self::dom( $html );

		// The region is the store's only once the script has loaded and marked it so.
		$root = self::one( $xpath, '//div[contains(@class, "fh-explorer ")]' );
		$this->assertSame( array( 'firehouse/explorer', '', 'callbacks.adopt' ), array( $root->getAttribute( 'data-fh-interactive' ), $root->getAttribute( 'data-wp-interactive' ), $root->getAttribute( 'data-wp-init' ) ) );
		$this->assertStringNotContainsString( 'data-wp-interactive', $html );
		// Each list's template follows what the server printed of it, so those items keep their nodes.
		$this->assertMatchesRegularExpression( '#data-wp-each-child="firehouse/explorer::state.results">.*<template data-wp-each--project#s', $html );
		$this->assertMatchesRegularExpression( '#data-wp-each-child="firehouse/explorer::state.applied">.*<template data-wp-each--filter#s', $html );
		$this->assertMatchesRegularExpression( '#data-wp-each-child="firehouse/explorer::state.sortOptions">[^<]*</option><template data-wp-each--option#', $html );
		foreach ( array( 'state.results', 'state.applied', 'state.sortOptions' ) as $list ) {
			$this->assertMatchesRegularExpression( '/<template data-wp-each--[a-z]+="' . preg_quote( $list, '/' ) . '" data-wp-each-key="context\.[a-z]+\.[a-z]+">/', $html, $list );
		}
		$tiles = $xpath->query( '//article[@class="fh-tile"][not(ancestor::template)]' );
		$this->assertNotFalse( $tiles );
		$this->assertGreaterThan( 0, $tiles->length );
		foreach ( $tiles as $tile ) {
			$this->assertInstanceOf( \DOMElement::class, $tile );
			$this->assertSame( 'firehouse/explorer::state.results', $tile->getAttribute( 'data-wp-each-child' ) );
		}
		$this->assertSame( 'firehouse/explorer::state.applied', self::one( $xpath, '//li[a[@class="fh-pill"]]' )->getAttribute( 'data-wp-each-child' ) );
		// What only the script can do is printed hidden, and the button it replaces bound to go.
		$this->assertSame( 'state.live', self::one( $xpath, '//div[@class="fh-explorer__apply"]' )->getAttribute( 'data-wp-bind--hidden' ) );
		$toggle = $xpath->query( '//button[contains(@class, "fh-explorer__facets-toggle")][@hidden][@aria-controls="fh-explorer-facets"]' );
		$this->assertSame( 1, false === $toggle ? 0 : $toggle->length );
	}

	/**
	 * Each option says how many projects it would leave; one that would leave none stays
	 * enabled, since without JavaScript the counts don't follow a change to another
	 * select. The GACCs and the other regions are in groups, as on the site; years run
	 * newest first and types in order. Each option carries its value and label for the
	 * script.
	 */
	public function test_the_options_and_their_counts(): void {
		$xpath = self::dom( self::render() );

		$this->assertSame( array( '|Any phase|*', 'before|Pre-fire planning, prevention, and mitigation (1)', 'during|Fire detection, active suppression, and downstream impacts (2)', 'after|Postfire recovery and rehabilitation (1)', 'long-term|Planning, adaptation, and policy (1)' ), self::options( $xpath, 'phase' ) );
		$region = self::options( $xpath, 'region' );
		$this->assertSame( array( '|Any area|*', 'AICC|Alaska (AICC) (1)' ), array_slice( $region, 0, 2 ) );
		// The national study counts in every GACC.
		$this->assertContains( 'GBCC|Great Basin (GBCC) (1)', $region );
		$this->assertContains( 'PACIFIC|Hawaii / Pacific (1)', $region );
		$this->assertContains( 'INTL|International (0)', $region );
		$this->assertSame( array( '|Any year|*', '2025|2025 (2)', '2024|2024 (2)' ), self::options( $xpath, 'year' ) );
		$this->assertSame( array( '|Any type|*', 'Program Evaluation|Program Evaluation (1)', 'Research Study|Research Study (2)', 'Testbed Evaluation|Testbed Evaluation (1)' ), self::options( $xpath, 'type' ) );
		$this->assertSame( array( 'during', 'Fire detection, active suppression, and downstream impacts' ), array_values( (array) json_decode( self::one( $xpath, '//option[@value="during"]' )->getAttribute( 'data-wp-context' ), true ) ) );
		$this->assertSame( 'type', json_decode( self::one( $xpath, '//div[@data-wp-context][.//select[@name="type"]]' )->getAttribute( 'data-wp-context' ), true )['facet'] );
	}

	/**
	 * 2025 and 2025.0 are one year, as one number in JavaScript; a project without a
	 * type adds no option.
	 */
	public function test_a_whole_float_year_is_one_option(): void {
		$projects                      = self::projects();
		$projects[0]['completionYear'] = (float) $projects[0]['completionYear'];
		unset( $projects[1]['projectType'] );

		$html  = self::render( '', $projects );
		$xpath = self::dom( $html );

		$this->assertSame( array( '|Any year|*', '2025|2025 (2)', '2024|2024 (2)' ), self::options( $xpath, 'year' ) );
		$this->assertContains( 'Research Study|Research Study (1)', self::options( $xpath, 'type' ) );
		$this->assertStringNotContainsString( '2025.0', $html );
	}

	/**
	 * The sort's options, the applied filters and the results line's words are worked
	 * out as the view script works them out, from the shared cases (shared/cases/explorer.json).
	 *
	 * @param array<string, mixed> $shared_case The case.
	 */
	#[DataProvider( 'shared_cases' )]
	public function test_shared_case( array $shared_case ): void {
		$query     = Search::parse_query( $shared_case['query'] );
		$xpath     = self::dom( self::render( $shared_case['query'] ) );
		$searching = '' !== Js::trim( $query['q'] );
		$options   = Explorer_Page::sort_options( $query, $searching );
		foreach ( $options as &$option ) {
			unset( $option['key'] );
		}
		unset( $option );

		$this->assertSame( $shared_case['sortOptions'], $options );
		$this->assertSame(
			array_map( static fn( array $option ): string => $option['value'] . '|' . $option['label'] . ( $option['selected'] ? '|*' : '' ), $shared_case['sortOptions'] ),
			self::options( $xpath, 'sort' )
		);
		$this->assertSame( $shared_case['applied'], array_values( Explorer_Page::applied( $query ) ) );
		$this->assertSame( $shared_case['applied'], array_map( array( self::class, 'text' ), self::shown( $xpath, '//a[@class="fh-pill"]' ) ) );
		$this->assertSame( $shared_case['countRest'], self::text( self::one( $xpath, '//span[@data-wp-text="state.countRest"]' ) ) );
	}

	/**
	 * The shared cases.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function shared_cases(): array {
		return Cases::provider( 'explorer.json' );
	}

	/**
	 * A search shows what it matched of how many, a link that clears it, and Clear all,
	 * which keeps the sort, as the site's does.
	 */
	public function test_a_search(): void {
		$xpath = self::dom( self::render( 'q=+smoke+&sort=title' ) );

		$this->assertSame( 'Showing 1 of 4 projects matching “smoke”', self::results_line( $xpath ) );
		$this->assertSame( ' smoke ', self::one( $xpath, '//input[@name="q"]' )->getAttribute( 'value' ) );
		$clear = self::one( $xpath, '//a[@class="fh-search__clear"]' );
		$this->assertSame( array( self::URL . '?sort=title#fh-content', 'Clear search' ), array( $clear->getAttribute( 'href' ), $clear->getAttribute( 'aria-label' ) ) );
		self::one( $xpath, '//ul[@class="fh-explorer__applied"]' );
		$this->assertSame( self::URL . '?sort=title#fh-content', self::one( $xpath, '//a[@class="fh-explorer__clear-all"]' )->getAttribute( 'href' ) );
		$this->assertSame( array(), self::shown( $xpath, '//a[@class="fh-pill"]' ) );
		$this->assertCount( 1, self::shown( $xpath, '//a[@class="fh-tile__link"]' ) );
	}

	/**
	 * Each filter applied is a link to the same address without it, named for what it
	 * removes, and its select shows it as set.
	 */
	public function test_each_filter_is_a_link_that_removes_it(): void {
		$xpath = self::dom( self::render( 'q=smoke&phase=during&region=NWCC&year=2025&type=Testbed+Evaluation&sort=oldest' ) );

		$pills = array_map(
			static fn( \DOMElement $pill ): array => array( $pill->getAttribute( 'href' ), $pill->getAttribute( 'aria-label' ), self::text( $pill ) ),
			self::shown( $xpath, '//a[@class="fh-pill"]' )
		);
		$this->assertSame(
			array(
				array( self::URL . '?q=smoke&region=NWCC&year=2025&type=Testbed+Evaluation&sort=oldest#fh-content', 'Remove filter Phase: During', 'Phase: During' ),
				array( self::URL . '?q=smoke&phase=during&year=2025&type=Testbed+Evaluation&sort=oldest#fh-content', 'Remove filter GACC: Northwest', 'GACC: Northwest' ),
				array( self::URL . '?q=smoke&phase=during&region=NWCC&type=Testbed+Evaluation&sort=oldest#fh-content', 'Remove filter Year: 2025', 'Year: 2025' ),
				array( self::URL . '?q=smoke&phase=during&region=NWCC&year=2025&sort=oldest#fh-content', 'Remove filter Type: Testbed Evaluation', 'Type: Testbed Evaluation' ),
			),
			$pills
		);
		$this->assertCount( 4, self::shown( $xpath, '//div[contains(concat(" ", @class, " "), " fh-filter--set ")]' ) );
		foreach ( array(
			'phase'  => 'during',
			'region' => 'NWCC',
			'year'   => '2025',
			'type'   => 'Testbed Evaluation',
		) as $name => $value ) {
			$this->assertSame( $value, self::one( $xpath, "//select[@name='$name']//option[@selected]" )->getAttribute( 'value' ), $name );
		}
		// The toggle's badge, which shows once the script does.
		$this->assertSame( '4', (string) $xpath->evaluate( 'string(//span[@data-wp-text="state.facetCount"])' ) );
	}

	/**
	 * A filter that leaves nothing stays chosen and enabled, so the select shows it, and
	 * the empty state offers to clear everything.
	 */
	public function test_a_filter_that_leaves_nothing(): void {
		$xpath = self::dom( self::render( 'region=INTL' ) );

		$this->assertContains( 'INTL|International (0)|*', self::options( $xpath, 'region' ) );
		$this->assertSame( 'Showing 0 of 4 projects', self::results_line( $xpath ) );
		$this->assertSame( 'No projects match your search and filters.', self::text( self::one( $xpath, '//p[@class="fh-explorer__empty-title"]' ) ) );
		self::one( $xpath, '//p[@class="fh-explorer__empty-body"]' );
		$actions = self::shown( $xpath, '//a[@class="fh-explorer__empty-action"]' );
		$this->assertSame( array( array( self::URL . '#fh-content', 'Clear search and filters' ) ), array_map( static fn( \DOMElement $link ): array => array( $link->getAttribute( 'href' ), self::text( $link ) ), $actions ) );
		$this->assertSame( array(), self::shown( $xpath, '//div[contains(@class, "fh-explorer__grid")]' ) );
	}

	/**
	 * With no projects to show, the page says so and points to the topic areas.
	 */
	public function test_no_projects(): void {
		$xpath = self::dom( self::render( '', array() ) );

		$this->assertSame( '0 projects', self::results_line( $xpath ) );
		$this->assertSame( 'No published projects are available yet.', self::text( self::one( $xpath, '//p[@class="fh-explorer__empty-title"]' ) ) );
		$this->assertSame( 'https://example.test/firehouse/#top-needs', self::one( $xpath, '//a[@class="fh-explorer__empty-action"]' )->getAttribute( 'href' ) );
		$this->assertSame( array(), self::shown( $xpath, '//p[@class="fh-explorer__empty-body"]' ) );
	}

	/**
	 * One project is "1 project", as the site says it.
	 */
	public function test_one_project_is_singular(): void {
		$this->assertSame( '1 project', self::results_line( self::dom( self::render( '', array( self::projects()[0] ) ) ) ) );
		$this->assertSame( 'Showing 1 of 1 project', self::results_line( self::dom( self::render( 'phase=during', array( self::projects()[0] ) ) ) ) );
	}

	/**
	 * An encoded line break in the search stays in the links, as the site keeps it, so
	 * removing a filter doesn't run two search words into one; in the search box, and in
	 * the script's text, a space stands for it.
	 */
	public function test_links_keep_an_encoded_line_break(): void {
		$query = 'q=smoke%0Afire&phase=during';
		$xpath = self::dom( self::render( $query ) );

		$this->assertSame( self::URL . '?q=smoke%0Afire#fh-content', self::one( $xpath, '//a[@class="fh-pill"]' )->getAttribute( 'href' ) );
		$this->assertSame( self::URL . '?phase=during#fh-content', self::one( $xpath, '//a[@class="fh-search__clear"]' )->getAttribute( 'href' ) );
		// A one-line field can't hold a line break, so the field shows it as a space.
		$this->assertSame( 'smoke fire', self::one( $xpath, '//input[@name="q"]' )->getAttribute( 'value' ) );
		$this->assertSame( 'smoke fire', Explorer_Page::state( self::projects(), Search::parse_query( $query ), self::URL )['text'] );
	}

	/**
	 * Phases or regions stored in the wrong shape don't stop the page (rule 6): the
	 * project shows, without them.
	 */
	public function test_phases_and_regions_in_the_wrong_shape_dont_stop_the_page(): void {
		$projects                      = self::projects();
		$projects[0]['firePhases']     = 'during';
		$projects[1]['geo']['regions'] = 'NWCC';

		self::results_line( self::dom( self::render( 'phase=before&region=NWCC', $projects ) ) );
		$this->assertCount( count( $projects ), self::shown( self::dom( self::render( '', $projects ) ), '//a[@class="fh-tile__link"]' ) );
		$state = Explorer_Page::state( $projects, Search::parse_query( '' ), self::URL );
		$this->assertSame( array(), $state['projects'][0]['firePhases'] );
		$this->assertSame( array(), $state['projects'][1]['geo']['regions'] );
	}

	/**
	 * What the form sends, read back, is the query the page was drawn for: the search
	 * box's text, and each select's chosen option, or else the first one the browser
	 * would show. Each filter's link reads back as the query without that filter, and
	 * Clear all as the sort alone. So sending the form unchanged, or following a link,
	 * never loses or changes what the address asked for.
	 *
	 * @param string $address A query string.
	 */
	#[DataProvider( 'addresses' )]
	public function test_the_form_sends_back_its_query( string $address ): void {
		$query = Search::parse_query( $address );
		$xpath = self::dom( self::render( $address ) );

		// A browser drops the line breaks from a one-line field's value before sending it.
		$text  = str_replace( array( "\r", "\n" ), '', self::one( $xpath, '//input[@name="q"]' )->getAttribute( 'value' ) );
		$pairs = array( 'q=' . rawurlencode( $text ) );
		foreach ( array( 'phase', 'region', 'year', 'type', 'sort' ) as $name ) {
			$options = self::shown( $xpath, "//select[@name='$name']//option" );
			$chosen  = array_values( array_filter( $options, static fn( \DOMElement $option ): bool => $option->hasAttribute( 'selected' ) ) );
			$enabled = array_values( array_filter( $options, static fn( \DOMElement $option ): bool => ! $option->hasAttribute( 'disabled' ) ) );
			$option  = $chosen[0] ?? $enabled[0] ?? null;
			$this->assertInstanceOf( \DOMElement::class, $option, $name );
			$pairs[] = $name . '=' . rawurlencode( $option->getAttribute( 'value' ) );
		}
		// The text comes back with each line break a space: the same words.
		$this->assertSame( array( 'q' => strtr( $query['q'], "\r\n", '  ' ) ) + $query, Search::parse_query( implode( '&', $pairs ) ), 'the form' );

		// A link's query: after its "?", before the fragment it lands on.
		$query_of = function ( string $href ): array {
			$this->assertStringEndsWith( Explorer_Page::LANDING, $href );
			$href = substr( $href, 0, -strlen( Explorer_Page::LANDING ) );
			$at   = strpos( $href, '?' );
			return Search::parse_query( false === $at ? '' : substr( $href, $at + 1 ) );
		};
		$removed  = array_map( static fn( \DOMElement $pill ): array => $query_of( $pill->getAttribute( 'href' ) ), self::shown( $xpath, '//a[@class="fh-pill"]' ) );
		$expected = array();
		foreach ( Search::FACETS as $facet ) {
			if ( null !== $query[ $facet ] ) {
				$expected[] = array_merge( $query, array( $facet => null ) );
			}
		}
		$this->assertSame( $expected, $removed, 'the filters\' links' );

		foreach ( self::shown( $xpath, '//a[@class="fh-explorer__clear-all"]' ) as $clear ) {
			$this->assertSame( array_merge( Search::parse_query( '' ), array( 'sort' => $query['sort'] ) ), $query_of( $clear->getAttribute( 'href' ) ), 'Clear all' );
		}
	}

	/**
	 * Addresses for the round trip: none, each parameter, repeated, malformed and
	 * encoded ones, and a long search.
	 *
	 * @return array<string, array{string}>
	 */
	public static function addresses(): array {
		$addresses = array( '', 'q=smoke', 'q=smke', 'q=Example&sort=title', 'phase=after', 'phase=during&region=NWCC', 'year=2025', 'year=2025.0', 'year=abc', 'year=0x7E9', 'type=Research+Study', 'type=%20Testbed%20Evaluation%20', 'sort=oldest', 'sort=relevance', 'sort=bogus', 'q=fire&sort=relevance', 'q=fire&sort=newest', 'region=NATIONAL', 'region=PACIFIC', 'region=INTL', 'region=nwcc', 'q=zzzz', 'q=zzzz&sort=title', 'phase=before&phase=after', 'phase%5B%5D=after', 'q=%C3%89VACUATION', 'q=smoke&year=2025&type=Testbed+Evaluation&sort=title', 'q=%E2%80%9Csmoke%E2%80%9D', 'q=+', 'q=%3Cb%3Efire%3C%2Fb%3E', 'q=fire&phase=bogus', 'q=fire+%26+smoke', 'q=caf%C3%A9+%25+50%2B', 'year=2024&phase=after', 'q=smoke%0Afire&phase=during', 'q=smoke%0D%0Afire', 'q=%0A', 'q=a&q=b', 'q=' . str_repeat( 'fyre+', 25 ) );
		return array_combine( array_map( static fn( string $address ): string => '?' . $address, $addresses ), array_map( static fn( string $address ): array => array( $address ), $addresses ) );
	}

	/**
	 * A year or type no project has can't be chosen in its select, so the form drops it
	 * when it's sent, where the site keeps it until its filter is removed. An accepted
	 * difference: such an address lists nothing, and its filter shows as removable.
	 */
	public function test_a_year_no_project_has_is_dropped_when_the_form_is_sent(): void {
		$options = self::options( self::dom( self::render( 'year=2030&type=Nonexistent' ) ), 'year' );
		$this->assertSame( array( '|Any year', '2025|2025 (0)', '2024|2024 (0)' ), $options );
	}

	/**
	 * What the view script starts from: the query, the box's text, the explorer's
	 * address, and each project as the search reads it, in the shapes search.ts expects.
	 * Nothing but those fields is sent: no survey answer the site doesn't show, nothing
	 * editorial but a project's summary, address and geography note.
	 */
	public function test_the_state_the_script_starts_from(): void {
		$projects                     = self::projects();
		$projects[0]['contactEmail']  = 'someone@example.test';
		$projects[0]['irbApproved']   = true;
		$projects[0]['fullRecordUrl'] = 'https://example.test/record';
		$projects[0]['papers']        = array( 'Smith 2024, Journal of Fire', array( 'title' => 'A paper', 'container' => 7 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- one line reads more plainly here.
		$projects[0]['needs']         = array( 'a need as text', array( 'need' => 'A need' ) );
		$projects[0]['takeaways']     = array( 'One', null, 2 );
		$projects[0]['authors']       = array( array( 'name' => 'A. Name' ), 'B. Plain' );
		$state                        = Explorer_Page::state( $projects, Search::parse_query( 'q=smoke&phase=during' ), self::URL );
		$first                        = $state['projects'][0];

		$this->assertSame( array( 'projects', 'query', 'text', 'url', 'landing', 'short', 'sorts', 'strings' ), array_keys( $state ) );
		$this->assertSame( Search::parse_query( 'q=smoke&phase=during' ), $state['query'] );
		$this->assertSame( array( 'smoke', self::URL, '#fh-content' ), array( $state['text'], $state['url'], $state['landing'] ) );
		$this->assertSame( array( 'slug', 'url', 'title', 'projectType', 'authors', 'org', 'summary', 'abstract', 'takeaways', 'methods', 'needs', 'papers', 'geo', 'completionYear', 'firePhases', 'byline' ), array_keys( $first ) );
		$this->assertSame(
			array(
				array(
					'title'     => 'A paper',
					'container' => null,
				),
			),
			$first['papers']
		);
		$this->assertSame(
			array(
				array(
					'need'           => 'A need',
					'recommendation' => null,
				),
			),
			$first['needs']
		);
		$this->assertSame( array( 'One' ), $first['takeaways'] );
		$this->assertSame( array( array( 'name' => 'A. Name' ) ), $first['authors'] );
		$this->assertSame( 'A. Name', $first['byline'] );
		$this->assertSame( self::URL . $projects[0]['slug'] . '/', $first['url'] );
		$this->assertStringNotContainsString( 'example.test/record', (string) wp_json_encode( $state ) );
		$this->assertStringNotContainsString( 'someone@', (string) wp_json_encode( $state ) );
		$this->assertCount( count( $projects ), $state['projects'] );
		$this->assertSame(
			array(
				'before'    => 'Before',
				'during'    => 'During',
				'after'     => 'After',
				'long-term' => 'Long-term',
			),
			$state['short']['phase']
		);
		$this->assertSame( 'Remove filter %s', $state['strings']['remove'] );
		// The words are the shared cases' own, which the script's tests build from too.
		$shared = Cases::read( 'explorer.json' );
		$this->assertSame( $shared['strings'], $state['strings'] );
		$this->assertSame( $shared['sorts'], $state['sorts'] );
	}

	/**
	 * A tile, as the site's ProjectTile: the type above the title, the title's link, the
	 * summary, the byline and year, and the phases' short names.
	 */
	public function test_a_tile(): void {
		$this->assertSame(
			'<article class="fh-tile"><span class="fh-tile__glow" aria-hidden="true"></span><span class="fh-tile__body"><span class="fh-tile__kicker">Research Study</span><h3 class="fh-tile__title"><a class="fh-tile__link" href="https://example.test/firehouse/projects/one/">One</a></h3><p class="fh-tile__summary">A summary.</p></span><span class="fh-tile__meta"><span class="fh-tile__byline">A. Name, B. Name, C. Name et al. · 2025</span>' . \Firehouse\Icons::svg( 'arrow-up-right', 16 ) . '</span><span class="fh-tile__foot"><span class="fh-tile__phases"><span class="fh-tile__phase">Before</span><span class="fh-tile__phase">Long-term</span></span></span></article>',
			Explorer_Page::tile(
				array(
					'url'            => 'https://example.test/firehouse/projects/one/',
					'title'          => 'One',
					'summary'        => 'A summary.',
					'projectType'    => 'Research Study',
					'completionYear' => 2025.0,
					'firePhases'     => array( 'before', 'not-a-phase', 'long-term' ),
					'authors'        => array( array( 'name' => 'A. Name' ), 'B. Plain', array( 'name' => 'B. Name' ), array( 'name' => null ), array( 'name' => 'C. Name' ), array( 'name' => 'D. Name' ) ),
				)
			)
		);
		$bare = Explorer_Page::tile(
			array(
				'url'            => 'https://example.test/firehouse/projects/two/',
				'title'          => 'Two',
				'completionYear' => 2024,
			),
			true
		);
		$this->assertStringStartsWith( '<article class="fh-tile" data-wp-each-child="firehouse/explorer::state.results">', $bare );
		$this->assertStringContainsString( '<span class="fh-tile__kicker" hidden></span>', $bare );
		$this->assertStringContainsString( '<span class="fh-tile__foot" hidden><span class="fh-tile__phases"></span></span>', $bare );
		$this->assertStringContainsString( '<span class="fh-tile__byline">Unattributed · 2024</span>', $bare );
	}

	/**
	 * The template the script draws each tile from is the tile the server prints, element
	 * for element and class for class, with its words bound; so is each applied filter's.
	 */
	public function test_the_templates_are_the_markup_the_server_prints(): void {
		$skeleton = static function ( string $html ): array {
			$xpath = self::dom( $html );
			$nodes = $xpath->query( '//body//*[not(self::template)][not(ancestor::svg)]' );
			return array_map(
				static fn( \DOMElement $node ): string => $node->nodeName . '.' . $node->getAttribute( 'class' ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the DOM's own name.
				false === $nodes ? array() : array_values( array_filter( iterator_to_array( $nodes ), static fn( mixed $node ): bool => $node instanceof \DOMElement ) )
			);
		};
		$inside   = static fn( string $template ): string => (string) preg_replace( '#^<template[^>]*>|</template>$#', '', $template );
		$project  = self::projects()[0] + array( 'projectType' => 'Research Study' );
		$template = $inside( Explorer_Page::tile_template() );
		// The phases' own template becomes the phases it draws: one span for each of the
		// project's, two here.
		$project['firePhases'] = array( 'before', 'during' );
		$template              = (string) preg_replace_callback( '#<template[^>]*>(.*?)</template>#', static fn( array $found ): string => str_repeat( $found[1], count( $project['firePhases'] ) ), $template );

		$this->assertSame( $skeleton( Explorer_Page::tile( $project ) ), $skeleton( $template ) );
		// A tile without a type or phases has the same shape, its kicker and footer hidden.
		$bare = array(
			'projectType' => null,
			'firePhases'  => array(),
		) + $project;
		$this->assertSame( $skeleton( Explorer_Page::tile( $bare ) ), $skeleton( (string) preg_replace( '#<span class="fh-tile__phase"[^>]*></span>#', '', $template ) ) );

		$html = self::render( 'phase=during' );
		$this->assertSame( 1, preg_match( '#<template data-wp-each--filter="state.applied"[^>]*>(.*?)</template>#', $html, $pill ) );
		$this->assertSame( 1, preg_match( '#<li data-wp-each-child="firehouse/explorer::state.applied">.*?</li>#', $html, $printed ) );
		$this->assertSame( $skeleton( $printed[0] ), $skeleton( $pill[1] ) );
	}

	/**
	 * The words in the address and the researchers' words are printed as they are,
	 * escaped: a character reference in a title shows as typed. In the page's state
	 * they're JSON, which the script sets as text, so they show as typed there too.
	 */
	public function test_words_are_escaped_as_they_are(): void {
		$projects                   = self::projects();
		$projects[0]['title']       = 'R&amp;D <one>';
		$projects[0]['projectType'] = 'Type "<b>"';
		$html                       = self::render( 'q=%22%3E%3Cscript%3E&type=Type+%22%3Cb%3E%22', $projects );
		$xpath                      = self::dom( $html );

		$this->assertSame( '">', substr( self::one( $xpath, '//input[@name="q"]' )->getAttribute( 'value' ), 0, 2 ) );
		$this->assertStringContainsString( 'name="q" value="&quot;&gt;&lt;script&gt;"', $html );
		$this->assertStringContainsString( 'matching <strong data-wp-text="state.quoted">“&quot;&gt;&lt;script&gt;”</strong>', $html );
		$this->assertStringContainsString( 'aria-label="Remove filter Type: Type &quot;&lt;b&gt;&quot;"><span>Type: Type &quot;&lt;b&gt;&quot;</span><svg', $html );
		$this->assertContains( 'Type "<b>"|Type "<b>" (0)|*', self::options( $xpath, 'type' ) );
		$this->assertStringContainsString( 'aria-label="Remove filter Type: R&amp;amp;D"><span>Type: R&amp;amp;D</span><svg', self::render( 'type=R%26amp%3BD', $projects ) );
		$html = self::render( '', $projects );
		$this->assertStringContainsString( '>R&amp;amp;D &lt;one&gt;</a></h3>', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertSame( 'R&amp;D <one>', Explorer_Page::state( $projects, Search::parse_query( '' ), self::URL )['projects'][0]['title'] );
	}

	/**
	 * The query is read from the address as the browser sent it, unslashed, and not
	 * from QUERY_STRING, which a server's rewrite rules can change; the script starts
	 * from the same query.
	 */
	public function test_the_query_comes_from_the_address(): void {
		$server = $_SERVER;
		try {
			$_SERVER['REQUEST_URI']  = "/firehouse/projects/?q=O\\'Brien&q=second&phase=after";
			$_SERVER['QUERY_STRING'] = 'q=/firehouse/projects/&q=O%27Brien';
			$states                  = array();
			Functions\when( 'wp_unslash' )->alias( 'stripslashes' );
			Functions\when( 'get_posts' )->justReturn( array() );
			Functions\when( 'get_post_type_archive_link' )->justReturn( self::URL );
			Functions\when( 'wp_interactivity_state' )->alias(
				static function ( string $store, array $state ) use ( &$states ): array {
					$states[ $store ] = $state;
					return $state;
				}
			);

			$xpath = self::dom( Explorer_Page::for_request() );

			$this->assertSame( "O'Brien", self::one( $xpath, '//input[@name="q"]' )->getAttribute( 'value' ) );
			$this->assertSame( 'after', self::one( $xpath, '//select[@name="phase"]//option[@selected]' )->getAttribute( 'value' ) );
			$this->assertSame( self::URL . '#fh-content', self::one( $xpath, '//form' )->getAttribute( 'action' ) );
			$this->assertSame( Search::parse_query( "q=O'Brien&phase=after" ), $states['firehouse/explorer']['query'] );

			$_SERVER['REQUEST_URI'] = '/firehouse/projects/';
			$this->assertSame( '', self::one( self::dom( Explorer_Page::for_request() ), '//input[@name="q"]' )->getAttribute( 'value' ) );
		} finally {
			$_SERVER = $server; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- puts back what the test changed.
		}
	}

	/**
	 * The projects are those a visitor may see, in import order, each with its address.
	 * One the site's own test refuses, or without a slug, a title as text or a year as
	 * a number, is left out.
	 */
	public function test_the_projects_a_visitor_may_see(): void {
		// Each post's stored fields; one left out isn't stored at all.
		$stored = array(
			31 => array(
				'fh_title'          => 'First',
				'fh_completionYear' => '2025',
				'fh_irbApproved'    => '1',
			),
			32 => array(
				'fh_title'          => 'No approval',
				'fh_completionYear' => '2025',
				'fh_irbApproved'    => '',
			),
			33 => array(
				'fh_completionYear' => '2024',
				'fh_irbApproved'    => '1',
			),
			34 => array(
				'fh_title'       => 'No year',
				'fh_irbApproved' => '1',
			),
			35 => array(
				'fh_title'          => 'Last',
				'fh_completionYear' => '2019.5',
				'fh_irbApproved'    => '1',
			),
			36 => array(
				'fh_title'          => 'No slug',
				'fh_completionYear' => '2025',
				'fh_irbApproved'    => '1',
			),
		);
		Functions\expect( 'get_posts' )->once()->andReturnUsing(
			function ( array $args ) use ( $stored ): array {
				$this->assertSame(
					Store::public_projects_query(
						array(
							'numberposts' => -1,
							'orderby'     => 'ID',
							'order'       => 'ASC',
						)
					),
					$args
				);
				return array_map( static fn( int $id ): \WP_Post => self::post( $id, 36 === $id ? '' : null ), array_keys( $stored ) );
			}
		);
		Functions\when( 'metadata_exists' )->alias( static fn( string $type, int $id, string $key ): bool => array_key_exists( $key, $stored[ $id ] ) );
		Functions\when( 'get_post_meta' )->alias( static fn( int $id, string $key ): string => $stored[ $id ][ $key ] ?? '' );
		Functions\when( 'get_permalink' )->alias( static fn( \WP_Post $post ): string => self::URL . $post->post_name . '/' );

		$projects = Explorer_Page::projects();

		$this->assertSame( array( 'First', 'Last' ), array_column( $projects, 'title' ) );
		$this->assertSame( array( self::URL . 'p31/', self::URL . 'p35/' ), array_column( $projects, 'url' ) );
		$this->assertSame( array( 'p31', 'p35' ), array_column( $projects, 'slug' ) );
		$this->assertSame( array( 2025, 2019.5 ), array_column( $projects, 'completionYear' ) );
	}

	/**
	 * A published project post without a password.
	 *
	 * @param int         $id   Its ID.
	 * @param string|null $slug Its slug, or null for one made from the ID.
	 */
	private static function post( int $id, ?string $slug = null ): \WP_Post {
		return new \WP_Post(
			array(
				'ID'          => $id,
				'post_type'   => 'fh_project',
				'post_status' => 'publish',
				'post_name'   => $slug ?? 'p' . $id,
			)
		);
	}
}
