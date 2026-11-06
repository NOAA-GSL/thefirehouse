/**
 * The Firehouse bar's menu, below 1024px: the button opens and closes the drawer, and
 * Escape closes it, as the site's `SiteHeader` does. The bar's markup carries the state
 * (`data-wp-context`) and binds `aria-expanded`, the icon, the button's name and the
 * drawer's `hidden` to it (includes/class-subnav.php).
 *
 * A plain ES module, with no build step: WordPress maps the import (view.asset.php).
 */
import { getContext, getElement, store } from '@wordpress/interactivity';

store( 'firehouse/subnav', {
	actions: {
		toggle() {
			const context = getContext();
			context.open = !context.open;
		},
		closeOnEscape( event ) {
			const context = getContext();
			if ( event.key !== 'Escape' || !context.open ) {
				return;
			}
			// Focus inside the drawer would fall to <body> when it hides, so it goes back
			// to the button that opened it.
			const { ref } = getElement();
			if ( ref.querySelector( '.fh-header__drawer' )?.contains( ref.ownerDocument.activeElement ) ) {
				ref.querySelector( '.fh-header__menu-btn' )?.focus();
			}
			context.open = false;
		},
	},
} );
