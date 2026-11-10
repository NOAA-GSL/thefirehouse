<?php
/**
 * Tests for a project's page.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Project_Page;
use Firehouse\Store;

/**
 * A project page shows what the researcher submitted, as submitted, in the site's
 * markup: the header, the abstract, takeaways, need cards and papers, the "At a
 * glance" panel, and the projects either side of it that a visitor may see.
 */
final class ProjectPageTest extends WordPressTestCase {

	/**
	 * Stands in for WordPress's addresses, escaping, shared JSON and Interactivity
	 * context. esc_url() drops the characters outside WordPress's set, as WordPress's
	 * does, and keeps only an http or https address, which is all these tests give it
	 * besides `javascript:` and an empty one, both of which WordPress's refuses too.
	 * esc_html() leaves a character reference alone, as WordPress's does, where Brain
	 * Monkey's would escape it again.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\stubEscapeFunctions();
		Functions\when( 'esc_html' )->alias( static fn( string $text ): string => htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false ) );
		Functions\when( 'esc_url' )->alias(
			static function ( string $url ): string {
				$url = (string) preg_replace( '|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\[\]\x80-\xff]|i', '', $url );
				return 1 === preg_match( '#^https?://#i', $url ) ? str_replace( array( '&', "'" ), array( '&#038;', '&#039;' ), $url ) : '';
			}
		);
		Functions\when( 'wp_json_file_decode' )->alias(
			static fn( string $filename, array $options = array() ): mixed => json_decode( (string) file_get_contents( $filename ), ! empty( $options['associative'] ) )
		);
		Functions\when( 'wp_interactivity_data_wp_context' )->alias(
			static fn( array $context ): string => "data-wp-context='" . json_encode( $context, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP ) . "'" // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the stand-in for WordPress's.
		);
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://example.test' . $path );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component = -1 ): mixed => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- the stand-in for wp_parse_url.
	}

	/**
	 * A project with every field, as Store::project() reads one, with whatever is changed.
	 *
	 * @param array<string, mixed> $changes Fields to change.
	 * @return array<string, mixed>
	 */
	private static function project( array $changes = array() ): array {
		return $changes + array(
			'slug'              => 'fwt-002',
			'title'             => 'Fire Weather Testbed #002: <Detection> & "Dissemination"',
			'authors'           => array(
				array( 'name' => 'Emily Wells' ),
				array( 'name' => 'Ansley Baring' ),
				array( 'name' => 'Kyle Thiem' ),
				array( 'name' => 'Jamie Vickery' ),
			),
			'org'               => 'Cooperative Institute for Research in the Atmosphere',
			'completionYear'    => 2025,
			'projectType'       => 'Testbed Evaluation',
			'firePhases'        => array( 'long-term', 'before' ),
			'publicationStatus' => 'in-review',
			'methods'           => array( 'Survey', 'Focus groups' ),
			'geo'               => array(
				'regions' => array( 'ONCC', 'NATIONAL' ),
				'note'    => 'Front Range',
			),
			'abstract'          => "In June 2024,  the testbed ran\na five-day evaluation.",
			'takeaways'         => array( 'NGFS is a safety net.', 'Alerts <b>reach</b> partners.' ),
			'needs'             => array(
				array(
					'need'           => 'Need for “safety net” tools among meteorologists: Automated tools are needed.',
					'recommendation' => 'Recommend ingesting NGFS into AWIPS.',
				),
				array( 'recommendation' => 'A recommendation on its own.' ),
				array( 'need' => 'More training, please.' ),
			),
			'papers'            => array(
				array(
					'title'     => 'An End-to-End Evaluation',
					'container' => 'NOAA technical memorandum',
					'year'      => 2025,
					'doi'       => '10.25923/4pqf-7g49',
				),
				array( 'url' => 'https://cimss.ssec.wisc.edu/ngfs/' ),
				array(
					'title' => 'Hotspot notifications',
					'url'   => 'https://www.weather.gov/hotspots/',
				),
			),
			'fullRecordUrl'     => 'https://repository.library.noaa.gov/view/noaa/12345',
			'published'         => true,
		);
	}

