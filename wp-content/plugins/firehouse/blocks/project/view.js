/**
 * The project page's need cards, as the site's `NeedCard` measures them. A card's body
 * has a cap on its height from 768px up, and verbatim survey text runs anywhere from
 * one line to several hundred words, so a long entry scrolls. A region that scrolls
 * has to be reachable by keyboard, so it becomes a tab stop, and then it needs a role
 * and a name; and it has to say that it scrolls, which the edge shadows do. A card
 * whose text fits gets none of that.
 *
 * Each card's markup carries its state (`data-wp-context`: which edges have more to
 * scroll to, null until it's measured; whether it has focus; and the name it takes if
 * it scrolls) and binds `data-overflow`, `tabindex`, `role` and `aria-label` to it
 * (includes/class-project-page.php). It's measured once it loads, as it scrolls, and
 * as it or its text changes size: the cap follows the window's height and the text
 * reflows, so a card that fits at one width can overflow at another.
 *
 * A card that has focus keeps its tab stop, role and name until focus leaves, even if
 * it stops scrolling (a reader zooming in can lift the cap): taking them away would
 * drop focus to the page.
 *
 * The cap holds only once a card has its `data-overflow` (assets/css/frame.css), so a
 * card this script never measures shows its whole text rather than scrolling it out of
 * a keyboard's reach.
 *
 * A plain ES module, with no build step: WordPress maps the import (view.asset.php).
 */
import { getContext, getElement, store, withScope } from '@wordpress/interactivity';

/** Whether a card is a tab stop: it scrolls (measured, and not `none`), or has focus. */
const isStop = () => {
	const context = getContext();
	return context.focused || [ 'top', 'bottom', 'both' ].includes( context.overflow );
};

store( 'firehouse/project', {
	state: {
		get tabIndex() {
			return isStop() ? 0 : null;
		},
		get role() {
			return isStop() ? 'group' : null;
		},
		get label() {
			return isStop() ? getContext().label : null;
		},
	},
	actions: {
		focus() {
			getContext().focused = true;
		},
		blur() {
			getContext().focused = false;
		},
	},
	callbacks: {
		measure() {
			const context = getContext();
			const { ref } = getElement();
			const update = withScope( () => {
				// 2px of slack: fractional layout sizes make an exactly-fitting box report a
				// scrollHeight a hair over its clientHeight, which would add a phantom tab stop.
				if ( ref.scrollHeight - ref.clientHeight <= 2 ) {
					context.overflow = 'none';
					return;
				}
				const atTop = ref.scrollTop <= 1;
				const atBottom = ref.scrollHeight - ref.scrollTop - ref.clientHeight <= 1;
				context.overflow = atTop ? 'bottom' : atBottom ? 'top' : 'both';
			} );

			update();
			ref.addEventListener( 'scroll', update, { passive: true } );
			// The halves are watched too: a card held at its cap keeps its size while its
			// text grows (a web font arriving, or the reader's text spacing), and only
			// they change.
			const observer = new ResizeObserver( update );
			observer.observe( ref );
			for ( const half of ref.children ) {
				observer.observe( half );
			}
			return () => {
				ref.removeEventListener( 'scroll', update );
				observer.disconnect();
			};
		},
	},
} );
