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
			'siteName'      => 'The Firehouse',
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
	 * setting is left out.
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
		foreach ( array( 'siteName', 'submitLabel', 'submitFormUrl' ) as $key ) {
			if ( is_string( $stored[ $key ] ?? null ) ) {
				$settings[ $key ] = $stored[ $key ];
			}
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
		self::add_field( 'firehouse_site', array( 'siteName' ), __( 'Site name', 'firehouse' ), __( 'The name in the Firehouse bar at the top of every Firehouse page.', 'firehouse' ) );

		add_settings_section( 'firehouse_submit', __( 'Submit a Finding', 'firehouse' ), '__return_null', self::PAGE );
		self::add_field( 'firehouse_submit', array( 'submitLabel' ), __( 'Button label', 'firehouse' ), __( 'The words on every button and link that asks researchers to submit.', 'firehouse' ) );
		self::add_field( 'firehouse_submit', array( 'submitFormUrl' ), __( 'Survey address', 'firehouse' ), __( 'Where those buttons and links go: the survey form. It must start with https://.', 'firehouse' ), 'url' );

		add_settings_section( 'firehouse_nav', __( 'Navigation', 'firehouse' ), array( self::class, 'nav_section' ), self::PAGE );
		self::add_field( 'firehouse_nav', array( 'navLabels', 'home' ), __( 'Home link', 'firehouse' ), __( 'Goes to the Firehouse landing page.', 'firehouse' ) );
		self::add_field( 'firehouse_nav', array( 'navLabels', 'projects' ), __( 'Projects link', 'firehouse' ), __( 'Goes to the list of projects.', 'firehouse' ) );
		self::add_field( 'firehouse_nav', array( 'navLabels', 'about' ), __( 'About link', 'firehouse' ), __( 'Goes to the About page.', 'firehouse' ) );
	}

	/**
	 * Adds the screen under the Firehouse menu.
	 */
	public static function add_page(): void {
		add_submenu_page(
			Post_Types::MENU,
			__( 'Firehouse settings', 'firehouse' ),
			__( 'Settings', 'firehouse' ),
			Capabilities::SETTINGS,
			self::PAGE,
			array( self::class, 'render' )
		);
	}

	/**
	 * Draws the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::SETTINGS ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php settings_errors(); ?>
			<p><?php esc_html_e( 'Every field is required.', 'firehouse' ); ?></p>
			<form action="options.php" method="post">
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
		$id    = self::field_id( $args['path'] );
		$value = self::value_at( self::get(), $args['path'] );
		printf(
			'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="regular-text" required aria-describedby="%2$s-description" />',
			esc_attr( $args['type'] ),
			esc_attr( $id ),
			esc_attr( self::OPTION . '[' . implode( '][', $args['path'] ) . ']' ),
			esc_attr( $value )
		);
		printf( '<p class="description" id="%1$s-description">%2$s</p>', esc_attr( $id ), esc_html( $args['description'] ) );
	}

	/**
	 * Checks what was submitted. A field that is empty or wrong keeps what it had, and
	 * the error names it and links to it.
	 *
	 * @param mixed $input The submitted settings.
	 * @return array{siteName: string, submitLabel: string, submitFormUrl: string, navLabels: array<string, string>}
	 */
	public static function sanitize( mixed $input ): array {
		$settings = self::get();
		if ( ! is_array( $input ) ) {
			return $settings;
		}

		$labels = array(
			'siteName'    => __( 'Site name', 'firehouse' ),
			'submitLabel' => __( 'Button label', 'firehouse' ),
			'home'        => __( 'Home link', 'firehouse' ),
			'projects'    => __( 'Projects link', 'firehouse' ),
			'about'       => __( 'About link', 'firehouse' ),
		);
		foreach ( array( 'siteName', 'submitLabel' ) as $key ) {
			$text = self::text( $input[ $key ] ?? null );
			if ( '' === $text ) {
				self::error( array( $key ), $labels[ $key ], __( 'can’t be empty.', 'firehouse' ) );
			} else {
				$settings[ $key ] = $text;
			}
		}
		foreach ( array_keys( self::NAV ) as $key ) {
			$text = self::text( $input['navLabels'][ $key ] ?? null );
			if ( '' === $text ) {
				self::error( array( 'navLabels', $key ), $labels[ $key ], __( 'can’t be empty.', 'firehouse' ) );
			} else {
				$settings['navLabels'][ $key ] = $text;
			}
		}

		$url = self::https_url( $input['submitFormUrl'] ?? null );
		if ( null === $url ) {
			self::error( array( 'submitFormUrl' ), __( 'Survey address', 'firehouse' ), __( 'has to be a web address that starts with https://.', 'firehouse' ) );
		} else {
			$settings['submitFormUrl'] = $url;
		}

		return $settings;
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
		add_settings_field(
			self::field_id( $path ),
			$label,
			array( self::class, 'text_field' ),
			self::PAGE,
			$section,
			array(
				'label_for'   => self::field_id( $path ),
				'path'        => $path,
				'description' => $description,
				'type'        => $type,
			)
		);
	}

	/**
	 * Records an error naming a field and linking to it.
	 *
	 * @param string[] $path    Where the value is in the option.
	 * @param string   $label   The field's label.
	 * @param string   $problem What is wrong, completing a sentence that starts with the label.
	 */
	private static function error( array $path, string $label, string $problem ): void {
		// WordPress sanitizes a new option twice, once to update it and once to add it.
		$code = self::field_id( $path );
		if ( in_array( $code, array_column( get_settings_errors( self::OPTION ), 'code' ), true ) ) {
			return;
		}
		add_settings_error(
			self::OPTION,
			self::field_id( $path ),
			sprintf( '<a href="#%1$s">%2$s</a> %3$s', esc_attr( self::field_id( $path ) ), esc_html( $label ), esc_html( $problem ) )
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