	/**
	 * The page for a project, with the projects either side of it.
	 *
	 * @param array<string, mixed> $changes Fields to change on the project.
	 * @param bool                 $before  Whether a project comes before it.
	 * @param bool                 $after   Whether one comes after it.
	 */
	private static function render( array $changes = array(), bool $before = true, bool $after = true ): string {
		return Project_Page::render(
			self::project( $changes ),
			$before ? array(
				'title' => 'Fire Weather Testbed #004',
				'url'   => 'https://example.test/firehouse/projects/fwt-004/',
			) : null,
			$after ? array(
				'title' => 'Fire Weather Testbed #003: Interagency',
				'url'   => 'https://example.test/firehouse/projects/fwt-003/',
			) : null
		);
	}

	/**
	 * The header: the way back to every project, the type, the title, everyone who
	 * worked on it with their organization, and the year, phases and status as pills.
	 */
	public function test_the_header(): void {
		$html = self::render();

		$this->assertMatchesRegularExpression( '#<nav class="fh-project__crumbs" aria-label="Breadcrumb"><a href="https://example.test/firehouse/projects/"><svg[^>]*width="14"[^>]*>.*?</svg>All projects</a></nav>#s', $html );
		$this->assertStringContainsString( '<span class="fh-eyebrow fh-project__type">Testbed Evaluation</span>', $html );
		$this->assertStringContainsString( '<h1 class="fh-project__title">Fire Weather Testbed #002: &lt;Detection&gt; &amp; &quot;Dissemination&quot;</h1>', $html );
		$this->assertSame( 1, substr_count( $html, '<h1' ) );
		$this->assertStringContainsString( '<p class="fh-project__byline">Emily Wells, Ansley Baring, Kyle Thiem and Jamie Vickery<span class="fh-project__org">Cooperative Institute for Research in the Atmosphere</span></p>', $html );
		$this->assertMatchesRegularExpression( '#<span class="fh-meta-pill"><svg[^>]*width="12"[^>]*>.*?</svg>Completed 2025</span>#s', $html );
		$this->assertMatchesRegularExpression( '#<span class="fh-meta-pill"><svg[^>]*width="12"[^>]*>.*?</svg><span class="fh-visually-hidden">Fire cycle phase: </span>Long-term · Before</span>#s', $html );
		$this->assertStringContainsString( '<span class="fh-meta-pill fh-meta-pill--info">In review</span>', $html );
		$this->assertStringContainsString( '<span class="fh-project__head-rule" aria-hidden="true"></span>', $html );
	}

	/**
	 * The main column: the abstract, the takeaways numbered as the survey asks for
	 * them, the need cards and the papers, each list with its count beside its heading
	 * for sighted readers.
	 */
	public function test_the_main_column(): void {
		$html = self::render();

		$this->assertStringContainsString( '<section class="fh-project__section"><h2 class="fh-project__section-title">Abstract</h2><p class="fh-project__prose">In June 2024,  the testbed ran' . "\n" . 'a five-day evaluation.</p></section>', $html );
		$this->assertStringContainsString( '<h2 class="fh-project__section-title">Major takeaways<span class="fh-project__section-count" aria-hidden="true">2</span></h2><ol class="fh-project__takeaways"><li><span class="fh-project__takeaway-num" aria-hidden="true">01</span><p>NGFS is a safety net.</p></li><li><span class="fh-project__takeaway-num" aria-hidden="true">02</span><p>Alerts &lt;b&gt;reach&lt;/b&gt; partners.</p></li></ol>', $html );
		$this->assertStringContainsString( '<h2 class="fh-project__section-title">End-user needs and recommendations<span class="fh-project__section-count" aria-hidden="true">3</span></h2><ol class="fh-entries" data-wp-interactive="firehouse/project"><li class="fh-entry">', $html );
		$this->assertSame( 3, substr_count( $html, '<li class="fh-entry">' ) );
		$this->assertStringContainsString( '<h2 class="fh-project__section-title">Publications and resources<span class="fh-project__section-count" aria-hidden="true">3</span></h2><ul class="fh-project__papers"><li><a class="fh-project__paper"', $html );
		$this->assertSame( 3, substr_count( $html, '<a class="fh-project__paper"' ) );
	}

