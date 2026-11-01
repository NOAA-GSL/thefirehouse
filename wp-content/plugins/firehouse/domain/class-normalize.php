<?php
/**
 * Content validation and normalization, ported from src/content/normalize.ts.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks site content and brings it to one shape, as the React site does on load.
 *
 * Three jobs:
 *
 * 1. Refuse structural problems, such as an unknown topic key or a project with no
 *    year, with a Content_Error rather than rendering a silently empty section.
 * 2. Resolve the "inherit the submit address" convention: a link with an empty
 *    `href` takes `settings.submitFormUrl`, so the address lives in one field.
 * 3. Drop projects without IRB approval or exemption before anything else sees them,
 *    and drop a synthesis's links to projects the site doesn't show.
 *
 * In the plugin this is the Import screen's dry run, and later the checks at save.
 * shared/cases/normalize.json holds the cases both versions must pass; they fix what
 * is refused, not the wording. A list here is what JavaScript calls an array, so a
 * JSON object never passes for one. Where the TypeScript would crash on a missing or
 * malformed block (a landing page with no hero, a team that isn't a list), this
 * refuses it instead.
 */
final class Normalize {

	/**
	 * Checks and normalizes the whole site.
	 *
	 * @param array<string, mixed> $raw The decoded content files: settings, topics,
	 *                                  topicSummaries, projects, landing and about.
	 * @return array<string, mixed> The same keys, normalized.
	 * @throws Content_Error When the content is refused.
	 */
	public static function site_content( array $raw ): array {
		$settings = $raw['settings'] ?? null;
		if ( ! is_array( $settings ) ) {
			throw new Content_Error( esc_html( 'Site settings are missing.' ) );
		}

		$submit_form_url = $settings['submitFormUrl'] ?? null;
		if ( ! is_string( $submit_form_url ) || '' === $submit_form_url ) {
			throw new Content_Error( esc_html( 'settings.submitFormUrl is required: every submit link reads from it.' ) );
		}

		$topics = array();
		foreach ( self::list_of( $raw['topics'] ?? null, 'topics' ) as $topic ) {
			$key          = is_array( $topic ) ? ( $topic['key'] ?? null ) : null;
			$topic        = is_array( $topic ) ? $topic : array();
			$topic['key'] = self::topic_key( $key, sprintf( 'topics[“%s”]', self::text( $key ) ) );
			$topics[]     = $topic;
		}
		usort( $topics, static fn( array $a, array $b ): int => ( $a['order'] ?? 0 ) <=> ( $b['order'] ?? 0 ) );
		if ( array() === $topics ) {
			throw new Content_Error( esc_html( 'At least one topic area is required.' ) );
		}

		$raw_projects = self::list_of( $raw['projects'] ?? null, 'projects' );

		return array(
			'settings'       => self::settings( $settings, $submit_form_url ),
			'topics'         => $topics,
			'topicSummaries' => self::topic_summaries( $raw['topicSummaries'] ?? null, self::published_slugs( $raw_projects ) ),
			'projects'       => self::projects( $raw_projects ),
			'landing'        => self::landing( $raw['landing'] ?? null, $submit_form_url ),
			'about'          => self::about( $raw['about'] ?? null, $submit_form_url ),
		);
	}

	/**
	 * Settings, with the nav and footer links resolved.
	 *
	 * @param array<string, mixed> $settings        The settings block.
	 * @param string               $submit_form_url The survey address.
	 * @return array<string, mixed>
	 * @throws Content_Error When the nav or the footer groups aren't lists.
	 */
	private static function settings( array $settings, string $submit_form_url ): array {
		$settings['nav'] = array_map(
			static fn( mixed $link ): array => self::resolve_link( $link, $submit_form_url, 'settings.nav' ),
			self::list_of( $settings['nav'] ?? null, 'settings.nav' )
		);

		$groups = array();
		foreach ( self::list_of( $settings['footerGroups'] ?? null, 'settings.footerGroups' ) as $group ) {
			$group          = is_array( $group ) ? $group : array();
			$group['links'] = array_map(
				static fn( mixed $link ): array => self::resolve_link( $link, $submit_form_url, 'settings.footerGroups' ),
				self::list_of( $group['links'] ?? null, 'settings.footerGroups links' )
			);
			$groups[]       = $group;
		}
		$settings['footerGroups'] = $groups;

		return $settings;
	}

