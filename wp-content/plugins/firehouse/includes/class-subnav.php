<?php
/**
 * The Firehouse bar.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Firehouse bar under the theme's header, on every Firehouse page: the wordmark,
 * the navigation links and the Submit a Finding button (WORDPRESS-PLAN.md, Look and
 * feel). The `firehouse/subnav` block renders it, and the frame places the block.
 *
 * It is the site's `SiteHeader` without the NOAA and GSL logos and the theme toggle,
 * which the theme's header already has. Its classes are the component's, so
 * firehouse.css styles it, and it is a plain `div`, not a `header`: the theme's header
 * is the page's banner. Below 1024px the links fold into a drawer that the Interactivity
 * API opens (blocks/subnav/view.js); without JavaScript, frame.css keeps them in the bar.
 *
 * The menu button is named "Firehouse menu", one name whether the drawer is open or
 * shut, where the site's header swaps "Open menu" and "Close menu": the theme's header
 * has a menu button of its own, and `aria-expanded` already says which state it's in.
 *
 * The logo is two images, one per theme, as the site's `BrandMark` draws it. Each
 * carries its size, so the wordmark doesn't shift when it arrives, and loads lazily, so
 * the one the theme hides waits until someone switches.
 */
final class Subnav {

	/**
	 * The drawer's ID, which the menu button controls. There is one bar per page.
	 */
	public const DRAWER_ID = 'fh-subnav-drawer';

	/**
	 * Whether the frame is placing the bar right now.
	 *
	 * @var bool
	 */
	private static bool $placing = false;

	/**
	 * The page's one bar, as the frame places it: the bar's block, rendered while the
	 * frame places it. Its block prints the bar only then (placing()), so a copy typed
	 * into a page's own content prints nothing, whenever and wherever that content
	 * renders, before the frame's bar or after it: a page has one bar, one menu button
	 * and one drawer ID, and it's always the frame's.
	 */
	public static function place(): string {
		self::$placing = true;
		try {
			return do_blocks( '<!-- wp:firehouse/subnav /-->' );
		} finally {
			self::$placing = false;
		}
	}

	/**
	 * Whether the frame is placing the bar right now, which is the only time its block
	 * prints it.
	 */
	public static function placing(): bool {
		return self::$placing;
	}

	/**
	 * The bar's links, in order: each one's label, its address under /firehouse/, and
	 * whether it is the current page's.
	 *
	 * @return list<array{label: string, url: string, current: bool}>
	 */
	public static function links(): array {
		$labels  = Settings::get()['navLabels'];
		$current = self::current();
		$links   = array();
		foreach ( Settings::NAV as $key => $route ) {
			$links[] = array(
				'label'   => $labels[ $key ],
				'url'     => home_url( '/' . Query::ROOT_PATH . rtrim( $route, '/' ) . '/' ),
				'current' => $key === $current,
			);
		}
		return $links;
	}

	/**
	 * Which link is the current page's, by key: `home` on the landing page, `projects`
	 * on the explorer and every project, and a page's own link on that page and any
	 * page under it, such as `about`. A topic or a missing address has none.
	 */
	public static function current(): string {
		if ( is_post_type_archive( Post_Types::PROJECT ) || is_singular( Post_Types::PROJECT ) ) {
			return 'projects';
		}
		if ( ! is_page() ) {
			return '';
		}
		$page = get_queried_object();
		if ( ! $page instanceof \WP_Post || ! Capabilities::in_tree( $page ) ) {
			return '';
		}
		if ( Capabilities::root_id() === $page->ID ) {
			return 'home';
		}
		$path = (string) get_page_uri( $page );
		foreach ( Settings::NAV as $key => $route ) {
			$under = Query::ROOT_PATH . rtrim( $route, '/' );
			if ( '/' !== $route && ( $under === $path || str_starts_with( $path, $under . '/' ) ) ) {
				return $key;
			}
		}
		return '';
	}

