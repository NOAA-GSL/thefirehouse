<?php
/**
 * The landing page's blocks.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

use Firehouse\Domain\Derive;
use Firehouse\Domain\Js;
use Firehouse\Domain\Vocabulary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The landing page: the site's `LandingPage`, as blocks in the `firehouse` page. The
 * words are the blocks' attributes, which the Import screen writes from the seed
 * bundle's landing.json when the page doesn't exist yet; the numbers and the needs are
 * computed when the page renders, from the projects a visitor may see and the topics'
 * syntheses.
 *
 * - `firehouse/hero`: the hero, with `firehouse/stats`, the live counts, inside it.
 * - `firehouse/top-needs`: the four topic cards, the synthesis note, and "Where these
 *   needs come from".
 * - `firehouse/submit-band`: the closing band with the Submit button.
 *
 * The coverage map comes with the explorer in Phase 5.
 *
 * Its links are stored as Page_Blocks keeps them: one to the survey address follows the
 * Submit a Finding address in the settings, and with none set, its button is left out
 * rather than pointing nowhere.
 */
final class Landing_Page {

	/**
	 * What the counts and cards are computed from, once per request.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $content = null;

	/**
	 * The page's blocks, from the bundle's landing content, as the Import screen saves
	 * them. A link to the bundle's survey address is stored empty, so it follows the
	 * settings. Only text reaches the page: a link's label and where it goes, what the
	 * site's `Stat` has (its accent as true or false), and each section's words. A key
	 * the site doesn't have, or a value that isn't text, is left out.
	 *
	 * @param array<string, mixed> $landing    The bundle's landing content, normalized.
	 * @param string               $submit_url The bundle's survey address.
	 */
	public static function blocks( array $landing, string $submit_url ): string {
		$hero  = is_array( $landing['hero'] ?? null ) ? $landing['hero'] : array();
		$cta   = static fn( mixed $link ): array => Page_Blocks::link_fields( $link, $submit_url );
		$stats = array();
		foreach ( is_array( $landing['stats'] ?? null ) ? $landing['stats'] : array() as $stat ) {
			$stats[] = Page_Blocks::text_fields( $stat, array( 'id', 'source', 'value', 'label', 'caption' ) )
				+ ( is_array( $stat ) && isset( $stat['accent'] ) ? array( 'accent' => Js::truthy( $stat['accent'] ) ) : array() );
		}
		$words = static fn( mixed $section ): array => Page_Blocks::text_fields( $section, array( 'eyebrow', 'heading', 'body' ) ) + array(
			'eyebrow' => '',
			'heading' => '',
			'body'    => '',
		);
		return serialize_blocks(
			array(
				Page_Blocks::block(
					'firehouse/hero',
					$words( $hero ) + array(
						'primaryCta'   => $cta( $hero['primaryCta'] ?? null ),
						'secondaryCta' => $cta( $hero['secondaryCta'] ?? null ),
					),
					array( Page_Blocks::block( 'firehouse/stats', array( 'items' => $stats ) ) )
				),
				Page_Blocks::block(
					'firehouse/top-needs',
					Page_Blocks::text_fields( $landing['topicSection'] ?? null, array( 'eyebrow', 'heading', 'body' ) )
						+ array( 'needsPerCard' => is_int( $landing['needsPerCard'] ?? null ) ? $landing['needsPerCard'] : Topic_Page::NEEDS )
				),
				Page_Blocks::block(
					'firehouse/submit-band',
					array_intersect_key( $words( $landing['submitBand'] ?? null ), array_flip( array( 'heading', 'body' ) ) )
						+ array( 'cta' => $cta( $landing['submitBand']['cta'] ?? null ) )
				),
			)
		);
	}

