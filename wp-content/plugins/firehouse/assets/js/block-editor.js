/**
 * The FireHouse blocks in the block editor. The landing and About pages' blocks show
 * their words as text fields in place, which an editor changes; the layout, links,
 * counts and portraits stay as they are (WORDPRESS-PLAN.md, Phase 6), and the page's
 * parts can't be added, moved or removed there (Blocks::lock_layout()). Each block's
 * fields are in FIELDS below. A block not there lists what the page stores in it,
 * read-only. A block that stores nothing and holds no others says FireHouse adds it. A
 * block that holds others (the hero holds the live counts; the About sections block
 * holds the About page's four middle sections) shows them after it, indented and
 * locked.
 *
 * Words are kept as typed: plain text, which the pages print escaped
 * (includes/class-page-words.php keeps them past WordPress's filters). A heading, a
 * button's text and the like can't be left empty: while one is, the field is marked,
 * the editor says which in a notice that goes to it, and holds the save. The hold is a
 * guide, not a control: the Code editor and an autosave don't keep it.
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
	const { __, _n, _x, sprintf } = wp.i18n;

	/** A one-line field; `required` ones can't be left empty; `when` limits it to some entries. */
	const text = ( key, label, options ) => ( { kind: 'text', key, label, ...( options || {} ) } );
	/** A field of a few lines. */
	const area = ( key, label, options ) => ( { kind: 'area', key, label, ...( options || {} ) } );
	/** A button or link: its text, which can't be empty, and where it goes, shown. */
	const button = ( key, label ) => ( { kind: 'button', key, label } );
	/** Paragraphs, each a field, as many as stored. */
	const paragraphs = ( key, label ) => ( { kind: 'paragraphs', key, label } );
	/** Entries, as many as stored, each with its fields; `item` names one, with its number. */
	const list = ( key, label, item, fields ) => ( { kind: 'list', key, label, item, fields } );
	/** Something stored that isn't words, shown as it is, or as `shown( value, entry )` words it. */
	const fixed = ( key, label, shown ) => ( { kind: 'fixed', key, label, shown } );

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

	/** What a count counts, in words (Derive::stat_value()). */
	const SOURCES = {
		publishedProjectCount: __( 'The projects visitors can see', 'firehouse' ),
		topicCount: __( 'The topic areas', 'firehouse' ),
		needCount: __( 'The end-user needs in those projects', 'firehouse' ),
		static: __( 'Not counted: it shows the words below as written', 'firehouse' ),
	};

	/** Each editable block's fields, in the order the page shows them. */
	const FIELDS = {
		'firehouse/hero': [ EYEBROW, HEADING, BODY, button( 'primaryCta', __( 'First button', 'firehouse' ) ), button( 'secondaryCta', __( 'Second button', 'firehouse' ) ) ],
		'firehouse/stats': [
			list( 'items', __( 'Counts', 'firehouse' ), COUNT, [
				fixed( 'source', __( 'What it counts', 'firehouse' ), ( source ) => ( Object.prototype.hasOwnProperty.call( SOURCES, source ) ? SOURCES[ source ] : source ) ),
				text( 'value', __( 'Shown as', 'firehouse' ), { required: true, when: ( entry ) => 'static' === entry.source } ),
				text( 'label', __( 'Label', 'firehouse' ), { required: true } ),
				text( 'caption', __( 'Caption', 'firehouse' ) ),
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
			// A link that goes nowhere isn't on the page (About_Page::process()), so its
			// text needn't be filled in.
			list( 'links', __( 'Links', 'firehouse' ), LINK, [
				text( 'label', __( 'Link text', 'firehouse' ), { required: true, when: goesSomewhere } ),
				fixed( 'to', __( 'Goes to', 'firehouse' ), ( to, entry ) => ( goesSomewhere( entry ) ? destination( entry ) : __( 'Nowhere, so the page leaves it out', 'firehouse' ) ) ),
			] ),
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
	// The block editor's own colour for an error, 5.6:1 on white.
	const ERROR = '#cc1818';

	/** A stored field's name for people: its label, or its key as words. */
	function label( key ) {
		if ( Object.prototype.hasOwnProperty.call( LABELS, key ) ) {
			return LABELS[ key ];
		}
		const words = String( key ).replace( /([a-z0-9])([A-Z])/g, '$1 $2' ).toLowerCase();
		return words.charAt( 0 ).toUpperCase() + words.slice( 1 );
	}

	/** Whether a stored entry is an object of fields: not an array, not a word. */
	function isEntry( stored ) {
		return !! stored && 'object' === typeof stored && ! Array.isArray( stored );
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
	 * Whether a stored button exists: it has somewhere to go, a page or an address, or
	 * its text, which goes to the survey's address (Page_Blocks::link()).
	 */
	function exists( link ) {
		return isEntry( link ) && ( 'string' === typeof link.to || 'string' === typeof link.href || ! blank( link.label ) );
	}

	/**
	 * Whether a stored About link goes somewhere: a FireHouse page, or an address, which
	 * if empty is the survey's. One with neither isn't on the page.
	 */
	function goesSomewhere( link ) {
		return isEntry( link ) && ( ( 'string' === typeof link.to && '' !== link.to ) || 'string' === typeof link.href );
	}

	/** Where a stored link goes, in words: a FireHouse page, an address, or the survey's. */
	function destination( link ) {
		if ( isEntry( link ) && 'string' === typeof link.to && '' !== link.to ) {
			return link.to;
		}
		if ( isEntry( link ) && 'string' === typeof link.href && '' !== link.href ) {
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

	/** The stored entries of a list, or none. */
	function entries( stored ) {
		return Array.isArray( stored ) ? stored : [];
	}

	/** An entry's field's name: its entry, then itself. */
	function within( entry, name ) {
		/* translators: 1: an entry, such as "Step 2", 2: one of its fields, such as "Title". */
		return sprintf( _x( '%1$s: %2$s', 'a field of a list entry', 'firehouse' ), entry, name );
	}

	/** A button's text field's name. */
	function buttonText( name ) {
		/* translators: %s: a button, such as "First button". */
		return sprintf( __( '%s text', 'firehouse' ), name );
	}

	/** The fields a field shows for an entry: those whose `when` the entry meets. */
	function shownFor( list_, entry ) {
		return list_.filter( ( field ) => ! field.when || field.when( entry ) );
	}

	/**
	 * The fields that can't be empty and are, in the order the block shows them, each
	 * with its name and the ID of its control: a heading, a button's text, an entry's
	 * title (named with its entry).
	 */
	function missing( spec, attributes, prefix, path ) {
		return spec.flatMap( ( field ) => {
			const stored = attributes ? attributes[ field.key ] : undefined;
			const name = prefix ? within( prefix, field.label ) : field.label;
			const id = path + field.key;
			if ( 'text' === field.kind || 'area' === field.kind ) {
				return field.required && blank( stored ) ? [ { name, id } ] : [];
			}
			if ( 'button' === field.kind ) {
				return exists( stored ) && blank( stored.label ) ? [ { name: buttonText( name ), id: id + '-label' } ] : [];
			}
			if ( 'list' === field.kind ) {
				return entries( stored ).flatMap( ( entry, index ) => ( isEntry( entry ) ? missing( shownFor( field.fields, entry ), entry, sprintf( field.item, index + 1 ), id + '-' + index + '-' ) : [] ) );
			}
			return [];
		} );
	}

	/** A text field, or a field of a few lines, bound to a stored word. */
	function control( field, stored, change, prefix, id ) {
		const empty = field.required && blank( stored );
		const notes = [ field.required ? __( 'Required.', 'firehouse' ) : '', field.help || '' ].filter( Boolean ).join( ' ' );
		const help = empty
			? el(
					'span',
					null,
					notes + ' ',
					el( 'span', { style: { color: ERROR, fontWeight: 600 } }, __( 'Error: it’s empty, so the page can’t be saved.', 'firehouse' ) )
			  )
			: notes || undefined;
		// The control's own ID is WordPress's, which ties its label to it; the notice's
		// "Go to" finds it by its data attribute. An empty one is outlined in red, which
		// leaves WordPress's focus border to show over it, and shows in forced colours.
		const props = {
			'data-firehouse-field': id,
			label: prefix ? within( prefix, field.label ) : field.label,
			value: words( stored ),
			onChange: change,
			help,
			__nextHasNoMarginBottom: true,
			'aria-invalid': empty ? true : undefined,
			'aria-required': field.required ? true : undefined,
			style: empty ? { outline: '2px solid ' + ERROR, outlineOffset: '1px' } : undefined,
		};
		// Each field with room under it, which WordPress's own spacing for these is going away.
		return el(
			'div',
			{ key: field.key, style: { marginBottom: '1em' } },
			'area' === field.kind ? el( TextareaControl, { ...props, rows: 3 } ) : el( TextControl, { ...props, __next40pxDefaultSize: true } )
		);
	}

	/** Something stored that isn't words, shown with its name. */
	function shown( name, stored, key ) {
		return el( 'p', { key, style: { margin: '0 0 1em' } }, el( 'strong', null, name + ': ' ), 'string' === typeof stored ? stored || NOTHING : value( stored ) );
	}

	/**
	 * A field's controls, given what's stored, how to store a change, and the ID its
	 * control takes. A list or paragraphs shows one group or field per entry stored,
	 * never adding or removing any, and a button that doesn't exist says so: the page's
	 * layout is fixed.
	 */
	function render( field, stored, change, prefix, id, entry ) {
		switch ( field.kind ) {
			case 'text':
			case 'area':
				return control( field, stored, change, prefix, id );
			case 'button': {
				if ( ! exists( stored ) ) {
					return shown( field.label, __( 'None on this part of the page', 'firehouse' ), field.key );
				}
				const textField = { kind: 'text', key: field.key + '-label', label: buttonText( field.label ), required: true };
				return el(
					'div',
					{ key: field.key },
					control( textField, stored.label, ( next ) => change( { ...stored, label: next } ), prefix, id + '-label' ),
					/* translators: %s: a button, such as "First button". */
					shown( sprintf( __( '%s goes to', 'firehouse' ), field.label ), destination( stored ), field.key + '-to' )
				);
			}
			case 'paragraphs': {
				const items = Array.isArray( stored ) ? stored : [];
				if ( ! items.length ) {
					return shown( sprintf( field.label, 1 ), NOTHING, field.key );
				}
				return el(
					'div',
					{ key: field.key },
					items.map( ( paragraph, index ) =>
						control(
							{ kind: 'area', key: field.key + '-' + index, label: sprintf( field.label, index + 1 ) },
							paragraph,
							( next ) => change( items.map( ( item, at ) => ( at === index ? next : item ) ) ),
							prefix,
							id + '-' + index
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
					items.map( ( item, index ) => {
						const name = sprintf( field.item, index + 1 );
						if ( ! isEntry( item ) ) {
							return shown( name, item, field.key + '-' + index );
						}
						const set = ( key, next ) => change( items.map( ( one, at ) => ( at === index ? { ...one, [ key ]: next } : one ) ) );
						return el(
							'fieldset',
							{ key: field.key + '-' + index, style: GROUP },
							el( 'legend', { style: { fontWeight: 600, padding: '0 0.25em' } }, name ),
							shownFor( field.fields, item ).map( ( inner ) => render( inner, item[ inner.key ], ( next ) => set( inner.key, next ), '', id + '-' + index + '-' + inner.key, item ) )
						);
					} )
				);
			}
			default:
				return shown( field.label, field.shown ? field.shown( stored, entry || {} ) : stored, field.key );
		}
	}

	/** A data store's actions or reader, when the editor has it; the widgets editor may not. */
	function store( name, read ) {
		try {
			const data = wp.data && ( read ? wp.data.select : wp.data.dispatch );
			return data ? data( name ) || null : null;
		} catch ( error ) {
			return null;
		}
	}

	/**
	 * Takes the editor to a block and one of its fields, by its data attribute, in the
	 * editor's frame or the page. Its ID is made of the block's client ID and the
	 * attributes' names, so it needs no escaping in a selector.
	 */
	function goTo( clientId, id ) {
		const editor = store( 'core/block-editor' );
		if ( editor && editor.selectBlock ) {
			editor.selectBlock( clientId );
		}
		window.setTimeout( () => {
			const selector = '[data-firehouse-field="' + id + '"]';
			const frame = window.document.querySelector( 'iframe[name="editor-canvas"]' );
			const inFrame = frame && frame.contentDocument ? frame.contentDocument.querySelector( selector ) : null;
			const field = inFrame || window.document.querySelector( selector );
			if ( field && field.focus ) {
				field.focus();
			}
		}, 0 );
	}

	/**
	 * While a field that can't be empty is, the page's save is held, and the editor says
	 * which block and fields, in a notice screen readers hear, with a button that goes to
	 * the first; once each is filled in, both go. Each block holds its own hold, by its
	 * client ID.
	 */
	function useRequired( clientId, title, gaps ) {
		const key = gaps.map( ( gap ) => gap.id ).join( '\n' );
		useEffect( () => {
			const editor = store( 'core/editor' );
			const notices = store( 'core/notices' );
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
				const names = gaps.map( ( gap ) => gap.name ).join( _x( ', ', 'between the names of fields in a list', 'firehouse' ) );
				notices.createNotice(
					'error',
					sprintf(
						/* translators: 1: a part of the page, such as "FireHouse hero", 2: the fields that are empty. */
						_n( '%1$s: fill in %2$s. The page can’t be saved while it’s empty.', '%1$s: fill in %2$s. The page can’t be saved while they’re empty.', gaps.length, 'firehouse' ),
						title,
						names
					),
					{
						id: lock,
						isDismissible: false,
						actions: [
							{
								/* translators: 1: a field, such as "Heading", 2: a part of the page, such as "FireHouse hero". */
								label: sprintf( __( 'Go to %1$s in %2$s', 'firehouse' ), gaps[ 0 ].name, title ),
								onClick: () => goTo( clientId, gaps[ 0 ].id ),
							},
						],
					}
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
			const own = __( 'The words on this part of the page. FireHouse adds everything else, and the layout stays as it is. Tab moves through these fields; the Down arrow at the end of a field moves on to the next part’s fields.', 'firehouse' );
			return holds ? own + ' ' + __( 'The parts below have their own.', 'firehouse' ) : own;
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
			const path = 'fh-' + clientId + '-';
			const gaps = spec ? missing( spec, attributes, '', path ) : [];
			useRequired( clientId, title, gaps );
			let body = null;
			if ( spec ) {
				// A group named for its part, so each "Heading" among the page's fields says
				// whose it is.
				body = el(
					'div',
					{ role: 'group', 'aria-label': title, style: { width: '100%' } },
					spec.map( ( field ) => render( field, attributes[ field.key ], ( next ) => setAttributes( { [ field.key ]: next } ), '', path + field.key ) )
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
