/**
 * The FireHouse blocks in the block editor, read-only until Phase 6 lets editors change
 * what they store (WORDPRESS-PLAN.md). Each block lists what the page stores in it (its
 * words, and the links, icons, portraits and counts' settings that go with them), so
 * that can be told from what FireHouse adds on the site. A block that stores nothing
 * and holds no others says FireHouse adds it. A block that holds others (the hero holds
 * the live counts; the About sections block holds the About page's four middle
 * sections) shows them after it, indented and locked.
 *
 * Plain JavaScript with no build step, like the blocks' view scripts: WordPress's own
 * packages come from the `wp` global the editor loads. `window.firehouseBlocks` maps
 * each block's name to the attributes its block.json declares, which are the fields it
 * shows (includes/class-blocks.php); its title comes from block.json through WordPress.
 *
 * Every block saves its inner blocks and nothing else, since its render.php prints it:
 * one that holds none saves the same self-closing comment it was stored as, and one
 * that holds others can't lose them, whatever its block.json declares.
 */
( function ( wp, blocks ) {
	if ( ! wp || ! wp.blocks || ! blocks || 'object' !== typeof blocks ) {
		return;
	}
	const { registerBlockType, getBlockType } = wp.blocks;
	const { createElement: el } = wp.element;
	const { useBlockProps, InnerBlocks } = wp.blockEditor;
	const { Placeholder } = wp.components;
	const { __ } = wp.i18n;

	// Names for stored fields whose keys read badly as words.
	const LABELS = {
		eyebrow: __( 'Line above the heading', 'firehouse' ),
		items: __( 'Entries', 'firehouse' ),
		cta: __( 'Button', 'firehouse' ),
		primaryCta: __( 'First button', 'firehouse' ),
		secondaryCta: __( 'Second button', 'firehouse' ),
		href: __( 'Link', 'firehouse' ),
		to: __( 'FireHouse page', 'firehouse' ),
		emailLead: __( 'Words before the email address', 'firehouse' ),
		needsPerCard: __( 'Needs shown per topic area', 'firehouse' ),
		photo: __( 'Portrait', 'firehouse' ),
		photoAlt: __( 'Portrait description', 'firehouse' ),
		photoId: __( 'Portrait (Media Library ID)', 'firehouse' ),
		source: __( 'Counted from', 'firehouse' ),
		accent: __( 'Highlighted', 'firehouse' ),
	};
	const NOTHING = __( '(nothing stored)', 'firehouse' );
	const BORDER = '2px solid #949494';

	/** A stored field's name for people: its label, or its key as words. */
	function label( key ) {
		if ( Object.prototype.hasOwnProperty.call( LABELS, key ) ) {
			return LABELS[ key ];
		}
		const words = String( key ).replace( /([a-z0-9])([A-Z])/g, '$1 $2' ).toLowerCase();
		return words.charAt( 0 ).toUpperCase() + words.slice( 1 );
	}

	/** A stored value as text: paragraphs for a list of words, a numbered list of others. */
	function value( stored ) {
		if ( Array.isArray( stored ) ) {
			if ( ! stored.length ) {
				return NOTHING;
			}
			if ( stored.every( ( item ) => 'string' === typeof item ) ) {
				return stored.map( ( item, index ) => el( 'p', { key: index, style: { margin: '0 0 0.5em' } }, item || NOTHING ) );
			}
			return el( 'ol', { style: { margin: '0 0 0 1.25em', padding: 0 } }, stored.map( ( item, index ) => el( 'li', { key: index }, value( item ) ) ) );
		}
		if ( stored && 'object' === typeof stored ) {
			return Object.keys( stored ).length ? fields( stored, true ) : NOTHING;
		}
		if ( true === stored || false === stored ) {
			return stored ? __( 'Yes', 'firehouse' ) : __( 'No', 'firehouse' );
		}
		if ( '' === stored || null === stored || undefined === stored ) {
			return NOTHING;
		}
		return String( stored );
	}

	/**
	 * An object's fields as a description list, indented when it sits inside another. A
	 * link stored with no FireHouse page and no address goes to the Submit a Finding
	 * address in the settings (includes/class-page-blocks.php), so it says so.
	 */
	function fields( object, nested ) {
		const style = nested ? { margin: 0, paddingLeft: '0.75em', borderLeft: BORDER } : { margin: 0, width: '100%' };
		return el(
			'dl',
			{ style },
			Object.keys( object ).flatMap( ( key ) => {
				let shown = value( object[ key ] );
				if ( 'href' === key && '' === object.href && ! object.to ) {
					shown = __( 'The Submit a Finding address in FireHouse → Settings', 'firehouse' );
				}
				return [
					el( 'dt', { key: key + '-term', style: { fontWeight: 600, marginTop: '0.5em' } }, label( key ) ),
					el( 'dd', { key: key + '-value', style: { margin: 0, whiteSpace: 'pre-wrap' } }, shown ),
				];
			} )
		);
	}

	/** Whether the block holds others, as its block.json's allowedBlocks says. */
	function holdsOthers( name ) {
		const type = getBlockType( name );
		return !! ( type && Array.isArray( type.allowedBlocks ) && type.allowedBlocks.length );
	}

	/** What the box says about the block: what it stores, what it holds, what FireHouse adds. */
	function explanation( stores, holds ) {
		if ( stores && holds ) {
			return __( 'This page stores what’s listed here, and the parts below store their own. FireHouse adds the rest of this part of the page. Changing them comes in a later update.', 'firehouse' );
		}
		if ( stores ) {
			return __( 'This page stores what’s listed here. FireHouse adds the rest of this part of the page. Changing them comes in a later update.', 'firehouse' );
		}
		if ( holds ) {
			return __( 'Holds the parts below, which store their own. Nothing else in it is stored in this page.', 'firehouse' );
		}
		return __( 'FireHouse adds this part of the page. Nothing in it is stored in this page.', 'firehouse' );
	}

	function edit( name, keys ) {
		return function FireHouseBlockEdit( { attributes } ) {
			const blockProps = useBlockProps();
			const holds = holdsOthers( name );
			const stored = {};
			keys.forEach( ( key ) => {
				stored[ key ] = attributes[ key ];
			} );
			// The explanation is a paragraph, not the Placeholder's instructions: WordPress
			// hides those in a narrow box and reads them aloud when the box appears.
			return el(
				'div',
				blockProps,
				el(
					Placeholder,
					{ label: getBlockType( name ).title },
					el( 'p', { style: { margin: 0, width: '100%' } }, explanation( keys.length > 0, holds ) ),
					keys.length ? fields( stored, false ) : null
				),
				holds ? el( 'div', { style: { marginLeft: '1.5em', paddingLeft: '0.75em', borderLeft: BORDER } }, el( InnerBlocks, { templateLock: 'all', renderAppender: false } ) ) : null
			);
		};
	}

	function save() {
		return el( InnerBlocks.Content );
	}

	Object.keys( blocks ).forEach( ( name ) => {
		const keys = Array.isArray( blocks[ name ] ) ? blocks[ name ].map( String ) : [];
		registerBlockType( name, { edit: edit( name, keys ), save } );
	} );
} )( window.wp, window.firehouseBlocks );
