<?php
/**
 * The About page's blocks.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

use Firehouse\Domain\Email_Shape;
use Firehouse\Domain\Format;
use Firehouse\Domain\Js;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The About page: the site's `AboutPage`, as blocks in the `about` page under
 * `firehouse`. The words are the blocks' attributes, which the Import screen writes
 * from the seed bundle's about.json when the page doesn't exist yet.
 *
 * - `firehouse/about-intro`: what the hub is, the page's heading.
 * - `firehouse/about-sections`: the body, holding the four sections below in order.
 * - `firehouse/about-mission`: why it exists.
 * - `firehouse/about-audiences`: who it serves, a card for each audience.
 * - `firehouse/about-process`: how it works, the numbered steps and the links after them.
 * - `firehouse/about-team`: who is behind it, the team's mailbox, a card for each member.
 * - `firehouse/about-cta`: the closing band with the Submit button.
 *
 * Each member's portrait is a Media Library image an administrator uploaded, which
 * the import found by its Title, the member's `photo` (`stephanie-hoekstra`), named in
 * the dry run so whoever imports can check it, and stored in the page as its ID. The
 * page looks nothing up when it renders, so an image uploaded later under the same
 * Title changes nothing; without one, the card shows the member's initials, as the
 * site does. Biographies are printed as stored, one paragraph each: they were written
 * by the people they describe.
 */
final class About_Page {

	/**
	 * The page's name under `firehouse`, so its address is /firehouse/about/.
	 */
	public const SLUG = 'about';

	/**
	 * The characters that would end a `mailto:` address or add to it, encoded
	 * (RFC 6068), so the link goes to the address shown and nowhere else.
	 */
	private const MAILTO = array(
		'%' => '%25',
		'?' => '%3F',
		'#' => '%23',
		'&' => '%26',
	);

	/**
	 * How wide a portrait is drawn, at every width (`.fh-person__portrait`).
	 */
	private const PORTRAIT_WIDTH = '168px';

	/**
	 * The page's blocks, from the bundle's about content, as the Import screen saves
	 * them. Only text reaches the page: each section's words, its items' text, and a
	 * link's label and where it goes, with a link to the bundle's survey address stored
	 * empty so it follows the settings; and each member's portrait, as the ID of the
	 * image the import found for it. Anything else is left out: a value that isn't
	 * text, and a key the page doesn't use, such as a member's `photoUrl`, which the
	 * site uses for a portrait hosted elsewhere.
	 *
	 * @param array<string, mixed> $about      The bundle's about content, normalized.
	 * @param string               $submit_url The bundle's survey address.
	 * @param array<string, int>   $portraits  The portraits found, by `photo` name (portraits()).
	 */
	public static function blocks( array $about, string $submit_url, array $portraits = array() ): string {
		$part    = static fn( string $name ): mixed => $about[ $name ] ?? null;
		$words   = static fn( mixed $section, array $names ): array => Page_Blocks::text_fields( $section, $names ) + array_fill_keys( $names, '' );
		$link    = static fn( mixed $item ): array => Page_Blocks::link_fields( $item, $submit_url );
		$members = array();
		foreach ( self::list_of( $part( 'team' )['members'] ?? null ) as $member ) {
			$photo     = self::text( $member, 'photo' );
			$members[] = Page_Blocks::text_fields( $member, array( 'name', 'photo', 'photoAlt' ) )
				+ ( isset( $portraits[ $photo ] ) ? array( 'photoId' => (int) $portraits[ $photo ] ) : array() )
				+ array( 'bio' => self::strings( $member['bio'] ?? null ) );
		}

		return serialize_blocks(
			array(
				Page_Blocks::block( 'firehouse/about-intro', $words( $part( 'hero' ), array( 'eyebrow', 'heading', 'body' ) ) ),
				Page_Blocks::block(
					'firehouse/about-sections',
					array(),
					array(
						Page_Blocks::block(
							'firehouse/about-mission',
							$words( $part( 'mission' ), array( 'heading' ) ) + array( 'paragraphs' => self::strings( $part( 'mission' )['paragraphs'] ?? null ) )
						),
						Page_Blocks::block(
							'firehouse/about-audiences',
							$words( $part( 'audiences' ), array( 'eyebrow', 'heading', 'body' ) ) + array(
								'items' => array_map(
									static fn( array $item ): array => Page_Blocks::text_fields( $item, array( 'icon', 'title', 'body' ) ),
									self::list_of( $part( 'audiences' )['items'] ?? null )
								),
							)
						),
						Page_Blocks::block(
							'firehouse/about-process',
							$words( $part( 'process' ), array( 'eyebrow', 'heading', 'body' ) ) + array(
								'steps' => array_map(
									static fn( array $step ): array => Page_Blocks::text_fields( $step, array( 'title', 'body' ) ),
									self::list_of( $part( 'process' )['steps'] ?? null )
								),
								'links' => array_map( $link, self::list_of( $part( 'process' )['links'] ?? null ) ),
							)
						),
						Page_Blocks::block(
							'firehouse/about-team',
							$words( $part( 'team' ), array( 'eyebrow', 'heading', 'body', 'emailLead', 'email' ) ) + array( 'members' => $members )
						),
					)
				),
				Page_Blocks::block(
					'firehouse/about-cta',
					$words( $part( 'cta' ), array( 'heading', 'body' ) ) + array( 'cta' => $link( $part( 'cta' )['cta'] ?? null ) )
				),
			)
		);
	}

