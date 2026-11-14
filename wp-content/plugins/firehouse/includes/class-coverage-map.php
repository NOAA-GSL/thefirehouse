<?php
/**
 * The landing page's coverage map.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

use Firehouse\Domain\Derive;
use Firehouse\Domain\Map_Colors;
use Firehouse\Domain\Search;
use Firehouse\Domain\Vocabulary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The coverage map, `firehouse/coverage-map`: the site's coverage section on the
 * landing page, with its `RegionMap`. Where the research comes from, by Geographic Area
 * Coordination Center (GACC): a map of the ten, coloured by count, a count for each of
 * them and for the four answers with no boundary (Pacific, national, international, not
 * sure), and the projects filed under the one selected.
 *
 * Without JavaScript, the region list is the map. Each region is a button that submits
 * a GET form for `?region=` and its key, the address the static site uses, and the page
 * comes back at that region's projects (the button's `formaction`). The selected
 * region's button, "All submissions" and "Clear" submit nothing, which selects none,
 * and the page comes back at the section's heading, by the list. The map's stage and
 * credit are printed, and frame.css hides them when scripting is off.
 *
 * With JavaScript, the block's view script (blocks/coverage-map/view.js) takes the same
 * markup over through the Interactivity API: a choice changes the page in place and the
 * address with it, and the map, drawn by the site's own drawing code (the
 * `@firehouse/coverage-map` module, which Blocks::register() registers), follows. So
 * the server prints every part a choice can show, the hint, the results' heading, list
 * and empty message, each hidden unless the selection shows it, and the state the
 * script starts from (state()).
 *
 * Each GACC with something filed under it has a swatch carrying its colour for both
 * themes, from the map's ramps (Map_Colors), and frame.css shows the one for the theme
 * the page is in.
 */
final class Coverage_Map {

	/**
	 * The view script's store, and the region it takes over.
	 */
	public const STORE = 'firehouse/coverage-map';

	/**
	 * The script module that draws the map: the site's mapCanvas.ts and Leaflet,
	 * bundled by scripts/plugin-assets.mjs.
	 */
	public const MAP_MODULE = '@firehouse/coverage-map';

	/**
	 * The form the region buttons submit.
	 */
	public const FORM = 'fh-map-form';

	/**
	 * The selected region's projects, where a button that selects a region brings the
	 * page back to.
	 */
	public const RESULTS = 'fh-map-results';

	/**
	 * The map for this request: the projects a visitor may see, and the region the
	 * address selects, read as the explorer reads its query (Search::params()), with a
	 * key that isn't a region's ignored, as the site ignores it. The view script's state
	 * goes to WordPress with it.
	 *
	 * The projects are the explorer's, so one without a slug, a title as text or a year
	 * as a number is left out (rule 6), where the hero's counts, which read every public
	 * project (Landing_Page::content()), keep it. The site counts one set for both.
	 */
	public static function for_request(): string {
		$region   = Search::params( Explorer_Page::query_string() )['region'] ?? null;
		$projects = self::projects( Explorer_Page::projects() );
		$selected = Vocabulary::is_one_of( $region, Vocabulary::regions() ) ? $region : null;
		wp_interactivity_state( self::STORE, self::state( $projects, $selected ) );
		return self::render( $projects, $selected, home_url( '/' . Query::ROOT_PATH . '/' ) );
	}

	/**
	 * Each project as the map reads it, in the order given: its title, year and address,
	 * the regions it counts toward (a national study counts toward every GACC as well,
	 * Derive::effective_regions()), and whether it's a national study.
	 *
	 * @param list<array<string, mixed>> $all The projects a visitor may see, from Explorer_Page::projects().
	 * @return list<array{title: string, year: int|float, url: string, regions: list<string>, national: bool}>
	 */
	public static function projects( array $all ): array {
		$projects = array();
		foreach ( $all as $project ) {
			$regions    = is_array( $project['geo']['regions'] ?? null ) ? array_values( array_filter( $project['geo']['regions'], 'is_string' ) ) : array();
			$projects[] = array(
				'title'    => $project['title'],
				'year'     => $project['completionYear'],
				'url'      => $project['url'],
				'regions'  => Derive::effective_regions( $regions ),
				'national' => in_array( 'NATIONAL', $regions, true ),
			);
		}
		return $projects;
	}

