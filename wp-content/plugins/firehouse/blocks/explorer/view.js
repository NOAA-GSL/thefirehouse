/**
 * The explorer with JavaScript: the site's `ProjectsPage` behaviour on the markup the
 * server printed (includes/class-explorer-page.php), through the Interactivity API.
 *
 * - Results, counts and the applied filters follow each select as it changes, and the
 *   search after a 200ms pause in typing, as on the site, with no request: the browser
 *   searches the projects in the page's state with the site's own search.ts
 *   (`@firehouse/search`, bundled by scripts/plugin-assets.mjs). So it lists what the
 *   site lists, and what the server listed wherever PHP has the intl extension
 *   (README.md, Search).
 * - The address follows the query, written as the site writes it, with
 *   `history.replaceState`, so any view is still a link to share.
 * - Escape clears the search. Clearing the search, removing a filter and clearing
 *   them all happen in place: clearing puts focus in the search; removing a filter puts
 *   it on the filter that takes its place, else Clear all, else the search. A click
 *   with a modifier key still opens the link, as a link's would.
 * - On a phone the filters fold behind a "Filters and sort" button, which the server
 *   prints hidden, for a page without the script; the "Update results" button, which a
 *   page without the script needs, goes.
 *
 * The region becomes interactive only here, when this script has loaded: the server
 * marks it `data-fh-interactive`, and this sets `data-wp-interactive`, before
 * WordPress takes the page over. If this script or the search fails to load, the
 * runtime, which other blocks load, leaves the region alone, and the page works as it
 * does without JavaScript, rather than drawn from an empty store. What the visitor
 * typed or chose before then is kept.
 *
 * Not ported: the site's "/" shortcut to the search. A one-key shortcut that can't be
 * turned off fails WCAG 2.1.4 (the Section 508 review, 13 November).
 *
 * Most derived values, and all those the shared cases cover, are worked out by the
 * functions exported below, which take the search module and the page's data as
 * arguments, so tests run them without WordPress. A plain ES module, with no build
 * step: WordPress maps both imports (view.asset.php).
 */
import * as interactivity from '@wordpress/interactivity';
import * as search from '@firehouse/search';

const { getContext, getElement, store } = interactivity;

/**
 * Marks an action that calls preventDefault(). WordPress added withSyncEvent() in 6.8,
 * warns (with SCRIPT_DEBUG on) about an action that needs it and lacks it, and plans to
 * run such actions after the event, too late to prevent it. 6.7, which the plugin
 * supports, has none and runs every action during the event, so there the actions pass
 * through as they are.
 */
const withSyncEvent = interactivity.withSyncEvent ?? ( ( action ) => action );

/** The explorer's store, and the region it takes over. */
export const STORE = 'firehouse/explorer';

/** How long typing pauses before the results and the address follow, as on the site. */
export const DEBOUNCE_MS = 200;

/** A query's facets, as search.ts names them. */
const FACETS = [ 'phase', 'region', 'year', 'type' ];

/** A project's values for one facet, as the explorer counts them (ProjectsPage.tsx). */
export function valuesOf( lib, facet ) {
	return {
		phase: ( project ) => project.firePhases,
		region: ( project ) => lib.effectiveRegions( project.geo?.regions ),
		year: ( project ) => [ project.completionYear ],
		type: ( project ) => ( project.projectType ? [ project.projectType ] : [] ),
	}[ facet ];
}

/** Whether there's text to search for: anything but spaces, as the site trims it. */
export const isSearching = ( query ) => query.q.trim().length > 0;

/** Whether a search or a filter is applied. */
export const isFiltered = ( lib, query ) => isSearching( query ) || lib.activeFacetCount( query ) > 0;

/**
 * A string with its "%s" replaced, as PHP's sprintf() replaces it: by a function, so a
 * "$&" or "$$" in a researcher's words is kept as written (rule 1), not read as a
 * pattern.
 */
export const fill = ( format, value ) => format.replace( '%s', () => String( value ) );

/**
 * The explorer's address for a query, as the site writes it, landing where the server's
 * links land.
 */
