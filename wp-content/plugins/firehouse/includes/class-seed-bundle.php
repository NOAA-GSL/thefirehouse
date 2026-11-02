<?php
/**
 * The seed bundle: the site's content in one JSON file.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

use Firehouse\Domain\Content_Error;
use Firehouse\Domain\Json;
use Firehouse\Domain\Json_Object;
use Firehouse\Domain\Normalize;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads a seed bundle, the file `npm run seed` writes from src/content/data/: the
 * settings, topics, syntheses, projects, landing page and About page in one object,
 * marked with `firehouseSeed`. The bundle holds what the site already publishes, and
 * no submitter's private answers (rule 2).
 */
final class Seed_Bundle {

	/**
	 * The format this plugin reads, as `firehouseSeed` gives it.
	 */
	public const FORMAT = 1;

	/**
	 * The largest bundle read. Today's is about a tenth of this; the cap stops a wrong
	 * file being read into memory whole.
	 */
	public const MAX_BYTES = 1048576;

	/**
	 * Reads a bundle and checks its content as the React site does.
	 *
	 * @param string $json The bundle.
	 * @return array{content: array<string, mixed>, notes: list<string>} The content,
	 *         normalized (Normalize::site_content()'s shape), and a note for each link
	 *         normalizing dropped.
	 * @throws Content_Error When the file is too large, isn't JSON, isn't a bundle,
	 *                       or holds content Normalize refuses.
	 */
	public static function read( string $json ): array {
		if ( strlen( $json ) > self::MAX_BYTES ) {
			throw new Content_Error( esc_html( sprintf( 'The file is larger than a seed bundle can be (%d KB).', self::MAX_BYTES / 1024 ) ) );
		}
		try {
			$raw = Json::decode( $json );
		} catch ( \JsonException $e ) {
			throw new Content_Error( esc_html( 'The file isn’t JSON: ' . $e->getMessage() . '.' ) );
		}
		if ( $raw instanceof Json_Object ) {
			$raw = $raw->getArrayCopy();
		}
		if ( ! is_array( $raw ) || self::FORMAT !== ( $raw['firehouseSeed'] ?? null ) ) {
			throw new Content_Error( esc_html( 'The file isn’t a Firehouse seed bundle. Make one with npm run seed.' ) );
		}
		$content = Normalize::site_content( $raw );
		return array(
			'content' => $content,
			'notes'   => self::dropped_links( $raw['topicSummaries'] ?? null, $content['topicSummaries'] ),
		);
	}

	/**
	 * A note for each project a synthesis names that the site doesn't show.
	 *
	 * Normalize drops such a link, and the need's stored count with it, as the React
	 * site does, so a synthesis never links to or counts a project the site hides.
	 * The dry run says so, quoting the need, so no source is lost unseen (rule 5).
	 *
	 * @param mixed                      $raw       The bundle's syntheses, before normalizing.
	 * @param list<array<string, mixed>> $summaries The syntheses, normalized, in the same order.
	 * @return list<string>
	 */
	private static function dropped_links( mixed $raw, array $summaries ): array {
		$notes = array();
		foreach ( $summaries as $index => $summary ) {
			$raw_needs = $raw[ $index ]['topNeeds'] ?? null;
			if ( ! is_array( $raw_needs ) ) {
				continue;
			}
			foreach ( $summary['topNeeds'] as $position => $need ) {
				$named = $raw_needs[ $position ]['projects'] ?? null;
				if ( ! is_array( $named ) ) {
					continue;
				}
				foreach ( array_diff( array_filter( $named, 'is_string' ), $need['projects'] ?? array() ) as $slug ) {
					$notes[] = sprintf(
						'In the %1$s synthesis, the need “%2$s” names “%3$s”, which isn’t a published project in the bundle, so the need loses that link and its stored count.',
						$summary['topic'],
						is_string( $need['text'] ?? null ) ? $need['text'] : '',
						$slug
					);
				}
			}
		}
		return $notes;
	}
}
