<?php
/**
 * Tests for the plugin's blocks.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Blocks;
use Firehouse\Coverage_Map;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;

/**
 * Each block registers from its folder, and its block.json names the files that
 * render it and run it.
 */
final class BlocksTest extends WordPressTestCase {

	/**
	 * The blocks folder.
	 */
	private const BLOCKS = __DIR__ . '/../blocks/';

	/**
	 * Every block registers from its own folder, and the two script modules the view
	 * scripts import by name: the site's search, and the map's drawing, minified unless
	 * `SCRIPT_DEBUG` is on.
	 */
	public function test_each_block_registers_from_its_folder(): void {
		$registered  = array();
		$collections = array();
		Functions\when( 'register_block_type' )->alias(
			static function ( string $path ) use ( &$registered ): void {
				$registered[] = $path;
			}
		);
		Functions\when( 'wp_register_block_metadata_collection' )->alias(
			static function ( string $path, string $manifest ) use ( &$collections, &$registered ): void {
				$collections[] = array( $path, $manifest, count( $registered ) );
			}
		);

		$modules = array();
		Functions\when( 'plugins_url' )->alias( static fn( string $path ): string => 'https://example.test/wp-content/plugins/firehouse/' . $path );
		Functions\when( 'wp_register_script_module' )->alias(
			static function ( string $id, string $src, array $deps, string $version ) use ( &$modules ): void {
				$modules[] = array( $id, $src, $deps, $version );
			}
		);

		Functions\when( 'wp_scripts_get_suffix' )->justReturn( '.min' );

		Blocks::register();

		// The explorer's view script imports the site's search by this name, and the
		// coverage map's the map's drawing.
		$version = static fn( string $path ): string => \Firehouse\VERSION . '-' . filemtime( dirname( __DIR__ ) . '/' . $path );
		$this->assertSame(
			array(
				array( '@firehouse/search', 'https://example.test/wp-content/plugins/firehouse/assets/search/search.js', array(), $version( 'assets/search/search.js' ) ),
				array( Coverage_Map::MAP_MODULE, 'https://example.test/wp-content/plugins/firehouse/assets/map/map.min.js', array(), $version( 'assets/map/map.min.js' ) ),
			),
			$modules
		);

		$this->assertSame( array_map( static fn( string $name ): string => dirname( __DIR__ ) . '/blocks/' . $name, array( 'about-audiences', 'about-cta', 'about-intro', 'about-mission', 'about-process', 'about-sections', 'about-team', 'coverage-map', 'explorer', 'hero', 'not-found', 'project', 'stats', 'submit-band', 'subnav', 'top-needs', 'topic' ) ), $registered );
		// The manifest comes first, so no block reads its block.json.
		$this->assertSame( array( array( dirname( __DIR__ ) . '/blocks', dirname( __DIR__ ) . '/blocks/blocks-manifest.php', 0 ) ), $collections );

		// Under SCRIPT_DEBUG, the readable copy.
		$modules = array();
		Functions\when( 'wp_scripts_get_suffix' )->justReturn( '' );
		Blocks::register();
		$this->assertSame( 'https://example.test/wp-content/plugins/firehouse/assets/map/map.js', $modules[1][1] );
	}