	/**
	 * The bar's markup.
	 */
	public static function render(): string {
		$settings = Settings::get();
		$site     = $settings['siteName'];
		$links    = self::links();
		$main     = dirname( __DIR__ ) . '/firehouse.php';
		/* translators: %s: the site's name, such as "The Firehouse". */
		$home_label = sprintf( __( '%s — home', 'firehouse' ), $site );

		ob_start();
		?>
<div class="fh-header" data-wp-interactive="firehouse/subnav" data-wp-context='{"open":false}' data-wp-on-document--keydown="actions.closeOnEscape" data-wp-on-window--pageshow="actions.closeOnRestore" data-wp-on--focusout="actions.closeOnFocusOut">
	<div class="fh-header__bar fh-container">
		<a href="<?php echo esc_url( home_url( '/' . Query::ROOT_PATH . '/' ) ); ?>" class="fh-header__lockup" aria-label="<?php echo esc_attr( $home_label ); ?>">
			<span class="fh-header__brand">
				<span class="fh-brand fh-brand--auto" style="height:32px">
					<img class="fh-brand__img fh-brand__img--light" src="<?php echo esc_url( plugins_url( 'assets/logos/firehouse-mark.png', $main ) ); ?>" width="94" height="96" loading="lazy" alt="">
					<img class="fh-brand__img fh-brand__img--dark" src="<?php echo esc_url( plugins_url( 'assets/logos/firehouse-mark-on-dark.png', $main ) ); ?>" width="94" height="96" loading="lazy" alt="">
				</span>
				<span class="fh-header__wordmark"><?php echo esc_html( $site ); ?></span>
			</span>
		</a>
		<nav class="fh-header__nav" aria-label="<?php esc_attr_e( 'Firehouse', 'firehouse' ); ?>">
			<?php self::print_links( $links ); ?>
		</nav>
		<div class="fh-header__actions">
			<?php if ( '' !== $settings['submitFormUrl'] ) : ?>
				<span class="fh-header__cta"><?php self::print_submit( $settings, 'sm' ); ?></span>
			<?php endif; ?>
			<button type="button" class="fh-header__icon-btn fh-header__menu-btn" aria-expanded="false" aria-controls="<?php echo esc_attr( self::DRAWER_ID ); ?>" data-wp-bind--aria-expanded="context.open" data-wp-on--click="actions.toggle">
				<span class="fh-header__menu-state" data-wp-bind--hidden="context.open"><?php echo Icons::svg( 'menu', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- one of the plugin's checked icons. ?></span>
				<span class="fh-header__menu-state" hidden data-wp-bind--hidden="!context.open"><?php echo Icons::svg( 'x', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above. ?></span>
				<span class="fh-visually-hidden"><?php esc_html_e( 'Firehouse menu', 'firehouse' ); ?></span>
			</button>
		</div>
	</div>
	<div id="<?php echo esc_attr( self::DRAWER_ID ); ?>" class="fh-header__drawer" hidden data-wp-bind--hidden="!context.open">
		<nav class="fh-container fh-header__drawer-nav" aria-label="<?php esc_attr_e( 'Firehouse', 'firehouse' ); ?>">
			<?php
			self::print_links( $links );
			if ( '' !== $settings['submitFormUrl'] ) {
				self::print_submit( $settings, 'md' );
			}
			?>
		</nav>
	</div>
</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Prints the links, the current page's marked as current.
	 *
	 * @param list<array{label: string, url: string, current: bool}> $links The links.
	 */
	private static function print_links( array $links ): void {
		foreach ( $links as $link ) {
			printf(
				'<a class="fh-header__link%1$s" href="%2$s"%3$s>%4$s</a>',
				$link['current'] ? ' fh-header__link--active' : '',
				esc_url( $link['url'] ),
				$link['current'] ? ' aria-current="page"' : '',
				esc_html( $link['label'] )
			);
		}
	}

	/**
	 * Prints the Submit a Finding button. An address on another site opens in a new
	 * tab and says so.
	 *
	 * @param array{submitLabel: string, submitFormUrl: string} $settings The settings.
	 * @param 'sm'|'md'                                         $size     The button's size.
	 */
	private static function print_submit( array $settings, string $size ): void {
		echo Markup::button( $settings['submitLabel'], $settings['submitFormUrl'], 'accent', $size, array( 'plus' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup escapes what it builds.
	}
}
