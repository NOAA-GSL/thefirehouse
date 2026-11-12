<?php
/**
 * Every Firehouse page: the theme's header, the Firehouse wrapper, the theme's footer.
 *
 * The wrapper sits outside the theme's content wrappers and holds a skip link, the
 * Firehouse bar (the `firehouse/subnav` block) and the page's main landmark. The skip
 * link sits at the top of the wrapper and leads past the bar to the main landmark: the
 * theme prints no wp_body_open hook, so nothing can go before its header. The main
 * landmark holds the page's content, then the disclaimer every Firehouse page ends with.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<div class="fh-app">
	<a class="fh-skip-link" href="#fh-content"><?php esc_html_e( 'Skip to main content', 'firehouse' ); ?></a>
	<?php echo \Firehouse\Subnav::place(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the plugin's own block, which escapes what it prints. ?>
	<main id="fh-content" tabindex="-1">
		<?php
		\Firehouse\Frame::content();
		\Firehouse\Frame::disclaimer();
		?>
	</main>
</div>
<?php
get_footer();