	/**
	 * The hero: the wordmark, the eyebrow, the heading and body, the two buttons, and
	 * the counts beside them.
	 *
	 * @param array<string, mixed> $attributes The block's attributes.
	 * @param string               $stats      The counts, the inner block's markup.
	 */
	public static function hero( array $attributes, string $stats ): string {
		$text    = static fn( string $name ): string => is_string( $attributes[ $name ] ?? null ) ? $attributes[ $name ] : '';
		$buttons = Page_Blocks::button( $attributes['primaryCta'] ?? null, 'accent', 'lg', array( 'plus' ), array( 'block-mobile' ) )
			. Page_Blocks::button( $attributes['secondaryCta'] ?? null, 'secondary', 'lg', array( 1 => 'arrow-right' ), array( 'on-dark', 'block-mobile' ) );
		return sprintf(
			'<section class="fh-hero"><div class="fh-hero__media" aria-hidden="true"><div class="fh-hero__smoke"></div><div class="fh-hero__scrim"></div></div><div class="fh-hero__content fh-container"><div class="fh-hero__grid"><div class="fh-hero__panel"><img class="fh-brand fh-brand--dark fh-hero__logo" src="%1$s" alt="%2$s" style="height:72px"><span class="fh-hero__eyebrow">%3$s</span><h1 class="fh-hero__heading">%4$s</h1><p class="fh-hero__body">%5$s</p><div class="fh-hero__actions">%6$s</div></div>%7$s</div></div><span class="fh-hero__horizon" aria-hidden="true"></span></section>',
			esc_url( plugins_url( 'assets/logos/firehouse-wordmark-on-dark.png', dirname( __DIR__ ) . '/firehouse.php' ) ),
			esc_attr__( 'FireHouse — Fire Weather Research Hub', 'firehouse' ),
			Markup::text( $text( 'eyebrow' ) ),
			Markup::text( $text( 'heading' ) ),
			Markup::text( $text( 'body' ) ),
			$buttons,
			$stats
		);
	}

	/**
	 * The live counts: each stat's value, computed as the site computes it
	 * (Derive::stat_value()), its label and caption.
	 *
	 * @param array<string, mixed> $attributes The block's attributes: its `items`.
	 */
	public static function stats( array $attributes ): string {
		$items = '';
		foreach ( is_array( $attributes['items'] ?? null ) ? $attributes['items'] : array() as $stat ) {
			if ( ! is_array( $stat ) ) {
				continue;
			}
			$label   = is_string( $stat['label'] ?? null ) ? $stat['label'] : '';
			$caption = is_string( $stat['caption'] ?? null ) ? $stat['caption'] : '';
			$items  .= sprintf(
				'<li class="fh-stat-pane"><div class="fh-stat"><span class="fh-stat__value%1$s">%2$s</span><span class="fh-stat__label">%3$s</span>%4$s</div></li>',
				Js::truthy( $stat['accent'] ?? null ) ? ' fh-stat__value--accent' : '',
				Markup::text( Derive::stat_value( $stat, self::content() ) ),
				Markup::text( $label ),
				'' !== $caption ? '<span class="fh-stat__caption">' . Markup::text( $caption ) . '</span>' : ''
			);
		}
		return sprintf( '<ul class="fh-hero__stats" aria-label="%1$s">%2$s</ul>', esc_attr__( 'Hub statistics', 'firehouse' ), $items );
	}

