<?php
/**
 * The project explorer.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

use Firehouse\Domain\Derive;
use Firehouse\Domain\Format;
use Firehouse\Domain\Js;
use Firehouse\Domain\Search;
use Firehouse\Domain\Vocabulary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The project explorer: the site's `ProjectsPage`, rendered by the `firehouse/explorer`
 * block on the project archive's address.
 *
 * It works without JavaScript (open question 11). Every control belongs to one `GET`
 * form, so sending it reloads the page with the parameters the React explorer reads,
 * `?q=`, `?phase=`, `?region=`, `?year=`, `?type=` and `?sort=` (the empty ones a
 * form sends read as unset), and the server searches and counts with Domain\Search, the
 * port of `search.ts`. The selects stand outside the form element, where the site has
 * them, and join it by its ID. A button the site doesn't have, "Update results", sends
 * it. Clearing the search, removing a filter and clearing them all are links to the
 * address without them, where the site has buttons.
 *
 * What only JavaScript can do isn't printed yet: results that update as you type,
 * Escape to clear the search, the "/" shortcut, the button that folds the filters away
 * on a phone (they show, unfolded), and the quick look. The Interactivity API brings
 * them, on this same markup.
 *
 * Researchers' words, and the words in the address, are printed as they are, escaped
 * (rule 1).
 */
final class Explorer_Page {

	/**
	 * The form's ID, which the selects and the button outside it name.
	 */
	public const FORM = 'fh-explorer-form';

	/**
	 * Where the form and the links back to the explorer land: the page's main landmark,
	 * the skip link's target (templates/frame.php). Without it, each reload starts at the
	 * top of the theme's header, many keyboard stops from the search.
	 */
	public const LANDING = '#fh-content';

	/**
	 * The page for this request, from its address and what WordPress stores.
	 *
	 * The query is read from the address as the browser sent it (REQUEST_URI), not
	 * from QUERY_STRING, which a server's rewrite rules can change, or from $_GET,
	 * which PHP reads by its own rules (Search::params() says how they differ).
	 */
	public static function for_request(): string {
		return self::render( self::projects(), Search::parse_query( self::query_string() ), (string) get_post_type_archive_link( Post_Types::PROJECT ) );
	}

	/**
	 * This request's query string, unslashed, from its address as the browser sent it:
	 * what follows the first "?" in REQUEST_URI, or "".
	 */
	public static function query_string(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read as the browser reads its address; every value is checked or escaped where it's used.
		$at  = strpos( $uri, '?' );
		return false === $at ? '' : substr( $uri, $at + 1 );
	}

