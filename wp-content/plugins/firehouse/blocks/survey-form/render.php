<?php
/**
 * Renders the survey form, `firehouse/survey-form`. Survey\Form builds the markup
 * from Survey\Survey::state().
 *
 * The Submit page holds it. On any other page, it renders nothing: a form sent from
 * anywhere else would never reach the handler, which acts only on the Submit page.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$firehouse_submit = Survey\Survey::page();
if ( null !== $firehouse_submit && is_page( $firehouse_submit->ID ) && Frame::is_firehouse_request() ) {
	echo Survey\Form::render( Survey\Survey::state() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Survey\Form escapes everything it prints.
}