	/**
	 * The top needs: the section's words, the synthesis note, a card for each published
	 * topic area with its first needs, and where the needs come from.
	 *
	 * @param array<string, mixed> $attributes The block's attributes.
	 */
	public static function top_needs( array $attributes ): string {
		$text     = static fn( string $name ): string => is_string( $attributes[ $name ] ?? null ) ? $attributes[ $name ] : '';
		$per_card = is_int( $attributes['needsPerCard'] ?? null ) && $attributes['needsPerCard'] >= 0 ? $attributes['needsPerCard'] : Topic_Page::NEEDS;
		$content  = self::content();
		$info     = Derive::synthesis_info( $content );
		$model    = is_string( $info['model'] ?? null ) ? $info['model'] : null;
		$root     = '/' . Query::ROOT_PATH . '/';

		$cards = '';
		foreach ( self::cards( $content['topics'], $per_card, array_fill_keys( array_column( $content['projects'], 'post_id' ), array() ) ) as $card ) {
			$needs = '';
			foreach ( $card['needs'] as $index => $need ) {
				$needs .= sprintf(
					'<li class="fh-needs__item"><span class="fh-needs__rank" aria-hidden="true">%1$d</span><span class="fh-needs__text">%2$s</span>%3$s</li>',
					$index + 1,
					Markup::text( $need['text'] ),
					/* translators: %d: how many projects raise the need. */
					$need['mentions'] > 0 ? '<span class="fh-needs__count"><span class="fh-visually-hidden">. </span>' . esc_html( sprintf( __( 'Project count: %d', 'firehouse' ), $need['mentions'] ) ) . '</span>' : ''
				);
			}
			$cards .= sprintf(
				'<article class="fh-topic-card fh-glass" style="--card-tint: var(--topic-%1$s-tint)"><span class="fh-topic-card__wash" aria-hidden="true"></span>%2$s<h3 class="fh-topic-card__title"><a class="fh-topic-card__link" href="%3$s">%4$s</a></h3>%5$s<div class="fh-topic-card__foot"><span class="fh-topic-card__cue" aria-hidden="true">%6$s%7$s</span></div></article>',
				esc_attr( $card['key'] ),
				Markup::topic_tag( $card['key'], 'md', $card['short'] ),
				esc_url( home_url( $root . 'topics/' . $card['key'] . '/' ) ),
				Markup::text( $card['label'] ),
				'' !== $needs ? '<ol class="fh-needs">' . $needs . '</ol>' : '<p class="fh-topic-card__empty">' . esc_html__( 'No needs have been published for this area yet.', 'firehouse' ) . '</p>',
				esc_html__( 'View topic area and sources', 'firehouse' ),
				Icons::svg( 'arrow-right', 15 )
			);
		}

		return sprintf(
			'<section class="fh-topics fh-container" id="top-needs" aria-labelledby="topics-heading"><div class="fh-topics__intro">%1$s<h2 id="topics-heading" class="fh-topics__heading">%2$s</h2>%3$s%4$s</div><div class="fh-topics__grid">%5$s</div><aside class="fh-source" id="needs-source" aria-labelledby="source-heading"><div class="fh-source__copy"><h3 id="source-heading" class="fh-source__heading">%6$s</h3><p class="fh-source__body">%7$s</p></div>%8$s</aside></section>',
			'' !== $text( 'eyebrow' ) ? '<span class="fh-eyebrow">' . Markup::text( $text( 'eyebrow' ) ) . '</span>' : '',
			Markup::text( $text( 'heading' ) ),
			'' !== $text( 'body' ) ? '<p class="fh-topics__body">' . Markup::text( $text( 'body' ) ) . '</p>' : '',
			Markup::synthesis_note( $model, null, is_string( $info['updatedAt'] ?? null ) ? $info['updatedAt'] : null, (int) $info['sourceCount'], count( $content['projects'] ), false, 'fh-topics__note' ),
			$cards,
			esc_html__( 'Where these needs come from', 'firehouse' ),
			sprintf(
				/* translators: %s: ", with" and the AI tool that read the projects, or nothing. */
				esc_html__( 'Topic areas are not assigned project by project. Every project in The Firehouse is read together%s. The end-user needs its researchers recorded are grouped into the four areas above and ranked by how often they come up. This pass serves as an initial draft that our team reviews and refines before finalizing each synthesis. Open any topic area to see which specific projects each need draws on; each project page preserves the researchers’ own words.', 'firehouse' ),
				/* translators: %s: the AI tool that read the projects. */
				null !== $model && '' !== $model ? Markup::text( sprintf( __( ', with %s', 'firehouse' ), $model ) ) : ''
			),
			Markup::button( __( 'Explore all projects', 'firehouse' ), home_url( $root . 'projects/' ), 'secondary', 'md', array( 1 => 'arrow-right' ) )
		);
	}

