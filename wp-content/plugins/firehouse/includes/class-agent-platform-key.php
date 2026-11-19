<?php
/**
 * The Google API key an administrator saves on FireHouse → Settings.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the Google Cloud project ID and API key an administrator types on FireHouse →
 * Settings, for a site whose administrators can't edit wp-config.php (Eric, 18 November).
 * Agent_Platform uses it before wp-config.php's constants. Modeled on the TerraViz
 * plugin's service-token setting.
 *
 * - **Its own option and form,** not firehouse_settings: that option is read on every
 *   FireHouse page and saved by FireHouse Editors, and its form's "Nothing was changed"
 *   has to stay true. WordPress lets only an administrator (manage_options) save this
 *   option's group, and sanitize() checks again. Nothing else reads it, and no REST
 *   route shows it.
 * - **The API key is sealed** with libsodium's secretbox, under a sealing key made from
 *   the site's AUTH_KEY and AUTH_SALT, so a copy of the database alone can't use it, and
 *   it doesn't open on a site with other salts (one sharing them would open it; nothing
 *   binds it to the site's address or the project). WordPress supplies secretbox where
 *   PHP's sodium extension is missing (wp-includes/sodium_compat). The project ID isn't
 *   a secret: it's kept as typed, so the screen shows which project the key is for.
 * - **Saved only while wp-config.php sets the salts.** Without them WordPress keeps its
 *   own in the database, beside the sealed API key, and sealing would hide nothing.
 * - **Never shown again.** The API key's box is always empty, and left empty keeps the
 *   API key saved.
 * - **Changed salts** leave an API key that doesn't open. Agent_Platform then uses the
 *   constants if there are any, and the screen and Site Health say to enter it again.
 */
final class Agent_Platform_Key {

	/**
	 * The option, and its settings group.
	 */
	public const OPTION = 'firehouse_google_ai_key';

	/**
	 * The option 0.3.1 kept Cloudflare's Workers AI key in, before the plugin moved to
	 * Google's Agent Platform. Nothing reads it; uninstall.php removes it.
	 */
	public const OLD_OPTION = 'firehouse_workers_ai_key';

	/**
	 * Who may save, change or remove the key: an administrator.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * What a sealed API key starts with, so a later way of sealing can be told apart.
	 */
	private const SEALED = 'fh1.';

	/**
	 * What the sealing key is made from besides the salts, so it's never another
	 * plugin's, nor the one Cloudflare's token was sealed under.
	 */
	private const CONTEXT = 'firehouse-google-ai-key-v1|';

	/**
	 * Hooks the setting.
	 */
	public static function hook(): void {
		add_action( 'admin_init', array( self::class, 'register' ) );
	}