	/**
	 * A need card starts unmeasured, so its cap is off, with its state for the view
	 * script, and prints the need, its lead in bold, and its recommendation, as written.
	 * Its name, if it scrolls, is the lead without its colon.
	 */
	public function test_a_need_card(): void {
		$card = Project_Page::need_card( self::project()['needs'][0] );

		$this->assertStringStartsWith( '<li class="fh-entry"><div class="fh-entry__body" data-wp-context=\'{"overflow":null,"label":"Need for \u201csafety net\u201d tools among meteorologists \u2014 scrollable"}\' data-wp-init="callbacks.measure" data-wp-bind--data-overflow="context.overflow" data-wp-bind--tabindex="state.tabIndex" data-wp-bind--role="state.role" data-wp-bind--aria-label="state.label">', $card );
		$this->assertDoesNotMatchRegularExpression( '/\sdata-overflow=/', $card );
		$this->assertDoesNotMatchRegularExpression( '/\stabindex=/', $card );
		$this->assertDoesNotMatchRegularExpression( '/\srole=/', $card );
		$this->assertDoesNotMatchRegularExpression( '/\saria-label=/', $card );
		$this->assertMatchesRegularExpression( '#<div class="fh-entry__need"><span class="fh-entry__label"><svg[^>]*width="13"[^>]*>.*?</svg>Need</span><p class="fh-entry__text"><strong class="fh-entry__lead">Need for “safety net” tools among meteorologists:</strong> Automated tools are needed.</p></div>#s', $card );
		$this->assertMatchesRegularExpression( '#<div class="fh-entry__rec"><span class="fh-entry__label"><svg[^>]*width="13"[^>]*>.*?</svg>Recommendation</span><p class="fh-entry__text">Recommend ingesting NGFS into AWIPS.</p></div></div></li>$#s', $card );
	}

	/**
	 * A recommendation stands alone as just its band; a need without the survey's lead
	 * has no bold, and both are named for what they are if they scroll. An entry with
	 * neither, or that isn't an entry, isn't a card.
	 */
	public function test_a_need_card_shows_only_what_it_has(): void {
		$rec = Project_Page::need_card( array( 'recommendation' => 'A recommendation on its own.' ) );
		$this->assertStringNotContainsString( 'fh-entry__need', $rec );
		$this->assertStringContainsString( '"label":"Need and recommendation \u2014 scrollable"', $rec );

		$need = Project_Page::need_card( array( 'need' => 'More training, please.' ) );
		$this->assertStringContainsString( '<p class="fh-entry__text"> More training, please.</p>', $need );
		$this->assertStringNotContainsString( 'fh-entry__rec', $need );
		$this->assertStringNotContainsString( '<strong', $need );
		$this->assertStringContainsString( '"label":"Need and recommendation \u2014 scrollable"', $need );

		foreach ( array( array(), array( 'need' => '' ), array( 'need' => 7 ), 'a need', null ) as $entry ) {
			$this->assertSame( '', Project_Page::need_card( $entry ) );
		}
	}