	/**
	 * The closing band: its heading and body, and the Submit button.
	 *
	 * @param array<string, mixed> $attributes The block's attributes.
	 */
	public static function submit_band( array $attributes ): string {
		$text = static fn( string $name ): string => is_string( $attributes[ $name ] ?? null ) ? $attributes[ $name ] : '';
		return sprintf(
			'<section class="fh-cta-band" aria-labelledby="submit-heading"><div class="fh-cta-band__inner fh-container fh-glass"><span class="fh-cta-band__glow" aria-hidden="true"></span><div class="fh-cta-band__copy"><h2 id="submit-heading" class="fh-cta-band__heading">%1$s</h2><p class="fh-cta-band__body">%2$s</p></div>%3$s</div></section>',
			Markup::text( $text( 'heading' ) ),
			Markup::text( $text( 'body' ) ),
			Page_Blocks::button( $attributes['cta'] ?? null, 'accent', 'lg', array( 'plus' ), array( 'block-mobile' ) )
		);
	}

	/**
	 * Each published topic area's card, in the areas' order: its key, label and short
	 * label (null for none, so the tag shows the vocabulary's), and its first needs as
	 * the topic page counts them, among the projects a visitor may see.
	 *
	 * @param list<array<string, mixed>> $topics   The published topics: each one's key and fields.
	 * @param int                        $per_card How many needs each card shows.
	 * @param array<int, mixed>          $visible  The projects a visitor may see, keyed by post ID.
	 * @return list<array{key: string, label: string, short: string|null, needs: list<array<string, mixed>>}>
	 */
	public static function cards( array $topics, int $per_card, array $visible ): array {
		$cards = array();
		foreach ( $topics as $topic ) {
			$cards[] = array(
				'key'   => $topic['key'],
				'label' => is_string( $topic['label'] ?? null ) ? $topic['label'] : '',
				'short' => is_string( $topic['short'] ?? null ) ? $topic['short'] : null,
				'needs' => Topic_Page::needs( array_slice( Topic_Page::valid( $topic['topNeeds'] ?? null ), 0, $per_card ), $visible ),
			);
		}
		return $cards;
	}

	/**
	 * What the counts, cards and note are computed from, in the shape of the site's
	 * content: `projects`, the projects a visitor may see, each with its post ID and needs;
	 * `topics`, the published topic areas in order, each with its fields; and
	 * `topicSummaries`, their syntheses. Read once per request: two queries for the
	 * projects and their fields, and the topics; the cards count from the same projects.
	 *
	 * @return array{projects: list<array<string, mixed>>, topics: list<array<string, mixed>>, topicSummaries: list<array<string, mixed>>}
	 */
	public static function content(): array {
		if ( null !== self::$content ) {
			return self::$content;
		}
		$projects = array();
		foreach ( get_posts( Store::public_projects_query( array( 'numberposts' => -1 ) ) ) as $post ) {
			if ( Store::is_public( $post ) ) {
				$needs      = get_post_meta( $post->ID, Meta::key( 'needs' ), true );
				$projects[] = array(
					'post_id'   => $post->ID,
					'published' => true,
					'needs'     => is_array( $needs ) ? array_values( array_filter( $needs, 'is_array' ) ) : array(),
				);
			}
		}

		$topics = array();
		foreach ( Store::topics() as $topic ) {
			if ( 'publish' === $topic['status'] && Vocabulary::is_one_of( $topic['key'], Vocabulary::topics() ) ) {
				$topics[] = array( 'key' => $topic['key'] ) + $topic['fields'];
			}
		}
		usort( $topics, static fn( array $a, array $b ): int => self::order( $a ) <=> self::order( $b ) );

		self::$content = array(
			'projects'       => $projects,
			'topics'         => $topics,
			'topicSummaries' => array_map( static fn( array $topic ): array => array( 'topic' => $topic['key'] ) + $topic, $topics ),
		);
		return self::$content;
	}

	/**
	 * Forgets what content() read, for tests and for a request that changes it.
	 */
	public static function forget(): void {
		self::$content = null;
	}

	/**
	 * A topic's place in the order, or 0 if it has none that's a number.
	 *
	 * @param array<string, mixed> $topic The topic's fields.
	 */
	private static function order( array $topic ): int|float {
		return is_int( $topic['order'] ?? null ) || is_float( $topic['order'] ?? null ) ? $topic['order'] : 0;
	}
}
