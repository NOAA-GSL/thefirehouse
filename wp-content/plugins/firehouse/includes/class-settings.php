<?php
/**
 * The Firehouse → Settings screen.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The site-wide Firehouse text: the site name, the label and address of every
 * "Submit a Finding" control, and the navigation labels.
 *
 * These are the fields that exist now. The survey's question wording, the AI prompt
 * and who is emailed about submissions join them in later phases. The footer
 * settings of the React site aren't here: GSL's footer takes its place. The three
 * navigation links are fixed in code, because their routes are the site's structure;
 * only their labels are settings.
 *
 * Firehouse Editors can change them, through their own capability rather than
 * manage_options, which would open every setting on the site.
 */
final class Settings {

	/**
	 * The option, and the settings group.
	 */
	public const OPTION = 'firehouse_settings';

	/**
	 * The screen's slug.
	 */
	public const PAGE = 'firehouse-settings';

	/**
	 * The navigation links, by key: their routes under /firehouse/.
	 */
	public const NAV = array(
		'home'     => '/',
		'projects' => '/projects',
		'about'    => '/about',
	);

	/**
	 * What was typed into each field of a refused save, by field ID, while the screen
	 * is drawn.
	 *
	 * @var array<string, string>
	 */
	private static array $rejected = array();

	/**
	 * Hooks the setting, the screen and the capability.
	 */
	public static function hook(): void {
		add_action( 'admin_init', array( self::class, 'register' ) );
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_filter( 'option_page_capability_' . self::OPTION, array( self::class, 'capability' ) );
	}

	/**
	 * The settings before anyone has saved them: the React site's wording. The submit
	 * address has no default, so a site can't point at a wrong one.
	 *
	 * @return array{siteName: string, submitLabel: string, submitFormUrl: string, navLabels: array<string, string>}
	 */
	public static function defaults(): array {
		return array(
			'siteName'      => 'The FireHouse',
			'submitLabel'   => 'Submit a Finding',
			'submitFormUrl' => '',
			'navLabels'     => array(
				'home'     => 'Home',
				'projects' => 'Explore Projects',
				'about'    => 'About',
			),
		);
	}