	/**
	 * Registers the option for the screen's key form.
	 */
	public static function register(): void {
		register_setting(
			self::OPTION,
			self::OPTION,
			array(
				'type'              => 'object',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => array(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * The saved project ID and API key, the key opened, for Agent_Platform alone, which
	 * sends it only to Google. Null when there's none, or its API key doesn't open.
	 *
	 * @return array{project: string, key: string}|null
	 */
	public static function credentials(): ?array {
		$stored = self::stored();
		if ( '' === $stored['project'] || '' === $stored['key'] ) {
			return null;
		}
		$key = self::open( $stored['key'] );
		return null === $key ? null : array(
			'project' => $stored['project'],
			'key'     => $key,
		);
	}

	/**
	 * Whether a key is saved here, whether or not it opens.
	 */
	public static function saved(): bool {
		return '' !== self::stored()['key'];
	}

	/**
	 * Whether the saved API key doesn't open: the site's salts have changed since.
	 */
	public static function unreadable(): bool {
		$sealed = self::stored()['key'];
		return '' !== $sealed && null === self::open( $sealed );
	}

	/**
	 * Whether wp-config.php sets the salts the API key is sealed with: AUTH_KEY and
	 * AUTH_SALT are its own, and WordPress uses them rather than ones it keeps in the
	 * database.
	 */
	public static function salts_in_config(): bool {
		if ( ! defined( 'AUTH_KEY' ) || ! defined( 'AUTH_SALT' ) ) {
			return false;
		}
		$key  = constant( 'AUTH_KEY' );
		$salt = constant( 'AUTH_SALT' );
		return is_string( $key ) && is_string( $salt ) && wp_salt( 'auth' ) === $key . $salt;
	}

	/**
	 * Checks a save from the key form. Nothing changes unless all is well: each problem
	 * is recorded against its field, and what's saved stays. An empty API key keeps the
	 * one saved; "Remove the key" takes both away.
	 *
	 * @param mixed $input The form: project, typedKey, and remove.
	 * @return mixed What's saved.
	 */
	public static function sanitize( mixed $input ): mixed {
		$current = get_option( self::OPTION, array() );
		if ( ! current_user_can( self::CAPABILITY ) || ! is_array( $input ) ) {
			return $current;
		}
		// WordPress sanitizes a new option twice, the second time given what the first
		// returned: a sealed API key where the form has a typed one. It's kept as it is if
		// it opens, which only one sealed here can.
		if ( ! array_key_exists( 'typedKey', $input ) ) {
			$project = $input['project'] ?? null;
			$sealed  = $input['key'] ?? null;
			return Agent_Platform::project_shaped( $project ) && is_string( $sealed ) && null !== self::open( $sealed )
				? array(
					'project' => $project,
					'key'     => $sealed,
				)
				: $current;
		}
		if ( '1' === ( $input['remove'] ?? null ) ) {
			return array();
		}

		$project  = is_string( $input['project'] ?? null ) ? trim( $input['project'] ) : '';
		$typed    = is_string( $input['typedKey'] ) ? trim( $input['typedKey'] ) : '';
		$sealed   = self::stored()['key'];
		$problems = array();
		if ( ! Agent_Platform::project_shaped( $project ) ) {
			$problems['project'] = __( 'The project ID has to be 6 to 30 lowercase letters, digits and hyphens, starting with a letter, as the Google Cloud console shows it. It’s the project’s ID, not its name or number.', 'firehouse' );
		}
		if ( '' !== $typed ) {
			if ( ! Agent_Platform::key_shaped( $typed ) ) {
				$problems['key'] = __( 'The API key has to be 30 to 100 letters, digits, hyphens and underscores, as Google gives it. A service account’s JSON key file won’t do: it has to be an API key.', 'firehouse' );
			} elseif ( ! self::salts_in_config() ) {
				$problems['key'] = __( 'This site’s wp-config.php doesn’t set its own AUTH_KEY and AUTH_SALT, so WordPress keeps them in the database, where a copy of it would unlock the API key. Ask whoever runs the server to set them, or to put the key in wp-config.php instead.', 'firehouse' );
			} else {
				$sealed = self::seal( $typed );
				if ( null === $sealed ) {
					$problems['key'] = __( 'The API key couldn’t be sealed, so it wasn’t saved. Try again.', 'firehouse' );
				}
			}
		} elseif ( '' === $sealed ) {
			$problems['key'] = __( 'Type the API key.', 'firehouse' );
		} elseif ( null === self::open( $sealed ) ) {
			$problems['key'] = __( 'The saved API key can’t be read any more, because the site’s salts have changed. Type it again.', 'firehouse' );
		}

		if ( array() !== $problems ) {
			$recorded = array_column( get_settings_errors( self::OPTION ), 'code' );
			foreach ( $problems as $field => $message ) {
				if ( ! in_array( self::field_id( $field ), $recorded, true ) ) {
					add_settings_error( self::OPTION, self::field_id( $field ), esc_html( $message ) );
				}
			}
			return $current;
		}
		return array(
			'project' => $project,
			'key'     => (string) $sealed,
		);
	}

	/**
	 * The problems with a refused save, by field ID.
	 *
	 * @return array<string, string>
	 */
	public static function errors(): array {
		$errors = array();
		foreach ( get_settings_errors( self::OPTION ) as $error ) {
			if ( 'error' === ( $error['type'] ?? null ) ) {
				$errors[ (string) $error['code'] ] = wp_specialchars_decode( (string) $error['message'], ENT_QUOTES );
			}
		}
		return $errors;
	}

	/**
	 * Draws the key's section of the screen, for an administrator: where the key in use
	 * comes from, and a form of its own to save, change or remove the one kept here.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$errors  = self::errors();
		$project = self::stored()['project'];
		$saved   = self::saved();
		$focused = false;
		$state   = static function ( string $id ) use ( $errors, &$focused ): string {
			if ( ! isset( $errors[ $id ] ) ) {
				return '';
			}
			$state   = ' aria-invalid="true"' . ( $focused ? '' : ' autofocus' );
			$focused = true;
			return $state;
		};

		echo '<div id="firehouse-google-ai-key"><h2>' . esc_html__( 'Google API key', 'firehouse' ) . '</h2>';
		echo '<p>' . esc_html__( 'The Google Cloud project and API key Analyze results uses, on FireHouse → Synthesis, to have a Gemini model on Google’s Agent Platform (formerly Vertex AI) draft the synthesis. Only administrators see this. Save a key only once NOAA has approved Google’s Agent Platform for this site.', 'firehouse' ) . '</p>';
		echo '<p><strong>' . esc_html( self::status() ) . '</strong></p>';
		if ( ! self::salts_in_config() ) {
			echo '<p>' . esc_html__( 'This site’s wp-config.php doesn’t set its own AUTH_KEY and AUTH_SALT, so an API key can’t be saved here: ask whoever runs the server to set them, or to put the key in wp-config.php.', 'firehouse' ) . '</p>';
		}
		?>
		<form action="options.php" method="post" novalidate>
			<?php
			// settings_fields() by hand: its nonce field has the ID the form above already
			// gives its own.
			printf( '<input type="hidden" name="option_page" value="%s" />', esc_attr( self::OPTION ) );
			echo '<input type="hidden" name="action" value="update" />';
			printf( '<input type="hidden" name="_wpnonce" value="%s" />', esc_attr( wp_create_nonce( self::OPTION . '-options' ) ) );
			wp_referer_field();
			?>
			<table class="form-table" role="presentation"><tbody>
			<?php
			self::row(
				'project',
				__( 'Project ID', 'firehouse' ),
				'<input type="text" name="' . esc_attr( self::OPTION . '[project]' ) . '" value="' . esc_attr( $project ) . '" class="regular-text code" autocomplete="off" spellcheck="false"' . $state( self::field_id( 'project' ) ),
				__( 'The ID of the Google Cloud project the API key belongs to, as the console’s project picker shows it.', 'firehouse' ),
				$errors
			);
			self::row(
				'key',
				__( 'API key', 'firehouse' ),
				'<input type="password" name="' . esc_attr( self::OPTION . '[typedKey]' ) . '" value="" class="regular-text code" autocomplete="new-password" spellcheck="false"' . $state( self::field_id( 'key' ) ),
				$saved
					? __( 'An API key is saved. It’s never shown again; leave this empty to keep it, or type a new one to replace it.', 'firehouse' )
					: __( 'An API key from that project, bound to a service account that may use Agent Platform, and restricted to the Agent Platform API, so it can do nothing else. It’s sealed before it’s saved, and never shown again.', 'firehouse' ),
				$errors
			);
			if ( $saved ) {
				$id = self::field_id( 'remove' );
				printf(
					'<tr><th scope="row">%1$s</th><td><label for="%2$s"><input type="checkbox" id="%2$s" name="%3$s" value="1" aria-describedby="%2$s-description" /> %4$s</label><p class="description" id="%2$s-description">%5$s</p></td></tr>',
					esc_html__( 'Remove', 'firehouse' ),
					esc_attr( $id ),
					esc_attr( self::OPTION . '[remove]' ),
					esc_html__( 'Remove the key saved here', 'firehouse' ),
					esc_html__( 'Analyze results then uses the key in wp-config.php, if the site has one, or is turned off.', 'firehouse' )
				);
			}
			?>
			</tbody></table>
			<?php submit_button( __( 'Save key', 'firehouse' ), 'secondary', 'firehouse-save-key' ); ?>
		</form></div>
		<?php
	}

	/**
	 * Where the key Analyze results uses comes from, in a sentence.
	 */
	public static function status(): string {
		$source = Agent_Platform::source();
		if ( self::unreadable() ) {
			return Agent_Platform::FROM_CONFIG === $source
				? __( 'A key is saved here, but its API key can’t be read any more, because the site’s salts have changed. Until it’s typed again, Analyze results uses the key in wp-config.php.', 'firehouse' )
				: __( 'A key is saved here, but its API key can’t be read any more, because the site’s salts have changed. Until it’s typed again, Analyze results is turned off.', 'firehouse' );
		}
		if ( Agent_Platform::FROM_SETTINGS === $source ) {
			return __( 'Analyze results uses the key saved here.', 'firehouse' );
		}
		if ( Agent_Platform::FROM_CONFIG === $source ) {
			return __( 'Analyze results uses the key in wp-config.php. A key saved here would be used in its place.', 'firehouse' );
		}
		return __( 'The site has no Google API key, so Analyze results is turned off.', 'firehouse' );
	}

	/**
	 * Draws one field's row: its label, its box, its error and what describes it.
	 *
	 * @param string                $field       "project" or "key".
	 * @param string                $label       The label.
	 * @param string                $input       The box's tag, escaped, up to its ID and what describes it, which this adds.
	 * @param string                $description What it is.
	 * @param array<string, string> $errors      The problems with a refused save, by field ID.
	 */
	private static function row( string $field, string $label, string $input, string $description, array $errors ): void {
		$id    = self::field_id( $field );
		$error = $errors[ $id ] ?? null;
		printf(
			'<tr%1$s><th scope="row"><label for="%2$s">%3$s</label></th><td>%4$s id="%2$s" aria-describedby="%5$s" />',
			null === $error ? '' : ' class="form-invalid form-required"',
			esc_attr( $id ),
			esc_html( $label ),
			$input, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped as the box was made.
			esc_attr( ( null === $error ? '' : $id . '-error ' ) . $id . '-description' )
		);
		if ( null !== $error ) {
			printf( '<p class="description" id="%1$s-error"><strong>%2$s</strong> %3$s</p>', esc_attr( $id ), esc_html__( 'Error:', 'firehouse' ), esc_html( $error ) );
		}
		printf( '<p class="description" id="%1$s-description">%2$s</p></td></tr>', esc_attr( $id ), esc_html( $description ) );
	}

	/**
	 * A field's ID, as "firehouse-google-ai-project".
	 *
	 * @param string $field The field.
	 */
	private static function field_id( string $field ): string {
		return 'firehouse-google-ai-' . $field;
	}

	/**
	 * What's saved, each part '' when there's none.
	 *
	 * @return array{project: string, key: string}
	 */
	private static function stored(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'project' => is_string( $stored['project'] ?? null ) ? $stored['project'] : '',
			'key'     => is_string( $stored['key'] ?? null ) ? $stored['key'] : '',
		);
	}

	/**
	 * An API key sealed for the database, or null if it can't be.
	 *
	 * @param string $key The API key.
	 */
	private static function seal( string $key ): ?string {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return null;
		}
		try {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			return self::SEALED . bin2hex( $nonce . sodium_crypto_secretbox( $key, $nonce, self::sealing_key() ) );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * A sealed API key opened, or null if it doesn't open: not one sealed here, or sealed
	 * under other salts, or changed since.
	 *
	 * @param string $sealed The sealed API key.
	 */
	private static function open( string $sealed ): ?string {
		if ( ! str_starts_with( $sealed, self::SEALED ) || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return null;
		}
		$hex = substr( $sealed, strlen( self::SEALED ) );
		if ( 1 !== preg_match( '/\A(?:[0-9a-f]{2})+\z/', $hex ) ) {
			return null;
		}
		$raw = (string) hex2bin( $hex );
		if ( strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}
		try {
			$key = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::sealing_key() );
		} catch ( \Exception $e ) {
			return null;
		}
		return is_string( $key ) ? $key : null;
	}

	/**
	 * The sealing key: made from the site's auth salts, and this plugin's purpose for it.
	 */
	private static function sealing_key(): string {
		return sodium_crypto_generichash( self::CONTEXT . wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