	/**
	 * The slugs a synthesis may link to: published projects with IRB approval.
	 *
	 * @param mixed[] $projects The raw projects.
	 * @return array<string, true> Slugs as keys.
	 */
	private static function published_slugs( array $projects ): array {
		$slugs = array();
		foreach ( $projects as $project ) {
			if ( is_array( $project ) && Js::truthy( $project['published'] ?? null ) && true === ( $project['irbApproved'] ?? null ) && is_string( $project['slug'] ?? null ) ) {
				$slugs[ $project['slug'] ] = true;
			}
		}
		return $slugs;
	}

	/**
	 * The synthesis for each topic.
	 *
	 * A need keeps only the projects the site shows. If any are dropped, its stored
	 * count goes too, because it would over-report; the page then counts what is left.
	 * Older passes stored needs as plain strings, which are accepted.
	 *
	 * @param mixed               $raw             The raw summaries.
	 * @param array<string, true> $published_slugs Slugs a need may link to.
	 * @return list<array<string, mixed>>
	 * @throws Content_Error When a summary names an unknown topic, or its needs or
	 *                       their projects aren't lists.
	 */
	private static function topic_summaries( mixed $raw, array $published_slugs ): array {
		$summaries = array();
		foreach ( self::list_of( $raw, 'topicSummaries' ) as $summary ) {
			$topic            = is_array( $summary ) ? ( $summary['topic'] ?? null ) : null;
			$summary          = is_array( $summary ) ? $summary : array();
			$summary['topic'] = self::topic_key( $topic, sprintf( 'topicSummaries[“%s”]', self::text( $topic ) ) );
			$where            = sprintf( 'topicSummaries[“%s”].topNeeds', $summary['topic'] );

			$needs = array();
			foreach ( self::list_of( $summary['topNeeds'] ?? null, $where ) as $need ) {
				$entry = is_string( $need ) ? array( 'text' => $need ) : $need;
				if ( ! is_array( $entry ) ) {
					throw new Content_Error( esc_html( sprintf( '%s has an entry that isn’t a need.', $where ) ) );
				}
				if ( isset( $entry['projects'] ) ) {
					$listed = self::list_of( $entry['projects'], $where . ' projects' );
					$kept   = array_values(
						array_filter(
							$listed,
							static fn( mixed $slug ): bool => is_string( $slug ) && isset( $published_slugs[ $slug ] )
						)
					);
					if ( count( $kept ) !== count( $listed ) ) {
						unset( $entry['mentions'] );
					}
					$entry['projects'] = $kept;
				} else {
					unset( $entry['projects'] );
				}
				$needs[] = $entry;
			}
			$summary['topNeeds'] = $needs;
			$summaries[]         = $summary;
		}
		return $summaries;
	}

