<?php
/**
 * Every Firehouse page: the theme's header, the Firehouse wrapper, the theme's footer.
 *
 * The wrapper is the page's main landmark, outside the theme's content wrappers. Its
 * skip link sits at the top of the wrapper and leads past the Firehouse bar to the
 * page's content: the theme prints no wp_body_open hook, so nothing can go before its
 * header. Phase 4 fills the content; until then it holds a placeholder.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<main id="fh-main" class="fh-app">
	<a class="fh-skip-link" href="#fh-content"><?php esc_html_e( 'Skip to main content', 'firehouse' ); ?></a>
	<div id="fh-content" tabindex="-1">
		<?php \Firehouse\Frame::placeholder(); ?>
	</div>
</main>
<?php
get_footer();
