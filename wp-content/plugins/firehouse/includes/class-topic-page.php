<?php
/**
 * A topic area's page.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

use Firehouse\Domain\Derive;
use Firehouse\Domain\Vocabulary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A topic area's page: the site's `TopicPage`, rendered by the `firehouse/topic`
 * block. The area's top needs come first, each traced to the projects it was drawn
 * from that a visitor may see, with what the area covers beside them and the other
 * three areas below.
 *
 * The needs are the synthesis, the one place the site paraphrases researchers, so the
 * synthesis note says so and says whether the pass has been reviewed. The project
 * titles under each need are the researchers' own, escaped and otherwise as written
 * (rule 1).
 */
final class Topic_Page {

	/**
	 * How many needs the page shows.
	 */
	public const NEEDS = 5;

	/**
	 * The page for a topic post, from what WordPress stores.
	 *
	 * @param \WP_Post $post The topic post.
	 */
	public static function for_post( \WP_Post $post ): string {
		$topic = Store::topic( $post );
		$needs = self::top( $topic['fields']['topNeeds'] ?? null );

		$others = array();
		foreach ( Store::topics() as $other ) {
			if ( $other['post_id'] !== $post->ID && 'publish' === $other['status'] ) {
				$others[] = array( 'key' => $other['key'] ) + $other['fields'];
			}
		}
		usort( $others, static fn( array $a, array $b ): int => self::order( $a ) <=> self::order( $b ) );

		return self::render(
			array( 'key' => $topic['key'] ) + $topic['fields'],
			self::needs( $needs, self::projects( $needs ) ),
			$others,
			Store::public_project_count(),
			Settings::get()
		);
	}

	/**
	 * The needs the page shows: the first five that are needs, with text to show.
	 * Meta checks every write, but a value stored around it is passed over rather than
	 * stopping the page or taking a place from a need after it.
	 *
	 * @param mixed $needs The topic's stored needs.
	 * @return list<array<string, mixed>>
	 */
	public static function top( mixed $needs ): array {
		return array_slice( array_values( array_filter( is_array( $needs ) ? $needs : array(), array( self::class, 'is_need' ) ) ), 0, self::NEEDS );
	}

	/**
	 * The projects a visitor may see that the needs name, by post ID: each one's fields
	 * and its address. Two queries whatever their number: the posts, then all their
	 * fields.
	 *
	 * A project that is unpublished, password-protected or without IRB approval is
	 * left out (Store::public_projects_query()).
	 *
	 * @param list<array<string, mixed>> $needs The needs, a need's projects as post IDs.
	 * @return array<int, array<string, mixed>>
	 */
	public static function projects( array $needs ): array {
		$ids = array();
		foreach ( $needs as $need ) {
			foreach ( is_array( $need ) && is_array( $need['projects'] ?? null ) ? $need['projects'] : array() as $id ) {
				if ( is_int( $id ) && $id > 0 ) {
					$ids[ $id ] = $id;
				}
			}
		}
		if ( array() === $ids ) {
			return array();
		}

		$projects = array();
		$posts    = get_posts(
			Store::public_projects_query(
				array(
					'post__in'    => array_values( $ids ),
					'numberposts' => -1,
				)
			)
		);
		foreach ( $posts as $post ) {
			$fields = Store::project( $post )['fields'];
			// The site's own test, as well as the query's: a value written around Meta,
			// or a second row, mustn't pass one and fail the other.
			if ( true === ( $fields['irbApproved'] ?? null ) ) {
				$projects[ $post->ID ] = array( 'url' => (string) get_permalink( $post ) ) + $fields;
			}
		}
		return $projects;
	}

	/**
	 * Each need as the page shows it: its text, its project count, and the projects
	 * behind it a visitor may see, in the synthesis's order.
	 *
	 * A project a visitor may not see is left out, as the site's normalize step leaves
	 * it out, and then the stored count no longer holds: the count is the projects that
	 * remain. A need that names no projects keeps its stored count.
	 *
	 * @param list<array<string, mixed>>       $needs    The needs, a need's projects as post IDs.
	 * @param array<int, array<string, mixed>> $projects The projects a visitor may see, by post ID.
	 * @return list<array{text: string, mentions: int, sources: list<array<string, mixed>>}>
	 */
	public static function needs( array $needs, array $projects ): array {
		$shown = array();
		foreach ( array_filter( $needs, array( self::class, 'is_need' ) ) as $need ) {
			$listed  = is_array( $need['projects'] ?? null ) ? $need['projects'] : array();
			$sources = array();
			foreach ( $listed as $id ) {
				if ( ( is_int( $id ) || is_string( $id ) ) && isset( $projects[ $id ] ) ) {
					$sources[] = $projects[ $id ];
				}
			}
			$counted = array( 'projects' => $sources );
			if ( count( $sources ) === count( $listed ) && array_key_exists( 'mentions', $need ) ) {
				$counted['mentions'] = $need['mentions'];
			}
			$shown[] = array(
				'text'     => $need['text'],
				'mentions' => Derive::need_mentions( $counted ),
				'sources'  => $sources,
			);
		}
		return $shown;
	}