	/**
	 * The page's introduction: its eyebrow, its heading, which is the page's, and what
	 * the hub is.
	 *
	 * @param array<string, mixed> $attributes The block's attributes.
	 */
	public static function intro( array $attributes ): string {
		return sprintf(
			'<section class="fh-about__hero"><div class="fh-about__hero-inner fh-container">%1$s<h1 class="fh-about__heading">%2$s</h1>%3$s</div></section>',
			self::optional( '<span class="fh-eyebrow">%s</span>', self::text( $attributes, 'eyebrow' ) ),
			Markup::text( self::text( $attributes, 'heading' ) ),
			self::optional( '<p class="fh-about__lede">%s</p>', self::text( $attributes, 'body' ) )
		);
	}

	/**
	 * The page's body, around its sections.
	 *
	 * @param string $content The sections, the inner blocks' markup.
	 */
	public static function sections( string $content ): string {
		return '<div class="fh-about__body fh-container">' . $content . '</div>';
	}

	/**
	 * Why it exists: the heading and its paragraphs.
	 *
	 * @param array<string, mixed> $attributes The block's attributes.
	 */
	public static function mission( array $attributes ): string {
		$paragraphs = '';
		foreach ( self::strings( $attributes['paragraphs'] ?? null ) as $paragraph ) {
			$paragraphs .= '<p>' . Markup::text( $paragraph ) . '</p>';
		}
		return sprintf(
			'<section class="fh-about__section" aria-labelledby="about-mission"><h2 id="about-mission" class="fh-about__section-heading">%1$s</h2><div class="fh-about__prose">%2$s</div></section>',
			Markup::text( self::text( $attributes, 'heading' ) ),
			$paragraphs
		);
	}

	/**
	 * Who it serves: the section's words, and a card for each audience with its icon.
	 *
	 * @param array<string, mixed> $attributes The block's attributes.
	 */
	public static function audiences( array $attributes ): string {
		$items = '';
		foreach ( self::list_of( $attributes['items'] ?? null ) as $item ) {
			$items .= sprintf(
				'<li class="fh-audience fh-glass"><span class="fh-audience__icon" aria-hidden="true">%1$s</span><h3 class="fh-audience__title">%2$s</h3><p class="fh-audience__body">%3$s</p></li>',
				Icons::svg( self::text( $item, 'icon' ), 20 ),
				Markup::text( self::text( $item, 'title' ) ),
				Markup::text( self::text( $item, 'body' ) )
			);
		}
		return sprintf(
			'<section class="fh-about__section" aria-labelledby="about-audiences">%1$s<ul class="fh-about__audiences">%2$s</ul></section>',
			self::section_intro( $attributes, 'about-audiences' ),
			$items
		);
	}

	/**
	 * How it works: the section's words, the steps in order, numbered as a list the
	 * eye and a screen reader read alike, and the links after them.
	 *
	 * @param array<string, mixed> $attributes The block's attributes.
	 */
	public static function process( array $attributes ): string {
		$steps = '';
		foreach ( self::list_of( $attributes['steps'] ?? null ) as $index => $step ) {
			$steps .= sprintf(
				'<li class="fh-step"><span class="fh-step__rank" aria-hidden="true">%1$d</span><div class="fh-step__main"><h3 class="fh-step__title">%2$s</h3><p class="fh-step__body">%3$s</p></div></li>',
				$index + 1,
				Markup::text( self::text( $step, 'title' ) ),
				Markup::text( self::text( $step, 'body' ) )
			);
		}
		$links = '';
		foreach ( self::list_of( $attributes['links'] ?? null ) as $link ) {
			// A link with neither a route nor an address goes nowhere, as on the site,
			// and an empty route is none; an empty address is the survey's.
			if ( ! ( is_string( $link['to'] ?? null ) && '' !== $link['to'] ) && ! is_string( $link['href'] ?? null ) ) {
				continue;
			}
			$links .= Page_Blocks::button(
				$link,
				'link',
				'md',
				array( 1 => 'arrow-right' ),
				array(),
				array(
					'extra_class' => 'fh-about__process-link',
					'icon_size'   => 15,
				)
			);
		}
		return sprintf(
			'<section class="fh-about__section" aria-labelledby="about-process">%1$s<ol class="fh-about__steps">%2$s</ol>%3$s</section>',
			self::section_intro( $attributes, 'about-process' ),
			$steps,
			self::optional( '<div class="fh-about__process-links">%s</div>', $links, false )
		);
	}