	/**
	 * A paper links to its DOI's address when it has one, and says the DOI; otherwise to
	 * its own address, named by its title, or by its host and path when it has none,
	 * never by an invented name. Every link opens in a new tab and says so.
	 */
	public function test_a_paper(): void {
		$papers = self::project()['papers'];

		$this->assertMatchesRegularExpression( '#^<a class="fh-project__paper" href="https://doi.org/10.25923/4pqf-7g49" target="_blank" rel="noopener noreferrer"><svg[^>]*width="16"[^>]*>.*?</svg><span><span class="fh-project__paper-title">An End-to-End Evaluation</span><span class="fh-project__paper-source">NOAA technical memorandum · 2025</span><span class="fh-project__doi">doi:10.25923/4pqf-7g49</span></span><svg[^>]*width="14"[^>]*>.*?</svg><span class="fh-visually-hidden">\(opens in a new tab\)</span></a>$#s', Project_Page::paper( $papers[0] ) );
		$this->assertMatchesRegularExpression( '#href="https://cimss.ssec.wisc.edu/ngfs/".*<span><span class="fh-project__paper-title">cimss.ssec.wisc.edu/ngfs</span></span>#s', Project_Page::paper( $papers[1] ) );
		$this->assertStringContainsString( '<span><span class="fh-project__paper-title">Hotspot notifications</span><span class="fh-project__doi">weather.gov/hotspots</span></span>', Project_Page::paper( $papers[2] ) );
	}

	/**
	 * A paper's address leads where a browser would take it as written. The characters
	 * WordPress would drop are percent-encoded instead, so a SICI DOI still finds its
	 * paper, and its label keeps them as written. An address already percent-encoded
	 * stays as it is. A paper with no address WordPress will print isn't listed.
	 */
	public function test_a_paper_link_keeps_its_address(): void {
		$sici = Project_Page::paper(
			array(
				'title' => 'A paper',
				'doi'   => '10.1002/(SICI)1097-4636(199706)35:4<513::AID-JBM12>3.0.CO;2-8',
			)
		);
		$this->assertStringContainsString( 'href="https://doi.org/10.1002/(SICI)1097-4636(199706)35:4%3C513::AID-JBM12%3E3.0.CO;2-8"', $sici );
		$this->assertStringContainsString( '<span class="fh-project__doi">doi:10.1002/(SICI)1097-4636(199706)35:4&lt;513::AID-JBM12&gt;3.0.CO;2-8</span>', $sici );

		$this->assertStringContainsString( 'href="https://example.org/a%20report%22.pdf"', Project_Page::paper( array( 'url' => 'https://example.org/a report".pdf' ) ) );
		$this->assertStringContainsString( 'href="https://example.org/a%20b%3Cc"', Project_Page::paper( array( 'url' => 'https://example.org/a%20b<c' ) ) );
		$this->assertStringContainsString( 'href="https://doi.org/10.1002/%28SICI%29"', Project_Page::paper( array( 'doi' => '10.1002/%28SICI%29' ) ) );

		foreach ( array( array( 'title' => 'No link' ), array( 'url' => 'javascript:alert(1)' ), array( 'url' => '' ), array( 'doi' => 12 ), 'https://example.org/', null ) as $paper ) {
			$this->assertSame( '', Project_Page::paper( $paper ) );
		}
	}

	/**
	 * A DOI follows doi.org's address as stored, as on the site: one the import took from
	 * a pasted link keeps the link's query or fragment, which doi.org ignores, so it
	 * still resolves. With no title, the paper is named by the address's host and path.
	 */
	public function test_a_doi_from_a_pasted_link_still_resolves(): void {
		$query = Project_Page::paper( array( 'doi' => '10.1002/joc.1234?af=R' ) );
		$this->assertStringContainsString( 'href="https://doi.org/10.1002/joc.1234?af=R"', $query );
		$this->assertStringContainsString( '<span class="fh-project__paper-title">doi.org/10.1002/joc.1234</span>', $query );
		$this->assertStringContainsString( '<span class="fh-project__doi">doi:10.1002/joc.1234?af=R</span>', $query );

		$this->assertStringContainsString( 'href="https://doi.org/10.1029/2020GL091234#accessDenialLayout"', Project_Page::paper( array( 'doi' => '10.1029/2020GL091234#accessDenialLayout' ) ) );
	}