	/**
	 * Each region's count, a national study counted in every GACC.
	 *
	 * @param list<array<string, mixed>> $projects The projects, from projects().
	 * @return array<string, int>
	 */
	public static function counts( array $projects ): array {
		$counts = array_fill_keys( Vocabulary::regions(), 0 );
		foreach ( $projects as $project ) {
			foreach ( $project['regions'] as $key ) {
				if ( isset( $counts[ $key ] ) ) {
					++$counts[ $key ];
				}
			}
		}
		return $counts;
	}

	/**
	 * What the view script starts from: the selected region; each project as the results
	 * list it, its year as the page prints it and its address as WordPress would keep
	 * it, so the script can't bind one the server would have dropped; each region's
	 * count, which colours the map; each region's short name; the tiles' host, which
	 * the script connects to early; and the words a choice rewrites, with each count's
	 * two forms, which the script picks between as English does, as the explorer's does.
	 *
	 * @param list<array<string, mixed>> $projects The projects, from projects().
	 * @param string|null                $selected The selected region's key, or null for none.
	 * @return array<string, mixed>
	 */
	public static function state( array $projects, ?string $selected ): array {
		return array(
			'selected' => $selected,
			'projects' => array_map(
				static fn( array $project ): array => array(
					'title'    => $project['title'],
					'year'     => (string) Search::key( $project['year'] ),
					'url'      => esc_url_raw( $project['url'] ),
					'regions'  => $project['regions'],
					'national' => $project['national'],
				),
				$projects
			),
			'counts'   => self::counts( $projects ),
			'names'    => array_map( static fn( array $item ): string => (string) $item['short'], Vocabulary::items( 'regions' ) ),
			'tileHost' => Map_Colors::tile_origin(),
			'strings'  => array(
				'heading'     => array(
					/* translators: 1: how many projects are filed under the region; 2: the region's name. */
					_n( '%1$d project in %2$s', '%1$d projects in %2$s', 1, 'firehouse' ),
					/* translators: 1: how many projects are filed under the region; 2: the region's name. */
					_n( '%1$d project in %2$s', '%1$d projects in %2$s', 2, 'firehouse' ),
				),
				'submissions' => array(
					/* translators: %d: how many projects are filed under a region, in the map's tooltip. */
					_n( '%d submission', '%d submissions', 1, 'firehouse' ),
					/* translators: %d: how many projects are filed under a region, in the map's tooltip. */
					_n( '%d submission', '%d submissions', 2, 'firehouse' ),
				),
			),
		);
	}