	/**
	 * A FireHouse page made of FireHouse blocks has its layout locked in the block
	 * editor, its parts fixed; an empty one, one holding another block, any other post's
	 * editor, a page outside the tree, or an editor with no post is left as it is.
	 */
	public function test_a_firehouse_pages_layout_is_locked(): void {
		Functions\when( 'get_posts' )->justReturn( array( 10 ) );
		Functions\when( 'get_post_ancestors' )->alias( static fn( \WP_Post $post ): array => 0 === $post->post_parent ? array() : array( $post->post_parent ) );
		$block    = static fn( string $name, array $attrs = array() ): array => array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
		Functions\when( 'parse_blocks' )->alias(
			static fn( string $content ): array => match ( $content ) {
				'firehouse' => array( $block( 'firehouse/about-intro' ) ),
				'noted'     => array( $block( 'firehouse/about-intro', array( 'metadata' => array( 'noteId' => 5 ) ) ) ),
				'mixed'     => array( $block( 'firehouse/about-intro' ), $block( 'core/paragraph' ) ),
				default     => array(),
			}
		);
		$editing  = static fn( ?\WP_Post $post ): object => (object) array( 'post' => $post );
		$page     = static fn( int $id, int $parent_id, string $type = 'page', string $content = 'firehouse' ): \WP_Post => new \WP_Post(
			array(
				'ID'           => $id,
				'post_type'    => $type,
				'post_parent'  => $parent_id,
				'post_content' => $content,
			)
		);
		$settings = array( 'titlePlaceholder' => 'Add title' );

		$this->assertSame( 'all', Blocks::lock_layout( $settings, $editing( $page( 10, 0 ) ) )['templateLock'], 'The landing page.' );
		$this->assertSame( 'all', Blocks::lock_layout( $settings, $editing( $page( 11, 10 ) ) )['templateLock'], 'The About page.' );
		$this->assertSame( 'all', Blocks::lock_layout( $settings, $editing( $page( 11, 10, 'page', 'noted' ) ) )['templateLock'], 'A block with a note on it.' );
		$this->assertSame( $settings, Blocks::lock_layout( $settings, $editing( $page( 12, 10, 'page', '' ) ) ), 'An empty page in the tree.' );
		$this->assertSame( $settings, Blocks::lock_layout( $settings, $editing( $page( 13, 10, 'page', 'mixed' ) ) ), 'A page holding another block.' );
		$this->assertSame( $settings, Blocks::lock_layout( $settings, $editing( $page( 20, 0 ) ) ), 'A page outside the tree.' );
		$this->assertSame( $settings, Blocks::lock_layout( $settings, $editing( $page( 30, 10, 'post' ) ) ), 'Not a page.' );
		$this->assertSame( $settings, Blocks::lock_layout( $settings, $editing( null ) ), 'The site editor.' );
		$this->assertSame( $settings, Blocks::lock_layout( $settings, null ) );
	}

