<?php
/**
 * The FireHouse guide in the dashboard: FireHouse → Help, and a Help tab on each
 * FireHouse screen.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows the guide in help/. Its Markdown pages are the source; scripts/plugin-help.mjs
 * builds them into help/help.json, and CI fails if that falls behind. FireHouse → Help
 * shows every page the user may read, with its screenshots. On a FireHouse screen, the
 * Help tab at the top right shows the pages that name that screen, without the
 * screenshots, and links to the Help page. Pages for administrators (installing,
 * importing, people) show only to those who may import. Nothing here writes anything.
 */
final class Help {

	/**
	 * The Help page's slug.
	 */
	public const PAGE = 'firehouse-help';

	/**
	 * Reading the guide: FireHouse Editors and administrators both have it.
	 */
	public const CAP = 'edit_fh_projects';

	/**
	 * The screens a page of the guide can name, as Help::screen_key() finds them.
	 * scripts/plugin-help.mjs has the same list, and scripts/plugin-help.test.mjs holds
	 * the two together.
	 */
	public const SCREENS = array( 'project-list', 'project-edit', 'topic-list', 'topic-edit', 'synthesis', 'settings', 'import' );

	/**
	 * The Help page's and the Help tabs' look, in WordPress's admin colours.
	 */
	private const STYLE = '.fh-help{max-width:52rem}'
		. '.fh-help p,.fh-help li{font-size:14px;line-height:1.6}'
		. '.fh-help__page{margin-top:2.5em;padding-top:.5em;border-top:1px solid #c3c4c7}'
		. '.fh-help ul{list-style:disc;margin-left:1.5em}'
		. '.fh-help__figure{margin:1.25em 0}'
		. '.fh-help__figure img{display:block;max-width:100%;height:auto;border:1px solid #c3c4c7}'
		. '.fh-help__figure figcaption{color:#50575e;font-size:13px;margin-top:.4em}'
		. '.fh-help__note{border-left:4px solid #72aee6;background:#fff;padding:0 1em;margin:1em 0}'
		. '.contextual-help-tabs-wrap .fh-help__note{background:transparent}';

