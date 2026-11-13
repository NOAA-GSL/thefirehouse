/**
 * The explorer with JavaScript: the site's `ProjectsPage` behaviour on the markup the
 * server printed (includes/class-explorer-page.php), through the Interactivity API.
 *
 * - Results, counts and the applied filters follow the search as it's typed (after a
 *   200ms pause, as on the site) and each select as it changes, with no request: the
 *   browser searches the projects in the page's state with the site's own search.ts
 *   (`@firehouse/search`, bundled by scripts/plugin-assets.mjs), so it lists what the
 *   site lists and what the server listed.
 * - The address follows the query, written as the site writes it, with
 *   `history.replaceState`, so any view is still a link to share.
 * - Escape clears the search; removing a filter or clearing them all happens in place,
 *   and focus moves somewhere sensible rather than to the page's top.
 * - On a phone the filters fold behind a "Filters and sort" button, which the server
 *   prints hidden, for a page without the script; the "Update results" button, which a
 *   page without the script needs, goes.
 *
 * Not ported: the site's "/" shortcut to the search. A one-key shortcut that can't be
 * turned off fails WCAG 2.1.4 (the Section 508 review, 13 November).
 *
 * Every derived value is worked out by the functions exported below, which take the
 * search module and the state, so tests run them without WordPress. A plain ES module,
 * with no build step: WordPress maps both imports (view.asset.php).
 */
import * as interactivity from '@wordpress/interactivity';
import * as search from '@firehouse/search';

const { getContext, getElement, store } = interactivity;

/**
 * Marks an action that calls preventDefault(), which WordPress then runs as the event
 * happens (6.8 and later warn without it, and later versions will refuse).
 * WordPress 6.7, which the plugin still supports, has no withSyncEvent() and runs
 * every action so.
 */
const withSyncEvent = interactivity.withSyncEvent ?? ( ( action ) => action );

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
 * The explorer's address for a query, as the site writes it, landing where the server's
 * links land.
 */
export function addressOf( lib, url, query, landing = '' ) {
	const string = lib.serializeProjectQuery( query ).toString();
	return url + ( string ? `?${ string }` : '' ) + landing;
}

/** The query with nothing but its sort, as Clear all leaves it. */
export const cleared = ( query ) => ( { q: '', phase: null, region: null, year: null, type: null, sort: query.sort } );

/** Each project the query finds, as its tile shows it. */
export function results( lib, data, query ) {
	return lib.filterProjects( data.projects, query ).map( ( project ) => ( {
		slug: project.slug,
		url: project.url,
		title: project.title,
		projectType: project.projectType || '',
		summary: project.summary,
		meta: `${ project.byline } · ${ project.completionYear }`,
		phases: project.firePhases
			.filter( ( phase ) => phase in data.short.phase )
			.map( ( phase, index ) => ( { key: `${ index }-${ phase }`, label: data.short.phase[ phase ] } ) ),
	} ) );
}

/** How many projects each value of a facet would leave, as the site counts them. */
export const countsOf = ( lib, data, query, facet ) => lib.facetCounts( data.projects, query, facet, valuesOf( lib, facet ) );

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
		const text = data.strings.filter[ facet ].replace( '%s', label[ facet ]( query[ facet ] ) );
		return {
			facet,
			label: text,
			name: data.strings.remove.replace( '%s', text ),
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

/** The results line's words after the count: "of 8 projects", or "projects". */
export function countRest( data, shown, total, filtered ) {
	const form = ( forms, n ) => forms[ n === 1 ? 0 : 1 ];
	return filtered ? form( data.strings.of, total ).replace( '%s', String( total ) ) : form( data.strings.count, shown );
}

/**
 * One query's results and counts, worked out once however many bindings read them.
 * Keyed by the query's fields, which every reader reads, so a change to any of them is
 * seen. The projects aren't in the key: they're the page's, and don't change once it
 * loads.
 */
let memo = { key: null };
function worked( data ) {
	const { q, phase, region, year, type, sort } = data.query;
	const key = JSON.stringify( [ q, phase, region, year, type, sort ] );
	if ( memo.key !== key ) {
		memo = { key, counts: {}, results: results( search, data, data.query ) };
	}
	return memo;
}
function counts( data, facet ) {
	const cache = worked( data );
	cache.counts[ facet ] ??= countsOf( search, data, data.query, facet );
	return cache.counts[ facet ];
}

/** The pending write of the typed text into the query. */
let timer = null;

const { state, actions } = store( 'firehouse/explorer', {
	state: {
		/** True only here: the server never sends it, so its bindings print as without the script. */
		get live() {
			return true;
		},
		filtersOpen: false,
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
		write() {
			window.history.replaceState( window.history.state, '', addressOf( search, state.url, state.query ) );
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
			event.preventDefault();
			state.text = '';
			actions.update( { q: '' } );
			document.getElementById( 'fh-explorer-q' )?.focus();
		} ),
		/**
		 * Removes one filter; focus goes to the filter that takes its place, or else to
		 * Clear all, or else to the search, rather than to the page's top with the link.
		 */
		removeFilter: withSyncEvent( function ( event ) {
			event.preventDefault();
			const { ref } = getElement();
			const list = ref.closest( 'ul' );
			const index = [ ...list.querySelectorAll( 'a.fh-pill' ) ].indexOf( ref );
			actions.update( { [ getContext().filter.facet ]: null } );
			setTimeout( () => {
				const next = list.querySelectorAll( 'a.fh-pill' )[ index ] ?? list.querySelector( '.fh-explorer__clear-all' );
				( next && ! list.hidden ? next : document.getElementById( 'fh-explorer-q' ) )?.focus();
			} );
		} ),
		clearAll: withSyncEvent( function ( event ) {
			event.preventDefault();
			clearTimeout( timer );
			state.text = '';
			state.query = cleared( state.query );
			actions.write();
			document.getElementById( 'fh-explorer-q' )?.focus();
		} ),
		toggleFilters() {
			state.filtersOpen = ! state.filtersOpen;
		},
	},
} );
