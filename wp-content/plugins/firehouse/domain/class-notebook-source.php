<?php
/**
 * The document NotebookLM reads: every published project, word for word.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A port of buildNotebookSource() in `scripts/notebooklm-source.mjs`, which
 * shared/cases/notebooklm-source.json holds both to, byte for byte.
 *
 * - **Verbatim.** The researchers' text is copied as it is, as the project pages show
 *   it (rule 1): a synthesis built on a paraphrase compounds it.
 * - **Published projects only,** the ones the site shows, so the synthesis can't draw
 *   on anything a visitor can't read.
 * - **Nothing private.** A project's fields hold no email, job title or Q10 answer
 *   (rule 2), so there is nothing to strip.
 * - **The same bytes for the same content.** No date in it.
 *
 * The source's title, "The Firehouse — collected project findings", is the name the
 * synthesis prompt in `notebooklm/README.md` asks for, so it keeps that spelling.
 */
final class Notebook_Source {

	/**
	 * The document's title, which the synthesis prompt names it by.
	 */
	public const TITLE = 'The Firehouse — collected project findings';

	/**
	 * The document.
	 *
	 * @param list<array<string, mixed>> $projects Projects as types.ts has them; those not `published` are left out.
	 * @param list<array<string, mixed>> $topics   Topics as types.ts has them: label, description, covers, order.
	 */
	public static function build( array $projects, array $topics ): string {
		$parts = self::parts( $projects, $topics );
		return $parts['intro'] . "\n\n---\n\n" . implode( "\n\n---\n\n", $parts['projects'] ) . "\n";
	}

	/**
	 * The document's parts: its introduction, ending with the four topic areas, and each
	 * published project's section, most recent first. build() joins them with rules;
	 * Analysis puts each between markers its text can't fake.
	 *
	 * @param list<array<string, mixed>> $projects Projects as types.ts has them; those not `published` are left out.
	 * @param list<array<string, mixed>> $topics   Topics as types.ts has them: label, description, covers, order.
	 * @return array{intro: string, projects: list<string>}
	 */
	public static function parts( array $projects, array $topics ): array {
		$published = array_values( array_filter( $projects, static fn( array $project ): bool => true === ( $project['published'] ?? false ) ) );
		usort( $published, array( Derive::class, 'by_recency' ) );

		usort( $topics, static fn( array $a, array $b ): int => ( $a['order'] ?? 0 ) <=> ( $b['order'] ?? 0 ) );
		$definitions = array();
		foreach ( $topics as $topic ) {
			$lines  = array( '### ' . self::text( $topic['label'] ?? '' ), '', self::text( $topic['description'] ?? '' ) );
			$covers = self::strings( $topic['covers'] ?? null );
			if ( array() !== $covers ) {
				array_push( $lines, '', 'Covers:', '', self::list( $covers ) );
			}
			$definitions[] = implode( "\n", $lines );
		}

		$sections = array();
		foreach ( $published as $index => $project ) {
			$sections[] = self::project( $project, $index );
		}

		return array(
			'intro'    => implode(
				"\n",
				array(
					'# ' . self::TITLE,
					'',
					'Source document for the topic-area synthesis. It contains all ' . count( $published ) . ' '
						. 'published projects in The Firehouse, NOAA Global Systems Laboratory\'s hub for '
						. 'fire weather social science. Each project\'s abstract, takeaways, needs and '
						. 'recommendations are reproduced exactly as the researchers submitted them.',
					'',
					'Topic areas are a lens across the whole collection. No project is assigned to a '
						. 'topic area; a single project may inform several areas or none.',
					'',
					'## The four topic areas',
					'',
					implode( "\n\n", $definitions ),
				)
			),
			'projects' => $sections,
		);
	}

