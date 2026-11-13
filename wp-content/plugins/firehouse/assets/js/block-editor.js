/**
 * The FireHouse blocks in the block editor, read-only until Phase 6 lets editors change
 * their words (WORDPRESS-PLAN.md). Each block shows the words the page stores in it, so
 * they can be told from what the plugin prints on the site; a block that stores none
 * says the plugin prints it. A block that holds others (the hero's counts, the About
 * page's sections) shows them after it, locked.
 *
 * Plain JavaScript with no build step, like the blocks' view scripts: WordPress's own
 * packages come from the `wp` global the editor loads, and the blocks' names from
 * `window.firehouseBlocks` (includes/class-blocks.php). The blocks' titles and stored
 * fields come from their block.json, which WordPress hands the editor.
 *
 * Saving keeps the page as stored: a block that holds no others saves nothing, since
 * its render.php prints it, and one that holds others saves only them.
 */
( function ( wp, names ) {
	if ( ! wp || ! wp.blocks || ! Array.isArray( names ) ) {
		return;
	}
	const { registerBlockType, getBlockType } = wp.blocks;
	const { createElement: el } = wp.element;
	const { useBlockProps, InnerBlocks } = wp.blockEditor;
	const { Placeholder } = wp.components;
	const { __ } = wp.i18n;

	// Attributes WordPress adds to every block, which hold none of the page's words.
	const WORDPRESS_OWN = [ 'lock', 'metadata', 'className', 'style', 'anchor', 'align' ];

	// Names for stored fields whose keys read badly as words.
	const LABELS = {
		cta: __( 'Button', 'firehouse' ),
		primaryCta: __( 'First button', 'firehouse' ),
		secondaryCta: __( 'Second button', 'firehouse' ),
		href: __( 'Link', 'firehouse' ),
		to: __( 'Link on this site', 'firehouse' ),
		emailLead: __( 'Before the email address', 'firehouse' ),
		needsPerCard: __( 'Needs shown per topic', 'firehouse' ),
	};

	/** A stored field's name for people: its label, or its key as words. */
	function label( key ) {
		if ( LABELS[ key ] ) {
			return LABELS[ key ];
		}
		const words = key.replace( /([a-z0-9])([A-Z])/g, '$1 $2' ).toLowerCase();
		return words.charAt( 0 ).toUpperCase() + words.slice( 1 );
	}

	/** A stored value as text: a list for a list, its fields for an object. */
	function value( stored ) {
		if ( Array.isArray( stored ) ) {
			return stored.length
				? el( 'ol', { style: { margin: '0 0 0 1.25em', padding: 0 } }, stored.map( ( item, index ) => el( 'li', { key: index }, value( item ) ) ) )
				: __( '(none)', 'firehouse' );
		}
		if ( stored && 'object' === typeof stored ) {
			return fields( stored );
		}
		if ( '' === stored || null === stored || undefined === stored ) {
			return __( '(empty)', 'firehouse' );
		}
		return String( stored );
	}

	/** An object's fields as a description list. */
	function fields( object ) {
		return el(
			'dl',
			{ style: { margin: 0, width: '100%' } },
			Object.keys( object ).flatMap( ( key ) => [
				el( 'dt', { key: key + '-term', style: { fontWeight: 600, marginTop: '0.5em' } }, label( key ) ),
				el( 'dd', { key: key + '-value', style: { margin: 0, whiteSpace: 'pre-wrap' } }, value( object[ key ] ) ),
			] )
		);
	}

	/** Whether the block holds others, as its block.json's allowedBlocks says. */
	function holdsOthers( name ) {
		const type = getBlockType( name );
		return !! ( type && Array.isArray( type.allowedBlocks ) && type.allowedBlocks.length );
	}

	function edit( name ) {
		return function FireHouseBlockEdit( { attributes } ) {
			const blockProps = useBlockProps();
			const type = getBlockType( name );
			const keys = Object.keys( type.attributes || {} ).filter( ( key ) => ! WORDPRESS_OWN.includes( key ) );
			const holds = holdsOthers( name );
			let instructions = __( 'Printed by the plugin. Nothing in it is stored in this page.', 'firehouse' );
			if ( keys.length ) {
				instructions = __( 'The words this page stores here. They can’t be changed here yet. Everything else in this part of the page is printed by the plugin.', 'firehouse' );
			} else if ( holds ) {
				instructions = __( 'Holds the parts below. Nothing else in it is stored in this page.', 'firehouse' );
			}
			const stored = {};
			keys.forEach( ( key ) => {
				stored[ key ] = attributes[ key ];
			} );
			return el(
				'div',
				blockProps,
				el( Placeholder, { label: type.title, instructions }, keys.length ? fields( stored ) : null ),
				holds ? el( InnerBlocks, { templateLock: 'all', renderAppender: false } ) : null
			);
		};
	}

	function save( name ) {
		return function FireHouseBlockSave() {
			return holdsOthers( name ) ? el( InnerBlocks.Content ) : null;
		};
	}

	names.forEach( ( name ) => {
		registerBlockType( name, { edit: edit( name ), save: save( name ) } );
	} );
} )( window.wp, window.firehouseBlocks );