	/**
	 * The block editor loads the blocks' editor script, on WordPress's own packages,
	 * versioned by the plugin and the file, with every block's name and the attributes
	 * its block.json declares before it, and the plugin's translations.
	 */
	public function test_the_editor_script_loads_with_every_block_name(): void {
		$enqueued     = array();
		$inline       = array();
		$translations = array();
		Functions\when( 'plugins_url' )->alias( static fn( string $path ): string => 'https://example.test/wp-content/plugins/firehouse/' . $path );
		Functions\when( 'wp_json_encode' )->alias( static fn( mixed $data ): string => (string) json_encode( $data ) );
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( ...$args ) use ( &$enqueued ): void {
				$enqueued[] = $args;
			}
		);
		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( ...$args ) use ( &$inline ): void {
				$inline[] = $args;
			}
		);
		Functions\when( 'wp_set_script_translations' )->alias(
			static function ( ...$args ) use ( &$translations ): void {
				$translations[] = $args;
			}
		);
		// Not editing a FireHouse page, so there are no portraits to offer.
		Functions\when( 'get_post' )->justReturn( null );

		Blocks::enqueue_editor();

		$file = dirname( __DIR__ ) . '/assets/js/block-editor.js';
		$this->assertFileExists( $file );
		$this->assertSame(
			array(
				array(
					Blocks::EDITOR_SCRIPT,
					'https://example.test/wp-content/plugins/firehouse/assets/js/block-editor.js',
					array( 'wp-a11y', 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-element', 'wp-i18n' ),
					\Firehouse\VERSION . '-' . filemtime( $file ),
					false,
				),
			),
			$enqueued
		);
		$blocks = array();
		foreach ( Blocks::NAMES as $name ) {
			$block                          = json_decode( (string) file_get_contents( self::BLOCKS . $name . '/block.json' ), true );
			$blocks[ 'firehouse/' . $name ] = array_keys( $block['attributes'] ?? array() );
		}
		$this->assertSame( array( 'eyebrow', 'heading', 'body', 'primaryCta', 'secondaryCta' ), $blocks['firehouse/hero'] );
		$this->assertSame( array(), $blocks['firehouse/subnav'] );
		$this->assertSame( array( array( Blocks::EDITOR_SCRIPT, 'window.firehouseBlocks = ' . json_encode( $blocks ) . '; window.firehousePortraits = [];', 'before' ) ), $inline );
		$this->assertSame( array( array( Blocks::EDITOR_SCRIPT, 'firehouse' ) ), $translations );
	}

	/**
	 * A portrait's title goes into the editor's inline script with < and & as escapes,
	 * so nothing in a title can read as markup there.
	 */
	public function test_a_portraits_title_cant_read_as_markup(): void {
		$inline = array();
		Functions\when( 'plugins_url' )->justReturn( 'https://example.test/block-editor.js' );
		Functions\when( 'wp_enqueue_script' )->justReturn();
		Functions\when( 'wp_set_script_translations' )->justReturn( true );
		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( string $handle, string $script ) use ( &$inline ): void {
				$inline[] = $script;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( static fn( mixed $data, int $flags = 0 ): string => (string) json_encode( $data, $flags ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- standing in for wp_json_encode().
		$about = new \WP_Post(
			array(
				'ID'          => 11,
				'post_type'   => 'page',
				'post_parent' => 10,
			)
		);
		Functions\when( 'get_post' )->alias(
			static fn( mixed $id = null ): ?\WP_Post => match ( null === $id ? 11 : (int) $id ) {
				10 => new \WP_Post(
					array(
						'ID'        => 10,
						'post_type' => 'page',
					)
				),
				11 => $about,
				50 => new \WP_Post(
					array(
						'ID'          => 50,
						'post_type'   => 'attachment',
						'post_status' => 'inherit',
					)
				),
				default => null,
			}
		);
		Functions\when( 'is_post_publicly_viewable' )->justReturn( true );
		Functions\when( 'get_posts' )->alias( static fn( array $args ): array => 'attachment' === ( $args['post_type'] ?? null ) ? array( 50 ) : array( 10 ) );
		Functions\when( '_prime_post_caches' )->justReturn();
		Functions\when( 'get_post_ancestors' )->justReturn( array( 10 ) );
		Functions\when( 'get_pages' )->justReturn( array( $about ) );
		Functions\when( 'parse_blocks' )->justReturn( array() );
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://example.test/50.webp' );
		Functions\when( 'get_post_field' )->justReturn( '<!--<script> & x' );

		Blocks::enqueue_editor();

		// The title as JSON with < and & escaped, as it should appear.
		$escaped = substr( (string) json_encode( '<!--<script> & x', JSON_HEX_TAG | JSON_HEX_AMP ), 1, -1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the expected encoding.
		$this->assertStringNotContainsString( '<', $escaped );
		$this->assertStringContainsString( 'window.firehousePortraits = [{"id":50,"title":"' . $escaped . '"', $inline[0] );
		$this->assertStringNotContainsString( '<script', $inline[0] );
	}

	/**
	 * A FireHouse page's editor is offered every portrait attached to the FireHouse pages
	 * a visitor can see and those its team cards use, each only if the About page would
	 * show it; any other post's editor, none.
	 */
	public function test_the_portraits_a_team_card_can_show(): void {
		Functions\when( 'get_post_ancestors' )->alias( static fn( \WP_Post $post ): array => 0 === $post->post_parent ? array() : array( $post->post_parent ) );
		// Under the root (10): the About page (11), a draft (12) and one with a password (13).
		Functions\when( 'get_pages' )->justReturn(
			array(
				new \WP_Post( array( 'ID' => 11 ) ),
				new \WP_Post( array( 'ID' => 12 ) ),
				new \WP_Post( array( 'ID' => 13 ) ),
			)
		);
		$asked = array();
		Functions\when( 'get_posts' )->alias(
			static function ( array $args ) use ( &$asked ): array {
				if ( 'attachment' === ( $args['post_type'] ?? null ) ) {
					$asked[] = $args;
					return array( 50, 51 );
				}
				return array( 10 );
			}
		);
		$primed = array();
		Functions\when( '_prime_post_caches' )->alias(
			static function ( array $ids, bool $terms, bool $meta ) use ( &$primed ): void {
				$primed[] = array( $ids, $terms, $meta );
			}
		);
		$team = array(
			'blockName'    => 'firehouse/about-team',
			'attrs'        => array(
				'members' => array(
					array( 'photoId' => 52 ),
					array( 'photoId' => 50 ),
					array( 'photoId' => '53' ),
					'not a member',
				),
			),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
		Functions\when( 'parse_blocks' )->justReturn(
			array(
				array(
					'blockName'    => 'firehouse/about-sections',
					'attrs'        => array(),
					'innerBlocks'  => array( $team ),
					'innerHTML'    => '',
					'innerContent' => array( null ),
				),
			)
		);
		// The About page would show 50 and 52, not 51, attached to a page a visitor can't see.
		Functions\when( 'get_post' )->alias(
			static fn( mixed $id ): ?\WP_Post => match ( true ) {
				in_array( (int) $id, array( 50, 51, 52 ), true ) => new \WP_Post(
					array(
						'ID'          => (int) $id,
						'post_type'   => 'attachment',
						'post_status' => 'inherit',
						'post_parent' => 51 === (int) $id ? 12 : 0,
					)
				),
				in_array( (int) $id, array( 10, 11, 12, 13 ), true ) => new \WP_Post(
					array(
						'ID'            => (int) $id,
						'post_type'     => 'page',
						'post_status'   => 12 === (int) $id ? 'draft' : 'publish',
						'post_password' => 13 === (int) $id ? 'secret' : '',
					)
				),
				default => null,
			}
		);
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );
		$public = true;
		Functions\when( 'is_post_publicly_viewable' )->alias(
			static function ( \WP_Post $post ) use ( &$public ): bool {
				return $public && 'publish' === $post->post_status;
			}
		);
		// The title as stored; displayed, WordPress would have turned "--" into a dash entity.
		Functions\when( 'get_post_field' )->alias( static fn( string $field, int $id, string $context ): string => 'raw' === $context ? 'Portrait ' . $id . " -- O'Brien & more" : 'texturized' );
		Functions\when( 'wp_get_attachment_image_url' )->alias( static fn( int $id ): string => 'https://example.test/' . $id . '-150x150.webp' );
		$about = new \WP_Post(
			array(
				'ID'          => 11,
				'post_type'   => 'page',
				'post_parent' => 10,
			)
		);

		$this->assertSame(
			array(
				array(
					'id'    => 50,
					'title' => "Portrait 50 -- O'Brien & more",
					'url'   => 'https://example.test/50-150x150.webp',
				),
				array(
					'id'    => 52,
					'title' => "Portrait 52 -- O'Brien & more",
					'url'   => 'https://example.test/52-150x150.webp',
				),
			),
			Blocks::portraits( $about )
		);
		$this->assertSame( array( 10, 11 ), $asked[0]['post_parent__in'], 'Attached to the FireHouse pages a visitor can see.' );
		$this->assertSame( 'image', $asked[0]['post_mime_type'] );
		$this->assertSame( -1, $asked[0]['numberposts'], 'Every one, none left off.' );
		$this->assertSame( array( array( array( 50, 51, 52 ), false, true ) ), $primed, 'Every image and its metadata loaded at once, its terms not.' );

		// With no FireHouse page a visitor can see, no image is asked for, since WordPress
		// would take an empty `post_parent__in` as every image on the site; only the
		// portraits the cards use are offered.
		$public = false;
		$asked  = array();
		$this->assertSame( array( 52, 50 ), array_column( Blocks::portraits( $about ), 'id' ) );
		$this->assertSame( array(), $asked );
		$this->assertSame( array(), Blocks::portraits( new \WP_Post( array( 'post_type' => 'post' ) ) ), 'Not a page.' );
		$this->assertSame( array(), Blocks::portraits( null ), 'No post.' );
	}

	/**
	 * The folders in blocks/ are the blocks Blocks registers, so none is left out, and
	 * beside them is only the manifest.
	 */
	public function test_every_folder_is_registered(): void {
		$entries = array_values( array_diff( (array) scandir( self::BLOCKS ), array( '.', '..' ) ) );
		$folders = array_values( array_filter( $entries, static fn( string $entry ): bool => is_dir( self::BLOCKS . $entry ) ) );
		$this->assertSame( Blocks::NAMES, $folders );
		$this->assertSame( array( 'blocks-manifest.php' ), array_values( array_diff( $entries, $folders ) ) );
	}

	/**
	 * The manifest is every block's block.json, as WordPress would decode it, by folder:
	 * WordPress registers the blocks from it, not from the files. It runs the manifest,
	 * so only once the manifest is shown to hold nothing that runs.
	 */
	#[Depends( 'test_the_manifest_holds_only_literals' )]
	public function test_the_manifest_is_each_block_json(): void {
		$expected = array();
		foreach ( Blocks::NAMES as $name ) {
			$expected[ $name ] = json_decode( (string) file_get_contents( self::BLOCKS . $name . '/block.json' ), true, 512, JSON_THROW_ON_ERROR );
		}
		ksort( $expected, SORT_STRING );

		$this->assertSame( $expected, require self::BLOCKS . 'blocks-manifest.php', 'blocks/blocks-manifest.php is out of date with the block.json files. Run: npm run plugin:blocks' );
	}

	/**
	 * The manifest is its header and one array of literals, with nothing that runs:
	 * WordPress includes it on every request, so code added around the same array would
	 * run there. Read as tokens, never included. A parenthesis may only open an array,
	 * since a string followed by one would call the function it names. Strings and whole
	 * numbers stand in as `<str>` and `<int>`, which no token can be, so a constant can't
	 * pass for one. This holds the file to literals; the test above, which depends on
	 * it, holds it to the right ones in the right shape.
	 */
	public function test_the_manifest_holds_only_literals(): void {
		$kept = array();
		foreach ( \PhpToken::tokenize( (string) file_get_contents( self::BLOCKS . 'blocks-manifest.php' ) ) as $token ) {
			if ( $token->is( array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ) ) ) {
				continue;
			}
			$kept[] = match ( true ) {
				$token->is( T_OPEN_TAG )                                                    => '<?php',
				$token->is( T_CONSTANT_ENCAPSED_STRING ) && "'ABSPATH'" !== $token->text => '<str>',
				$token->is( T_LNUMBER )                                                     => '<int>',
				default                                                                     => $token->text,
			};
		}
		$this->assertMatchesRegularExpression(
			'/\A<\?php declare \( strict_types = <int> \) ; if \( ! defined \( \'ABSPATH\' \) \) \{ exit ; \} return array \((?: (?:array \(|\)|,|=>|<str>|(?:- )?<int>|true|false|null))* ;\z/',
			implode( ' ', $kept )
		);
	}

	/**
	 * The blocks, by folder.
	 *
	 * @return array<string, array{string}>
	 */
	public static function blocks(): array {
		return array_combine( Blocks::NAMES, array_map( static fn( string $name ): array => array( $name ), Blocks::NAMES ) );
	}

	/**
	 * A block is named after its folder, renders on the server, stays out of the
	 * inserter, and its view script, if it has one, declares the Interactivity API, and
	 * each module it imports: one it imports only once the page is up, as dynamic.
	 *
	 * @param string $name The block's folder.
	 */
	#[DataProvider( 'blocks' )]
	public function test_a_block_names_its_files( string $name ): void {
		$folder = self::BLOCKS . $name . '/';
		$block  = json_decode( (string) file_get_contents( $folder . 'block.json' ), true );

		$this->assertSame( 'firehouse/' . $name, $block['name'] );
		$this->assertSame( 3, $block['apiVersion'] );
		$this->assertSame( 'firehouse', $block['textdomain'] );
		$this->assertSame( 'file:./render.php', $block['render'] );
		$this->assertFileExists( $folder . 'render.php' );
		$this->assertFalse( $block['supports']['inserter'] );
		// No custom CSS, class names, lock or name: they'd be attributes the block doesn't
		// declare, which send an editor's words back through kses (Page_Words).
		foreach ( array( 'className', 'customClassName', 'customCSS', 'lock', 'renaming' ) as $support ) {
			$this->assertFalse( $block['supports'][ $support ] ?? null, $support );
		}

		if ( isset( $block['viewScriptModule'] ) ) {
			$this->assertSame( 'file:./view.js', $block['viewScriptModule'] );
			// The explorer and the coverage map print their own page and don't ask
			// WordPress to process their directives (Explorer_Page says why); the explorer
			// imports the site's search too, and the coverage map the map's drawing, once
			// the page is up.
			$prints = in_array( $name, array( 'explorer', 'coverage-map' ), true );
			$this->assertSame( ! $prints, $block['supports']['interactivity'] ?? false );
			$asset = require $folder . 'view.asset.php';
			$this->assertSame(
				array(
					'explorer'     => array( '@wordpress/interactivity', '@firehouse/search' ),
					'coverage-map' => array(
						'@wordpress/interactivity',
						array(
							'id'     => Coverage_Map::MAP_MODULE,
							'import' => 'dynamic',
						),
					),
				)[ $name ] ?? array( '@wordpress/interactivity' ),
				$asset['dependencies']
			);
			// WordPress maps only the modules declared, so a script that imports another
			// would fail to load: what it imports is what it declares, in order, and what
			// it imports later, by name, is declared dynamic.
			$script = (string) file_get_contents( $folder . 'view.js' );
			preg_match_all( '/^import\b[^\'"]*[\'"]([^\'"]+)[\'"]/m', $script, $imports );
			$this->assertSame( array_values( array_filter( $asset['dependencies'], 'is_string' ) ), $imports[1] );
			foreach ( array_filter( $asset['dependencies'], 'is_array' ) as $dependency ) {
				$this->assertSame( 'dynamic', $dependency['import'] );
				$this->assertStringContainsString( "export const MAP_MODULE = '" . $dependency['id'] . "';", $script );
				$this->assertStringContainsString( 'await import( MAP_MODULE )', $script );
			}
			$this->assertSame( (string) filemtime( $folder . 'view.js' ), $asset['version'] );
		}
	}
}