	/**
	 * A browser reads a `\` in an address's host or path as `/`, so the link does too:
	 * dropping it would move the host to whatever follows an `@`. In a DOI it stays in
	 * the path, under doi.org.
	 */
	public function test_a_backslash_cant_move_the_host(): void {
		$this->assertStringContainsString( 'href="https://doi.org/@evil.example/10.1175/x"', Project_Page::paper( array( 'url' => 'https://doi.org\@evil.example/10.1175/x' ) ) );
		$this->assertStringContainsString( 'href="https://doi.org//@evil.example/10.1175/x"', Project_Page::paper( array( 'doi' => '\@evil.example/10.1175/x' ) ) );
	}

	/**
	 * Stored text shows exactly as stored, a character reference included: WordPress's
	 * esc_html() would leave "&amp;" for the browser to show as "&". The scrollable
	 * card's name keeps it too.
	 */
	public function test_stored_text_keeps_its_character_references(): void {
		$html = self::render(
			array(
				'title'     => 'R&amp;D &lt;fire&gt;',
				'takeaways' => array( 'Fire weather &ndash; a review' ),
				'needs'     => array( array( 'need' => 'Need for R&amp;D among us: more &amp; better.' ) ),
				'papers'    => array(
					array(
						'title' => 'A &amp; B',
						'url'   => 'https://example.org/',
					),
				),
			)
		);

		$this->assertStringContainsString( '<h1 class="fh-project__title">R&amp;amp;D &amp;lt;fire&amp;gt;</h1>', $html );
		$this->assertStringContainsString( '<p>Fire weather &amp;ndash; a review</p>', $html );
		$this->assertStringContainsString( '<strong class="fh-entry__lead">Need for R&amp;amp;D among us:</strong> more &amp;amp; better.', $html );
		$this->assertStringContainsString( '"label":"Need for R\u0026amp;D among us \u2014 scrollable"', $html );
		$this->assertStringContainsString( '<span class="fh-project__paper-title">A &amp;amp; B</span>', $html );
	}

	/**
	 * Every stored text the page prints keeps a character reference as stored: each
	 * field holds one, and none comes out as a reference a browser would decode.
	 */
	public function test_no_stored_text_is_printed_with_esc_html(): void {
		$mark  = static fn( string $text ): string => $text . ' &amp;Q';
		$html  = self::render(
			array(
				'title'       => $mark( 'Title' ),
				'projectType' => $mark( 'Type' ),
				'authors'     => array( array( 'name' => $mark( 'Author' ) ) ),
				'org'         => $mark( 'Org' ),
				'abstract'    => $mark( 'Abstract' ),
				'takeaways'   => array( $mark( 'Takeaway' ) ),
				'needs'       => array(
					array(
						'need'           => $mark( 'Need for marks among tests:' ),
						'recommendation' => $mark( 'Recommendation' ),
					),
				),
				'methods'     => array( $mark( 'Method' ) ),
				'geo'         => array(
					'regions' => array( 'ONCC' ),
					'note'    => $mark( 'Note' ),
				),
				'papers'      => array(
					array(
						'title'     => $mark( 'Paper' ),
						'container' => $mark( 'Journal' ),
						'doi'       => $mark( '10.1000/doi' ),
					),
					array(
						'title' => 'Linked',
						'url'   => 'https://example.org/' . $mark( 'path' ),
					),
				),
			)
		);
		$html .= Project_Page::render(
			self::project(),
			array(
				'title' => $mark( 'Previous' ),
				'url'   => 'https://example.test/firehouse/projects/a/',
			),
			null
		);

		$this->assertStringNotContainsString( '&amp;Q', $html );
		$this->assertSame( 17, substr_count( $html, '&amp;amp;Q' ) );
	}

	/**
	 * A blank title still names a paper, as the site's `??` keeps it, with no host.
	 */
	public function test_a_blank_title_is_still_the_title(): void {
		$this->assertStringContainsString(
			'<span><span class="fh-project__paper-title"></span></span>',
			Project_Page::paper(
				array(
					'title' => '',
					'url'   => 'https://example.org/a',
				)
			)
		);
	}