	/**
	 * The settings: what is stored, over the defaults. Anything stored that isn't a
	 * setting is left out, and so is a survey address the screen would refuse, which
	 * only a write that skipped it could have stored: the public pages show no Submit
	 * button rather than one that goes nowhere (rule 6).
	 *
	 * @return array{siteName: string, submitLabel: string, submitFormUrl: string, navLabels: array<string, string>}
	 */
	public static function get(): array {
		$defaults = self::defaults();
		$stored   = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return $defaults;
		}
		$settings = $defaults;
		foreach ( array( 'siteName', 'submitLabel' ) as $key ) {
			if ( is_string( $stored[ $key ] ?? null ) ) {
				$settings[ $key ] = $stored[ $key ];
			}
		}
		if ( '' !== ( $stored['submitFormUrl'] ?? '' ) ) {
			$settings['submitFormUrl'] = self::https_url( $stored['submitFormUrl'] ) ?? '';
		}
		foreach ( array_keys( self::NAV ) as $key ) {
			if ( is_string( $stored['navLabels'][ $key ] ?? null ) ) {
				$settings['navLabels'][ $key ] = $stored['navLabels'][ $key ];
			}
		}
		return $settings;
	}

	/**
	 * The capability that saves the settings.
	 */
	public static function capability(): string {
		return Capabilities::SETTINGS;
	}

	/**
	 * Registers the setting and lays out its fields.
	 */
	public static function register(): void {
		register_setting(
			self::OPTION,
			self::OPTION,
			array(
				'type'              => 'object',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);

		add_settings_section( 'firehouse_site', __( 'Site', 'firehouse' ), '__return_null', self::PAGE );
		self::add_field( 'firehouse_site', array( 'siteName' ), __( 'Site name', 'firehouse' ), __( 'The name in the FireHouse bar at the top of every FireHouse page, and in each page’s browser-tab title and link preview. It doesn’t change the words on the pages, or the landing and About pages’ titles in Pages.', 'firehouse' ) );

		add_settings_section( 'firehouse_submit', __( 'Submit a Finding', 'firehouse' ), '__return_null', self::PAGE );
		self::add_field( 'firehouse_submit', array( 'submitLabel' ), __( 'Button label', 'firehouse' ), __( 'The words on the Submit a Finding button in the FireHouse bar and on the topic pages. The buttons on the landing and About pages keep the words stored with those pages.', 'firehouse' ) );
		self::add_field( 'firehouse_submit', array( 'submitFormUrl' ), __( 'Survey address', 'firehouse' ), __( 'Where every Submit a Finding button goes: the survey form. It must start with https://.', 'firehouse' ), 'url' );

		add_settings_section( 'firehouse_nav', __( 'Navigation', 'firehouse' ), array( self::class, 'nav_section' ), self::PAGE );
		self::add_field( 'firehouse_nav', array( 'navLabels', 'home' ), __( 'Home link', 'firehouse' ), __( 'Goes to the FireHouse landing page.', 'firehouse' ) );
		self::add_field( 'firehouse_nav', array( 'navLabels', 'projects' ), __( 'Projects link', 'firehouse' ), __( 'Goes to the list of projects.', 'firehouse' ) );
		self::add_field( 'firehouse_nav', array( 'navLabels', 'about' ), __( 'About link', 'firehouse' ), __( 'Goes to the About page.', 'firehouse' ) );
	}

	/**
	 * Adds the screen under the Firehouse menu.
	 */
	public static function add_page(): void {
		$hook = add_submenu_page(
			Post_Types::MENU,
			__( 'FireHouse settings', 'firehouse' ),
			__( 'Settings', 'firehouse' ),
			Capabilities::SETTINGS,
			self::PAGE,
			array( self::class, 'render' )
		);
		if ( $hook ) {
			add_action(
				'load-' . $hook,
				static function (): void {
					add_filter( 'admin_title', array( self::class, 'title' ) );
					// WordPress's style for a field in error replaces the focus ring with the
					// same red border every field in error has; this puts the ring back.
					wp_add_inline_style( 'common', '.form-table tr.form-invalid.form-required input:focus{box-shadow:0 0 0 2px var(--wp-admin-theme-color,#2271b1)}' );
				}
			);
		}
	}

	/**
	 * Says in the browser's title that a save was refused, so a screen reader's first
	 * words do: a refused save otherwise reloads looking like a successful one.
	 *
	 * @param string $title The admin page's title.
	 */
	public static function title( string $title ): string {
		return array() === self::errors() ? $title : __( 'Not saved:', 'firehouse' ) . ' ' . $title;
	}

	/**
	 * Draws the screen. After a refused save, a summary at the top lists each problem
	 * and links to its field, and each field shows what was typed into it, its error,
	 * and the focus on the first.
	 */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::SETTINGS ) ) {
			return;
		}
		$errors         = self::errors();
		$rejected       = get_transient( self::rejected_key() );
		self::$rejected = is_array( $rejected ) ? $rejected : array();
		delete_transient( self::rejected_key() );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php if ( array() !== $errors ) : ?>
				<div id="firehouse-settings-errors" class="notice notice-error inline">
					<h2>
						<?php
						/* translators: %d: how many problems. */
						echo esc_html( sprintf( _n( 'Not saved: %d problem', 'Not saved: %d problems', count( $errors ), 'firehouse' ), count( $errors ) ) );
						?>
					</h2>
					<p><?php esc_html_e( 'Nothing was changed. Fix these, then save again:', 'firehouse' ); ?></p>
					<ul>
						<?php foreach ( $errors as $id => $message ) : ?>
							<li><a href="#<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $message ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php else : ?>
				<?php settings_errors( 'general' ); ?>
			<?php endif; ?>
			<p><?php esc_html_e( 'Every field is required.', 'firehouse' ); ?></p>
			<form action="options.php" method="post" novalidate>
				<?php
				settings_fields( self::OPTION );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Explains the navigation section.
	 */
	public static function nav_section(): void {
		echo '<p>' . esc_html__( 'The links themselves are fixed; only what they say changes here.', 'firehouse' ) . '</p>';
	}

	/**
	 * Draws one text field, its label pointing at it and its description tied to it.
	 *
	 * @param array{path: string[], description: string, type: string} $args The field.
	 */
	public static function text_field( array $args ): void {
		static $focused = false;

		$id        = self::field_id( $args['path'] );
		$error     = self::errors()[ $id ] ?? null;
		$value     = self::$rejected[ $id ] ?? self::value_at( self::get(), $args['path'] );
		$described = null === $error ? $id . '-description' : $id . '-error ' . $id . '-description';
		$state     = '';
		if ( null !== $error ) {
			$state = ' aria-invalid="true"' . ( $focused ? '' : ' autofocus' );
			// The first field in error takes the focus.
			$focused = true;
		}
		printf(
			'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="regular-text" required aria-describedby="%5$s"%6$s />',
			esc_attr( $args['type'] ),
			esc_attr( $id ),
			esc_attr( self::OPTION . '[' . implode( '][', $args['path'] ) . ']' ),
			esc_attr( $value ),
			esc_attr( $described ),
			$state // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed attributes, written above.
		);
		if ( null !== $error ) {
			printf( '<p class="description" id="%1$s-error"><strong>%2$s</strong> %3$s</p>', esc_attr( $id ), esc_html__( 'Error:', 'firehouse' ), esc_html( $error ) );
		}
		printf( '<p class="description" id="%1$s-description">%2$s</p>', esc_attr( $id ), esc_html( $args['description'] ) );
	}

	/**
	 * Checks a save. If anything is wrong, nothing is changed: each error is recorded
	 * against its field, and what was typed is kept for a minute so the screen can
	 * show it again.
	 *
	 * @param mixed $input The submitted settings.
	 * @return array{siteName: string, submitLabel: string, submitFormUrl: string, navLabels: array<string, string>}
	 */
	public static function sanitize( mixed $input ): array {
		list( $settings, $errors ) = self::validate( $input );
		if ( array() === $errors ) {
			return $settings;
		}

		// WordPress sanitizes a new option twice, once to update it and once to add it.
		$recorded = array_column( get_settings_errors( self::OPTION ), 'code' );
		foreach ( $errors as $id => $message ) {
			if ( ! in_array( $id, $recorded, true ) ) {
				add_settings_error( self::OPTION, $id, esc_html( $message ) );
			}
		}
		set_transient( self::rejected_key(), self::typed( $input ), MINUTE_IN_SECONDS );
		return self::get();
	}

	/**
	 * Checks settings, from the screen or the Import screen, without saving them.
	 *
	 * @param mixed $input The settings to check.
	 * @return array{0: array{siteName: string, submitLabel: string, submitFormUrl: string, navLabels: array<string, string>}, 1: array<string, string>}
	 *         The settings, with each valid field changed; and each error, by field ID.
	 */
	public static function validate( mixed $input ): array {
		$settings = self::get();
		$errors   = array();
		if ( ! is_array( $input ) ) {
			return array( $settings, $errors );
		}

		$labels = array(
			'siteName'    => __( 'Site name', 'firehouse' ),
			'submitLabel' => __( 'Button label', 'firehouse' ),
			'home'        => __( 'Home link', 'firehouse' ),
			'projects'    => __( 'Projects link', 'firehouse' ),
			'about'       => __( 'About link', 'firehouse' ),
		);
		/* translators: %s: a field's label. */
		$empty = __( '%s can’t be empty.', 'firehouse' );

		foreach ( array( 'siteName', 'submitLabel' ) as $key ) {
			$text = self::text( $input[ $key ] ?? null );
			if ( '' === $text ) {
				$errors[ self::field_id( array( $key ) ) ] = sprintf( $empty, $labels[ $key ] );
			} else {
				$settings[ $key ] = $text;
			}
		}
		$url = self::https_url( $input['submitFormUrl'] ?? null );
		if ( null === $url ) {
			$errors[ self::field_id( array( 'submitFormUrl' ) ) ] = __( 'Survey address has to be a web address that starts with https://.', 'firehouse' );
		} else {
			$settings['submitFormUrl'] = $url;
		}
		foreach ( array_keys( self::NAV ) as $key ) {
			$text = self::text( $input['navLabels'][ $key ] ?? null );
			if ( '' === $text ) {
				$errors[ self::field_id( array( 'navLabels', $key ) ) ] = sprintf( $empty, $labels[ $key ] );
			} else {
				$settings['navLabels'][ $key ] = $text;
			}
		}

		return array( $settings, $errors );
	}

	/**
	 * The errors from a refused save, by field ID.
	 *
	 * @return array<string, string>
	 */
	private static function errors(): array {
		$errors = array();
		foreach ( get_settings_errors( self::OPTION ) as $error ) {
			if ( 'error' === ( $error['type'] ?? null ) ) {
				$errors[ (string) $error['code'] ] = wp_specialchars_decode( (string) $error['message'], ENT_QUOTES );
			}
		}
		return $errors;
	}

	/**
	 * What was typed into each field, by field ID.
	 *
	 * @param mixed $input The submitted settings.
	 * @return array<string, string>
	 */
	private static function typed( mixed $input ): array {
		$typed = array();
		if ( ! is_array( $input ) ) {
			return $typed;
		}
		foreach ( array( array( 'siteName' ), array( 'submitLabel' ), array( 'submitFormUrl' ) ) as $path ) {
			$typed[ self::field_id( $path ) ] = self::value_at( $input, $path );
		}
		foreach ( array_keys( self::NAV ) as $key ) {
			$typed[ self::field_id( array( 'navLabels', $key ) ) ] = self::value_at( $input, array( 'navLabels', $key ) );
		}
		return $typed;
	}

	/**
	 * The transient holding the current user's refused save.
	 */
	private static function rejected_key(): string {
		return 'firehouse_settings_rejected_' . get_current_user_id();
	}

	/**
	 * Lays out one field.
	 *
	 * @param string   $section     The section.
	 * @param string[] $path        Where the value is in the option.
	 * @param string   $label       The label.
	 * @param string   $description What it is for.
	 * @param string   $type        The input type.
	 */
	private static function add_field( string $section, array $path, string $label, string $description, string $type = 'text' ): void {
		$id = self::field_id( $path );
		add_settings_field(
			$id,
			$label,
			array( self::class, 'text_field' ),
			self::PAGE,
			$section,
			array(
				'label_for'   => $id,
				'path'        => $path,
				'description' => $description,
				'type'        => $type,
				// WordPress's own style for a field in error: a red border.
				'class'       => isset( self::errors()[ $id ] ) ? 'form-invalid form-required' : '',
			)
		);
	}

	/**
	 * A field's ID: "firehouse-" and its path, such as "firehouse-navLabels-home".
	 *
	 * @param string[] $path Where the value is in the option.
	 */
	private static function field_id( array $path ): string {
		return 'firehouse-' . implode( '-', $path );
	}

	/**
	 * The value at a path in the settings.
	 *
	 * @param array<string, mixed> $settings The settings.
	 * @param string[]             $path     The path.
	 */
	private static function value_at( array $settings, array $path ): string {
		$value = $settings;
		foreach ( $path as $key ) {
			$value = is_array( $value ) ? ( $value[ $key ] ?? '' ) : '';
		}
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Submitted text as one line, or '' if it isn't text.
	 *
	 * @param mixed $value The submitted value.
	 */
	private static function text( mixed $value ): string {
		return is_string( $value ) ? sanitize_text_field( $value ) : '';
	}

	/**
	 * A submitted address if it is an https address with a host, unchanged; otherwise
	 * null. One that WordPress would have to rewrite to make safe is refused rather
	 * than quietly changed.
	 *
	 * @param mixed $value The submitted value.
	 */
	private static function https_url( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$url   = trim( $value );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( $parts['scheme'] ?? '' ) || '' === ( $parts['host'] ?? '' ) ) {
			return null;
		}
		return esc_url_raw( $url, array( 'https' ) ) === $url ? $url : null;
	}
}
