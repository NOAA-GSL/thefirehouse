<?php
/**
 * The Firehouse not-found page.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

use Firehouse\Domain\Vocabulary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a missing address under /firehouse/ shows: the site's `NotFoundPage`, rendered
 * by the `firehouse/not-found` block. It offers the hub's structure, not just an
 * apology: home, the explorer and the four topic areas.
 *
 * The requested address is echoed back, so a visitor can spot a typo and a broken
 * link can be reported by quoting it. It is attacker-chosen text, so it is escaped,
 * cut to 64 characters and set as a `code` chip, clearly a quoted address and not the
 * site's own words. WordPress answers with a real 404, so no `noindex` is needed.
 *
 * Any address under /firehouse/topics gets the topic-area wording, the bare one
 * included, since WordPress treats it the same with or without its trailing slash.
 */
final class Not_Found {

	/**
	 * How much of the requested address to echo back.
	 */
	private const MAX_PATH = 64;

	/**
	 * The page's markup, inside the frame's main landmark.
	 */
	public static function render(): string {
		global $wp;
		// WordPress slashes the request it parses, so o'brien would read o\'brien; and a
		// path that isn't valid UTF-8 would escape to nothing at all.
		$path      = wp_check_invalid_utf8( '/' . ( $wp instanceof \WP ? wp_unslash( $wp->request ) : '' ), true );
		$requested = mb_strlen( $path ) > self::MAX_PATH ? mb_substr( $path, 0, self::MAX_PATH ) . '…' : $path;
		$topic     = str_starts_with( $path . '/', '/' . Query::ROOT_PATH . '/topics/' );
		$root      = '/' . Query::ROOT_PATH . '/';

		ob_start();
		?>
<div class="fh-not-found fh-container">
	<div class="fh-not-found__inner">
		<span class="fh-eyebrow"><?php esc_html_e( 'Error 404', 'firehouse' ); ?></span>
		<h1 class="fh-not-found__heading"><?php echo $topic ? esc_html__( 'Topic area not found', 'firehouse' ) : esc_html__( 'Page not found', 'firehouse' ); ?></h1>
		<p class="fh-not-found__body"><?php echo $topic ? esc_html__( 'The Firehouse organizes its findings into four topic areas, and that isn’t one of them. The address may be mistyped, or the link may predate a change to the site.', 'firehouse' ) : esc_html__( 'That page doesn’t exist, or it may have moved. Everything on the hub is reachable from here.', 'firehouse' ); ?></p>
		<p class="fh-not-found__requested"><?php esc_html_e( 'Requested address', 'firehouse' ); ?> <code class="fh-not-found__path"><?php echo esc_html( $requested ); ?></code></p>
		<div class="fh-not-found__actions">
			<?php
			echo Markup::button( __( 'Back to The Firehouse', 'firehouse' ), home_url( $root ), 'primary', 'md', array( 1 => 'arrow-right' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup escapes what it builds.
			echo Markup::button( __( 'Explore projects', 'firehouse' ), home_url( $root . 'projects/' ), 'secondary' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above.
			?>
		</div>
		<nav class="fh-not-found__topics" aria-labelledby="not-found-topics">
			<h2 id="not-found-topics" class="fh-not-found__subheading"><?php esc_html_e( 'The four topic areas', 'firehouse' ); ?></h2>
			<ul class="fh-not-found__topic-list">
				<?php foreach ( Vocabulary::topic_areas() as $area ) : ?>
					<li><a class="fh-not-found__topic-link" href="<?php echo esc_url( home_url( $root . 'topics/' . $area['key'] . '/' ) ); ?>"><?php echo Markup::topic_tag( $area['key'], 'sm' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup escapes what it builds. ?><span class="fh-not-found__topic-label"><?php echo esc_html( $area['label'] ); ?></span><?php echo Icons::svg( 'arrow-right', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- one of the plugin's checked icons. ?></a></li>
				<?php endforeach; ?>
			</ul>
		</nav>
	</div>
</div>
		<?php
		return (string) ob_get_clean();
	}
}