	/**
	 * The coverage section: its heading and words, the map's stage and credit, the region
	 * list with each region's count, and the selected region's projects, or a hint when
	 * none is selected.
	 *
	 * The results end in a status line, empty when the page loads, where the view script
	 * says what a choice changed: "2 projects in Great Basin", not the whole list, which
	 * a live region around the results would read out.
	 *
	 * @param list<array<string, mixed>> $projects The projects, from projects().
	 * @param string|null                $selected The selected region's key, or null for none.
	 * @param string                     $action   The landing page's address, which the form submits to.
	 */
	public static function render( array $projects, ?string $selected, string $action ): string {
		$counts   = self::counts( $projects );
		$gaccs    = Vocabulary::gaccs();
		$max      = max( 1, ...array_map( static fn( string $key ): int => $counts[ $key ], $gaccs ) );
		$total    = count( array_filter( $projects, static fn( array $project ): bool => array() !== $project['regions'] ) );
		$national = count( array_filter( $projects, static fn( array $project ): bool => $project['national'] ) );
		$regions  = Vocabulary::items( 'regions' );

		$results = $action . '#' . self::RESULTS;
		$list    = self::region( null, __( 'All submissions', 'firehouse' ), $total, $selected, $results );
		foreach ( $gaccs as $key ) {
			$list .= self::region( $key, $regions[ $key ]['short'], $counts[ $key ], $selected, $results, $max );
		}
		$off_map = '';
		foreach ( array_diff( Vocabulary::regions(), $gaccs ) as $key ) {
			$off_map .= self::region( $key, $regions[ $key ]['short'], $counts[ $key ], $selected, $results );
		}

		return sprintf(
			'<section class="fh-coverage-section fh-container" aria-labelledby="coverage-heading"><div class="fh-coverage"><div class="fh-coverage__head"><h2 id="coverage-heading" class="fh-coverage__title">%1$s</h2><p class="fh-coverage__sub">%2$s</p></div><div class="fh-map" data-fh-interactive="%13$s">%14$s<div class="fh-map__side"><form id="%3$s" method="get" action="%4$s" hidden></form><div class="fh-map__side-head"><h3 class="fh-map__side-title" id="fh-map-list">%5$s</h3><p class="fh-map__side-total">%6$s</p></div>%7$s<ul class="fh-map__list" aria-labelledby="fh-map-list">%8$s</ul><div class="fh-map__offmap"><h4 class="fh-map__offmap-title">%9$s</h4><ul class="fh-map__list">%10$s</ul></div></div><div class="fh-map__results" id="%12$s">%11$s<p class="fh-visually-hidden" role="status" data-wp-text="state.announced"></p></div></div></div></section>',
			esc_html__( 'Where the research comes from', 'firehouse' ),
			esc_html__( 'Submissions by Geographic Area Coordination Center (GACC). Select one to open the projects filed there.', 'firehouse' ),
			esc_attr( self::FORM ),
			esc_url( $action . '#coverage-heading' ),
			esc_html__( 'Coverage by Geographic Area Coordination Center', 'firehouse' ),
			/* translators: %d: how many projects name a region. */
			esc_html( sprintf( _n( '%d submission', '%d submissions', $total, 'firehouse' ), $total ) ),
			$national > 0
				/* translators: %d: how many national studies there are. */
				? '<p class="fh-map__note">' . esc_html( sprintf( _n( 'GACC counts include %d national study, counted once in every area.', 'GACC counts include %d national studies, counted once in every area.', $national, 'firehouse' ), $national ) ) . '</p>'
				: '',
			$list,
			esc_html__( 'Not on the map', 'firehouse' ),
			$off_map,
			self::results( $projects, $selected, null !== $selected ? $regions[ $selected ]['short'] : '' ),
			esc_attr( self::RESULTS ),
			esc_attr( self::STORE ),
			self::stage()
		);
	}

	/**
	 * The map's stage and credit, as the site's are: the canvas the view script draws the
	 * map into, hidden from assistive technology, which the list serves; the GACC labels
	 * toggle, hidden until the map is drawn, so it's never a button that does nothing;
	 * the notice that the basemap didn't load, hidden until then; and, under it,
	 * the boundaries' source and the basemap's attribution, which Esri's terms require.
	 * Both are hidden if the map can't be drawn, and frame.css hides them without
	 * JavaScript.
	 */
	private static function stage(): string {
		return sprintf(
			'<div class="fh-map__stage" data-wp-bind--hidden="state.failed"><div class="fh-map__canvas" aria-hidden="true" data-wp-init="callbacks.draw"></div><div class="fh-map__scan" aria-hidden="true"></div><button type="button" class="fh-map__codes-toggle" aria-pressed="true" hidden data-wp-bind--hidden="!state.drawn" data-wp-bind--aria-pressed="state.showCodes" data-wp-on--click="actions.toggleCodes"><span class="fh-map__codes-box" aria-hidden="true"></span>%1$s</button><p class="fh-map__offline" role="status" hidden data-wp-bind--hidden="!state.tilesFailed">%2$s</p></div><p class="fh-map__credit" data-wp-bind--hidden="state.failed">%3$s</p>',
			esc_html__( 'GACC labels', 'firehouse' ),
			esc_html__( 'Basemap unavailable — region boundaries and counts are still shown.', 'firehouse' ),
			/* translators: %s: the basemap's attribution, such as "Esri, HERE, Garmin, © OpenStreetMap contributors". */
			esc_html( sprintf( __( 'Boundaries: NIFC National GACC Boundaries. Basemap: %s.', 'firehouse' ), Map_Colors::attribution() ) )
		);
	}

