<?php
/**
 * The seed bundle: the site's content in one JSON file.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

use Firehouse\Domain\Content_Error;
use Firehouse\Domain\Email_Shape;
use Firehouse\Domain\Json;
use Firehouse\Domain\Json_Object;
use Firehouse\Domain\Normalize;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads a seed bundle, the file `npm run seed` writes from src/content/data/: the
 * settings, topics, syntheses, projects, landing page and About page in one object,
 * marked with `firehouseSeed`.
 *
 * A bundle from `npm run seed` holds what the site already publishes and no
 * submitter's private answers (rule 2). The plugin doesn't take that on trust: read()
 * reports, as problems that stop the import, any project, topic or synthesis field
 * the plugin doesn't store, and any text shaped like an email address other than a
 * team work address in the About page's team mailbox field, naming where each
 * is and never what it says. Whatever a bundle holds, only the fields the schemas
 * name reach a post.
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
	 * @return array{content: array<string, mixed>, notes: list<string>, problems: list<string>}
	 *         The content, normalized (Normalize::site_content()'s shape); a note for
	 *         each link normalizing dropped; and a problem for each field the plugin
	 *         doesn't store and each email address where none belongs.
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
		$content  = Normalize::site_content( $raw );
		$problems = self::unknown_fields( $content );
		foreach ( Email_Shape::find( $content ) as $path ) {
			if ( 1 === preg_match( '/^projects\.(\d+)/', $path, $match ) && is_string( $content['projects'][ (int) $match[1] ]['slug'] ?? null ) ) {
				$path .= sprintf( ' (“%s”)', $content['projects'][ (int) $match[1] ]['slug'] );
			}
			$problems[] = sprintf( 'Something shaped like an email address is in %s. No one’s email belongs in the site’s content (rule 2), except the team’s mailbox on the About page.', $path );
		}
		return array(
			'content'  => $content,
			'notes'    => self::dropped_links( $raw['topicSummaries'] ?? null, $content['topicSummaries'] ),
			'problems' => $problems,
		);
	}

	/**
	 * A problem for each project, topic or synthesis field the plugin doesn't store:
	 * a field a newer site added, or one that should never have been in the files.
	 *
	 * @param array<string, mixed> $content The content, normalized.
	 * @return list<string>
	 */
	private static function unknown_fields( array $content ): array {
		$project = array_keys( Meta::fields( Post_Types::PROJECT ) );
		$topic   = array_diff( array_keys( Meta::fields( Post_Types::TOPIC ) ), Meta::SYNTHESIS_FIELDS );
		$known   = array(
			// A project's slug and published flag are its post's; `author` is the older
			// single-string byline, which Normalize turns into `authors`.
			'projects'       => array_merge( $project, array( 'slug', 'published', 'author' ) ),
			'topics'         => array_merge( $topic, array( 'key', 'label' ) ),
			'topicSummaries' => array_merge( Meta::SYNTHESIS_FIELDS, array( 'topic' ) ),
		);

		$problems = array();
		foreach ( $known as $block => $fields ) {
			foreach ( $content[ $block ] as $index => $item ) {
				$where = sprintf( '%s.%d', $block, $index );
				$name  = $item['slug'] ?? $item['key'] ?? $item['topic'] ?? null;
				if ( is_string( $name ) ) {
					$where .= sprintf( ' (“%s”)', $name );
				}
				foreach ( array_diff( array_keys( $item ), $fields ) as $field ) {
					// A field's name is shown only if it looks like one: a name can carry
					// anything, an email address included.
					$field      = 1 === preg_match( '/^[A-Za-z][A-Za-z0-9_]{0,63}$/', (string) $field ) ? $field : 'a field with an unusual name';
					$problems[] = sprintf( '%1$s has a field the plugin doesn’t store: %2$s. Remove it from the content files, or update the plugin if it is new.', $where, $field );
				}
			}
		}
		return $problems;
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
