/**
 * The coverage map with JavaScript: the site's `RegionMap` behaviour on the markup the
 * server printed (includes/class-coverage-map.php), through the Interactivity API.
 *
 * - A choice in the region list, or a click on a region's shape, changes the page in
 *   place: the buttons' pressed states, the results and their heading, and the address,
 *   written as the site writes it (`?region=` and the key, or nothing) with
 *   `history.replaceState`, so any view is still a link to share.
 * - The status line says what a choice changed, "4 projects in Great Basin", and only
 *   that, from the heading's own words. It says nothing for the region the page opened
 *   with, or once none is selected: the button used says so itself.
 * - Clear disappears once none is selected, so focus moves to "All submissions", then
 *   the pressed button, once the page shows it pressed, rather than falling back to the
 *   top of the page.
 * - The map is the site's: the `@firehouse/coverage-map` module, its mapCanvas.ts and
 *   Leaflet bundled by scripts/plugin-assets.mjs, which this imports once the page is
 *   up, so the list works while it loads. The map follows the selection, the GACC
 *   labels toggle and GSL's light/dark switch (`data-theme` on the page). If the
 *   module can't load, the stage and credit go and the list works as before.
 *
 * The region becomes interactive only here, when this script has loaded: the server
 * marks it `data-fh-interactive`, and this sets `data-wp-interactive`, before WordPress
 * takes the page over. If this script fails to load, the runtime leaves the region
 * alone, and the page works as it does without JavaScript, but for the map's empty
 * stage, which frame.css shows whenever scripting is on.
 *
 * The derived values are worked out by the functions exported below, which take the
 * page's data as arguments, so tests run them without WordPress. A plain ES module,
 * with no build step: WordPress maps its imports (view.asset.php).
 */
import * as interactivity from '@wordpress/interactivity';

const { getContext, getElement, store } = interactivity;

/** As in the explorer's view script: preventDefault() needs it from WordPress 6.8. */
const withSyncEvent = interactivity.withSyncEvent ?? ( ( action ) => action );

/** The coverage map's store, and the region it takes over. */
export const STORE = 'firehouse/coverage-map';

/** The map's module, which WordPress maps (Coverage_Map::MAP_MODULE). */
export const MAP_MODULE = '@firehouse/coverage-map';

/** One of a count's two forms, as English picks: the first for one, the second otherwise. */
export const form = ( pair, count ) => pair[ count === 1 ? 0 : 1 ];

/** The projects filed under a region, in the page's order; none when none is selected. */
export const shownIn = ( projects, selected ) => ( selected === null ? [] : projects.filter( ( project ) => project.regions.includes( selected ) ) );

/**
 * The results' heading as two pieces of text around the region's name: its format, the
 * form for the count, split at `%2$s`, the count put in for `%1$d` wherever it falls.
 * Coverage_Map::heading() splits it the same way.
 */
export function heading( strings, count ) {
	const [ before, after = '' ] = form( strings.heading, count ).split( '%2$s' );
	const fill = ( text ) => text.replaceAll( '%1$d', String( count ) );
	return [ fill( before ), fill( after ) ];
}

/** What the status line says once a region is chosen: the heading, as one line of text. */
export function announcement( data, selected ) {
	if ( selected === null ) {
		return '';
	}
	const [ before, after ] = heading( data.strings, shownIn( data.projects, selected ).length );
	return `${ before }${ data.names[ selected ] }${ after }`;
}

/** A region's tooltip line on the map: "4 submissions". */
export const submissions = ( strings, count ) => form( strings.submissions, count ).replace( '%d', String( count ) );

/** The page's address with a region selected, or none, relative to its path. */
export const addressOf = ( path, selected ) => path + ( selected === null ? '' : `?region=${ encodeURIComponent( selected ) }` );

/** The theme GSL's switch has the page in. */
export const themeOf = ( root ) => ( root.getAttribute( 'data-theme' ) === 'dark' ? 'dark' : 'light' );

const browser = typeof document !== 'undefined';
const region = browser ? document.querySelector( `[data-fh-interactive="${ STORE }"]` ) : null;

/** The map, once its module has loaded and drawn it. */
let canvas = null;

const { state, actions } = store( STORE, {
	state: {
		showCodes: true,
		tilesFailed: false,
		failed: false,
		announced: '',
		get none() {
			return state.selected === null;
		},
		get shown() {
			return shownIn( state.projects, state.selected );
		},
		get empty() {
			return state.selected !== null && state.shown.length === 0;
		},
		get before() {
			return heading( state.strings, state.shown.length )[ 0 ];
		},
		get name() {
			return state.selected === null ? '' : state.names[ state.selected ];
		},
		get after() {
			return heading( state.strings, state.shown.length )[ 1 ];
		},
		get pressed() {
			return getContext().key === state.selected;
		},
		get tagged() {
			return getContext().project.national && state.selected !== 'NATIONAL';
		},
	},
	actions: {
		/** Selects a region, or none, everywhere it shows: the page, the address and the map. */
		choose( key ) {
			state.selected = key;
			state.announced = announcement( state, key );
			window.history.replaceState( window.history.state, '', addressOf( window.location.pathname, key ) );
			canvas?.update( { selected: key } );
		},
		/** A region's button: selects it, or none if it's selected; "All submissions" selects none. */
		select: withSyncEvent( function ( event ) {
			event.preventDefault();
			const { key } = getContext();
			actions.choose( key === state.selected ? null : key );
		} ),
		clear: withSyncEvent( function ( event ) {
			event.preventDefault();
			actions.choose( null );
			// A frame later, once WordPress shows "All submissions" pressed and Clear gone.
			const all = region?.querySelector( '.fh-map__list .fh-map__region' );
			window.requestAnimationFrame( () => all?.focus() );
		} ),
		toggleCodes() {
			state.showCodes = ! state.showCodes;
			canvas?.update( { showCodes: state.showCodes } );
		},
	},
	callbacks: {
		/** Draws the map into its canvas once its module loads, and keeps it following the theme. */
		async draw() {
			const host = getElement().ref;
			const root = document.documentElement;
			try {
				const { drawMap } = await import( MAP_MODULE );
				canvas = drawMap( host, {
					counts: state.counts,
					theme: themeOf( root ),
					selected: state.selected,
					showCodes: state.showCodes,
					submissions: ( count ) => submissions( state.strings, count ),
					onSelect: ( key ) => actions.choose( key ),
					onTiles: ( failed ) => {
						state.tilesFailed = failed;
					},
				} );
			} catch {
				state.failed = true;
				return;
			}
			new window.MutationObserver( () => canvas.update( { theme: themeOf( root ) } ) ).observe( root, { attributes: true, attributeFilter: [ 'data-theme' ] } );
		},
	},
} );

// The region is WordPress's to take over only now that its store is here.
region?.setAttribute( 'data-wp-interactive', STORE );
