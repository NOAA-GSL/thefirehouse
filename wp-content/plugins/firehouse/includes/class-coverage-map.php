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
 * Coordination Center (GACC): a count for each of the ten, and for the four answers
 * with no boundary (Pacific, national, international, not sure), and the projects
 * filed under the one selected.
 *
 * Without JavaScript, the region list is the map. Each region is a button that submits
 * a GET form for `?region=` and its key, the address the site uses, and the page
 * comes back with that region's projects; the selected region's button, "All
 * submissions" and "Clear" submit nothing, which selects none. The form goes back to
 * the section's heading. The map itself, which only the script can draw, comes in
 * the next part of Phase 5.
 *
 * Each GACC's swatch carries its colour for both themes, from the map's ramps
 * (Map_Colors), and frame.css shows the one for the theme the page is in.
 */
final class Coverage_Map {

	/**
	 * The form the region buttons submit.
	 */
	public const FORM = 'fh-map-form';

	/**
	 * The map for this request: the projects a visitor may see, and the region the
	 * address selects, read as the explorer reads its query (Search::params()), with a
	 * key that isn't a region's ignored, as the site ignores it.
	 */
	public static function for_request(): string {
		$region = Search::params( Explorer_Page::query_string() )['region'] ?? null;
		return self::render(
			self::projects( Explorer_Page::projects() ),
			Vocabulary::is_one_of( $region, Vocabulary::regions() ) ? $region : null,
			home_url( '/' . Query::ROOT_PATH . '/' )
		);
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
	 * The coverage section: its heading and words, the region list with each region's
	 * count, and the selected region's projects, or a hint when none is selected.
	 *
	 * @param list<array<string, mixed>> $projects The projects, from projects().
	 * @param string|null                $selected The selected region's key, or null for none.
	 * @param string                     $action   The landing page's address, which the form submits to.
	 */
	public static function render( array $projects, ?string $selected, string $action ): string {
		$counts = array_fill_keys( Vocabulary::regions(), 0 );
		foreach ( $projects as $project ) {
			foreach ( $project['regions'] as $key ) {
				if ( isset( $counts[ $key ] ) ) {
					++$counts[ $key ];
				}
			}
		}
		$gaccs    = Vocabulary::gaccs();
		$max      = max( 1, ...array_map( static fn( string $key ): int => $counts[ $key ], $gaccs ) );
		$total    = count( array_filter( $projects, static fn( array $project ): bool => array() !== $project['regions'] ) );
		$national = count( array_filter( $projects, static fn( array $project ): bool => $project['national'] ) );
		$regions  = Vocabulary::items( 'regions' );

		$list = self::region( null, __( 'All submissions', 'firehouse' ), $total, $selected );
		foreach ( $gaccs as $key ) {
			$list .= self::region( $key, $regions[ $key ]['short'], $counts[ $key ], $selected, $max );
		}
		$off_map = '';
		foreach ( array_diff( Vocabulary::regions(), $gaccs ) as $key ) {
			$off_map .= self::region( $key, $regions[ $key ]['short'], $counts[ $key ], $selected );
		}

		return sprintf(
			'<section class="fh-coverage-section fh-container" aria-labelledby="coverage-heading"><div class="fh-coverage"><div class="fh-coverage__head"><h2 id="coverage-heading" class="fh-coverage__title">%1$s</h2><p class="fh-coverage__sub">%2$s</p></div><div class="fh-map"><div class="fh-map__side"><form id="%3$s" method="get" action="%4$s" hidden></form><div class="fh-map__side-head"><h3 class="fh-map__side-title" id="fh-map-list">%5$s</h3><p class="fh-map__side-total">%6$s</p></div>%7$s<ul class="fh-map__list" aria-labelledby="fh-map-list">%8$s</ul><div class="fh-map__offmap"><h4 class="fh-map__offmap-title">%9$s</h4><ul class="fh-map__list">%10$s</ul></div></div><div class="fh-map__results" role="region" aria-live="polite">%11$s</div></div></div></section>',
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
			null !== $selected ? self::results( $projects, $selected, $regions[ $selected ]['short'] ) : '<p class="fh-map__hint">' . esc_html__( 'Select a GACC on the map or in the list to see the research filed there.', 'firehouse' ) . '</p>'
		);
	}

	/**
	 * One region's button: its name, its code for a GACC, and its count. A GACC's swatch
	 * holds its colour for each theme; one with nothing filed has none. Pressed, it
	 * selects its region, or none if it's already selected; "All submissions" (no key)
	 * selects none.
	 *
	 * @param string|null $key      The region's key, or null for all.
	 * @param string      $name     What the button calls it.
	 * @param int         $count    Its count.
	 * @param string|null $selected The selected region's key, or null for none.
	 * @param int|null    $max      The busiest GACC's count, for a GACC; null for the rest.
	 */
	private static function region( ?string $key, string $name, int $count, ?string $selected, ?int $max = null ): string {
		$on     = $key === $selected;
		$swatch = '';
		if ( null !== $max ) {
			$colors = $count > 0
				? sprintf( ' style="--fh-swatch-light: %1$s; --fh-swatch-dark: %2$s"', esc_attr( Map_Colors::heat( $count, $max, 'light' )['fill'] ), esc_attr( Map_Colors::heat( $count, $max, 'dark' )['fill'] ) )
				: '';
			$swatch = '<span class="fh-map__swatch" aria-hidden="true"' . $colors . '></span>';
		}
		return sprintf(
			'<li><button type="submit" form="%1$s"%2$s class="fh-map__region%3$s%4$s" aria-pressed="%5$s">%6$s<span class="fh-map__region-name">%7$s%8$s</span><span class="fh-map__region-count">%9$d</span></button></li>',
			esc_attr( self::FORM ),
			null !== $key && ! $on ? ' name="region" value="' . esc_attr( $key ) . '"' : '',
			null === $key || in_array( $key, Vocabulary::gaccs(), true ) ? '' : ' fh-map__region--sm',
			$on ? ' fh-map__region--on' : '',
			$on ? 'true' : 'false',
			$swatch,
			esc_html( $name ),
			null !== $max ? '<span class="fh-map__region-code">' . esc_html( (string) $key ) . '</span>' : '',
			$count
		);
	}

	/**
	 * The selected region's projects, in the order given, each linking to its page; a
	 * national study's is tagged so in any region but National. Or, if none is filed
	 * there, saying so.
	 *
	 * @param list<array<string, mixed>> $projects The projects, from projects().
	 * @param string                     $selected The selected region's key.
	 * @param string                     $name     What the region is called.
	 */
	private static function results( array $projects, string $selected, string $name ): string {
		$shown = array_values( array_filter( $projects, static fn( array $project ): bool => in_array( $selected, $project['regions'], true ) ) );
		$items = '';
		foreach ( $shown as $project ) {
			$items .= sprintf(
				'<li><a class="fh-map__project" href="%1$s"><span>%2$s</span>%3$s<span class="fh-map__project-year">%4$s</span>%5$s</a></li>',
				esc_url( $project['url'] ),
				Markup::text( $project['title'] ),
				$project['national'] && 'NATIONAL' !== $selected ? '<span class="fh-map__project-tag">' . esc_html__( 'National', 'firehouse' ) . '</span>' : '',
				esc_html( (string) Search::key( $project['year'] ) ),
				Icons::svg( 'arrow-right', 14 )
			);
		}
		$count = count( $shown );
		return sprintf(
			'<p class="fh-map__results-head">%1$s%2$s<button type="submit" form="%3$s" class="fh-map__clear">%4$s</button></p>%5$s',
			Icons::svg( 'map-pin', 14 ),
			sprintf(
				/* translators: 1: how many projects are filed under the region; 2: the region's name. */
				esc_html( _n( '%1$d project in %2$s', '%1$d projects in %2$s', $count, 'firehouse' ) ),
				$count,
				'<strong>' . esc_html( $name ) . '</strong>'
			),
			esc_attr( self::FORM ),
			esc_html__( 'Clear', 'firehouse' ),
			'' !== $items
				? '<ul class="fh-map__projects">' . $items . '</ul>'
				: '<p class="fh-map__empty">' . esc_html__( 'No published research is filed under this region yet.', 'firehouse' ) . '</p>'
		);
	}
}