	/**
	 * The "At a glance" panel: each fact the project has, in the site's order, the
	 * mapped regions with their codes, the full record, and the note that the text is
	 * the researcher's own and the topic areas are the synthesis.
	 */
	public function test_the_panel(): void {
		$html = self::render();

		$this->assertStringContainsString( '<aside class="fh-project__aside" aria-label="Project details">', $html );
		$this->assertStringContainsString( '<h2 class="fh-project__panel-title">At a glance</h2>', $html );
		preg_match_all( '#<dt class="fh-project__fact-label"><svg[^>]*>.*?</svg>([^<]*)</dt><dd class="fh-project__fact-value">(.*?)</dd>#s', $html, $facts, PREG_SET_ORDER );
		$this->assertSame(
			array(
				'Project type'       => 'Testbed Evaluation',
				'Completion year'    => '2025',
				'Fire cycle phase'   => '<ul class="fh-project__phases"><li><strong>Long-term</strong><span>Planning, adaptation, and policy</span></li><li><strong>Before</strong><span>Pre-fire planning, prevention, and mitigation</span></li></ul>',
				'Data collection'    => 'Survey, Focus groups',
				'Geographic areas'   => '<span class="fh-project__regions"><span class="fh-project__region">N. California<abbr title="Northern California (ONCC)">ONCC</abbr></span><span class="fh-project__region">National</span></span><span class="fh-project__region-note">Front Range</span>',
				'Organization'       => 'Cooperative Institute for Research in the Atmosphere',
				'Publication status' => 'In review',
			),
			array_column( array_map( static fn( array $fact ): array => array( $fact[1], $fact[2] ), $facts ), 1, 0 )
		);
		$this->assertMatchesRegularExpression( '#<a href="https://repository.library.noaa.gov/view/noaa/12345" class="fh-btn fh-btn--secondary fh-btn--sm" target="_blank" rel="noopener noreferrer"><svg[^>]*width="14"[^>]*>.*?</svg>View full record<span class="fh-visually-hidden"> \(opens in a new tab\)</span></a>#s', $html );
		$this->assertMatchesRegularExpression( '#<p class="fh-project__verbatim"><svg[^>]*width="14"[^>]*>.*?</svg><span>Takeaways, needs and recommendations on this page are reproduced as the researcher submitted them. The <a href="https://example.test/firehouse/\#top-needs">topic areas</a> are an AI-assisted synthesis across all projects, so no single project is filed under one.</span></p>#s', $html );
	}

	/**
	 * The projects either side link by their titles, as written; at either end, the
	 * other link keeps its place; with neither, there's no pager.
	 */
	public function test_the_pager(): void {
		$this->assertMatchesRegularExpression( '#<nav class="fh-project__pager fh-container" aria-label="Other projects">\s*<a class="fh-project__pager-link" href="https://example.test/firehouse/projects/fwt-004/"><span class="fh-project__pager-dir"><svg[^>]*>.*?</svg>Previous</span><span class="fh-project__pager-title">Fire Weather Testbed \#004</span></a>\s*<a class="fh-project__pager-link fh-project__pager-link--next" href="https://example.test/firehouse/projects/fwt-003/"><span class="fh-project__pager-dir">Next<svg[^>]*>.*?</svg></span><span class="fh-project__pager-title">Fire Weather Testbed \#003: Interagency</span></a>\s*</nav>#s', self::render() );
		$this->assertMatchesRegularExpression( '#aria-label="Other projects">\s*<span></span>\s*<a class="fh-project__pager-link fh-project__pager-link--next"#', self::render( array(), false ) );
		$this->assertMatchesRegularExpression( '#Previous</span>.*?</a>\s*</nav>#s', self::render( array(), true, false ) );
		$this->assertStringNotContainsString( 'fh-project__pager', self::render( array(), false, false ) );
	}