	/**
	 * One region's button: its name, its code for a GACC, and its count. A GACC's swatch
	 * holds its colour for each theme; one with nothing filed has none. Pressed, it
	 * selects its region and brings the page back at its projects, or selects none if
	 * it's already selected; "All submissions" (no key) selects none. With the script,
	 * it does the same in place.
	 *
	 * @param string|null $key      The region's key, or null for all.
	 * @param string      $name     What the button calls it.
	 * @param int         $count    Its count.
	 * @param string|null $selected The selected region's key, or null for none.
	 * @param string      $results  The landing page's address at the results.
	 * @param int|null    $max      The busiest GACC's count, for a GACC; null for the rest.
	 */
	private static function region( ?string $key, string $name, int $count, ?string $selected, string $results, ?int $max = null ): string {
		$on     = $key === $selected;
		$swatch = '';
		if ( null !== $max ) {
			$colors = $count > 0
				? sprintf( ' style="--fh-swatch-light: %1$s; --fh-swatch-dark: %2$s"', esc_attr( Map_Colors::heat( $count, $max, 'light' )['fill'] ), esc_attr( Map_Colors::heat( $count, $max, 'dark' )['fill'] ) )
				: '';
			$swatch = '<span class="fh-map__swatch" aria-hidden="true"' . $colors . '></span>';
		}
		return sprintf(
			'<li><button type="submit" form="%1$s"%2$s class="fh-map__region%3$s%4$s" aria-pressed="%5$s" data-wp-context="%10$s" data-wp-on--click="actions.select" data-wp-bind--aria-pressed="state.pressed" data-wp-class--fh-map__region--on="state.pressed">%6$s<span class="fh-map__region-name">%7$s%8$s</span><span class="fh-map__region-count">%9$d</span></button></li>',
			esc_attr( self::FORM ),
			null !== $key && ! $on ? ' formaction="' . esc_url( $results ) . '" name="region" value="' . esc_attr( $key ) . '"' : '',
			null === $key || in_array( $key, Vocabulary::gaccs(), true ) ? '' : ' fh-map__region--sm',
			$on ? ' fh-map__region--on' : '',
			$on ? 'true' : 'false',
			$swatch,
			esc_html( $name ),
			null !== $max ? '<span class="fh-map__region-code">' . esc_html( (string) $key ) . '</span>' : '',
			$count,
			esc_attr( (string) wp_json_encode( array( 'key' => $key ) ) )
		);
	}