	/**
	 * Who is behind it: the section's words, the team's one mailbox, said once, and a
	 * card for each member: the portrait the page holds, or initials, the name, and the
	 * biography as written. The mailbox is shown only if it's a team work address, as
	 * the import requires (rule 2), with the characters that would add to a `mailto:`
	 * link encoded.
	 *
	 * @param array<string, mixed> $attributes The block's attributes.
	 */
	public static function team( array $attributes ): string {
		$cards = '';
		foreach ( self::list_of( $attributes['members'] ?? null ) as $member ) {
			$name     = self::text( $member, 'name' );
			$photo    = is_int( $member['photoId'] ?? null ) ? $member['photoId'] : 0;
			$portrait = self::shows( $photo ) ? self::portrait( $photo, is_string( $member['photoAlt'] ?? null ) ? $member['photoAlt'] : '' ) : '';
			$bio      = '';
			foreach ( self::strings( $member['bio'] ?? null ) as $paragraph ) {
				$bio .= '<p>' . Markup::text( $paragraph ) . '</p>';
			}
			$cards .= sprintf(
				'<li class="fh-person fh-glass"><div class="fh-person__head"><div class="fh-person__portrait">%1$s</div><div class="fh-person__id"><h3 class="fh-person__name">%2$s</h3></div></div><div class="fh-person__bio">%3$s</div></li>',
				'' !== $portrait ? $portrait : '<span class="fh-person__monogram" aria-hidden="true">' . Markup::text( Format::initials( $name ) ) . '</span>',
				Markup::text( $name ),
				$bio
			);
		}

		$email   = Js::trim( self::text( $attributes, 'email' ) );
		$lead    = Js::trim( self::text( $attributes, 'emailLead' ) );
		$mailto  = Email_Shape::is_team_email( $email ) ? Markup::url( 'mailto:' . strtr( $email, self::MAILTO ) ) : '';
		$mailbox = '' !== $mailto
			? sprintf(
				'<p class="fh-about__section-body">%1$s<a class="fh-about__team-email" href="%2$s">%3$s</a></p>',
				'' !== $lead ? Markup::text( $lead ) . ' ' : '',
				$mailto,
				Markup::text( $email )
			)
			: '';

		return sprintf(
			'<section class="fh-about__section" aria-labelledby="about-team">%1$s<ul class="fh-about__team">%2$s</ul></section>',
			self::section_intro( $attributes, 'about-team', $mailbox ),
			$cards
		);
	}

	/**
	 * The closing band: its heading and body, and the Submit button.
	 *
	 * @param array<string, mixed> $attributes The block's attributes.
	 */
	public static function cta( array $attributes ): string {
		return sprintf(
			'<section class="fh-about__cta-section fh-container" aria-labelledby="about-cta"><div class="fh-about__cta fh-glass"><span class="fh-about__cta-glow" aria-hidden="true"></span><div><h2 id="about-cta" class="fh-about__cta-heading">%1$s</h2><p class="fh-about__cta-body">%2$s</p></div>%3$s</div></section>',
			Markup::text( self::text( $attributes, 'heading' ) ),
			Markup::text( self::text( $attributes, 'body' ) ),
			Page_Blocks::button( $attributes['cta'] ?? null, 'accent', 'lg', array( 'plus' ), array( 'block-mobile' ) )
		);
	}

	/**
	 * The Media Library images titled as these portraits, for the import to store: for
	 * each, the newest image with that Title, which WordPress sets from the file's name.
	 * The slug WordPress gives an upload can't be used: it has to differ from every other
	 * post's, and a page elsewhere on the site, such as a staff profile, may already have
	 * `stephanie-hoekstra`, making the upload `stephanie-hoekstra-2`. Newest by ID, which
	 * no one can set: an upload's date can be changed, so a date could put a planted
	 * image first. It runs only in the import's dry run, not as the page renders.
	 *
	 * @param string[] $names The members' `photo` names; empty ones are skipped.
	 * @return array<string, int> Each found image's ID, by the name it was found under.
	 */
	public static function portraits( array $names ): array {
		$found = array();
		foreach ( array_unique( $names ) as $name ) {
			if ( '' === $name ) {
				continue;
			}
			$images = get_posts(
				array(
					'post_type'              => 'attachment',
					'post_status'            => 'inherit',
					'post_mime_type'         => 'image',
					'title'                  => $name,
					'numberposts'            => 1,
					'orderby'                => 'ID',
					'order'                  => 'DESC',
					'update_post_term_cache' => false,
				)
			);
			if ( isset( $images[0] ) && $images[0] instanceof \WP_Post ) {
				$found[ $name ] = (int) $images[0]->ID;
			}
		}
		return $found;
	}