	/**
	 * Whether a stored need is one the page can show: an array with text that isn't
	 * blank.
	 *
	 * @param mixed $need The stored need.
	 */
	private static function is_need( mixed $need ): bool {
		return is_array( $need ) && is_string( $need['text'] ?? null ) && '' !== trim( $need['text'] );
	}

	/**
	 * The page's markup, inside the frame's main landmark.
	 *
	 * @param array<string, mixed>       $topic     The topic: its key, label, short label, covers and synthesis fields.
	 * @param list<array<string, mixed>> $needs     The needs, from needs().
	 * @param list<array<string, mixed>> $others    The other topics, in order: each one's key, label and short label.
	 * @param int                        $published How many projects a visitor may see.
	 * @param array<string, mixed>       $settings  The Firehouse settings.
	 */
	public static function render( array $topic, array $needs, array $others, int $published, array $settings ): string {
		$key    = self::key( $topic['key'] ?? '' );
		$short  = is_string( $topic['short'] ?? null ) ? $topic['short'] : '';
		$covers = array_values( array_filter( is_array( $topic['covers'] ?? null ) ? $topic['covers'] : array(), 'is_string' ) );
		$root   = '/' . Query::ROOT_PATH . '/';
		$url    = is_string( $settings['submitFormUrl'] ?? null ) ? $settings['submitFormUrl'] : '';
		$sync   = static fn( string $field ): ?string => is_string( $topic[ $field ] ?? null ) ? $topic[ $field ] : null;
		$count  = is_numeric( $topic['sourceCount'] ?? null ) ? (int) $topic['sourceCount'] : $published;
		$style  = implode(
			'; ',
			array_map(
				static fn( string $part ): string => sprintf( '--topic-%1$s: var(--topic-%2$s-%1$s)', $part, $key ),
				array( 'text', 'tint', 'border', 'fill' )
			)
		);

		ob_start();
		?>
<div class="fh-topic" style="<?php echo esc_attr( $style ); ?>">
	<section class="fh-topic__hero">
		<div class="fh-topic__hero-inner fh-container">
			<nav class="fh-crumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', 'firehouse' ); ?>">
				<a href="<?php echo esc_url( home_url( $root ) ); ?>"><?php esc_html_e( 'Home', 'firehouse' ); ?></a>
				<?php echo Icons::svg( 'chevron-right', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- one of the plugin's checked icons. ?>
				<a href="<?php echo esc_url( home_url( $root . '#top-needs' ) ); ?>"><?php esc_html_e( 'Top needs', 'firehouse' ); ?></a>
				<?php echo Icons::svg( 'chevron-right', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above. ?>
				<span aria-current="page"><?php echo esc_html( $short ); ?></span>
			</nav>
			<?php echo Markup::topic_tag( $key, 'md', $short ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup escapes what it builds. ?>
			<h1 class="fh-topic__heading"><?php echo esc_html( is_string( $topic['label'] ?? null ) ? $topic['label'] : '' ); ?></h1>
		</div>
	</section>

	<div class="fh-topic__body fh-container">
		<section class="fh-topic__needs" aria-labelledby="needs-heading">
			<div class="fh-topic__needs-head">
				<h2 id="needs-heading" class="fh-topic__subheading">
				<?php
				echo array() === $needs
					? esc_html__( 'Top needs', 'firehouse' )
					/* translators: %d: how many needs the page shows. */
					: esc_html( sprintf( _n( 'Top %d need', 'Top %d needs', count( $needs ), 'firehouse' ), count( $needs ) ) );
				?>
				</h2>
				<?php echo Markup::synthesis_note( $sync( 'model' ), $sync( 'reviewedBy' ), $sync( 'updatedAt' ), $count, $published ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup escapes what it builds. ?>
				<p class="fh-topic__how"><?php esc_html_e( 'Project count: the number of projects that raise each need.', 'firehouse' ); ?> <a href="<?php echo esc_url( home_url( $root . '#needs-source' ) ); ?>"><?php esc_html_e( 'How these needs are synthesized', 'firehouse' ); ?></a></p>
			</div>

			<?php if ( array() !== $needs ) : ?>
			<ol class="fh-topic__needs-list">
				<?php foreach ( $needs as $index => $need ) : ?>
				<li class="fh-need">
					<span class="fh-need__rank" aria-hidden="true"><?php echo (int) $index + 1; ?></span>
					<div class="fh-need__main">
						<p class="fh-need__text"><?php echo esc_html( $need['text'] ); ?></p>
						<?php if ( $need['mentions'] > 0 ) : ?>
							<?php /* translators: %d: how many projects raise the need. */ ?>
						<p class="fh-need__meta"><?php echo esc_html( sprintf( __( 'Project count: %d', 'firehouse' ), $need['mentions'] ) ); ?></p>
						<?php endif; ?>
						<?php if ( array() !== $need['sources'] ) : ?>
							<?php /* translators: %d: the need's rank on the page. */ ?>
						<ul class="fh-need__sources" aria-label="<?php echo esc_attr( sprintf( __( 'Projects behind need %d', 'firehouse' ), $index + 1 ) ); ?>">
							<?php foreach ( $need['sources'] as $project ) : ?>
							<li><a class="fh-need__source" href="<?php echo esc_url( is_string( $project['url'] ?? null ) ? $project['url'] : '' ); ?>"><?php echo Icons::svg( 'file-text', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- one of the plugin's checked icons. ?><span class="fh-need__source-title"><?php echo esc_html( is_string( $project['title'] ?? null ) ? $project['title'] : '' ); ?></span><span class="fh-need__source-year"><?php echo esc_html( is_numeric( $project['completionYear'] ?? null ) ? (string) (int) $project['completionYear'] : '' ); ?></span></a></li>
							<?php endforeach; ?>
						</ul>
						<?php endif; ?>
					</div>
				</li>
				<?php endforeach; ?>
			</ol>
			<?php else : ?>
			<p class="fh-topic__empty"><?php esc_html_e( 'No needs have been published for this area yet.', 'firehouse' ); ?></p>
			<?php endif; ?>
		</section>

		<?php if ( array() !== $covers || '' !== $url ) : ?>
		<aside class="fh-topic__aside"<?php echo array() !== $covers ? ' aria-labelledby="covers-heading"' : ''; ?>>
			<?php if ( array() !== $covers ) : ?>
			<div class="fh-topic__covers-panel">
				<h2 id="covers-heading" class="fh-topic__covers-heading"><?php esc_html_e( 'What this area covers', 'firehouse' ); ?></h2>
				<ul class="fh-topic__covers">
					<?php foreach ( $covers as $item ) : ?>
					<li><?php echo esc_html( $item ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>
			<?php if ( '' !== $url ) : ?>
			<div class="fh-topic__contribute">
				<p><?php esc_html_e( 'Have a finding that speaks to this area?', 'firehouse' ); ?></p>
				<?php echo Markup::button( (string) $settings['submitLabel'], $url, 'accent', 'sm', array( 'plus' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup escapes what it builds. ?>
			</div>
			<?php endif; ?>
		</aside>
		<?php endif; ?>
	</div>

		<?php if ( array() !== $others ) : ?>
	<nav class="fh-topic__more fh-container" aria-labelledby="more-heading">
		<h2 id="more-heading" class="fh-topic__subheading"><?php esc_html_e( 'Other topic areas', 'firehouse' ); ?></h2>
		<ul class="fh-topic__more-list">
			<?php foreach ( $others as $other ) : ?>
				<?php $other_key = self::key( $other['key'] ?? '' ); ?>
			<li><a class="fh-topic__more-link" href="<?php echo esc_url( home_url( $root . 'topics/' . $other_key . '/' ) ); ?>"><?php echo Markup::topic_tag( $other_key, 'sm', is_string( $other['short'] ?? null ) ? $other['short'] : null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup escapes what it builds. ?><span class="fh-topic__more-row"><span class="fh-topic__more-label"><?php echo esc_html( is_string( $other['label'] ?? null ) ? $other['label'] : '' ); ?></span><?php echo Icons::svg( 'arrow-right', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- one of the plugin's checked icons. ?></span></a></li>
			<?php endforeach; ?>
		</ul>
	</nav>
		<?php endif; ?>
</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * A topic's place in the order, or 0 if it has none that's a number.
	 *
	 * @param array<string, mixed> $topic The topic's fields.
	 */
	private static function order( array $topic ): int|float {
		return is_int( $topic['order'] ?? null ) || is_float( $topic['order'] ?? null ) ? $topic['order'] : 0;
	}

	/**
	 * A topic key from the vocabulary: the key, or the first area's if it isn't one,
	 * as the site's TopicTag falls back. The key names CSS variables and an address.
	 *
	 * @param mixed $key The key.
	 */
	private static function key( mixed $key ): string {
		$keys = Vocabulary::topics();
		return is_string( $key ) && in_array( $key, $keys, true ) ? $key : $keys[0];
	}
}
