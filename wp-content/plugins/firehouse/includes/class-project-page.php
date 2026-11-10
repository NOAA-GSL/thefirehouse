<?php
/**
 * A project's page.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

use Firehouse\Domain\Derive;
use Firehouse\Domain\Format;
use Firehouse\Domain\Vocabulary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A project's page: the site's `ProjectPage`, rendered by the `firehouse/project`
 * block. The title, authors and a few facts as pills; the abstract, takeaways, need
 * cards and papers; an "At a glance" panel beside them; and links to the projects
 * either side of it in the explorer's order.
 *
 * Everything the researcher wrote is printed as submitted, escaped by
 * Markup::text() and otherwise untouched (rule 1): nothing is summarised, tidied or
 * merged. A need's lead is bolded by splitting the text where the survey's pattern
 * ends (Format::need_lead()), not by rewriting it.
 *
 * A need card whose text runs past its height scrolls, and only then becomes a tab
 * stop with a name, as the site's does: the block's view script measures each card.
 * Until it has, the cap is off and the whole entry shows (assets/css/frame.css), so
 * without JavaScript, or if the script doesn't run, no text is out of a keyboard's
 * reach.
 *
 * Meta checks every write, but a value stored around it is passed over rather than
 * stopping the page: a field that isn't the type its schema gives is treated as
 * missing, and a key outside the vocabulary is left out.
 */
final class Project_Page {

	/**
	 * The page for a project post, from what WordPress stores.
	 *
	 * @param \WP_Post $post The project post.
	 */
	public static function for_post( \WP_Post $post ): string {
		$link                = static fn( ?array $project ): ?array => null === $project ? null : array(
			'title' => $project['title'],
			'url'   => (string) get_permalink( $project['id'] ),
		);
		[ $previous, $next ] = self::neighbours( $post->ID, self::order() );
		return self::render( Store::project( $post )['fields'], $link( $previous ), $link( $next ) );
	}

	/**
	 * The projects a visitor may see, in the explorer's order: newest first, then by
	 * title (Derive::by_recency()). Each one's post ID, title and year. Two queries
	 * whatever their number: the posts, then all their fields.
	 *
	 * A project is checked as the site checks it as well as by the query
	 * (Store::is_public()), and one whose title isn't text or whose year isn't a number
	 * has no place in the order and is left out.
	 *
	 * The posts come in import order, which is the site's JSON order, and usort() keeps
	 * ties where they are, so two projects the order can't tell apart keep the site's
	 * order too.
	 *
	 * @return list<array{id: int, title: string, completionYear: int|float}>
	 */
	public static function order(): array {
		$projects = array();
		$args     = array(
			'numberposts' => -1,
			'orderby'     => 'ID',
			'order'       => 'ASC',
		);
		foreach ( get_posts( Store::public_projects_query( $args ) ) as $post ) {
			$title = get_post_meta( $post->ID, Meta::key( 'title' ), true );
			$year  = Meta::typed( get_post_meta( $post->ID, Meta::key( 'completionYear' ), true ), 'number' );
			if ( Store::is_public( $post ) && is_string( $title ) && ( is_int( $year ) || is_float( $year ) ) ) {
				$projects[] = array(
					'id'             => $post->ID,
					'title'          => $title,
					'completionYear' => $year,
				);
			}
		}
		usort( $projects, array( Derive::class, 'by_recency' ) );
		return $projects;
	}

	/**
	 * The projects before and after one in an order, or null at either end. A project
	 * that isn't in it, as when someone who may edit a hidden project views it, has
	 * neither.
	 *
	 * @param int                        $id    The project's post ID.
	 * @param list<array<string, mixed>> $order The projects, from order().
	 * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
	 */
	public static function neighbours( int $id, array $order ): array {
		$index = array_search( $id, array_column( $order, 'id' ), true );
		if ( ! is_int( $index ) ) {
			return array( null, null );
		}
		return array( $order[ $index - 1 ] ?? null, $order[ $index + 1 ] ?? null );
	}