	/**
	 * Whether the page shows this image as a portrait: an image in the Media Library, in
	 * the status uploads have (the import looks for no other), uploaded on its own or to
	 * a post visitors may see without a password. One
	 * attached to a draft, private or password-protected post isn't shown, whatever the
	 * page's attributes say, so the page can't be used to show what WordPress keeps from
	 * visitors. is_post_publicly_viewable() checks the post's type and status, not its
	 * password, so that is checked here.
	 *
	 * @param int $id The image's ID.
	 */
	public static function shows( int $id ): bool {
		if ( $id <= 0 || ! wp_attachment_is_image( $id ) ) {
			return false;
		}
		$image = get_post( $id );
		if ( ! $image instanceof \WP_Post || 'inherit' !== $image->post_status ) {
			return false;
		}
		if ( 0 === (int) $image->post_parent ) {
			return true;
		}
		$parent = get_post( (int) $image->post_parent );
		return $parent instanceof \WP_Post && '' === $parent->post_password && is_post_publicly_viewable( $parent );
	}

	/**
	 * A portrait, as WordPress prints a Media Library image: its sizes offered for the
	 * width it's drawn at, loaded when it nears the window. Its alternative text is
	 * empty unless the member's `photoAlt` says otherwise, since the name follows it:
	 * describing the photo as the person would announce the name twice.
	 *
	 * @param int    $id  The image's ID.
	 * @param string $alt The alternative text.
	 */
	private static function portrait( int $id, string $alt ): string {
		return wp_get_attachment_image(
			$id,
			'medium',
			false,
			array(
				'class'    => 'fh-person__photo',
				'alt'      => $alt,
				'loading'  => 'lazy',
				'decoding' => 'async',
				'sizes'    => self::PORTRAIT_WIDTH,
			)
		);
	}

	/**
	 * A section's introduction: its eyebrow, its heading, which names the section, its
	 * body, and anything after them.
	 *
	 * @param array<string, mixed> $attributes The block's attributes.
	 * @param string               $id         The heading's ID, which the section names.
	 * @param string               $after      Markup after the body.
	 */
	private static function section_intro( array $attributes, string $id, string $after = '' ): string {
		return sprintf(
			'<div class="fh-about__section-intro">%1$s<h2 id="%2$s" class="fh-about__section-heading">%3$s</h2>%4$s%5$s</div>',
			self::optional( '<span class="fh-eyebrow">%s</span>', self::text( $attributes, 'eyebrow' ) ),
			esc_attr( $id ),
			Markup::text( self::text( $attributes, 'heading' ) ),
			self::optional( '<p class="fh-about__section-body">%s</p>', self::text( $attributes, 'body' ) ),
			$after
		);
	}

	/**
	 * Markup around some text, or nothing when the text is empty, as the site leaves out
	 * an empty eyebrow or body.
	 *
	 * @param string $format The markup, with `%s` where the text goes.
	 * @param string $text   The text, or markup already escaped.
	 * @param bool   $escape Whether the text is stored text to escape.
	 */
	private static function optional( string $format, string $text, bool $escape = true ): string {
		return '' !== $text ? sprintf( $format, $escape ? Markup::text( $text ) : $text ) : '';
	}

	/**
	 * A stored field that is text, or ''.
	 *
	 * @param array<string, mixed> $part The block's attributes, or one of its items.
	 * @param string               $name The field.
	 */
	private static function text( array $part, string $name ): string {
		return is_string( $part[ $name ] ?? null ) ? $part[ $name ] : '';
	}

	/**
	 * The items of a list that are objects, in order.
	 *
	 * @param mixed $list_value The list.
	 * @return list<array<string, mixed>>
	 */
	private static function list_of( mixed $list_value ): array {
		return is_array( $list_value ) ? array_values( array_filter( $list_value, 'is_array' ) ) : array();
	}

	/**
	 * The items of a list that are text, in order.
	 *
	 * @param mixed $list_value The list.
	 * @return list<string>
	 */
	private static function strings( mixed $list_value ): array {
		return is_array( $list_value ) ? array_values( array_filter( $list_value, 'is_string' ) ) : array();
	}
}