	/**
	 * A project with only what the survey requires shows only that: no type, abstract,
	 * lists, regions, organization, status or record, and its phases are "Not specified".
	 */
	public function test_a_project_shows_only_what_it_has(): void {
		$html = self::render(
			array(
				'projectType'       => '',
				'firePhases'        => array(),
				'publicationStatus' => '',
				'methods'           => array(),
				'geo'               => array( 'regions' => array() ),
				'abstract'          => '',
				'takeaways'         => array(),
				'needs'             => array(),
				'papers'            => array(),
				'fullRecordUrl'     => '',
				'org'               => '',
			)
		);

		foreach ( array( 'fh-project__type', 'Abstract', 'Major takeaways', 'fh-entries', 'fh-project__papers', 'Data collection', 'Geographic areas', 'Organization', 'Publication status', 'fh-meta-pill--', 'fh-project__org', 'View full record', 'Fire cycle phase: ' ) as $absent ) {
			$this->assertStringNotContainsString( $absent, $html, $absent );
		}
		$this->assertStringContainsString( 'Fire cycle phase</dt><dd class="fh-project__fact-value">Not specified</dd>', $html );
		$this->assertStringContainsString( 'Completed 2025', $html );
	}

	/**
	 * A value stored around Meta is passed over, not printed or allowed to stop the
	 * page: keys outside the vocabulary, items that aren't text, and a record address
	 * a browser couldn't safely follow.
	 */
	public function test_malformed_values_are_passed_over(): void {
		$html = self::render(
			array(
				'title'             => array( 'not text' ),
				'authors'           => array( 'Emily Wells', array( 'name' => 'Kyle Thiem' ), array( 'org' => 'CIRA' ) ),
				'completionYear'    => '2025',
				'firePhases'        => array( 'during', 'sometime', 3 ),
				'publicationStatus' => 'forthcoming',
				'methods'           => 'Survey',
				'geo'               => array( 'regions' => array( 'ONCC', 'MOON' ) ),
				'takeaways'         => array( 'Kept.', 42, null ),
				'needs'             => 'Need for something: this.',
				'papers'            => array( array( 'url' => 'javascript:alert(1)' ), 'https://example.org/' ),
				'fullRecordUrl'     => 'javascript:alert(1)',
			)
		);

		$this->assertStringContainsString( '<h1 class="fh-project__title"></h1>', $html );
		$this->assertStringContainsString( '<p class="fh-project__byline">Kyle Thiem<span class="fh-project__org">', $html );
		$this->assertStringContainsString( 'Completed </span>', $html );
		$this->assertStringContainsString( '</span>During</span>', $html );
		$this->assertStringNotContainsString( 'sometime', $html );
		$this->assertStringNotContainsString( 'fh-meta-pill--', $html );
		$this->assertStringNotContainsString( 'Data collection', $html );
		$this->assertStringContainsString( 'N. California<abbr title="Northern California (ONCC)">ONCC</abbr></span></span>', $html );
		$this->assertStringContainsString( '<span class="fh-project__section-count" aria-hidden="true">1</span></h2><ol class="fh-project__takeaways"><li><span class="fh-project__takeaway-num" aria-hidden="true">01</span><p>Kept.</p></li></ol>', $html );
		$this->assertStringNotContainsString( 'fh-entries', $html );
		$this->assertStringNotContainsString( 'fh-project__papers', $html );
		$this->assertStringNotContainsString( 'javascript', $html );
		$this->assertStringNotContainsString( 'View full record', $html );
	}

	/**
	 * The projects either side, in an order: none at the ends, and neither for one
	 * that isn't in it, as when someone who may edit a hidden project views it.
	 */
	public function test_the_neighbours(): void {
		$order = array(
			array( 'id' => 11 ),
			array( 'id' => 12 ),
			array( 'id' => 13 ),
		);

		$this->assertSame( array( array( 'id' => 11 ), array( 'id' => 13 ) ), Project_Page::neighbours( 12, $order ) );
		$this->assertSame( array( null, array( 'id' => 12 ) ), Project_Page::neighbours( 11, $order ) );
		$this->assertSame( array( array( 'id' => 12 ), null ), Project_Page::neighbours( 13, $order ) );
		$this->assertSame( array( null, null ), Project_Page::neighbours( 14, $order ) );
		$this->assertSame( array( null, null ), Project_Page::neighbours( 11, array() ) );
	}