	/**
	 * The page's markup, inside the frame's main landmark.
	 *
	 * @param array<string, mixed>                   $project  The project's fields, as Store::project() reads them.
	 * @param array{title: string, url: string}|null $previous The project before it, if any.
	 * @param array{title: string, url: string}|null $next     The project after it, if any.
	 */
	public static function render( array $project, ?array $previous, ?array $next ): string {
		$text     = static fn( string $field ): string => is_string( $project[ $field ] ?? null ) ? $project[ $field ] : '';
		$geo      = is_array( $project['geo'] ?? null ) ? $project['geo'] : array();
		$root     = '/' . Query::ROOT_PATH . '/';
		$year     = self::number( $project['completionYear'] ?? null );
		$type     = $text( 'projectType' );
		$org      = $text( 'org' );
		$abstract = $text( 'abstract' );
		$record   = $text( 'fullRecordUrl' );
		$note     = is_string( $geo['note'] ?? null ) ? $geo['note'] : '';
		$methods  = self::strings( $project['methods'] ?? null );

		$phase_items  = Vocabulary::items( 'firePhases' );
		$phases       = array_values( array_filter( self::strings( $project['firePhases'] ?? null ), static fn( string $key ): bool => isset( $phase_items[ $key ] ) ) );
		$region_items = Vocabulary::items( 'regions' );
		$regions      = array_values( array_filter( self::strings( $geo['regions'] ?? null ), static fn( string $key ): bool => isset( $region_items[ $key ] ) ) );
		$status       = Vocabulary::items( 'publicationStatuses' )[ $text( 'publicationStatus' ) ] ?? null;
		/* translators: %s: the year the project was completed. */
		$completed = sprintf( __( 'Completed %s', 'firehouse' ), $year );

		$authors   = array_values( array_filter( is_array( $project['authors'] ?? null ) ? $project['authors'] : array(), static fn( mixed $author ): bool => is_array( $author ) && is_string( $author['name'] ?? null ) ) );
		$takeaways = self::strings( $project['takeaways'] ?? null );
		$needs     = array_values( array_filter( array_map( array( self::class, 'need_card' ), is_array( $project['needs'] ?? null ) ? $project['needs'] : array() ) ) );
		$papers    = array_values( array_filter( array_map( array( self::class, 'paper' ), is_array( $project['papers'] ?? null ) ? $project['papers'] : array() ) ) );

		ob_start();
		?>
<article class="fh-project">
	<header class="fh-project__head">
		<div class="fh-project__head-inner fh-container">
			<nav class="fh-project__crumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', 'firehouse' ); ?>"><a href="<?php echo esc_url( home_url( $root . 'projects/' ) ); ?>"><?php echo Icons::svg( 'arrow-left', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- one of the plugin's checked icons. ?><?php esc_html_e( 'All projects', 'firehouse' ); ?></a></nav>
		<?php if ( '' !== $type ) : ?>
			<span class="fh-eyebrow fh-project__type"><?php echo Markup::text( $type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup::text() escapes stored text. ?></span>
		<?php endif; ?>
			<h1 class="fh-project__title"><?php echo Markup::text( $text( 'title' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup::text() escapes stored text. ?></h1>
			<p class="fh-project__byline"><?php echo Markup::text( Format::byline( $authors, 0 ) ) . ( '' !== $org ? '<span class="fh-project__org">' . Markup::text( $org ) . '</span>' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup::text() escapes stored text. ?></p>
			<div class="fh-project__meta">
				<span class="fh-meta-pill"><?php echo Icons::svg( 'calendar', 12 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- one of the plugin's checked icons. ?><?php echo esc_html( $completed ); ?></span>
			<?php if ( array() !== $phases ) : ?>
				<span class="fh-meta-pill"><?php echo Icons::svg( 'flame', 12 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- one of the plugin's checked icons. ?><span class="fh-visually-hidden"><?php esc_html_e( 'Fire cycle phase: ', 'firehouse' ); ?></span><?php echo esc_html( implode( ' · ', array_map( static fn( string $key ): string => (string) $phase_items[ $key ]['short'], $phases ) ) ); ?></span>
			<?php endif; ?>
			<?php if ( null !== $status ) : ?>
				<span class="fh-meta-pill fh-meta-pill--<?php echo esc_attr( (string) $status['tone'] ); ?>"><?php echo esc_html( (string) $status['label'] ); ?></span>
			<?php endif; ?>
			</div>
		</div>
		<span class="fh-project__head-rule" aria-hidden="true"></span>
	</header>

	<div class="fh-project__layout fh-container">
		<div class="fh-project__main">
		<?php
		if ( '' !== $abstract ) {
			echo self::section( __( 'Abstract', 'firehouse' ), 0, '<p class="fh-project__prose">' . Markup::text( $abstract ) . '</p>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}
		if ( array() !== $takeaways ) {
			$items = '';
			foreach ( $takeaways as $index => $takeaway ) {
				$items .= sprintf( '<li><span class="fh-project__takeaway-num" aria-hidden="true">%1$02d</span><p>%2$s</p></li>', $index + 1, Markup::text( $takeaway ) );
			}
			echo self::section( __( 'Major takeaways', 'firehouse' ), count( $takeaways ), '<ol class="fh-project__takeaways">' . $items . '</ol>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above.
		}
		if ( array() !== $needs ) {
			echo self::section( __( 'End-user needs and recommendations', 'firehouse' ), count( $needs ), '<ol class="fh-entries" data-wp-interactive="firehouse/project">' . implode( '', $needs ) . '</ol>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above.
		}
		if ( array() !== $papers ) {
			echo self::section( __( 'Publications and resources', 'firehouse' ), count( $papers ), '<ul class="fh-project__papers"><li>' . implode( '</li><li>', $papers ) . '</li></ul>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above.
		}
		?>
		</div>

		<aside class="fh-project__aside" aria-label="<?php esc_attr_e( 'Project details', 'firehouse' ); ?>">
			<div class="fh-project__panel fh-glass fh-glass--raised">
				<h2 class="fh-project__panel-title"><?php esc_html_e( 'At a glance', 'firehouse' ); ?></h2>
				<dl class="fh-project__facts">
				<?php
				$phase_list = '';
				foreach ( $phases as $key ) {
					$phase_list .= sprintf( '<li><strong>%1$s</strong><span>%2$s</span></li>', esc_html( (string) $phase_items[ $key ]['short'] ), esc_html( (string) $phase_items[ $key ]['label'] ) );
				}
				$region_list = '';
				foreach ( $regions as $key ) {
					$region_list .= sprintf(
						'<span class="fh-project__region">%1$s%2$s</span>',
						esc_html( (string) $region_items[ $key ]['short'] ),
						empty( $region_items[ $key ]['mapped'] ) ? '' : sprintf( '<abbr title="%1$s">%2$s</abbr>', esc_attr( (string) $region_items[ $key ]['label'] ), esc_html( $key ) )
					);
				}
				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- fact() escapes its label; each value is built from escaped parts.
				echo '' !== $type ? self::fact( 'flask', __( 'Project type', 'firehouse' ), Markup::text( $type ) ) : '';
				echo self::fact( 'calendar', __( 'Completion year', 'firehouse' ), esc_html( $year ) );
				echo self::fact( 'flame', __( 'Fire cycle phase', 'firehouse' ), '' !== $phase_list ? '<ul class="fh-project__phases">' . $phase_list . '</ul>' : esc_html__( 'Not specified', 'firehouse' ) );
				echo array() !== $methods ? self::fact( 'clipboard', __( 'Data collection', 'firehouse' ), Markup::text( implode( ', ', $methods ) ) ) : '';
				echo '' !== $region_list ? self::fact( 'map-pin', __( 'Geographic areas', 'firehouse' ), '<span class="fh-project__regions">' . $region_list . '</span>' . ( '' !== $note ? '<span class="fh-project__region-note">' . Markup::text( $note ) . '</span>' : '' ) ) : '';
				echo '' !== $org ? self::fact( 'building', __( 'Organization', 'firehouse' ), Markup::text( $org ) ) : '';
				echo null !== $status ? self::fact( 'file-text', __( 'Publication status', 'firehouse' ), esc_html( (string) $status['label'] ) ) : '';
				// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
				</dl>
			<?php if ( '' !== Markup::url( $record ) ) : ?>
				<?php echo Markup::button( __( 'View full record', 'firehouse' ), $record, 'secondary', 'sm', array( 'external-link' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup escapes what it builds. ?>
			<?php endif; ?>
			</div>

			<?php
			// Says out loud what the page does, so a reader knows the text above is the
			// researcher's own words and not an editorial summary.
			printf(
				'<p class="fh-project__verbatim">%1$s<span>%2$s</span></p>',
				Icons::svg( 'quote', 14 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- one of the plugin's checked icons.
				sprintf(
					/* translators: %s: a link whose words are "topic areas". */
					esc_html__( 'Takeaways, needs and recommendations on this page are reproduced as the researcher submitted them. The %s are an AI-assisted synthesis across all projects, so no single project is filed under one.', 'firehouse' ),
					'<a href="' . esc_url( home_url( $root . '#top-needs' ) ) . '">' . esc_html__( 'topic areas', 'firehouse' ) . '</a>'
				) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the translated words escaped, and an escaped link.
			);
			?>
		</aside>
	</div>

		<?php if ( null !== $previous || null !== $next ) : ?>
	<nav class="fh-project__pager fh-container" aria-label="<?php esc_attr_e( 'Other projects', 'firehouse' ); ?>"><?php echo ( null !== $previous ? self::pager_link( $previous, false ) : '<span></span>' ) . ( null !== $next ? self::pager_link( $next, true ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pager_link() escapes what it builds. ?></nav>
	<?php endif; ?>
</article>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * One need card: a need and the recommendation the researcher paired with it, as the
	 * survey collected them, or '' for an entry with neither. Either may stand alone.
	 *
	 * The card starts unmeasured, with no `data-overflow`, so its cap is off: no tab
	 * stop, no role, no name. The view script measures it and binds which edges have
	 * more to scroll to (`none` when it fits), and, if its text runs past its height,
	 * `tabindex`, the `group` role and a name from the need's lead.
	 *
	 * @param mixed $entry The stored entry.
	 */
	public static function need_card( mixed $entry ): string {
		$entry = is_array( $entry ) ? $entry : array();
		$need  = is_string( $entry['need'] ?? null ) ? $entry['need'] : '';
		$rec   = is_string( $entry['recommendation'] ?? null ) ? $entry['recommendation'] : '';
		if ( '' === $need && '' === $rec ) {
			return '';
		}
		$split = '' !== $need ? Format::need_lead( $need ) : array( 'rest' => '' );
		$lead  = $split['lead'] ?? '';
		$label = '' !== $lead
			/* translators: %s: the need's opening words, such as "Need for hotspot alerts among land managers". */
			? sprintf( __( '%s — scrollable', 'firehouse' ), str_ends_with( $lead, ':' ) ? substr( $lead, 0, -1 ) : $lead )
			: __( 'Need and recommendation — scrollable', 'firehouse' );

		return sprintf(
			'<li class="fh-entry"><div class="fh-entry__body" %1$s data-wp-init="callbacks.measure" data-wp-bind--data-overflow="context.overflow" data-wp-bind--tabindex="state.tabIndex" data-wp-bind--role="state.role" data-wp-bind--aria-label="state.label">%2$s%3$s</div></li>',
			wp_interactivity_data_wp_context(
				array(
					'overflow' => null,
					'label'    => $label,
				)
			),
			'' === $need ? '' : sprintf(
				'<div class="fh-entry__need"><span class="fh-entry__label">%1$s%2$s</span><p class="fh-entry__text">%3$s %4$s</p></div>',
				Icons::svg( 'target', 13 ),
				esc_html__( 'Need', 'firehouse' ),
				'' !== $lead ? '<strong class="fh-entry__lead">' . Markup::text( $lead ) . '</strong>' : '',
				Markup::text( $split['rest'] )
			),
			'' === $rec ? '' : sprintf(
				'<div class="fh-entry__rec"><span class="fh-entry__label">%1$s%2$s</span><p class="fh-entry__text">%3$s</p></div>',
				Icons::svg( 'arrow-right', 13 ),
				esc_html__( 'Recommendation', 'firehouse' ),
				Markup::text( $rec )
			)
		);
	}

	/**
	 * One publication or resource, linked: its DOI's address, or its own, and '' for
	 * one with neither that a browser could follow.
	 *
	 * The survey collects links, not titles, so one with no published title of its own
	 * is named by its DOI, or its host and path, never by an invented name.
	 *
	 * A DOI may hold a `#` (a SICI DOI's check character can be one) or a `?`, which in
	 * an address would start a fragment or a query, or a `\`, which a browser would
	 * read as `/`; each is percent-encoded. A `%` is left as it is, since a DOI copied
	 * from a doi.org link is stored percent-encoded already.
	 *
	 * @param mixed $paper The stored paper.
	 */
	public static function paper( mixed $paper ): string {
		$paper = is_array( $paper ) ? $paper : array();
		$doi   = is_string( $paper['doi'] ?? null ) ? $paper['doi'] : '';
		$link  = is_string( $paper['url'] ?? null ) ? $paper['url'] : '';
		$url   = Markup::url( '' !== $doi ? 'https://doi.org/' . str_replace( array( '#', '?', '\\' ), array( '%23', '%3F', '%5C' ), $doi ) : $link );
		if ( '' === $url ) {
			return '';
		}
		$title     = is_string( $paper['title'] ?? null ) ? $paper['title'] : null;
		$container = is_string( $paper['container'] ?? null ) ? $paper['container'] : '';
		$year      = self::number( $paper['year'] ?? null );
		$source    = implode( ' · ', array_filter( array( $container, '0' === $year ? '' : $year ), static fn( string $part ): bool => '' !== $part ) );

		return sprintf(
			'<a class="fh-project__paper" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span><span class="fh-project__paper-title">%3$s</span>%4$s%5$s</span>%6$s<span class="fh-visually-hidden">%7$s</span></a>',
			$url,
			Icons::svg( 'file-text', 16 ),
			Markup::text( $title ?? ( '' !== $doi ? 'doi.org/' . $doi : Format::link_label( $link ) ) ),
			'' !== $source ? '<span class="fh-project__paper-source">' . Markup::text( $source ) . '</span>' : '',
			'' !== $doi
				? '<span class="fh-project__doi">doi:' . Markup::text( $doi ) . '</span>'
				: ( null !== $title && '' !== $title ? '<span class="fh-project__doi">' . Markup::text( Format::link_label( $link ) ) . '</span>' : '' ),
			Icons::svg( 'external-link', 14 ),
			esc_html__( '(opens in a new tab)', 'firehouse' )
		);
	}

	/**
	 * One titled block of the main column, with how many items it holds when it holds
	 * a list.
	 *
	 * @param string $title The heading.
	 * @param int    $count How many items, or 0 for none shown.
	 * @param string $body  Its markup, escaped.
	 */
	private static function section( string $title, int $count, string $body ): string {
		return sprintf(
			'<section class="fh-project__section"><h2 class="fh-project__section-title">%1$s%2$s</h2>%3$s</section>',
			esc_html( $title ),
			$count > 0 ? sprintf( '<span class="fh-project__section-count" aria-hidden="true">%d</span>', $count ) : '',
			$body
		);
	}

	/**
	 * A labelled row of the "At a glance" panel.
	 *
	 * @param string $icon  The icon's name.
	 * @param string $label The label.
	 * @param string $value Its markup, escaped.
	 */
	private static function fact( string $icon, string $label, string $value ): string {
		return sprintf(
			'<div class="fh-project__fact"><dt class="fh-project__fact-label">%1$s%2$s</dt><dd class="fh-project__fact-value">%3$s</dd></div>',
			Icons::svg( $icon, 14 ),
			esc_html( $label ),
			$value
		);
	}

	/**
	 * A link to the project before or after this one.
	 *
	 * @param array{title: string, url: string} $project The project.
	 * @param bool                              $next    Whether it comes after.
	 */
	private static function pager_link( array $project, bool $next ): string {
		return sprintf(
			'<a class="fh-project__pager-link%1$s" href="%2$s"><span class="fh-project__pager-dir">%3$s</span><span class="fh-project__pager-title">%4$s</span></a>',
			$next ? ' fh-project__pager-link--next' : '',
			esc_url( $project['url'] ),
			$next
				? esc_html__( 'Next', 'firehouse' ) . Icons::svg( 'chevron-right', 14 )
				: Icons::svg( 'chevron-left', 14 ) . esc_html__( 'Previous', 'firehouse' ),
			Markup::text( $project['title'] )
		);
	}

	/**
	 * The strings in a stored list, in order; anything else in it is passed over.
	 *
	 * @param mixed $stored The stored list.
	 * @return list<string>
	 */
	private static function strings( mixed $stored ): array {
		return array_values( array_filter( is_array( $stored ) ? $stored : array(), 'is_string' ) );
	}

	/**
	 * A stored number as the site prints a year or a count, or '' for anything else.
	 *
	 * @param mixed $value The stored value.
	 */
	private static function number( mixed $value ): string {
		return is_int( $value ) || is_float( $value ) ? (string) $value : '';
	}
}