	/**
	 * The projects, without those lacking IRB approval or exemption.
	 *
	 * Those are dropped here, before anything else sees them, so no page, count or
	 * link can reach one. A project that is dropped isn't checked either: it is never
	 * shown.
	 *
	 * @param mixed[] $raw The raw projects.
	 * @return list<array<string, mixed>>
	 * @throws Content_Error When a project is refused, or two share a slug.
	 */
	private static function projects( array $raw ): array {
		$projects = array();
		foreach ( $raw as $project ) {
			if ( ! is_array( $project ) || true !== ( $project['irbApproved'] ?? null ) ) {
				continue;
			}

			$slug = $project['slug'] ?? null;
			if ( ! is_string( $slug ) || '' === $slug ) {
				throw new Content_Error( esc_html( sprintf( 'The project titled “%s” has no slug. Its slug is its address on the site.', self::text( $project['title'] ?? null ) ) ) );
			}
			$where = sprintf( 'project “%s”', $slug );

			$year = $project['completionYear'] ?? null;
			if ( ! is_int( $year ) && ! ( is_float( $year ) && ! is_nan( $year ) ) ) {
				throw new Content_Error( esc_html( sprintf( '%s has no completion year. It is a primary filter.', $where ) ) );
			}

			$status = $project['publicationStatus'] ?? null;
			if ( Js::truthy( $status ) && ! Vocabulary::is_one_of( $status, Vocabulary::publication_statuses() ) ) {
				throw new Content_Error( esc_html( sprintf( '%s has unknown publication status “%s”.', $where, self::text( $status ) ) ) );
			}

			$project['authors']    = self::authors( $project['authors'] ?? null, $project['author'] ?? null, $where );
			$project['firePhases'] = self::keys_from(
				$project['firePhases'] ?? null,
				Vocabulary::fire_phases(),
				$where,
				'fire cycle phase',
				'Valid keys are listed in wp-content/plugins/firehouse/shared/vocabulary.json.'
			);

			if ( Js::truthy( $project['geo'] ?? null ) ) {
				$geo            = is_array( $project['geo'] ) ? $project['geo'] : array();
				$geo['regions'] = self::keys_from(
					$geo['regions'] ?? null,
					Vocabulary::regions(),
					$where,
					'coordination region',
					'Valid values are the ten GACC codes plus PACIFIC, NATIONAL, INTL and UNKNOWN: see wp-content/plugins/firehouse/shared/vocabulary.json.'
				);
				$project['geo'] = $geo;
			} else {
				unset( $project['geo'] );
			}

			$project['methods']   = $project['methods'] ?? array();
			$project['takeaways'] = $project['takeaways'] ?? array();
			$project['needs']     = self::needs( $project['needs'] ?? null, $where );
			$project['papers']    = $project['papers'] ?? array();
			$project['published'] = Js::truthy( $project['published'] ?? false );

			$projects[] = $project;
		}

		$repeat = array_keys( array_filter( array_count_values( array_column( $projects, 'slug' ) ), static fn( int $count ): bool => $count > 1 ) );
		if ( array() !== $repeat ) {
			throw new Content_Error( esc_html( sprintf( 'Duplicate project slug “%s”: slugs are used as URLs.', $repeat[0] ) ) );
		}

		return $projects;
	}

	/**
	 * The authors: the structured list, or a legacy single `author` string.
	 *
	 * @param mixed  $raw    The `authors` list.
	 * @param mixed  $legacy The older single `author` string.
	 * @param string $where  The project, for messages.
	 * @return list<array<string, mixed>>
	 * @throws Content_Error When there is no author, or one has no name.
	 */
	private static function authors( mixed $raw, mixed $legacy, string $where ): array {
		if ( self::is_list( $raw ) && array() !== $raw ) {
			$authors = array();
			foreach ( $raw as $entry ) {
				if ( is_string( $entry ) ) {
					$authors[] = array( 'name' => $entry );
					continue;
				}
				if ( ! is_array( $entry ) || ! Js::truthy( $entry['name'] ?? null ) ) {
					throw new Content_Error( esc_html( sprintf( '%s has an author entry with no name.', $where ) ) );
				}
				$authors[] = $entry;
			}
			return $authors;
		}
		$name = is_string( $legacy ) ? Js::trim( $legacy ) : '';
		if ( '' !== $name ) {
			return array( array( 'name' => $name ) );
		}
		throw new Content_Error( esc_html( sprintf( '%s must credit at least one author.', $where ) ) );
	}

	/**
	 * Need and recommendation entries. A bare string is a need on its own, so the
	 * older flat list still loads. Anything but a list is no entries.
	 *
	 * @param mixed  $raw   The `needs` list.
	 * @param string $where The project, for messages.
	 * @return list<array<string, mixed>>
	 * @throws Content_Error When an entry has neither a need nor a recommendation.
	 */
	private static function needs( mixed $raw, string $where ): array {
		if ( ! self::is_list( $raw ) ) {
			return array();
		}
		$needs = array();
		foreach ( $raw as $entry ) {
			$entry = is_string( $entry ) ? array( 'need' => $entry ) : $entry;
			if ( ! is_array( $entry ) || ! ( Js::truthy( $entry['need'] ?? null ) || Js::truthy( $entry['recommendation'] ?? null ) ) ) {
				throw new Content_Error( esc_html( sprintf( '%s has an empty need/recommendation entry.', $where ) ) );
			}
			$needs[] = $entry;
		}
		return $needs;
	}

