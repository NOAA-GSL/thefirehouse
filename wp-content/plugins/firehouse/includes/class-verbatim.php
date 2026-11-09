<?php
/**
 * Researchers' words, as they wrote them.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps WordPress from rewriting text on Firehouse pages (rule 1). WordPress curls
 * quotes and converts dashes (`wptexturize`), turns typed smileys into images
 * (`convert_smilies`) and recapitalizes its own name (`capital_P_dangit`) in titles,
 * content and the document title. Left on, a project page would quietly change what
 * a researcher submitted, in its title and in its `<title>`.
 *
 * It decides once WordPress has run the query, on `template_redirect`, before the page
 * prints anything.
 */
final class Verbatim {

	/**
	 * Where WordPress hooks `capital_P_dangit`, at priority 11.
	 */
	private const DANGIT = array( 'the_content', 'the_title', 'wp_title', 'document_title' );

	/**
	 * Hooks the switch.
	 */
	public static function hook(): void {
		add_action( 'template_redirect', array( self::class, 'keep' ) );
	}

	/**
	 * On a Firehouse page, turns the three rewrites off.
	 */
	public static function keep(): void {
		if ( ! Frame::is_firehouse_request() ) {
			return;
		}
		add_filter( 'run_wptexturize', '__return_false' );
		// wptexturize() reads that switch once a request and keeps the answer, so it is
		// asked again, in case anything texturized before now.
		wptexturize( ' ', true );
		add_filter( 'pre_option_use_smilies', '__return_zero' );
		foreach ( self::DANGIT as $hook ) {
			remove_filter( $hook, 'capital_P_dangit', 11 );
		}
	}
}