	/**
	 * The order is the explorer's, newest first, then by title, among the projects a
	 * visitor may see, in one lookup. A project the query returns that fails the site's
	 * own test, or whose title or year isn't stored as its schema says, is left out.
	 */
	public function test_the_order_is_the_explorers_among_public_projects(): void {
		$stored = array(
			21 => array( 'Beta', '2025', '1' ),
			22 => array( 'Alpha', '2025', '1' ),
			23 => array( 'Gamma', '2026', '1' ),
			24 => array( 'Delta', '2024', '' ),
			25 => array( 'Epsilon', 'soon', '1' ),
			26 => array( array( 'Zeta' ), '2023', '1' ),
			27 => array( 'Eta', '2019.5', '1' ),
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
				return array_map( static fn( int $id ): \WP_Post => self::post( $id ), array_keys( $stored ) );
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $id, string $key ) use ( $stored ): mixed {
				$fields = array( 'fh_title', 'fh_completionYear', 'fh_irbApproved' );
				return $stored[ $id ][ (int) array_search( $key, $fields, true ) ];
			}
		);

		$this->assertSame(
			array(
				array(
					'id'             => 23,
					'title'          => 'Gamma',
					'completionYear' => 2026,
				),
				array(
					'id'             => 22,
					'title'          => 'Alpha',
					'completionYear' => 2025,
				),
				array(
					'id'             => 21,
					'title'          => 'Beta',
					'completionYear' => 2025,
				),
				array(
					'id'             => 27,
					'title'          => 'Eta',
					'completionYear' => 2019.5,
				),
			),
			Project_Page::order()
		);
	}

	/**
	 * The page for a post reads the project from what WordPress stores and links the
	 * projects either side of it by their addresses.
	 */
	public function test_the_page_for_a_post(): void {
		Functions\when( 'get_posts' )->justReturn( array( self::post( 21 ), self::post( 22 ), self::post( 23 ) ) );
		Functions\when( 'metadata_exists' )->alias( static fn( string $type, int $id, string $key ): bool => in_array( $key, array( 'fh_title', 'fh_completionYear', 'fh_irbApproved', 'fh_abstract' ), true ) );
		Functions\when( 'get_post_meta' )->alias(
			static fn( int $id, string $key ): string => match ( $key ) {
				'fh_title'          => array(
					21 => 'Middle <one>',
					22 => 'Newest',
					23 => 'Oldest',
				)[ $id ],
				'fh_completionYear' => array(
					21 => '2025',
					22 => '2026',
					23 => '2024',
				)[ $id ],
				'fh_abstract'       => 'An abstract, as written.',
				default             => '1',
			}
		);
		Functions\when( 'get_permalink' )->alias( static fn( int $id ): string => 'https://example.test/firehouse/projects/p' . $id . '/' );

		$html = Project_Page::for_post( self::post( 22 ) );

		$this->assertStringContainsString( '<h1 class="fh-project__title">Newest</h1>', $html );
		$this->assertStringContainsString( '<p class="fh-project__prose">An abstract, as written.</p>', $html );
		$this->assertStringContainsString( 'aria-label="Other projects">', $html );
		$this->assertMatchesRegularExpression( '#<span></span>\s*<a class="fh-project__pager-link fh-project__pager-link--next" href="https://example.test/firehouse/projects/p21/">.*?<span class="fh-project__pager-title">Middle &lt;one&gt;</span></a>#s', $html );
	}

	/**
	 * A published project post without a password.
	 *
	 * @param int $id Its ID.
	 */
	private static function post( int $id ): \WP_Post {
		return new \WP_Post(
			array(
				'ID'          => $id,
				'post_type'   => 'fh_project',
				'post_status' => 'publish',
				'post_name'   => 'p' . $id,
			)
		);
	}
}