export function addressOf( lib, url, query, landing = '' ) {
	const string = lib.serializeProjectQuery( query ).toString();
	return url + ( string ? `?${ string }` : '' ) + landing;
}

/** The query with nothing but its sort, as Clear all leaves it. */
export const cleared = ( query ) => ( { q: '', phase: null, region: null, year: null, type: null, sort: query.sort } );

/** A project as its tile shows it. */
export function tileOf( data, project ) {
	return {
		slug: project.slug,
		url: project.url,
		title: project.title,
		projectType: project.projectType || '',
		summary: project.summary,
		meta: `${ project.byline } · ${ project.completionYear }`,
		phases: project.firePhases
			.filter( ( phase ) => Object.hasOwn( data.short.phase, phase ) )
			.map( ( phase, index ) => ( { key: `${ index }-${ phase }`, label: data.short.phase[ phase ] } ) ),
	};
}

/**
 * Each project the query finds, as its tile shows it. `scored` is the projects' scores
 * for the query's text (the search's scores()), which a page that also counts works out
 * once; without it, the search scores them. A tile's object is made once per project
 * and kept in `tiles`, so a tile the next query keeps keeps its object, and WordPress
 * redraws only the tiles that change.
 */
export function results( lib, data, query, scored, tiles = new Map() ) {
	return lib.filterProjects( data.projects, query, scored ).map( ( project ) => {
		if ( ! tiles.has( project.slug ) ) {
			tiles.set( project.slug, tileOf( data, project ) );
		}
		return tiles.get( project.slug );
	} );
}

/** How many projects each value of a facet would leave, as the site counts them. */
export const countsOf = ( lib, data, query, facet, scored ) => lib.facetCounts( data.projects, query, facet, valuesOf( lib, facet ), scored );

/** A facet's value as the query holds it, from an option's value. */
export const facetValue = ( facet, value ) => ( value === '' ? null : facet === 'year' ? Number( value ) : value );

/** The filters applied, each as its pill reads and links. */
export function applied( lib, data, query ) {
	const label = {
		phase: ( value ) => data.short.phase[ value ],
		region: ( value ) => data.short.region[ value ],
		year: ( value ) => String( value ),
		type: ( value ) => value,
	};
	return FACETS.filter( ( facet ) => query[ facet ] !== null ).map( ( facet ) => {
		const text = fill( data.strings.filter[ facet ], label[ facet ]( query[ facet ] ) );
		return {
			facet,
			label: text,
			name: fill( data.strings.remove, text ),
			href: addressOf( lib, data.url, { ...query, [ facet ]: null }, data.landing ),
		};
	} );
}

/**
 * The sort's options, as Explorer_Page::sort_options() works them out: "Best match"
 * only while there's text to match, and the option the page falls back to sending
 * what the query had.
 */
export function sortOptions( lib, data, query ) {
	const sort = lib.effectiveSort( query );
	const sent = query.sort === sort ? sort : query.sort ?? '';
	return Object.entries( data.sorts )
		.filter( ( [ key ] ) => key !== 'relevance' || isSearching( query ) )
		.map( ( [ key, label ] ) => ( { key, value: key === sort ? sent : key, label, selected: key === sort } ) );
}

/**
 * The results line's words after the count: "of 8 projects", or "projects". Two forms,
 * picked as English picks them; a language with other plural rules would need more.
 */
export function countRest( data, shown, total, filtered ) {
	const form = ( forms, n ) => forms[ n === 1 ? 0 : 1 ];
	return filtered ? fill( form( data.strings.of, total ), total ) : form( data.strings.count, shown );
}

/**
 * What the visitor changed in the page before the script loaded: the search box's text,
 * and each select whose chosen option isn't the one the server chose. Compared with
 * the server's own choices, not the query, so a filter the select can't show (a year no
 * project has) isn't taken for a change.
 */