	/**
	 * The landing page, with its submit links resolved.
	 *
	 * @param mixed  $raw             The landing block.
	 * @param string $submit_form_url The survey address.
	 * @return array<string, mixed>
	 * @throws Content_Error When the page or one of its links is missing.
	 */
	private static function landing( mixed $raw, string $submit_form_url ): array {
		if ( ! is_array( $raw ) || ! is_array( $raw['hero'] ?? null ) || ! is_array( $raw['submitBand'] ?? null ) ) {
			throw new Content_Error( esc_html( 'Landing page content is missing its hero or submit band.' ) );
		}
		$raw['hero']['primaryCta']   = self::resolve_link( $raw['hero']['primaryCta'] ?? null, $submit_form_url, 'landing.hero.primaryCta' );
		$raw['hero']['secondaryCta'] = self::resolve_link( $raw['hero']['secondaryCta'] ?? null, $submit_form_url, 'landing.hero.secondaryCta' );
		$raw['submitBand']['cta']    = self::resolve_link( $raw['submitBand']['cta'] ?? null, $submit_form_url, 'landing.submitBand.cta' );
		return $raw;
	}

	/**
	 * The About page.
	 *
	 * Checked rather than trusted because its audience cards name icons, and an
	 * unknown name has to be an error an editor can read rather than a broken page.
	 * Biographies are normalized in shape only: a single string becomes one
	 * paragraph, and blank paragraphs go. Nothing is trimmed or rewritten, because the
	 * text belongs to the person it describes.
	 *
	 * @param mixed  $raw             The About block.
	 * @param string $submit_form_url The survey address.
	 * @return array<string, mixed>
	 * @throws Content_Error When the page is missing, or a card, member or link is refused.
	 */
	private static function about( mixed $raw, string $submit_form_url ): array {
		if ( ! is_array( $raw ) ) {
			throw new Content_Error( esc_html( 'About page content is missing: the primary nav links to it.' ) );
		}

		$audiences          = is_array( $raw['audiences'] ?? null ) ? $raw['audiences'] : array();
		$audiences['items'] = array_map(
			static function ( mixed $item ): array {
				$item = is_array( $item ) ? $item : array();
				if ( ! Vocabulary::is_one_of( $item['icon'] ?? null, Vocabulary::icons() ) ) {
					throw new Content_Error(
						esc_html(
							sprintf(
								'about.audiences item “%s” uses unknown icon “%s”. Valid names are listed in wp-content/plugins/firehouse/shared/vocabulary.json.',
								self::text( $item['title'] ?? null ),
								self::text( $item['icon'] ?? null )
							)
						)
					);
				}
				return $item;
			},
			self::list_of( $audiences['items'] ?? array(), 'about.audiences.items' )
		);

		$team            = is_array( $raw['team'] ?? null ) ? $raw['team'] : array();
		$team['members'] = array_map(
			static function ( mixed $member ): array {
				$member = is_array( $member ) ? $member : array();
				if ( ! Js::truthy( $member['name'] ?? null ) ) {
					throw new Content_Error( esc_html( 'about.team has a member with no name.' ) );
				}
				$bio        = self::is_list( $member['bio'] ?? null ) ? $member['bio'] : array( $member['bio'] ?? null );
				$paragraphs = array_values( array_filter( $bio, static fn( mixed $text ): bool => is_string( $text ) && '' !== Js::trim( $text ) ) );
				if ( array() === $paragraphs ) {
					throw new Content_Error( esc_html( sprintf( 'about.team member “%s” has no biography.', self::text( $member['name'] ) ) ) );
				}
				$member['bio'] = $paragraphs;
				return $member;
			},
			self::list_of( $team['members'] ?? array(), 'about.team.members' )
		);

		$process          = is_array( $raw['process'] ?? null ) ? $raw['process'] : array();
		$process['links'] = array_map(
			static fn( mixed $link ): array => self::resolve_link( $link, $submit_form_url, 'about.process.links' ),
			self::list_of( $process['links'] ?? array(), 'about.process.links' )
		);

		$cta = $raw['cta'] ?? null;
		if ( ! is_array( $cta ) ) {
			throw new Content_Error( esc_html( 'about.cta is missing.' ) );
		}
		$cta['cta'] = self::resolve_link( $cta['cta'] ?? null, $submit_form_url, 'about.cta.cta' );

		$raw['audiences'] = $audiences;
		$raw['team']      = $team;
		$raw['process']   = $process;
		$raw['cta']       = $cta;
		return $raw;
	}

