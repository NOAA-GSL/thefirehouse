<?php
/**
 * The project explorer.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

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
 * With JavaScript, the block's view script (blocks/explorer/view.js) takes the same
 * markup over through the Interactivity API: results that update as you type, Escape
 * to clear the search, the filters folded behind a button on a phone, the address
 * kept in step, and the quick look: a native dialog that previews a project from its
 * tile. It searches in the browser with the site's own search.ts, bundled as
 * the `@firehouse/search` module (scripts/plugin-assets.mjs), over the projects in the
 * page's state (state()). The markup carries the directives it binds to, and templates
 * for the lists it redraws: the tiles, the applied filters, and the sort's options.
 *
 * The page is printed here, not by WordPress's processing of those directives, which
 * the block doesn't ask for: that processing prints text with esc_html(), which leaves
 * a character reference alone, so a title stored as "R&amp;D" would show as "R&D".
 * Researchers' words, and the words in the address, are printed as they are (rule 1),
 * and the script sets them as text, which keeps them as they are too. What the server
 * prints for a list stands in until the script draws the list from its template,
 * which follows it so the items keep their nodes, and focus. The region is
 * marked `data-fh-interactive`, and the script makes it WordPress's
 * (`data-wp-interactive`) only once it has loaded, so a script that fails to load
 * leaves the page as it is without JavaScript, rather than drawn from an empty store.
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
	 * The view script's store, for the Interactivity API.
	 */
	public const STORE = 'firehouse/explorer';

	/**
	 * The script module the view script imports the site's search from
	 * (Blocks::register()).
	 */
	public const SEARCH_MODULE = '@firehouse/search';

	/**
	 * The page for this request, from its address and what WordPress stores, with the
	 * state the view script starts from.
	 *
	 * The query is read from the address as the browser sent it (REQUEST_URI), not
	 * from QUERY_STRING, which a server's rewrite rules can change, or from $_GET,
	 * which PHP reads by its own rules (Search::params() says how they differ).
	 */
	public static function for_request(): string {
		$projects = self::projects();
		$query    = Search::parse_query( self::query_string() );
		$url      = (string) get_post_type_archive_link( Post_Types::PROJECT );
		wp_interactivity_state( self::STORE, self::state( $projects, $query, $url ) );
		return self::render( $projects, $query, $url );
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
	 * What the view script starts from, which the Interactivity API prints in the page as
	 * JSON: the projects a visitor may see, as the search reads them; the query the page
	 * was drawn for; and the words the script writes. Every value here is already on the
	 * page or on a project's own page, with two exceptions the search reads anyway: a
	 * geography note on a project with no region, and a paper whose link the project page
	 * refuses. A project's survey answers that the site doesn't show, and anything
	 * editorial but its summary, publication status, address and geography note, stay
	 * out.
	 *
	 * @param list<array<string, mixed>> $all   The projects a visitor may see, from projects().
	 * @param array<string, mixed>       $query The query, from Search::parse_query().
	 * @param string                     $url   The explorer's address.
	 * @return array<string, mixed>
	 */
	public static function state( array $all, array $query, string $url ): array {
		$short = static fn( string $name ): array => array_map( static fn( array $item ): string => (string) $item['short'], Vocabulary::items( $name ) );
		return array(
			'projects' => array_map( array( self::class, 'searchable' ), $all ),
			'query'    => $query,
			// The search box's text, as the page prints it (render()).
			'text'     => self::box_text( (string) $query['q'] ),
			'url'      => $url,
			'landing'  => self::LANDING,
			'short'    => array(
				'phase'  => $short( 'firePhases' ),
				'region' => $short( 'regions' ),
				// A status has no short form: the quick look shows its label, as the site's does.
				'status' => array_map( static fn( array $item ): string => (string) $item['label'], Vocabulary::items( 'publicationStatuses' ) ),
			),
			'sorts'    => self::sorts(),
			'strings'  => array(
				'hint'   => __( 'Searches titles, authors, abstracts, and findings. Results update as you type. Press Escape to clear.', 'firehouse' ),
				'filter' => array(
					/* translators: %s: a fire phase's short name, such as "Before". */
					'phase'  => __( 'Phase: %s', 'firehouse' ),
					/* translators: %s: a region's short name, such as "Great Basin". */
					'region' => __( 'GACC: %s', 'firehouse' ),
					/* translators: %s: a year. */
					'year'   => __( 'Year: %s', 'firehouse' ),
					/* translators: %s: a project type, as the researcher's answer has it. */
					'type'   => __( 'Type: %s', 'firehouse' ),
				),
				/* translators: %s: the filter, such as "Phase: Before". */
				'remove' => __( 'Remove filter %s', 'firehouse' ),
				// The script picks between the two forms by its count, as English does.
				'of'     => array(
					/* translators: %s: how many projects there are. */
					_n( 'of %s project', 'of %s projects', 1, 'firehouse' ),
					/* translators: %s: how many projects there are. */
					_n( 'of %s project', 'of %s projects', 2, 'firehouse' ),
				),
				'count'  => array( _n( 'project', 'projects', 1, 'firehouse' ), _n( 'project', 'projects', 2, 'firehouse' ) ),
				// The quick look's counts of what the project page holds that it doesn't.
				'look'   => array(
					/* translators: %s: how many more needs the project page lists. */
					'more'            => __( 'and %s more on the project page', 'firehouse' ),
					/* translators: %s: what else the project page holds, such as "2 takeaways, 1 related paper". */
					'rest'            => __( 'Also on the full page: %s.', 'firehouse' ),
					/* translators: %s: a number. */
					'takeaways'       => array( _n( '%s takeaway', '%s takeaways', 1, 'firehouse' ), _n( '%s takeaway', '%s takeaways', 2, 'firehouse' ) ),
					/* translators: %s: a number. */
					'recommendations' => array( _n( '%s recommendation', '%s recommendations', 1, 'firehouse' ), _n( '%s recommendation', '%s recommendations', 2, 'firehouse' ) ),
					/* translators: %s: a number. */
					'papers'          => array( _n( '%s related paper', '%s related papers', 1, 'firehouse' ), _n( '%s related paper', '%s related papers', 2, 'firehouse' ) ),
				),
			),
		);
	}

	/**
	 * A project as the view script searches and shows it: the fields search.ts reads, in
	 * the shapes it expects (anything else stored is passed over, as the PHP search
	 * passes it over), its address, and its byline as the tile prints it; and, for the
	 * quick look, its byline naming every author, as its page prints it, its publication
	 * status's key if the vocabulary has it (its page prints none otherwise), which the
	 * script shows as its label, and how many papers its page lists.
	 *
	 * @param array<string, mixed> $project The project, with its address.
	 * @return array<string, mixed>
	 */
	public static function searchable( array $project ): array {
		$text    = static fn( mixed $value ): ?string => is_string( $value ) ? $value : null;
		$texts   = static fn( mixed $values ): array => array_values( array_filter( is_array( $values ) ? $values : array(), 'is_string' ) );
		$entries = static fn( mixed $items, string $one, string $two ): array => array_values(
			array_map(
				static fn( array $item ): array => array(
					$one => $text( $item[ $one ] ?? null ),
					$two => $text( $item[ $two ] ?? null ),
				),
				array_filter( is_array( $items ) ? $items : array(), 'is_array' )
			)
		);
		$authors = array_values( array_filter( is_array( $project['authors'] ?? null ) ? $project['authors'] : array(), static fn( mixed $author ): bool => is_array( $author ) && is_string( $author['name'] ?? null ) ) );
		$geo     = is_array( $project['geo'] ?? null ) ? $project['geo'] : array();
		$status  = $project['publicationStatus'] ?? null;
		return array(
			'slug'              => (string) $project['slug'],
			'url'               => (string) $project['url'],
			'title'             => (string) $project['title'],
			'projectType'       => $text( $project['projectType'] ?? null ),
			'authors'           => array_map( static fn( array $author ): array => array( 'name' => $author['name'] ), $authors ),
			'org'               => $text( $project['org'] ?? null ),
			'summary'           => $text( $project['summary'] ?? null ) ?? '',
			'abstract'          => $text( $project['abstract'] ?? null ),
			'takeaways'         => $texts( $project['takeaways'] ?? null ),
			'methods'           => $texts( $project['methods'] ?? null ),
			'needs'             => $entries( $project['needs'] ?? null, 'need', 'recommendation' ),
			'papers'            => $entries( $project['papers'] ?? null, 'title', 'container' ),
			'geo'               => array(
				'regions' => $texts( $geo['regions'] ?? null ),
				'note'    => $text( $geo['note'] ?? null ),
			),
			'completionYear'    => $project['completionYear'],
			'firePhases'        => $texts( $project['firePhases'] ?? null ),
			'byline'            => Format::byline( $authors ),
			'fullByline'        => Format::byline( $authors, 0 ),
			'publicationStatus' => Vocabulary::is_one_of( $status, Vocabulary::publication_statuses() ) ? $status : null,
			'paperCount'        => count( Project_Page::papers( $project['papers'] ?? null ) ),
		);
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
		$facets    = Search::active_facet_count( $query );
		$filtered  = $searching || $facets > 0;
		$cleared   = self::href( $url, array( 'sort' => $query['sort'] ) );
		$hidden    = static fn( bool $hide ): string => $hide ? ' hidden' : '';

		ob_start();
		?>
<div class="fh-explorer fh-container" data-fh-interactive="<?php echo esc_attr( self::STORE ); ?>" data-wp-init="callbacks.adopt">
	<header class="fh-explorer__intro">
		<span class="fh-eyebrow"><?php esc_html_e( 'Project explorer', 'firehouse' ); ?></span>
		<h1 class="fh-explorer__heading"><?php esc_html_e( 'Completed research projects', 'firehouse' ); ?></h1>
		<p class="fh-explorer__body"><?php esc_html_e( 'Every project analyzed for The FireHouse. Search or filter, then open one to read its takeaways, end-user needs, recommendations, and related papers.', 'firehouse' ); ?></p>
	</header>

	<search class="fh-explorer__toolbar" aria-label="<?php esc_attr_e( 'Search and filter projects', 'firehouse' ); ?>">
		<form class="fh-search" id="<?php echo esc_attr( self::FORM ); ?>" method="get" action="<?php echo esc_url( $url . self::LANDING ); ?>" autocomplete="off" data-wp-on--submit="actions.submit">
			<label class="fh-visually-hidden" for="fh-explorer-q"><?php esc_html_e( 'Search projects', 'firehouse' ); ?></label>
			<?php echo Icons::svg( 'search', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- one of the plugin's checked icons. ?>
			<input id="fh-explorer-q" class="fh-search__input" type="search" name="q" value="<?php echo Markup::text( self::box_text( (string) $query['q'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup::text() escapes it, quotes included. ?>" placeholder="<?php esc_attr_e( 'Search projects', 'firehouse' ); ?>" autocomplete="off" spellcheck="false" enterkeyhint="search" aria-describedby="fh-explorer-q-hint" data-wp-bind--value="state.text" data-wp-on--input="actions.type" data-wp-on--keydown="actions.keydown">
			<a class="fh-search__clear" href="<?php echo self::href( $url, array( 'q' => '' ) + $query ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- href() escapes it. ?>" aria-label="<?php esc_attr_e( 'Clear search', 'firehouse' ); ?>"<?php echo $hidden( '' === $query['q'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed attribute. ?> data-wp-bind--hidden="!state.text" data-wp-bind--href="state.clearSearchHref" data-wp-on--click="actions.clearSearch"><?php echo Icons::svg( 'x', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above. ?></a>
			<span id="fh-explorer-q-hint" class="fh-visually-hidden" data-wp-text="state.strings.hint"><?php esc_html_e( 'Searches titles, authors, abstracts, and findings.', 'firehouse' ); ?></span>
		</form>

		<button type="button" class="fh-explorer__facets-toggle" aria-expanded="false" aria-controls="fh-explorer-facets" hidden data-wp-bind--hidden="!state.live" data-wp-bind--aria-expanded="state.filtersOpen" data-wp-on--click="actions.toggleFilters"><?php esc_html_e( 'Filters and sort', 'firehouse' ); ?><span class="fh-explorer__facets-badge"<?php echo $hidden( 0 === $facets ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed attribute. ?> data-wp-bind--hidden="!state.facetCount"><span data-wp-text="state.facetCount"><?php echo (int) $facets; ?></span><span class="fh-visually-hidden"><?php esc_html_e( ' applied', 'firehouse' ); ?></span></span><?php echo Icons::svg( 'chevron-down', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above. ?></button>
		<div id="fh-explorer-facets" class="fh-explorer__facets fh-explorer__facets--open" data-wp-class--fh-explorer__facets--open="state.facetsOpen">
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- facets() escapes what it builds.
			echo self::facets( $all, $query, $searching, $scores );
			?>
			<div class="fh-explorer__apply" data-wp-bind--hidden="state.live">
				<button type="submit" form="<?php echo esc_attr( self::FORM ); ?>" class="fh-btn fh-btn--secondary fh-btn--md"><?php esc_html_e( 'Update results', 'firehouse' ); ?></button>
			</div>
		</div>
	</search>

	<div class="fh-explorer__summary">
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- summary() escapes what it builds.
		echo self::summary( count( $projects ), count( $all ), $filtered, $searching ? Js::trim( (string) $query['q'] ) : '' );
		?>
		<ul class="fh-explorer__applied" aria-label="<?php esc_attr_e( 'Applied filters', 'firehouse' ); ?>"<?php echo $hidden( ! $filtered ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed attribute. ?> data-wp-bind--hidden="!state.filtered">
			<?php foreach ( self::applied( $query ) as $facet => $label ) : ?>
			<li data-wp-each-child="<?php echo esc_attr( self::STORE . '::state.applied' ); ?>"><a class="fh-pill" href="<?php echo self::href( $url, array( $facet => null ) + $query ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- href() escapes it. ?>" aria-label="<?php echo Markup::text( sprintf( /* translators: %s: the filter, such as "Phase: Before". */ __( 'Remove filter %s', 'firehouse' ), $label ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup::text() escapes it as the label shows, a character reference included. ?>"><span><?php echo Markup::text( $label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above. ?></span><?php echo Icons::svg( 'x', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- one of the plugin's checked icons. ?></a></li>
			<?php endforeach; ?>
			<template data-wp-each--filter="state.applied" data-wp-each-key="context.filter.facet"><li><a class="fh-pill" data-wp-bind--href="context.filter.href" data-wp-bind--aria-label="context.filter.name" data-wp-on--click="actions.removeFilter"><span data-wp-text="context.filter.label"></span><?php echo Icons::svg( 'x', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above. ?></a></li></template>
			<li><a class="fh-explorer__clear-all" href="<?php echo $cleared; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- href() escapes it. ?>" data-wp-bind--href="state.clearAllHref" data-wp-on--click="actions.clearAll"><?php esc_html_e( 'Clear all', 'firehouse' ); ?></a></li>
		</ul>
	</div>

	<div class="fh-explorer__grid"<?php echo $hidden( array() === $projects ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed attribute. ?> data-wp-bind--hidden="!state.hasResults">
		<?php
		foreach ( $projects as $project ) {
			echo self::tile( $project, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tile() escapes what it builds.
		}
		echo self::tile_template(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed template.
		?>
	</div>
	<div class="fh-explorer__empty"<?php echo $hidden( array() !== $projects ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed attribute. ?> data-wp-bind--hidden="state.hasResults">
		<p class="fh-explorer__empty-title"><span<?php echo $hidden( ! $filtered ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above. ?> data-wp-bind--hidden="!state.filtered"><?php esc_html_e( 'No projects match your search and filters.', 'firehouse' ); ?></span><span<?php echo $hidden( $filtered ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above. ?> data-wp-bind--hidden="state.filtered"><?php esc_html_e( 'No published projects are available yet.', 'firehouse' ); ?></span></p>
		<p class="fh-explorer__empty-body"<?php echo $hidden( ! $filtered ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above. ?> data-wp-bind--hidden="!state.filtered"><?php esc_html_e( 'Try fewer or shorter search words, or remove a filter. Every filter above shows how many projects each choice would leave.', 'firehouse' ); ?></p>
		<a class="fh-explorer__empty-action" href="<?php echo $cleared; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- href() escapes it. ?>"<?php echo $hidden( ! $filtered ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above. ?> data-wp-bind--hidden="!state.filtered" data-wp-bind--href="state.clearAllHref" data-wp-on--click="actions.clearAll"><?php esc_html_e( 'Clear search and filters', 'firehouse' ); ?></a>
		<a class="fh-explorer__empty-action" href="<?php echo esc_url( home_url( '/' . Query::ROOT_PATH . '/#top-needs' ) ); ?>"<?php echo $hidden( $filtered ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above. ?> data-wp-bind--hidden="state.filtered"><?php esc_html_e( 'Browse topic areas', 'firehouse' ); ?></a>
	</div>
		<?php echo self::quick_look(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed template. ?>
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
			. self::sort_select( $query, $searching );
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
	 * The sort's options for a query: "Best match" only while there is text to match.
	 * The option the page falls back to sends what the address had: nothing, or
	 * "relevance" with no text to match yet. Either way, once there is text the results
	 * are by best match, as on the site. A sort chosen is sent as itself. The view script
	 * works out the same (sortOptions() in blocks/explorer/view.js).
	 *
	 * @param array<string, mixed> $query     The query.
	 * @param bool                 $searching Whether there's text to search for.
	 * @return list<array{key: string, value: string, label: string, selected: bool}>
	 */
	public static function sort_options( array $query, bool $searching ): array {
		$sort    = Search::effective_sort( $query );
		$sent    = $query['sort'] === $sort ? $sort : (string) $query['sort'];
		$options = array();
		foreach ( self::sorts() as $key => $label ) {
			if ( 'relevance' !== $key || $searching ) {
				$options[] = array(
					'key'      => $key,
					'value'    => $key === $sort ? $sent : $key,
					'label'    => $label,
					'selected' => $key === $sort,
				);
			}
		}
		return $options;
	}

	/**
	 * A labelled native select for one filter, as the site's `FilterSelect`. An option
	 * that would leave no results says "(0)" but stays enabled, where the site disables
	 * it: without JavaScript, the counts describe the results on the page, not a change
	 * to another select made since, so a disabled option could refuse a choice that
	 * change makes possible. The view script, whose counts follow every change, disables
	 * them. With nothing chosen that an option holds, the browser shows the first.
	 *
	 * Each option carries its value and label for the script, which writes its count and
	 * marks it chosen or disabled.
	 *
	 * @param string                                    $name      The parameter it sets.
	 * @param string                                    $label     Its label.
	 * @param string                                    $value     The value chosen, or ''.
	 * @param string                                    $all_label The label of the option for none.
	 * @param array<string, list<array<string, mixed>>> $groups    Its options, by group label; '' for none.
	 */
	private static function select( string $name, string $label, string $value, string $all_label, array $groups ): string {
		$options = sprintf(
			'<option value=""%1$s %2$s data-wp-bind--selected="state.optionSelected">%3$s</option>',
			'' === $value ? ' selected' : '',
			wp_interactivity_data_wp_context( array( 'value' => '' ) ),
			esc_html( $all_label )
		);
		foreach ( $groups as $group => $items ) {
			$html = '';
			foreach ( $items as $item ) {
				$html .= sprintf(
					'<option value="%1$s"%2$s %3$s data-wp-text="state.optionText" data-wp-bind--disabled="state.optionDisabled" data-wp-bind--selected="state.optionSelected">%4$s</option>',
					Markup::text( $item['value'] ),
					$item['value'] === $value ? ' selected' : '',
					wp_interactivity_data_wp_context(
						array(
							'value' => $item['value'],
							'label' => $item['label'],
						)
					),
					Markup::text( $item['label'] . ' (' . $item['count'] . ')' )
				);
			}
			$options .= '' === $group ? $html : sprintf( '<optgroup label="%1$s">%2$s</optgroup>', esc_attr( (string) $group ), $html );
		}

		return sprintf(
			'<div class="fh-filter%1$s" %2$s data-wp-class--fh-filter--set="state.facetSet"><label class="fh-filter__label" for="%3$s">%4$s</label><div class="fh-filter__control"><select id="%3$s" name="%5$s" form="%6$s" data-wp-on--change="actions.choose">%7$s</select>%8$s</div></div>',
			'' !== $value ? ' fh-filter--set' : '',
			wp_interactivity_data_wp_context( array( 'facet' => $name ) ),
			esc_attr( 'fh-explorer-' . $name ),
			esc_html( $label ),
			esc_attr( $name ),
			esc_attr( self::FORM ),
			$options,
			Icons::svg( 'chevron-down', 16 )
		);
	}

	/**
	 * The sort's select. Its options change with the search (sort_options()), so the
	 * view script draws them from a template; the ones printed stand in until it does.
	 *
	 * @param array<string, mixed> $query     The query.
	 * @param bool                 $searching Whether there's text to search for.
	 */
	private static function sort_select( array $query, bool $searching ): string {
		$template = '<template data-wp-each--option="state.sortOptions" data-wp-each-key="context.option.key"><option data-wp-bind--value="context.option.value" data-wp-bind--selected="context.option.selected" data-wp-text="context.option.label"></option></template>';
		$options  = '';
		foreach ( self::sort_options( $query, $searching ) as $option ) {
			$options .= sprintf(
				'<option value="%1$s"%2$s data-wp-each-child="%3$s">%4$s</option>',
				esc_attr( $option['value'] ),
				$option['selected'] ? ' selected' : '',
				esc_attr( self::STORE . '::state.sortOptions' ),
				esc_html( $option['label'] )
			);
		}
		return sprintf(
			'<div class="fh-filter"><label class="fh-filter__label" for="fh-explorer-sort">%1$s</label><div class="fh-filter__control"><select id="fh-explorer-sort" name="sort" form="%2$s" data-wp-on--change="actions.chooseSort">%3$s</select>%4$s</div></div>',
			esc_html__( 'Sort by', 'firehouse' ),
			esc_attr( self::FORM ),
			$options . $template,
			Icons::svg( 'chevron-down', 16 )
		);
	}

	/**
	 * The results line, as the site's reads: "8 projects", or "Showing 2 of 8 projects
	 * matching “smoke”". Its parts are spans the view script shows, hides and rewrites, so
	 * the line itself, a live region, stays the same node.
	 *
	 * @param int    $shown    How many projects show.
	 * @param int    $total    How many a visitor may see.
	 * @param bool   $filtered Whether a search or a filter is applied.
	 * @param string $q        The text searched for, trimmed, or ''.
	 */
	private static function summary( int $shown, int $total, bool $filtered, string $q ): string {
		$rest = $filtered
			/* translators: %s: how many projects there are. */
			? sprintf( _n( 'of %s project', 'of %s projects', $total, 'firehouse' ), $total )
			: _n( 'project', 'projects', $shown, 'firehouse' );
		return sprintf(
			'<p class="fh-explorer__count" role="status"><span%1$s data-wp-bind--hidden="!state.filtered">%2$s </span><strong data-wp-text="state.shown">%3$d</strong> <span data-wp-text="state.countRest">%4$s</span><span%5$s data-wp-bind--hidden="!state.searching"> %6$s</span></p>',
			$filtered ? '' : ' hidden',
			esc_html__( 'Showing', 'firehouse' ),
			$shown,
			esc_html( $rest ),
			'' !== $q ? '' : ' hidden',
			/* translators: %s: the text searched for, in bold and in quotation marks. */
			sprintf( esc_html__( 'matching %s', 'firehouse' ), '<strong data-wp-text="state.quoted">“' . Markup::text( $q ) . '”</strong>' )
		);
	}

	/**
	 * The filters applied, by facet, each as its pill reads: "Phase: Before".
	 *
	 * @param array<string, mixed> $query The query.
	 * @return array<string, string>
	 */
	public static function applied( array $query ): array {
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
			/* translators: %s: a year. */
			$applied['year'] = sprintf( __( 'Year: %s', 'firehouse' ), $query['year'] );
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
	 * stays the title. A kicker with nothing in it is printed hidden, as the script's
	 * template draws it, so a tile the script keeps has the shape it would draw. The
	 * footer holds the phases and the quick look's button. Only the script can open the
	 * quick look, so without it the button is hidden, and so is a footer with no phases.
	 *
	 * @param array<string, mixed> $project   The project, with its address.
	 * @param bool                 $stand_in  Whether it stands in for the view script's list, which replaces it.
	 */
	public static function tile( array $project, bool $stand_in = false ): string {
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
			'<article class="fh-tile"%9$s><span class="fh-tile__glow" aria-hidden="true"></span><span class="fh-tile__body">%1$s<h3 class="fh-tile__title"><a class="fh-tile__link" href="%2$s">%3$s</a></h3><p class="fh-tile__summary">%4$s</p></span><span class="fh-tile__meta"><span class="fh-tile__byline">%5$s · %6$s</span>%7$s</span>%8$s</article>',
			'' !== $type ? '<span class="fh-tile__kicker">' . Markup::text( $type ) . '</span>' : '<span class="fh-tile__kicker" hidden></span>',
			esc_url( $text( 'url' ) ),
			Markup::text( $text( 'title' ) ),
			Markup::text( $text( 'summary' ) ),
			Markup::text( Format::byline( $authors ) ),
			esc_html( (string) Search::key( $project['completionYear'] ?? '' ) ),
			Icons::svg( 'arrow-up-right', 16 ),
			'<span class="fh-tile__foot"' . ( '' !== $phases ? '' : ' hidden' ) . ' data-wp-bind--hidden="!state.live"><span class="fh-tile__phases">' . $phases . '</span>' . self::peek( '<span>' . Markup::text( $text( 'title' ) ) . '</span>' ) . '</span>',
			$stand_in ? sprintf( ' data-wp-each-child="%s"', esc_attr( self::STORE . '::state.results' ) ) : ''
		);
	}

	/**
	 * The template the view script draws each tile from: tile()'s markup, its words bound
	 * to the project. A kicker or a footer with nothing in it is hidden.
	 */
	public static function tile_template(): string {
		return '<template data-wp-each--project="state.results" data-wp-each-key="context.project.slug"><article class="fh-tile"><span class="fh-tile__glow" aria-hidden="true"></span><span class="fh-tile__body"><span class="fh-tile__kicker" data-wp-bind--hidden="!context.project.projectType" data-wp-text="context.project.projectType"></span><h3 class="fh-tile__title"><a class="fh-tile__link" data-wp-bind--href="context.project.url" data-wp-text="context.project.title"></a></h3><p class="fh-tile__summary" data-wp-text="context.project.summary"></p></span><span class="fh-tile__meta"><span class="fh-tile__byline" data-wp-text="context.project.meta"></span>'
			. Icons::svg( 'arrow-up-right', 16 )
			. '</span><span class="fh-tile__foot" data-wp-bind--hidden="!state.live"><span class="fh-tile__phases"><template data-wp-each--phase="context.project.phases" data-wp-each-key="context.phase.key"><span class="fh-tile__phase" data-wp-text="context.phase.label"></span></template></span>'
			. self::peek( '<span data-wp-text="context.project.title"></span>' )
			. '</span></article></template>';
	}

	/**
	 * A tile's quick look button, as the site's `ProjectTile` has it: named for its
	 * project ("Quick look at …"), so twelve of them aren't twelve of the same. Hidden
	 * without the script, which alone can open the dialog.
	 *
	 * @param string $title Markup that names the project: its escaped title in a span, or a span bound to it.
	 */
	private static function peek( string $title ): string {
		return sprintf(
			'<button type="button" class="fh-tile__peek" hidden data-wp-bind--hidden="!state.live" data-wp-on--click="actions.peek">%1$s%2$s <span class="fh-visually-hidden">%3$s</span></button>',
			Icons::svg( 'eye', 14 ),
			esc_html__( 'Quick look', 'firehouse' ),
			/* translators: %s: a project's title. */
			sprintf( esc_html__( 'at %s', 'firehouse' ), $title )
		);
	}

	/**
	 * The quick look, as the site's `ProjectDetailModal`: a preview of one project,
	 * opened from its tile, that answers "is this the project I want?" without leaving
	 * the list. It shows the abstract and the first three end-user needs, as the
	 * researcher wrote them, counts what else the project's page holds, and leads to it.
	 *
	 * A native dialog, which the script opens with showModal(): the page behind it is
	 * inert, Escape closes it, and focus goes to its close button (`autofocus`, as the
	 * site focuses its own), then back to the button that opened it. It takes no tab
	 * stop of its own (`tabindex="-1"`): a panel taller than the window makes it scroll,
	 * and the browser would otherwise stop on it with its focus ring off the screen (the
	 * Section 508 review, 14 November). Its words are bound to the project being looked
	 * at; it holds none of a project's words until then, and without the script it never
	 * opens.
	 */
	private static function quick_look(): string {
		$section = static fn( string $bound, string $title, string $body ): string => sprintf(
			'<section class="fh-modal__section" data-wp-bind--hidden="!state.peek.%1$s"><h3 class="fh-modal__section-title">%2$s</h3>%3$s</section>',
			$bound,
			esc_html( $title ),
			$body
		);
		return '<dialog id="fh-quick-look" class="fh-modal__overlay" tabindex="-1" aria-labelledby="fh-quick-look-title" data-wp-on--pointerdown="actions.peekPress" data-wp-on--pointerup="actions.peekRelease" data-wp-on--click="actions.peekBackdrop" data-wp-on--close="actions.peekClosed">'
			. '<div class="fh-modal">'
			. '<header class="fh-modal__header"><div class="fh-modal__identity">'
			. '<span class="fh-eyebrow" data-wp-bind--hidden="!state.peek.kicker" data-wp-text="state.peek.kicker"></span>'
			. '<h2 id="fh-quick-look-title" class="fh-modal__title" data-wp-text="state.peek.title"></h2>'
			. '<span class="fh-modal__byline" data-wp-text="state.peek.byline"></span>'
			. '<span class="fh-modal__meta" data-wp-bind--hidden="!state.peek.hasMeta"><template data-wp-each--phase="state.peek.phases" data-wp-each-key="context.phase.key"><span class="fh-modal__meta-item" data-wp-text="context.phase.label"></span></template><span class="fh-modal__meta-item fh-modal__meta-item--status" data-wp-bind--hidden="!state.peek.status" data-wp-text="state.peek.status"></span></span>'
			. '</div>'
			. sprintf( '<button type="button" class="fh-modal__close" autofocus data-wp-on--click="actions.closePeek">%1$s<span class="fh-visually-hidden">%2$s</span></button>', Icons::svg( 'x', 20 ), esc_html__( 'Close project details', 'firehouse' ) )
			. '</header>'
			. $section( 'abstract', __( 'Abstract', 'firehouse' ), '<p class="fh-modal__prose fh-modal__prose--clamped" data-wp-text="state.peek.abstract"></p>' )
			. $section( 'needs.length', __( 'End-user needs', 'firehouse' ), '<ul class="fh-modal__list"><template data-wp-each--need="state.peek.needs" data-wp-each-key="context.need.key"><li data-wp-text="context.need.text"></li></template></ul><p class="fh-modal__more" data-wp-bind--hidden="!state.peek.more" data-wp-text="state.peek.more"></p>' )
			. '<p class="fh-modal__rest" data-wp-bind--hidden="!state.peek.rest" data-wp-text="state.peek.rest"></p>'
			. sprintf(
				'<footer class="fh-modal__footer"><a class="fh-btn fh-btn--accent fh-btn--md" data-wp-bind--href="state.peek.url">%1$s%2$s</a><span class="fh-modal__footer-note">%3$s</span></footer>',
				esc_html__( 'Open project page', 'firehouse' ),
				Icons::svg( 'arrow-right', 16 ),
				esc_html__( 'Full takeaways, recommendations and papers', 'firehouse' )
			)
			. '</div></dialog>';
	}

	/**
	 * The search box's text for a query's: a line break as a space. A one-line field
	 * drops a line break, which would send "smoke⏎fire" back as "smokefire"; a space
	 * keeps the words apart, and searches the same.
	 *
	 * @param string $q The text searched for.
	 */
	private static function box_text( string $q ): string {
		return strtr( $q, "\r\n", '  ' );
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