	/**
	 * One project's section.
	 *
	 * @param array<string, mixed> $project The project.
	 * @param int                  $index   Its place in the document, from 0.
	 */
	private static function project( array $project, int $index ): string {
		$authors = array();
		foreach ( is_array( $project['authors'] ?? null ) ? $project['authors'] : array() as $author ) {
			$authors[] = self::text( is_array( $author ) ? ( $author['name'] ?? '' ) : '' );
		}
		$lines = array(
			'## Project ' . ( $index + 1 ) . ': ' . self::text( $project['title'] ?? '' ),
			'',
			'- **Project ID:** ' . self::text( $project['id'] ?? '' ),
			'- **Page:** /projects/' . self::text( $project['slug'] ?? '' ),
			'- **Researchers:** ' . implode( ', ', $authors ),
		);
		if ( Js::truthy( $project['org'] ?? null ) ) {
			$lines[] = '- **Organization:** ' . self::text( $project['org'] );
		}
		$lines[] = '- **Completed:** ' . self::text( $project['completionYear'] ?? '' );
		if ( Js::truthy( $project['projectType'] ?? null ) ) {
			$lines[] = '- **Project type:** ' . self::text( $project['projectType'] );
		}
		$methods = self::strings( $project['methods'] ?? null );
		if ( array() !== $methods ) {
			$lines[] = '- **Data collection:** ' . implode( ', ', $methods );
		}
		$phases = self::strings( $project['firePhases'] ?? null );
		if ( array() !== $phases ) {
			$by_key  = Vocabulary::items( 'firePhases' );
			$lines[] = '- **Fire cycle phases:** ' . implode( '; ', array_map( static fn( string $key ): string => isset( $by_key[ $key ] ) ? $by_key[ $key ]['short'] . ' (' . $by_key[ $key ]['label'] . ')' : $key, $phases ) );
		}
		$regions = self::strings( is_array( $project['geo'] ?? null ) ? ( $project['geo']['regions'] ?? null ) : null );
		if ( array() !== $regions ) {
			$by_key  = Vocabulary::items( 'regions' );
			$lines[] = '- **Geographic areas:** ' . implode( '; ', array_map( static fn( string $key ): string => $by_key[ $key ]['label'] ?? $key, $regions ) );
		}

		if ( Js::truthy( $project['abstract'] ?? null ) ) {
			array_push( $lines, '', '### Abstract', '', self::text( $project['abstract'] ) );
		}

		$takeaways = self::strings( $project['takeaways'] ?? null );
		if ( array() !== $takeaways ) {
			array_push( $lines, '', '### Major takeaways', '', self::list( $takeaways ) );
		}

		$needs = is_array( $project['needs'] ?? null ) ? array_values( $project['needs'] ) : array();
		if ( array() !== $needs ) {
			array_push( $lines, '', '### End-user needs and recommendations' );
			foreach ( $needs as $i => $entry ) {
				$entry = is_array( $entry ) ? $entry : array();
				array_push( $lines, '', '**Entry ' . ( $i + 1 ) . '**' );
				if ( Js::truthy( $entry['need'] ?? null ) ) {
					array_push( $lines, '', 'Need: ' . self::text( $entry['need'] ) );
				}
				if ( Js::truthy( $entry['recommendation'] ?? null ) ) {
					array_push( $lines, '', 'Recommendation: ' . self::text( $entry['recommendation'] ) );
				}
			}
		}

		$papers = is_array( $project['papers'] ?? null ) ? array_values( $project['papers'] ) : array();
		if ( array() !== $papers ) {
			$items = array();
			foreach ( $papers as $paper ) {
				$paper   = is_array( $paper ) ? $paper : array();
				$link    = Js::truthy( $paper['doi'] ?? null ) ? 'https://doi.org/' . self::text( $paper['doi'] ) : ( $paper['url'] ?? null );
				$meta    = implode( ', ', array_map( array( self::class, 'text' ), array_values( array_filter( array( $paper['container'] ?? null, $paper['year'] ?? null ), array( Js::class, 'truthy' ) ) ) ) );
				$items[] = implode( ' — ', array_map( array( self::class, 'text' ), array_values( array_filter( array( $paper['title'] ?? null, $meta, $link ), array( Js::class, 'truthy' ) ) ) ) );
			}
			array_push( $lines, '', '### Publications and resources', '', self::list( $items ) );
		}

		return implode( "\n", $lines );
	}

	/**
	 * A Markdown list, one item a line.
	 *
	 * @param string[] $items The items.
	 */
	private static function list( array $items ): string {
		return implode( "\n", array_map( static fn( string $item ): string => '- ' . $item, $items ) );
	}

	/**
	 * A list's strings, as `.length` and `.join()` would see them; nothing for anything
	 * else.
	 *
	 * @param mixed $value The list.
	 * @return list<string>
	 */
	private static function strings( mixed $value ): array {
		return is_array( $value ) ? array_values( array_map( array( self::class, 'text' ), $value ) ) : array();
	}

	/**
	 * A value as a template literal prints it: a whole number without a decimal point.
	 *
	 * @param mixed $value The value.
	 */
	private static function text( mixed $value ): string {
		if ( is_float( $value ) && floor( $value ) === $value && abs( $value ) < 1e15 ) {
			return (string) (int) $value;
		}
		return is_scalar( $value ) ? (string) $value : '';
	}
}
