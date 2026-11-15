/**
 * The FireHouse blocks in the block editor. The landing and About pages' blocks show
 * their words as text fields in place, which an editor changes; the layout, links,
 * counts and portraits stay as they are (WORDPRESS-PLAN.md, Phase 6). Each block's
 * fields are in FIELDS below. A block not there lists what the page stores in it,
 * read-only. A block that stores nothing and holds no others says FireHouse adds it. A
 * block that holds others (the hero holds the live counts; the About sections block
 * holds the About page's four middle sections) shows them after it, indented and
 * locked.
 *
 * Words are kept as typed: plain text, which the pages print escaped
 * (includes/class-page-words.php keeps them past WordPress's filters). A heading, a
 * button's text and the like can't be left empty: while one is, the block says which,
 * the editor says so, and the page can't be saved.
 *
 * Plain JavaScript with no build step, like the blocks' view scripts: WordPress's own
 * packages come from the `wp` global the editor loads. `window.firehouseBlocks` maps
 * each block's name to the attributes its block.json declares (includes/class-blocks.php);
 * its title comes from block.json through WordPress.
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
	const { createElement: el, useEffect } = wp.element;
	const { useBlockProps, InnerBlocks } = wp.blockEditor;
	const { Placeholder, TextControl, TextareaControl } = wp.components;
	const { __, sprintf } = wp.i18n;

	/** A one-line field; `required` ones can't be left empty. */
	const text = ( key, label, options ) => ( { kind: 'text', key, label, ...( options || {} ) } );
	/** A field of a few lines. */
	const area = ( key, label, options ) => ( { kind: 'area', key, label, ...( options || {} ) } );
	/** A button or link: its text, which can't be empty, and where it goes, shown. */
	const button = ( key, label ) => ( { kind: 'button', key, label } );
	/** Paragraphs, each a field, as many as stored. */
	const paragraphs = ( key, label ) => ( { kind: 'paragraphs', key, label } );
	/** Entries, as many as stored, each with its fields; `item` names one, with its number. */
	const list = ( key, label, item, fields ) => ( { kind: 'list', key, label, item, fields } );
	/** Something stored that isn't words, shown as it is. */
	const fixed = ( key, label ) => ( { kind: 'fixed', key, label } );

	const EYEBROW = text( 'eyebrow', __( 'Line above the heading', 'firehouse' ) );
	const HEADING = text( 'heading', __( 'Heading', 'firehouse' ), { required: true } );
	const BODY = area( 'body', __( 'Text', 'firehouse' ) );

	/* translators: %d: the number of a count among the hero's counts. */
	const COUNT = __( 'Count %d', 'firehouse' );
	/* translators: %d: the number of a paragraph. */
	const PARAGRAPH = __( 'Paragraph %d', 'firehouse' );
	/* translators: %d: the number of an audience card. */
	const AUDIENCE = __( 'Audience %d', 'firehouse' );
	/* translators: %d: the number of a step. */
	const STEP = __( 'Step %d', 'firehouse' );
	/* translators: %d: the number of a link. */
	const LINK = __( 'Link %d', 'firehouse' );
	/* translators: %d: the number of a team member. */
	const MEMBER = __( 'Team member %d', 'firehouse' );
	/* translators: %d: the number of a paragraph of a biography. */
	const BIO = __( 'Biography, paragraph %d', 'firehouse' );

	/** Each editable block's fields, in the order the page shows them. */
	const FIELDS = {
		'firehouse/hero': [ EYEBROW, HEADING, BODY, button( 'primaryCta', __( 'First button', 'firehouse' ) ), button( 'secondaryCta', __( 'Second button', 'firehouse' ) ) ],
		'firehouse/stats': [
			list( 'items', __( 'Counts', 'firehouse' ), COUNT, [
				text( 'label', __( 'Label', 'firehouse' ), { required: true } ),
				text( 'caption', __( 'Caption', 'firehouse' ) ),
				fixed( 'source', __( 'Counted from', 'firehouse' ) ),
			] ),
		],
		'firehouse/top-needs': [ EYEBROW, HEADING, BODY, fixed( 'needsPerCard', __( 'Needs shown per topic area', 'firehouse' ) ) ],
		'firehouse/submit-band': [ HEADING, BODY, button( 'cta', __( 'Button', 'firehouse' ) ) ],
		'firehouse/about-intro': [ EYEBROW, HEADING, BODY ],
		'firehouse/about-mission': [ HEADING, paragraphs( 'paragraphs', PARAGRAPH ) ],
		'firehouse/about-audiences': [
			EYEBROW,
			HEADING,
			BODY,
			list( 'items', __( 'Audiences', 'firehouse' ), AUDIENCE, [
				text( 'title', __( 'Title', 'firehouse' ), { required: true } ),
				area( 'body', __( 'Text', 'firehouse' ) ),
				fixed( 'icon', __( 'Icon', 'firehouse' ) ),
			] ),
		],
		'firehouse/about-process': [
			EYEBROW,
			HEADING,
			BODY,
			list( 'steps', __( 'Steps', 'firehouse' ), STEP, [ text( 'title', __( 'Title', 'firehouse' ), { required: true } ), area( 'body', __( 'Text', 'firehouse' ) ) ] ),
			list( 'links', __( 'Links', 'firehouse' ), LINK, [ text( 'label', __( 'Link text', 'firehouse' ), { required: true } ), fixed( 'to', __( 'FireHouse page', 'firehouse' ) ) ] ),
		],
		'firehouse/about-team': [
			EYEBROW,
			HEADING,
			BODY,
			text( 'emailLead', __( 'Words before the email address', 'firehouse' ) ),
			text( 'email', __( 'Email address', 'firehouse' ), { help: __( 'The team’s mailbox. The page shows it only if it’s an @noaa.gov address.', 'firehouse' ) } ),
			list( 'members', __( 'Team members', 'firehouse' ), MEMBER, [
				text( 'name', __( 'Name', 'firehouse' ), { required: true } ),
				paragraphs( 'bio', BIO ),
				text( 'photoAlt', __( 'Portrait description', 'firehouse' ), {
					help: __( 'Usually empty: the name follows the portrait, so describing the person would say who they are twice. Describe only what else it shows.', 'firehouse' ),
				} ),
				fixed( 'photoId', __( 'Portrait (Media Library ID)', 'firehouse' ) ),
			] ),
		],
		'firehouse/about-cta': [ HEADING, BODY, button( 'cta', __( 'Button', 'firehouse' ) ) ],
	};

	// Names for stored fields whose keys read badly as words, for the read-only blocks.
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
	const GROUP = { margin: '0 0 1em', padding: '0.75em', border: '1px solid #949494', minWidth: 0 };

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

	/** Where a stored link goes, in words. */
	function destination( link ) {
		if ( link && 'string' === typeof link.to && '' !== link.to ) {
			return link.to;
		}
		if ( link && 'string' === typeof link.href && '' !== link.href ) {
			return link.href;
		}
		return __( 'The Submit a Finding address in FireHouse → Settings', 'firehouse' );
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
					shown = destination( object );
				}
				return [
					el( 'dt', { key: key + '-term', style: { fontWeight: 600, marginTop: '0.5em' } }, label( key ) ),
					el( 'dd', { key: key + '-value', style: { margin: 0, whiteSpace: 'pre-wrap' } }, shown ),
				];
			} )
		);
	}

	/** A stored word as a field's value: '' for anything that isn't text. */
	function words( stored ) {
		return 'string' === typeof stored ? stored : '';
	}

	/** Whether a required word is missing: empty, or only spaces. */
	function blank( stored ) {
		return '' === words( stored ).trim();
	}

	/** The stored entries of a list, each an object, or none. */
	function entries( stored ) {
		return Array.isArray( stored ) ? stored : [];
	}

	/**
	 * The fields that can't be empty and are, by name, in the order the block shows
	 * them: a heading, a button's text, an entry's title (named with its entry).
	 */
	function missing( spec, attributes, prefix ) {
		return spec.flatMap( ( field ) => {
			const stored = attributes ? attributes[ field.key ] : undefined;
			const name = prefix ? sprintf( /* translators: 1: an entry, such as "Step 2", 2: one of its fields. */ __( '%1$s: %2$s', 'firehouse' ), prefix, field.label ) : field.label;
			if ( 'text' === field.kind || 'area' === field.kind ) {
				return field.required && blank( stored ) ? [ name ] : [];
			}
			if ( 'button' === field.kind ) {
				return stored && 'object' === typeof stored && blank( stored.label ) ? [ sprintf( /* translators: %s: a button, such as "First button". */ __( '%s text', 'firehouse' ), name ) ] : [];
			}
			if ( 'list' === field.kind ) {
				return entries( stored ).flatMap( ( entry, index ) => ( entry && 'object' === typeof entry ? missing( field.fields, entry, sprintf( field.item, index + 1 ) ) : [] ) );
			}
			return [];
		} );
	}

	/** A text field, or a field of a few lines, bound to a stored word. */
	function control( field, stored, change, prefix ) {
		const empty = field.required && blank( stored );
		const help = [ field.required ? __( 'Required.', 'firehouse' ) : '', empty ? __( 'It’s empty, so the page can’t be saved.', 'firehouse' ) : '', field.help || '' ].filter( Boolean ).join( ' ' );
		const props = {
			label: prefix ? sprintf( /* translators: 1: an entry, such as "Step 2", 2: one of its fields. */ __( '%1$s: %2$s', 'firehouse' ), prefix, field.label ) : field.label,
			value: words( stored ),
			onChange: change,
			help: help || undefined,
			__nextHasNoMarginBottom: true,
			'aria-invalid': empty ? true : undefined,
			'aria-required': field.required ? true : undefined,
		};
		// Each field with room under it, which WordPress's own spacing for these is going away.
		return el(
			'div',
			{ key: field.key, style: { marginBottom: '1em' } },
			'area' === field.kind ? el( TextareaControl, { ...props, rows: 3 } ) : el( TextControl, { ...props, __next40pxDefaultSize: true } )
		);
	}

	/** Something stored that isn't words, shown as it is with its name. */
	function shown( name, stored, key ) {
		return el( 'p', { key, style: { margin: '0 0 1em' } }, el( 'strong', null, name + ': ' ), value( stored ) );
	}

	/**
	 * A field's controls, given what's stored and how to store a change. A list or
	 * paragraphs shows one group or field per entry stored, never adding or removing
	 * any: the page's layout is fixed.
	 */
	function render( field, stored, change, prefix ) {
		switch ( field.kind ) {
			case 'text':
			case 'area':
				return control( field, stored, change, prefix );
			case 'button': {
				const link = stored && 'object' === typeof stored ? stored : {};
				const textField = { kind: 'text', key: field.key + '-label', label: sprintf( /* translators: %s: a button, such as "First button". */ __( '%s text', 'firehouse' ), field.label ), required: true };
				return el(
					'div',
					{ key: field.key },
					control( textField, link.label, ( next ) => change( { ...link, label: next } ), prefix ),
					shown( sprintf( /* translators: %s: a button, such as "First button". */ __( '%s goes to', 'firehouse' ), field.label ), destination( link ), field.key + '-to' )
				);
			}
			case 'paragraphs': {
				const stored_ = Array.isArray( stored ) ? stored : [];
				if ( ! stored_.length ) {
					return shown( sprintf( field.label, 1 ), NOTHING, field.key );
				}
				return el(
					'div',
					{ key: field.key },
					stored_.map( ( paragraph, index ) =>
						control(
							{ kind: 'area', key: field.key + '-' + index, label: sprintf( field.label, index + 1 ) },
							paragraph,
							( next ) => change( stored_.map( ( item, at ) => ( at === index ? next : item ) ) ),
							prefix
						)
					)
				);
			}
			case 'list': {
				const items = entries( stored );
				if ( ! items.length ) {
					return shown( field.label, NOTHING, field.key );
				}
				return el(
					'div',
					{ key: field.key },
					items.map( ( entry, index ) => {
						const name = sprintf( field.item, index + 1 );
						if ( ! entry || 'object' !== typeof entry ) {
							return shown( name, entry, field.key + '-' + index );
						}
						const set = ( key, next ) => change( items.map( ( item, at ) => ( at === index ? { ...item, [ key ]: next } : item ) ) );
						return el(
							'fieldset',
							{ key: field.key + '-' + index, style: GROUP },
							el( 'legend', { style: { fontWeight: 600, padding: '0 0.25em' } }, name ),
							field.fields.map( ( inner ) => render( inner, entry[ inner.key ], ( next ) => set( inner.key, next ), '' ) )
						);
					} )
				);
			}
			default:
				return shown( field.label, stored, field.key );
		}
	}

	/** A data store's actions, when the editor has it; the widgets editor may not. */
	function actions( name ) {
		try {
			return wp.data && wp.data.dispatch ? wp.data.dispatch( name ) || null : null;
		} catch ( error ) {
			return null;
		}
	}

	/**
	 * While a field that can't be empty is, the page can't be saved, and the editor says
	 * which block and fields, as a notice screen readers hear; once each is filled in,
	 * both go. Each block holds its own lock, by its client ID.
	 */
	function useRequired( clientId, title, gaps ) {
		const key = gaps.join( '\n' );
		useEffect( () => {
			const editor = actions( 'core/editor' );
			const notices = actions( 'core/notices' );
			const lock = 'firehouse-required-' + clientId;
			const release = () => {
				if ( editor && editor.unlockPostSaving ) {
					editor.unlockPostSaving( lock );
				}
				if ( notices && notices.removeNotice ) {
					notices.removeNotice( lock );
				}
			};
			if ( ! gaps.length ) {
				release();
				return undefined;
			}
			if ( editor && editor.lockPostSaving ) {
				editor.lockPostSaving( lock );
			}
			if ( notices && notices.createNotice ) {
				notices.createNotice(
					'error',
					sprintf(
						/* translators: 1: a part of the page, such as "FireHouse hero", 2: the fields that are empty. */
						__( '%1$s: fill in %2$s. The page can’t be saved while they’re empty.', 'firehouse' ),
						title,
						gaps.join( ', ' )
					),
					{ id: lock, isDismissible: false }
				);
			}
			return release;
		}, [ clientId, key ] );
	}

	/** Whether the block holds others, as its block.json's allowedBlocks says. */
	function holdsOthers( name ) {
		const type = getBlockType( name );
		return !! ( type && Array.isArray( type.allowedBlocks ) && type.allowedBlocks.length );
	}

	/** What the box says about the block: what it stores, what it holds, what FireHouse adds. */
	function explanation( stores, holds, editable ) {
		if ( editable ) {
			return holds
				? __( 'The words on this part of the page. FireHouse adds the rest: its layout, links and counts stay as they are. The parts below have their own.', 'firehouse' )
				: __( 'The words on this part of the page. FireHouse adds the rest: its layout, links and counts stay as they are.', 'firehouse' );
		}
		if ( stores && holds ) {
			return __( 'This page stores what’s listed here, and the parts below store their own. FireHouse adds the rest of this part of the page.', 'firehouse' );
		}
		if ( stores ) {
			return __( 'This page stores what’s listed here. FireHouse adds the rest of this part of the page.', 'firehouse' );
		}
		if ( holds ) {
			return __( 'Holds the parts below, which store their own. Nothing else in it is stored in this page.', 'firehouse' );
		}
		return __( 'FireHouse adds this part of the page. Nothing in it is stored in this page.', 'firehouse' );
	}

	function edit( name, keys ) {
		const spec = Object.prototype.hasOwnProperty.call( FIELDS, name ) ? FIELDS[ name ] : null;
		return function FireHouseBlockEdit( { attributes, setAttributes, clientId } ) {
			const blockProps = useBlockProps();
			const holds = holdsOthers( name );
			const title = getBlockType( name ).title;
			const gaps = spec ? missing( spec, attributes, '' ) : [];
			useRequired( clientId, title, gaps );
			let body = null;
			if ( spec ) {
				body = el(
					'div',
					{ style: { width: '100%' } },
					spec.map( ( field ) => render( field, attributes[ field.key ], ( next ) => setAttributes( { [ field.key ]: next } ), '' ) )
				);
			} else if ( keys.length ) {
				const stored = {};
				keys.forEach( ( key ) => {
					stored[ key ] = attributes[ key ];
				} );
				body = fields( stored, false );
			}
			// The explanation is a paragraph, not the Placeholder's instructions: WordPress
			// hides those in a narrow box and reads them aloud when the box appears.
			return el(
				'div',
				blockProps,
				el(
					Placeholder,
					{ label: title },
					el( 'p', { style: { margin: '0 0 1em', width: '100%' } }, explanation( keys.length > 0, holds, !! spec ) ),
					body
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