export function changedBeforeLoad( doc ) {
	const changed = { patch: {} };
	const box = doc.getElementById( 'fh-explorer-q' );
	if ( box && box.value !== box.defaultValue ) {
		changed.text = box.value;
	}
	for ( const facet of [ ...FACETS, 'sort' ] ) {
		const select = doc.querySelector( `select[name="${ facet }"][form="fh-explorer-form"]` );
		const option = select?.options[ select.selectedIndex ];
		// With none of its options chosen by the server (a filter it can't show), a select
		// shows its first, and that's no change.
		const chosen = select ? [ ...select.options ].some( ( candidate ) => candidate.defaultSelected ) : false;
		const moved = chosen ? ! option?.defaultSelected : select?.selectedIndex > 0;
		if ( option && moved ) {
			changed.patch[ facet ] = facet === 'sort' ? option.value || null : facetValue( facet, option.value );
		}
	}
	return changed;
}

/**
 * One query's scores, results and counts, worked out once however many bindings read
 * them: each project is scored once, for the list and the four counts alike, as the
 * server does (Search::scores()). Keyed by the query's fields, which every reader reads,
 * so a change to any of them is seen. The projects aren't in the key, and the tiles'
 * objects are kept across queries: they're the page's, and don't change once it loads.
 */
let tiles = new Map();
let memo = { key: null };
function worked( data ) {
	const { q, phase, region, year, type, sort } = data.query;
	const key = JSON.stringify( [ q, phase, region, year, type, sort ] );
	if ( memo.key !== key ) {
		const scored = search.scores( data.projects, data.query.q );
		memo = { key, scored, counts: {}, results: results( search, data, data.query, scored, tiles ) };
	}
	return memo;
}
function counts( data, facet ) {
	const cache = worked( data );
	cache.counts[ facet ] ??= countsOf( search, data, data.query, facet, cache.scored );
	return cache.counts[ facet ];
}

/** Forgets the worked-out query and the tiles, for tests that change the projects. */
export function forget() {
	memo = { key: null };
	tiles = new Map();
}

/** The pending write of the typed text into the query. */
let timer = null;

/** Whether a click is a plain one, which the script follows in place; any other opens the link. */
const plain = ( event ) => ! ( event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey );

/** The search box, for focus. */
const searchBox = () => document.getElementById( 'fh-explorer-q' );

const browser = typeof document !== 'undefined';
const region = browser ? document.querySelector( `[data-fh-interactive="${ STORE }"]` ) : null;
const before = region ? changedBeforeLoad( document ) : { patch: {} };
// Where focus was in the explorer when the script loaded, so a list item that's moved as
// WordPress takes the page over (Clear all, after the filters' template) gets it back.
const focusedBefore = region?.contains( document.activeElement ) ? document.activeElement : null;