	/**
	 * The guide's pages, as help.json has them, once read.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private static ?array $pages = null;

	/**
	 * Adds the Help page and the Help tabs.
	 */
	public static function hook(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ), 20 );
		add_action( 'current_screen', array( self::class, 'add_tabs' ) );
	}

	/**
	 * Adds FireHouse → Help, after the FireHouse menu's other screens.
	 */
	public static function add_page(): void {
		$hook = add_submenu_page(
			Post_Types::MENU,
			__( 'FireHouse help', 'firehouse' ),
			__( 'Help', 'firehouse' ),
			self::CAP,
			self::PAGE,
			array( self::class, 'render' )
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, array( self::class, 'style' ) );
		}
	}

	/**
	 * Which FireHouse screen this is, as a page of the guide names it, or "" for any
	 * other screen. The FireHouse menu's own screens are recognised by their slug, since
	 * WordPress opens them with the projects' post type in the address.
	 *
	 * @param string $id        The screen's ID.
	 * @param string $base      The kind of screen, such as "edit" for a list.
	 * @param string $post_type The post type it's for, or "".
	 */
	public static function screen_key( string $id, string $base, string $post_type ): string {
		$pages = array(
			Synthesis_Screen::PAGE => 'synthesis',
			Settings::PAGE         => 'settings',
			Import_Screen::PAGE    => 'import',
		);
		foreach ( $pages as $page => $key ) {
			if ( str_ends_with( $id, '_page_' . $page ) ) {
				return $key;
			}
		}
		$types = array(
			Post_Types::PROJECT => 'project',
			Post_Types::TOPIC   => 'topic',
		);
		$kinds = array(
			'edit' => 'list',
			'post' => 'edit',
		);
		if ( isset( $types[ $post_type ], $kinds[ $base ] ) ) {
			return $types[ $post_type ] . '-' . $kinds[ $base ];
		}
		return '';
	}

	/**
	 * Adds a Help tab for each page of the guide that names this screen, and a link to
	 * the whole guide beside them.
	 *
	 * @param \WP_Screen $screen The screen being shown.
	 */
	public static function add_tabs( \WP_Screen $screen ): void {
		$key = self::screen_key( (string) $screen->id, (string) $screen->base, (string) $screen->post_type );
		if ( '' === $key || ! current_user_can( self::CAP ) ) {
			return;
		}
		$pages = array_filter(
			self::pages(),
			static fn( array $page ): bool => in_array( $key, $page['screens'], true )
		);
		if ( array() === $pages ) {
			return;
		}
		foreach ( $pages as $page ) {
			$screen->add_help_tab(
				array(
					'id'      => 'firehouse-help-' . $page['slug'],
					'title'   => $page['title'],
					'content' => self::content( $page['tab'] ),
				)
			);
		}
		$screen->set_help_sidebar(
			'<p><strong>' . esc_html__( 'The FireHouse guide', 'firehouse' ) . '</strong></p>'
			. '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'The whole guide, with screenshots', 'firehouse' ) . '</a></p>'
		);
		self::style();
	}

	/**
	 * The pages this user may read: every page for those who may import, otherwise the
	 * editors' pages. None if help.json is missing or damaged.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function pages(): array {
		if ( null === self::$pages ) {
			$data        = wp_json_file_decode( dirname( __DIR__ ) . '/help/help.json', array( 'associative' => true ) );
			self::$pages = array_values(
				array_filter(
					is_array( $data ) && is_array( $data['pages'] ?? null ) ? $data['pages'] : array(),
					static fn( mixed $page ): bool => is_array( $page )
						&& is_string( $page['slug'] ?? null )
						&& is_string( $page['title'] ?? null )
						&& in_array( $page['audience'] ?? null, array( 'editors', 'administrators' ), true )
						&& is_array( $page['screens'] ?? null )
						&& is_string( $page['summary'] ?? null )
						&& is_string( $page['html'] ?? null )
						&& is_string( $page['tab'] ?? null )
				)
			);
		}
		$everything = current_user_can( Capabilities::IMPORT );
		return array_values(
			array_filter(
				self::$pages,
				static fn( array $page ): bool => $everything || 'editors' === $page['audience']
			)
		);
	}

	/**
	 * Forgets the pages read, so a test can read them again.
	 */
	public static function reset(): void {
		self::$pages = null;
	}

	/**
	 * A page's HTML with its images' and links' addresses filled in, and only the HTML
	 * a post may hold.
	 *
	 * @param string $html The HTML from help.json.
	 */
	public static function content( string $html ): string {
		return wp_kses_post(
			strtr(
				$html,
				array(
					'{{images}}' => esc_url( plugins_url( 'help/images', dirname( __DIR__ ) . '/firehouse.php' ) ),
					'{{guide}}'  => esc_url( self::url() ),
				)
			)
		);
	}

	/**
	 * The Help page's address.
	 */
	public static function url(): string {
		return admin_url( 'edit.php?post_type=' . Post_Types::PROJECT . '&page=' . self::PAGE );
	}

	/**
	 * Adds the guide's look to the screen.
	 */
	public static function style(): void {
		wp_add_inline_style( 'common', self::STYLE );
	}

	/**
	 * Draws FireHouse → Help: a list of the pages, then each page.
	 */
	public static function render(): void {
		$pages = self::pages();
		echo '<div class="wrap fh-help">';
		echo '<h1>' . esc_html__( 'FireHouse help', 'firehouse' ) . '</h1>';
		echo '<p>' . esc_html__( 'How to keep the FireHouse up to date. On each FireHouse screen, the Help tab at the top right shows the part of this guide for that screen.', 'firehouse' ) . '</p>';

		if ( array() === $pages ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The guide’s file, help/help.json, is missing or damaged. Installing the plugin again puts it back.', 'firehouse' ) . '</p></div></div>';
			return;
		}

		$groups = array(
			'editors'        => __( 'For FireHouse Editors', 'firehouse' ),
			'administrators' => __( 'For administrators', 'firehouse' ),
		);
		$shown  = array_unique( array_column( $pages, 'audience' ) );
		echo '<nav class="fh-help__contents" aria-labelledby="fh-help-contents"><h2 id="fh-help-contents">' . esc_html__( 'Contents', 'firehouse' ) . '</h2>';
		foreach ( $groups as $audience => $label ) {
			if ( ! in_array( $audience, $shown, true ) ) {
				continue;
			}
			if ( count( $shown ) > 1 ) {
				echo '<h3>' . esc_html( $label ) . '</h3>';
			}
			echo '<ul>';
			foreach ( $pages as $page ) {
				if ( $audience === $page['audience'] ) {
					printf(
						'<li><a href="#fh-help-%1$s">%2$s</a>: %3$s</li>',
						esc_attr( $page['slug'] ),
						esc_html( $page['title'] ),
						esc_html( $page['summary'] )
					);
				}
			}
			echo '</ul>';
		}
		echo '</nav>';

		foreach ( $pages as $page ) {
			printf(
				'<section class="fh-help__page" id="fh-help-%1$s" aria-labelledby="fh-help-%1$s-title"><h2 id="fh-help-%1$s-title">%2$s</h2>%3$s<p><a href="#fh-help-contents">%4$s</a></p></section>',
				esc_attr( $page['slug'] ),
				esc_html( $page['title'] ),
				self::content( $page['html'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post() in content().
				esc_html__( 'Back to the contents', 'firehouse' )
			);
		}
		echo '</div>';
	}
}