	/**
	 * The results, every part a choice can show, each hidden unless this selection shows
	 * it: the hint, when none is selected; the heading that counts the selected region's
	 * projects, with Clear; their list, in the order given, each linking to its page, a
	 * national study's tagged so in any region but National; and, when none is filed
	 * there, saying so. The view script rewrites the heading's parts and draws the list
	 * from its template, which follows the projects the server printed. Each of those
	 * has the template's shape, the tag printed hidden where it doesn't apply, as the
	 * explorer's tiles have theirs: WordPress takes them over element by element, so a
	 * missing one would hand the year to the tag.
	 *
	 * @param list<array<string, mixed>> $projects The projects, from projects().
	 * @param string|null                $selected The selected region's key, or null for none.
	 * @param string                     $name     What the selected region is called, or ''.
	 */
	private static function results( array $projects, ?string $selected, string $name ): string {
		$shown  = null !== $selected ? array_values( array_filter( $projects, static fn( array $project ): bool => in_array( $selected, $project['regions'], true ) ) ) : array();
		$hidden = static fn( bool $hide ): string => $hide ? ' hidden' : '';
		$items  = '';
		foreach ( $shown as $project ) {
			$items .= sprintf(
				'<li data-wp-each-child="%6$s"><a class="fh-map__project" href="%1$s"><span>%2$s</span><span class="fh-map__project-tag"%3$s>%7$s</span><span class="fh-map__project-year">%4$s</span>%5$s</a></li>',
				esc_url( $project['url'] ),
				Markup::text( $project['title'] ),
				$project['national'] && 'NATIONAL' !== $selected ? '' : ' hidden',
				esc_html( (string) Search::key( $project['year'] ) ),
				Icons::svg( 'arrow-right', 14 ),
				esc_attr( self::STORE . '::state.shown' ),
				esc_html__( 'National', 'firehouse' )
			);
		}
		$count = count( $shown );
		/* translators: 1: how many projects are filed under the region; 2: the region's name. */
		$parts = self::heading( _n( '%1$d project in %2$s', '%1$d projects in %2$s', $count, 'firehouse' ), $count );

		return sprintf(
			'<p class="fh-map__hint"%1$s data-wp-bind--hidden="!state.none">%2$s</p><div class="fh-map__results-head"%3$s data-wp-bind--hidden="state.none">%4$s<h3 class="fh-map__results-title"><span data-wp-text="state.before">%5$s</span><strong data-wp-text="state.name">%6$s</strong><span data-wp-text="state.after">%7$s</span></h3><button type="submit" form="%8$s" class="fh-map__clear" data-wp-on--click="actions.clear">%9$s</button></div><ul class="fh-map__projects"%10$s data-wp-bind--hidden="!state.shown.length">%11$s%12$s</ul><p class="fh-map__empty"%13$s data-wp-bind--hidden="!state.empty">%14$s</p>',
			$hidden( null !== $selected ),
			esc_html__( 'Select a GACC on the map or in the list to see the research filed there.', 'firehouse' ),
			$hidden( null === $selected ),
			Icons::svg( 'map-pin', 14 ),
			esc_html( $parts[0] ),
			esc_html( $name ),
			esc_html( $parts[1] ),
			esc_attr( self::FORM ),
			esc_html__( 'Clear', 'firehouse' ),
			$hidden( 0 === $count ),
			$items,
			self::item_template(),
			$hidden( null === $selected || 0 !== $count ),
			esc_html__( 'No published research is filed under this region yet.', 'firehouse' )
		);
	}

	/**
	 * The results' heading as two pieces of text around the region's name, which the page
	 * prints in bold: the format split at its `%2$s`, the count put in for its `%1$d`
	 * wherever a translation puts it. The view script splits it the same way.
	 *
	 * @param string $format The heading, as `_n()` gives it for the count.
	 * @param int    $count  How many projects are filed under the region.
	 * @return array{0: string, 1: string}
	 */
	public static function heading( string $format, int $count ): array {
		$parts = explode( '%2$s', $format, 2 ) + array( 1 => '' );
		return array( str_replace( '%1$d', (string) $count, $parts[0] ), str_replace( '%1$d', (string) $count, $parts[1] ) );
	}

	/**
	 * The template the view script draws each project in the results from: results()'s
	 * markup, its words bound to the project. The National tag is hidden unless the
	 * project is a national study and the region isn't National.
	 */
	private static function item_template(): string {
		return '<template data-wp-each--project="state.shown" data-wp-each-key="context.project.url"><li><a class="fh-map__project" data-wp-bind--href="context.project.url"><span data-wp-text="context.project.title"></span><span class="fh-map__project-tag" data-wp-bind--hidden="!state.tagged">'
			. esc_html__( 'National', 'firehouse' )
			. '</span><span class="fh-map__project-year" data-wp-text="context.project.year"></span>'
			. Icons::svg( 'arrow-right', 14 )
			. '</a></li></template>';
	}
}