const { state, actions } = store( STORE, {
	state: {
		/** True only here: the server never sends it, so its bindings print as without the script. */
		get live() {
			return true;
		},
		// Left open when the visitor is already in the filters, or has moved down the
		// page, so folding them doesn't move what they're looking at.
		filtersOpen: Boolean( region && ( document.activeElement?.closest?.( '#fh-explorer-facets' ) || window.scrollY > 0 ) ),
		get facetsOpen() {
			return state.filtersOpen;
		},
		get searching() {
			return isSearching( state.query );
		},
		get facetCount() {
			return search.activeFacetCount( state.query );
		},
		get filtered() {
			return isFiltered( search, state.query );
		},
		get results() {
			return worked( state ).results;
		},
		get shown() {
			return state.results.length;
		},
		get hasResults() {
			return state.results.length > 0;
		},
		get countRest() {
			return countRest( state, state.shown, state.projects.length, state.filtered );
		},
		get quoted() {
			return `“${ state.query.q.trim() }”`;
		},
		get applied() {
			return applied( search, state, state.query );
		},
		get sortOptions() {
			return sortOptions( search, state, state.query );
		},
		get clearSearchHref() {
			return addressOf( search, state.url, { ...state.query, q: '' }, state.landing );
		},
		get clearAllHref() {
			return addressOf( search, state.url, cleared( state.query ), state.landing );
		},
		get facetSet() {
			return state.query[ getContext().facet ] !== null;
		},
		get optionCount() {
			const { facet, value } = getContext();
			return counts( state, facet ).get( facetValue( facet, value ) ) ?? 0;
		},
		get optionText() {
			return `${ getContext().label } (${ state.optionCount })`;
		},
		get optionSelected() {
			const { facet, value } = getContext();
			return state.query[ facet ] === facetValue( facet, value );
		},
		get optionDisabled() {
			return state.optionCount === 0 && ! state.optionSelected;
		},
	},
	actions: {
		/** The query, changed, with the typed text in it, and the address with it. */
		update( patch ) {
			clearTimeout( timer );
			state.query = { ...state.query, q: state.text, ...patch };
			actions.write();
		},
		/**
		 * Writes the query into the address, relative to the page, so the address keeps
		 * the origin and path the visitor came by.
		 */
		write() {
			const string = search.serializeProjectQuery( state.query ).toString();
			window.history.replaceState( window.history.state, '', string ? `?${ string }` : window.location.pathname );
		},
		type( event ) {
			state.text = event.target.value;
			clearTimeout( timer );
			timer = setTimeout( () => actions.update( {} ), DEBOUNCE_MS );
		},
		keydown: withSyncEvent( function ( event ) {
			if ( event.key === 'Escape' && state.text ) {
				event.preventDefault();
				state.text = '';
				clearTimeout( timer );
				timer = setTimeout( () => actions.update( {} ), DEBOUNCE_MS );
			}
		} ),
		/** Enter applies at once, rather than waiting out the pause. */
		submit: withSyncEvent( function ( event ) {
			event.preventDefault();
			actions.update( {} );
		} ),
		choose( event ) {
			const { facet } = getContext();
			actions.update( { [ facet ]: facetValue( facet, event.target.value ) } );
		},
		chooseSort( event ) {
			actions.update( { sort: event.target.value || null } );
		},
		clearSearch: withSyncEvent( function ( event ) {
			if ( ! plain( event ) ) {
				return;
			}
			event.preventDefault();
			state.text = '';
			searchBox()?.focus();
			actions.update( { q: '' } );
		} ),
		/**
		 * Removes one filter. Focus moves first, to the filter after it, which keeps its
		 * node since the list is keyed by facet, else to Clear all if a search or a
		 * filter remains, else to the search; so it never falls to the page with the
		 * removed link.
		 */
		removeFilter: withSyncEvent( function ( event ) {
			if ( ! plain( event ) ) {
				return;
			}
			event.preventDefault();
			const { ref } = getElement();
			const { facet } = getContext().filter;
			const list = ref.closest( 'ul' );
			const pills = [ ...list.querySelectorAll( 'a.fh-pill' ) ];
			const after = { ...state.query, q: state.text, [ facet ]: null };
			const next = pills[ pills.indexOf( ref ) + 1 ] ?? ( isFiltered( search, after ) ? list.querySelector( '.fh-explorer__clear-all' ) : searchBox() );
			next?.focus();
			actions.update( { [ facet ]: null } );
		} ),
		clearAll: withSyncEvent( function ( event ) {
			if ( ! plain( event ) ) {
				return;
			}
			event.preventDefault();
			clearTimeout( timer );
			state.text = '';
			searchBox()?.focus();
			state.query = cleared( state.query );
			actions.write();
		} ),
		toggleFilters() {
			state.filtersOpen = ! state.filtersOpen;
		},
	},
	callbacks: {
		/**
		 * Takes in what the visitor changed before the script loaded, once WordPress has
		 * the page's state; otherwise the page would put the server's choices back.
		 */
		adopt() {
			if ( before.text !== undefined ) {
				state.text = before.text;
			}
			if ( before.text !== undefined || Object.keys( before.patch ).length > 0 ) {
				actions.update( before.patch );
			}
			if ( focusedBefore?.isConnected && document.activeElement !== focusedBefore ) {
				focusedBefore.focus();
			}
		},
	},
} );

// The region is WordPress's to take over only now that its store is here.
region?.setAttribute( 'data-wp-interactive', STORE );