	/**
	 * A link with an empty `href` takes the survey address; any other link is left
	 * as it is.
	 *
	 * @param mixed  $link            The link: a label and an `href` or a `to` route.
	 * @param string $submit_form_url The survey address.
	 * @param string $where           Where the link is, for messages.
	 * @return array<string, mixed>
	 * @throws Content_Error When the link is missing.
	 */
	private static function resolve_link( mixed $link, string $submit_form_url, string $where ): array {
		if ( ! is_array( $link ) ) {
			throw new Content_Error( esc_html( sprintf( '%s is missing a link.', $where ) ) );
		}
		if ( '' === ( $link['href'] ?? null ) ) {
			$link['href'] = $submit_form_url;
		}
		return $link;
	}

	/**
	 * A topic key, or a refusal naming where it was used.
	 *
	 * @param mixed  $value The key.
	 * @param string $where Where it was used, for messages.
	 * @throws Content_Error When it isn't one of the four keys.
	 */
	private static function topic_key( mixed $value, string $where ): string {
		if ( ! Vocabulary::is_one_of( $value, Vocabulary::topics() ) ) {
			throw new Content_Error(
				esc_html(
					sprintf(
						'%s references unknown topic “%s”. Valid keys: %s.',
						$where,
						self::text( $value ),
						implode( ', ', Vocabulary::topics() )
					)
				)
			);
		}
		return $value;
	}

	/**
	 * Vocabulary keys, each checked, repeats kept once in their first place. Anything
	 * but a list is no keys.
	 *
	 * @param mixed    $values The keys.
	 * @param string[] $valid  The keys allowed.
	 * @param string   $where  The project, for messages.
	 * @param string   $what   What the keys are, for messages.
	 * @param string   $help   Where the valid keys are listed, for messages.
	 * @return list<string>
	 * @throws Content_Error When a key isn't allowed.
	 */
	private static function keys_from( mixed $values, array $valid, string $where, string $what, string $help ): array {
		if ( ! self::is_list( $values ) ) {
			return array();
		}
		foreach ( $values as $value ) {
			if ( ! Vocabulary::is_one_of( $value, $valid ) ) {
				throw new Content_Error( esc_html( sprintf( '%s references unknown %s “%s”. %s', $where, $what, self::text( $value ), $help ) ) );
			}
		}
		return array_values( array_unique( $values ) );
	}

	/**
	 * A list, or a refusal: the code after this reads it item by item.
	 *
	 * @param mixed  $value The value.
	 * @param string $where What it is, for messages.
	 * @return list<mixed>
	 * @throws Content_Error When it isn't a list.
	 */
	private static function list_of( mixed $value, string $where ): array {
		if ( ! self::is_list( $value ) ) {
			throw new Content_Error( esc_html( sprintf( '%s must be a list.', $where ) ) );
		}
		return $value;
	}

	/**
	 * Whether a value is what JavaScript calls an array. A decoded JSON object is a
	 * PHP array too, so is_array() alone would let one pass.
	 *
	 * @param mixed $value The value.
	 */
	private static function is_list( mixed $value ): bool {
		return is_array( $value ) && array_is_list( $value );
	}

	/**
	 * A value as text for a message.
	 *
	 * @param mixed $value The value.
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