	/**
	 * The projects a visitor may see, each one's fields and its address, in import
	 * order, which is the site's JSON order. Search sorts them, and keeps that order for
	 * ties. Two queries whatever their number: the posts, then all their fields.
	 *
	 * A project is checked as the site checks it as well as by the query
	 * (Store::is_public()). One without a slug, a title as text or a year as a number
	 * can't be listed or sorted, and is left out rather than stopping the page (rule 6).
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function projects(): array {
		$projects = array();
		$args     = array(
			'numberposts' => -1,
			'orderby'     => 'ID',
			'order'       => 'ASC',
		);
		foreach ( get_posts( Store::public_projects_query( $args ) ) as $post ) {
			if ( ! Store::is_public( $post ) ) {
				continue;
			}
			$fields = Store::project( $post )['fields'];
			$year   = $fields['completionYear'] ?? null;
			if ( '' !== $fields['slug'] && is_string( $fields['title'] ?? null ) && ( is_int( $year ) || is_float( $year ) ) ) {
				$projects[] = array( 'url' => (string) get_permalink( $post ) ) + $fields;
			}
		}
		return $projects;
	}

	/**
	 * The page's markup, inside the frame's main landmark.
	 *
	 * @param list<array<string, mixed>> $all   The projects a visitor may see, from projects().
	 * @param array<string, mixed>       $query The query, from Search::parse_query().
	 * @param string                     $url   The explorer's address.
	 */
	public static function render( array $all, array $query, string $url ): string {
		// Each project is scored once, for the list and the four counts alike.
		$scores    = Search::scores( $all, (string) $query['q'] );
		$projects  = Search::filter_projects( $all, $query, $scores );
		$searching = '' !== Js::trim( (string) $query['q'] );
		$filtered  = $searching || Search::active_facet_count( $query ) > 0;
		$cleared   = self::href( $url, array( 'sort' => $query['sort'] ) );

		ob_start();
		?>
<div class="fh-explorer fh-container">
	<header class="fh-explorer__intro">
		<span class="fh-eyebrow"><?php esc_html_e( 'Project explorer', 'firehouse' ); ?></span>
		<h1 class="fh-explorer__heading"><?php esc_html_e( 'Completed research projects', 'firehouse' ); ?></h1>
		<p class="fh-explorer__body"><?php esc_html_e( 'Every project analyzed for The FireHouse. Search or filter, then open one to read its takeaways, end-user needs, recommendations, and related papers.', 'firehouse' ); ?></p>
	</header>

	<search class="fh-explorer__toolbar" aria-label="<?php esc_attr_e( 'Search and filter projects', 'firehouse' ); ?>">
		<form class="fh-search" id="<?php echo esc_attr( self::FORM ); ?>" method="get" action="<?php echo esc_url( $url . self::LANDING ); ?>" autocomplete="off">
			<label class="fh-visually-hidden" for="fh-explorer-q"><?php esc_html_e( 'Search projects', 'firehouse' ); ?></label>
			<?php echo Icons::svg( 'search', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- one of the plugin's checked icons. ?>
			<input id="fh-explorer-q" class="fh-search__input" type="search" name="q" value="<?php echo Markup::text( (string) $query['q'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup::text() escapes it, quotes included. ?>" placeholder="<?php esc_attr_e( 'Search projects', 'firehouse' ); ?>" autocomplete="off" spellcheck="false" enterkeyhint="search" aria-describedby="fh-explorer-q-hint">
			<?php if ( '' !== $query['q'] ) : ?>
			<a class="fh-search__clear" href="<?php echo self::href( $url, array( 'q' => '' ) + $query ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- href() escapes it. ?>" aria-label="<?php esc_attr_e( 'Clear search', 'firehouse' ); ?>"><?php echo Icons::svg( 'x', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above. ?></a>
			<?php endif; ?>
			<span id="fh-explorer-q-hint" class="fh-visually-hidden"><?php esc_html_e( 'Searches titles, authors, abstracts, and findings.', 'firehouse' ); ?></span>
		</form>

		<div id="fh-explorer-facets" class="fh-explorer__facets fh-explorer__facets--open">
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- select() escapes what it builds.
			echo self::facets( $all, $query, $searching, $scores );
			?>
			<div class="fh-explorer__apply">
				<button type="submit" form="<?php echo esc_attr( self::FORM ); ?>" class="fh-btn fh-btn--secondary fh-btn--md"><?php esc_html_e( 'Update results', 'firehouse' ); ?></button>
			</div>
		</div>
	</search>

	<div class="fh-explorer__summary">
		<p class="fh-explorer__count" role="status">
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- summary() escapes what it builds.
		echo self::summary( count( $projects ), count( $all ), $filtered, $searching ? Js::trim( (string) $query['q'] ) : '' );
		?>
		</p>
		<?php
		$applied = self::applied( $query );
		if ( array() !== $applied || $filtered ) :
			?>
		<ul class="fh-explorer__applied" aria-label="<?php esc_attr_e( 'Applied filters', 'firehouse' ); ?>">
			<?php foreach ( $applied as $facet => $label ) : ?>
			<li><a class="fh-pill" href="<?php echo self::href( $url, array( $facet => null ) + $query ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- href() escapes it. ?>" aria-label="<?php echo Markup::text( sprintf( /* translators: %s: the filter, such as "Phase: Before". */ __( 'Remove filter %s', 'firehouse' ), $label ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup::text() escapes it as the label shows, a character reference included. ?>"><?php echo Markup::text( $label ) . Icons::svg( 'x', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup::text() escapes the label, and the icon is one of the plugin's checked ones. ?></a></li>
			<?php endforeach; ?>
			<?php if ( $filtered ) : ?>
			<li><a class="fh-explorer__clear-all" href="<?php echo $cleared; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- href() escapes it. ?>"><?php esc_html_e( 'Clear all', 'firehouse' ); ?></a></li>
			<?php endif; ?>
		</ul>
		<?php endif; ?>
	</div>

		<?php if ( array() !== $projects ) : ?>
	<div class="fh-explorer__grid">
			<?php
			foreach ( $projects as $project ) {
				echo self::tile( $project ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tile() escapes what it builds.
			}
			?>
	</div>
		<?php else : ?>
	<div class="fh-explorer__empty">
		<p class="fh-explorer__empty-title"><?php echo $filtered ? esc_html__( 'No projects match your search and filters.', 'firehouse' ) : esc_html__( 'No published projects are available yet.', 'firehouse' ); ?></p>
			<?php if ( $filtered ) : ?>
		<p class="fh-explorer__empty-body"><?php esc_html_e( 'Try fewer or shorter search words, or remove a filter. Every filter above shows how many projects each choice would leave.', 'firehouse' ); ?></p>
		<a class="fh-explorer__empty-action" href="<?php echo $cleared; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- href() escapes it. ?>"><?php esc_html_e( 'Clear search and filters', 'firehouse' ); ?></a>
			<?php else : ?>
		<a class="fh-explorer__empty-action" href="<?php echo esc_url( home_url( '/' . Query::ROOT_PATH . '/#top-needs' ) ); ?>"><?php esc_html_e( 'Browse topic areas', 'firehouse' ); ?></a>
			<?php endif; ?>
	</div>
		<?php endif; ?>
</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The four filters and the sort, each option with the number of results it would
	 * leave, holding the search and the other filters.
	 *
	 * @param list<array<string, mixed>> $all       The projects a visitor may see.
	 * @param array<string, mixed>       $query     The query.
	 * @param bool                       $searching Whether there's text to search for.
	 * @param array<float>               $scores    The projects' scores for the search text, from Search::scores().
	 */
	private static function facets( array $all, array $query, bool $searching, array $scores ): string {
		$counts = array();
		foreach ( Search::FACETS as $facet ) {
			$counts[ $facet ] = Search::facet_counts( $all, $query, $facet, static fn( array $project ): array => Search::facet_values( $project, $facet ), $scores );
		}
		$option = static fn( string $facet, string $value, string $label, string|int $key ): array => array(
			'value' => $value,
			'label' => $label,
			'count' => $counts[ $facet ][ $key ] ?? 0,
		);

		$phases = array();
		foreach ( Vocabulary::items( 'firePhases' ) as $key => $phase ) {
			$phases[] = $option( 'phase', $key, (string) $phase['label'], $key );
		}

		$gaccs = array();
		$other = array();
		foreach ( Vocabulary::items( 'regions' ) as $key => $region ) {
			if ( ! empty( $region['mapped'] ) ) {
				$gaccs[] = $option( 'region', $key, $region['short'] . ' (' . $key . ')', $key );
			} else {
				$other[] = $option( 'region', $key, (string) $region['short'], $key );
			}
		}

		// Each year once, newest first: 2025 and 2025.0 are one number, as in JavaScript.
		$years = array();
		foreach ( $all as $project ) {
			$years[ (string) Search::key( $project['completionYear'] ) ] = $project['completionYear'];
		}
		uasort( $years, static fn( int|float $a, int|float $b ): int => $b <=> $a );
		$year_options = array();
		foreach ( array_keys( $years ) as $year ) {
			$year_options[] = $option( 'year', (string) $year, (string) $year, Search::key( $years[ $year ] ) );
		}

		// Each type once, in code-point order: JavaScript's sort() without a compare
		// function, which differs only beyond the Basic Multilingual Plane.
		$types = array();
		foreach ( $all as $project ) {
			if ( Js::truthy( $project['projectType'] ?? null ) && is_string( $project['projectType'] ) ) {
				$types[ $project['projectType'] ] = true;
			}
		}
		$types = array_map( 'strval', array_keys( $types ) );
		sort( $types, SORT_STRING );
		$type_options = array_map( static fn( string $type ): array => $option( 'type', $type, $type, $type ), $types );

		// "Best match" only means something while there is text to match. The option the
		// page falls back to sends what the address had: nothing, or "relevance" with no
		// text to match yet. Either way, once there is text the results are by best match,
		// as on the site. A sort chosen is sent as itself.
		$sort  = Search::effective_sort( $query );
		$sent  = $query['sort'] === $sort ? $sort : (string) $query['sort'];
		$sorts = array();
		foreach ( self::sorts() as $key => $label ) {
			if ( 'relevance' !== $key || $searching ) {
				$sorts[] = array(
					'value' => $key === $sort ? $sent : $key,
					'label' => $label,
				);
			}
		}

		return self::select( 'phase', __( 'Fire phase', 'firehouse' ), (string) $query['phase'], __( 'Any phase', 'firehouse' ), array( '' => $phases ) )
			. self::select(
				'region',
				__( 'GACC', 'firehouse' ),
				(string) $query['region'],
				__( 'Any area', 'firehouse' ),
				array(
					__( 'Geographic Area Coordination Centers', 'firehouse' ) => $gaccs,
					__( 'Not on the map', 'firehouse' ) => $other,
				)
			)
			. self::select( 'year', __( 'Year completed', 'firehouse' ), null === $query['year'] ? '' : (string) $query['year'], __( 'Any year', 'firehouse' ), array( '' => $year_options ) )
			. self::select( 'type', __( 'Project type', 'firehouse' ), (string) $query['type'], __( 'Any type', 'firehouse' ), array( '' => $type_options ) )
			. self::select( 'sort', __( 'Sort by', 'firehouse' ), $sent, null, array( '' => $sorts ), false );
	}

	/**
	 * The sorts, by key, with their labels, as `SORT_OPTIONS` in search.ts has them.
	 *
	 * @return array<string, string>
	 */
	public static function sorts(): array {
		return array(
			'relevance' => __( 'Best match', 'firehouse' ),
			'newest'    => __( 'Newest first', 'firehouse' ),
			'oldest'    => __( 'Oldest first', 'firehouse' ),
			'title'     => __( 'Title A–Z', 'firehouse' ),
		);
	}

	/**
	 * A labelled native select, as the site's `FilterSelect`. An option that would leave
	 * no results says "(0)" but stays enabled, where the site disables it: without
	 * JavaScript, the counts describe the results on the page, not a change to another
	 * select made since, so a disabled option could refuse a choice that change makes
	 * possible. With nothing chosen that an option holds, the browser shows the first.
	 *
	 * @param string                                    $name      The parameter it sets.
	 * @param string                                    $label     Its label.
	 * @param string                                    $value     The value chosen, or ''.
	 * @param string|null                               $all_label The label of the option for none, if it has one.
	 * @param array<string, list<array<string, mixed>>> $groups    Its options, by group label; '' for none.
	 * @param bool                                      $mark_set  Whether a value chosen shows as an applied filter.
	 */
	private static function select( string $name, string $label, string $value, ?string $all_label, array $groups, bool $mark_set = true ): string {
		$id      = 'fh-explorer-' . $name;
		$options = null === $all_label ? '' : '<option value="">' . esc_html( $all_label ) . '</option>';
		foreach ( $groups as $group => $items ) {
			$html = '';
			foreach ( $items as $item ) {
				$count = $item['count'] ?? null;
				$html .= sprintf(
					'<option value="%1$s"%2$s>%3$s</option>',
					Markup::text( $item['value'] ),
					$item['value'] === $value ? ' selected' : '',
					Markup::text( null === $count ? $item['label'] : $item['label'] . ' (' . $count . ')' )
				);
			}
			$options .= '' === $group ? $html : sprintf( '<optgroup label="%1$s">%2$s</optgroup>', esc_attr( (string) $group ), $html );
		}

		return sprintf(
			'<div class="fh-filter%1$s"><label class="fh-filter__label" for="%2$s">%3$s</label><div class="fh-filter__control"><select id="%2$s" name="%4$s" form="%5$s">%6$s</select>%7$s</div></div>',
			$mark_set && '' !== $value ? ' fh-filter--set' : '',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( $name ),
			esc_attr( self::FORM ),
			$options,
			Icons::svg( 'chevron-down', 16 )
		);
	}

	/**
	 * The results line: how many projects show, of how many, and the text searched for.
	 *
	 * @param int    $shown    How many projects show.
	 * @param int    $total    How many a visitor may see.
	 * @param bool   $filtered Whether a search or a filter is applied.
	 * @param string $q        The text searched for, trimmed, or ''.
	 */
	private static function summary( int $shown, int $total, bool $filtered, string $q ): string {
		$strong = static fn( string $text ): string => '<strong>' . $text . '</strong>';
		if ( ! $filtered ) {
			/* translators: %s: how many projects, in bold. */
			return sprintf( esc_html( _n( '%s project', '%s projects', $shown, 'firehouse' ) ), $strong( (string) $shown ) );
		}
		/* translators: 1: how many projects show, in bold; 2: how many there are. */
		$line = sprintf( esc_html( _n( 'Showing %1$s of %2$s project', 'Showing %1$s of %2$s projects', $total, 'firehouse' ) ), $strong( (string) $shown ), (string) $total );
		if ( '' !== $q ) {
			/* translators: %s: the text searched for, in bold and in quotation marks. */
			$line .= ' ' . sprintf( esc_html__( 'matching %s', 'firehouse' ), $strong( '“' . Markup::text( $q ) . '”' ) );
		}
		return $line;
	}

	/**
	 * The filters applied, by facet, each as its pill reads: "Phase: Before".
	 *
	 * @param array<string, mixed> $query The query.
	 * @return array<string, string>
	 */
	private static function applied( array $query ): array {
		$applied = array();
		if ( null !== $query['phase'] ) {
			/* translators: %s: a fire phase's short name, such as "Before". */
			$applied['phase'] = sprintf( __( 'Phase: %s', 'firehouse' ), Vocabulary::items( 'firePhases' )[ $query['phase'] ]['short'] );
		}
		if ( null !== $query['region'] ) {
			/* translators: %s: a region's short name, such as "Great Basin". */
			$applied['region'] = sprintf( __( 'GACC: %s', 'firehouse' ), Vocabulary::items( 'regions' )[ $query['region'] ]['short'] );
		}
		if ( null !== $query['year'] ) {
			/* translators: %d: a year. */
			$applied['year'] = sprintf( __( 'Year: %d', 'firehouse' ), $query['year'] );
		}
		if ( null !== $query['type'] ) {
			/* translators: %s: a project type, as the researcher's answer has it. */
			$applied['type'] = sprintf( __( 'Type: %s', 'firehouse' ), $query['type'] );
		}
		return $applied;
	}

	/**
	 * A project's tile, as the site's `ProjectTile`: a link on the title, stretched over
	 * the tile by CSS, so the tile's whole area opens the project while the link's name
	 * stays the title.
	 *
	 * @param array<string, mixed> $project The project, with its address.
	 */
	public static function tile( array $project ): string {
		$text    = static fn( string $field ): string => is_string( $project[ $field ] ?? null ) ? $project[ $field ] : '';
		$items   = Vocabulary::items( 'firePhases' );
		$phases  = '';
		$authors = array_values( array_filter( is_array( $project['authors'] ?? null ) ? $project['authors'] : array(), static fn( mixed $author ): bool => is_array( $author ) && is_string( $author['name'] ?? null ) ) );
		foreach ( is_array( $project['firePhases'] ?? null ) ? $project['firePhases'] : array() as $phase ) {
			if ( is_string( $phase ) && isset( $items[ $phase ] ) ) {
				$phases .= '<span class="fh-tile__phase">' . esc_html( (string) $items[ $phase ]['short'] ) . '</span>';
			}
		}
		$type = $text( 'projectType' );

		return sprintf(
			'<article class="fh-tile"><span class="fh-tile__glow" aria-hidden="true"></span><span class="fh-tile__body">%1$s<h3 class="fh-tile__title"><a class="fh-tile__link" href="%2$s">%3$s</a></h3><p class="fh-tile__summary">%4$s</p></span><span class="fh-tile__meta"><span class="fh-tile__byline">%5$s · %6$s</span>%7$s</span>%8$s</article>',
			'' !== $type ? '<span class="fh-tile__kicker">' . Markup::text( $type ) . '</span>' : '',
			esc_url( $text( 'url' ) ),
			Markup::text( $text( 'title' ) ),
			Markup::text( $text( 'summary' ) ),
			Markup::text( Format::byline( $authors ) ),
			esc_html( (string) Search::key( $project['completionYear'] ?? '' ) ),
			Icons::svg( 'arrow-up-right', 16 ),
			'' !== $phases ? '<span class="fh-tile__foot"><span class="fh-tile__phases">' . $phases . '</span></span>' : ''
		);
	}

	/**
	 * The explorer's address with a query, its unset parts left out, as the site writes
	 * it (Search::serialize_query()), escaped for an attribute. The query is already
	 * percent-encoded, so only the address before it goes through esc_url(), which would
	 * drop an encoded line break and run two search words into one. It lands on the
	 * page's content, as the form does (LANDING).
	 *
	 * @param string               $url   The explorer's address.
	 * @param array<string, mixed> $query The query.
	 */
	private static function href( string $url, array $query ): string {
		$string = Search::serialize_query( $query );
		return esc_url( $url ) . ( '' === $string ? '' : Markup::text( '?' . $string ) ) . self::LANDING;
	}
}
