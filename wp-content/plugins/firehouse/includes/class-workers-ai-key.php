<?php
/**
 * The Workers AI key an administrator saves on FireHouse → Settings.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the Cloudflare account ID and API token an administrator types on FireHouse →
 * Settings, for a site whose wp-config.php nobody at hand can edit (Eric, 18 November).
 * Workers_Ai uses it before wp-config.php's constants. Modeled on the TerraViz
 * plugin's service-token setting.
 *
 * - **Its own option and form,** not firehouse_settings: that option is read on every
 *   FireHouse page and saved by FireHouse Editors, and its form's "Nothing was changed"
 *   has to stay true. WordPress lets only an administrator (manage_options) save this
 *   option's group, and sanitize() checks again. Nothing else reads it, and no REST
 *   route shows it.
 * - **The token is sealed** with libsodium's secretbox, under a key made from the site's
 *   AUTH_KEY and AUTH_SALT, so a copy of the database alone can't use it, and it doesn't
 *   open on another site. WordPress supplies secretbox where PHP's sodium extension is
 *   missing (wp-includes/sodium_compat). The account ID isn't a secret: it's kept as
 *   typed, so the screen shows which account the key is for.
 * - **Saved only while wp-config.php sets the salts.** Without them WordPress keeps its
 *   own in the database, beside the sealed token, and sealing would hide nothing.
 * - **Never shown again.** The token's box is always empty, and left empty keeps the
 *   token saved.
 * - **Changed salts** leave a token that doesn't open. Workers_Ai then uses the
 *   constants if there are any, and the screen and Site Health say to enter it again.
 */
final class Workers_Ai_Key {

	/**
	 * The option, and its settings group.
	 */
	public const OPTION = 'firehouse_workers_ai_key';

	/**
	 * Who may save, change or remove the key: an administrator.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * What a sealed token starts with, so a later way of sealing can be told apart.
	 */
	private const SEALED = 'fh1.';

	/**
	 * What the sealing key is made from besides the salts, so it's never another
	 * plugin's.
	 */
	private const CONTEXT = 'firehouse-workers-ai-key-v1|';

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
	 * The saved key, its token opened, for Workers_Ai alone, which sends it only to
	 * Cloudflare. Null when there's none, or its token doesn't open.
	 *
	 * @return array{account: string, token: string}|null
	 */
	public static function credentials(): ?array {
		$stored = self::stored();
		if ( '' === $stored['account'] || '' === $stored['token'] ) {
			return null;
		}
		$token = self::open( $stored['token'] );
		return null === $token ? null : array(
			'account' => $stored['account'],
			'token'   => $token,
		);
	}

	/**
	 * Whether a key is saved here, whether or not its token opens.
	 */
	public static function saved(): bool {
		return '' !== self::stored()['token'];
	}

	/**
	 * Whether the saved token doesn't open: the site's salts have changed since.
	 */
	public static function unreadable(): bool {
		$sealed = self::stored()['token'];
		return '' !== $sealed && null === self::open( $sealed );
	}

	/**
	 * Whether wp-config.php sets the salts the token is sealed with: AUTH_KEY and
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
	 * is recorded against its field, and what's saved stays. An empty token keeps the one
	 * saved; "Remove the key" takes both away.
	 *
	 * @param mixed $input The form: account, token, and remove.
	 * @return mixed What's saved.
	 */
	public static function sanitize( mixed $input ): mixed {
		$current = get_option( self::OPTION, array() );
		if ( ! current_user_can( self::CAPABILITY ) || ! is_array( $input ) ) {
			return $current;
		}
		// WordPress sanitizes a new option twice, the second time given what the first
		// returned: a sealed token where the form has a typed one. It's kept as it is if
		// it opens, which only one sealed here can.
		if ( ! array_key_exists( 'typedToken', $input ) ) {
			$account = $input['account'] ?? null;
			$sealed  = $input['token'] ?? null;
			return Workers_Ai::account_shaped( $account ) && is_string( $sealed ) && null !== self::open( $sealed )
				? array(
					'account' => $account,
					'token'   => $sealed,
				)
				: $current;
		}
		if ( '1' === ( $input['remove'] ?? null ) ) {
			return array();
		}

		$account  = is_string( $input['account'] ?? null ) ? trim( $input['account'] ) : '';
		$typed    = is_string( $input['typedToken'] ) ? trim( $input['typedToken'] ) : '';
		$sealed   = self::stored()['token'];
		$problems = array();
		if ( ! Workers_Ai::account_shaped( $account ) ) {
			$problems['account'] = __( 'The account ID has to be 32 characters, each a digit or a letter from a to f, as Cloudflare shows it.', 'firehouse' );
		}
		if ( '' !== $typed ) {
			if ( ! Workers_Ai::token_shaped( $typed ) ) {
				$problems['token'] = __( 'The API token has to be 20 to 200 letters, digits, hyphens and underscores, as Cloudflare gives it.', 'firehouse' );
			} elseif ( ! self::salts_in_config() ) {
				$problems['token'] = __( 'This site’s wp-config.php doesn’t set its own AUTH_KEY and AUTH_SALT, so WordPress keeps them in the database, where a copy of it would unlock the token. Ask whoever runs the server to set them, or to put the key in wp-config.php instead.', 'firehouse' );
			} else {
				$sealed = self::seal( $typed );
				if ( null === $sealed ) {
					$problems['token'] = __( 'The API token couldn’t be sealed, so it wasn’t saved. Try again.', 'firehouse' );
				}
			}
		} elseif ( '' === $sealed ) {
			$problems['token'] = __( 'Type the API token.', 'firehouse' );
		} elseif ( null === self::open( $sealed ) ) {
			$problems['token'] = __( 'The saved API token can’t be read any more, because the site’s salts have changed. Type it again.', 'firehouse' );
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
			'account' => $account,
			'token'   => (string) $sealed,
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
		$account = self::stored()['account'];
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

		echo '<div id="firehouse-workers-ai-key"><h2>' . esc_html__( 'Workers AI key', 'firehouse' ) . '</h2>';
		echo '<p>' . esc_html__( 'The Cloudflare account and API token Analyze results uses, on FireHouse → Synthesis, to have a model draft the synthesis. Only administrators see this. Save a key only once NOAA has approved Workers AI for this site.', 'firehouse' ) . '</p>';
		echo '<p><strong>' . esc_html( self::status() ) . '</strong></p>';
		if ( ! self::salts_in_config() ) {
			echo '<p>' . esc_html__( 'This site’s wp-config.php doesn’t set its own AUTH_KEY and AUTH_SALT, so a token can’t be saved here: ask whoever runs the server to set them, or to put the key in wp-config.php.', 'firehouse' ) . '</p>';
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
				'account',
				__( 'Account ID', 'firehouse' ),
				'<input type="text" name="' . esc_attr( self::OPTION . '[account]' ) . '" value="' . esc_attr( $account ) . '" class="regular-text code" autocomplete="off" spellcheck="false"' . $state( self::field_id( 'account' ) ),
				__( 'On the Cloudflare dashboard’s Workers AI page, under “Use REST API”.', 'firehouse' ),
				$errors
			);
			self::row(
				'token',
				__( 'API token', 'firehouse' ),
				'<input type="password" name="' . esc_attr( self::OPTION . '[typedToken]' ) . '" value="" class="regular-text code" autocomplete="new-password" spellcheck="false"' . $state( self::field_id( 'token' ) ),
				$saved
					? __( 'A token is saved. It’s never shown again; leave this empty to keep it, or type a new one to replace it.', 'firehouse' )
					: __( 'Made from Cloudflare’s “Workers AI” API token template, which lets it do nothing else. It’s sealed before it’s saved, and never shown again.', 'firehouse' ),
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
		$source = Workers_Ai::source();
		if ( self::unreadable() ) {
			return Workers_Ai::FROM_CONFIG === $source
				? __( 'A key is saved here, but its token can’t be read any more, because the site’s salts have changed. Until it’s typed again, Analyze results uses the key in wp-config.php.', 'firehouse' )
				: __( 'A key is saved here, but its token can’t be read any more, because the site’s salts have changed. Until it’s typed again, Analyze results is turned off.', 'firehouse' );
		}
		if ( Workers_Ai::FROM_SETTINGS === $source ) {
			return __( 'Analyze results uses the key saved here.', 'firehouse' );
		}
		if ( Workers_Ai::FROM_CONFIG === $source ) {
			return __( 'Analyze results uses the key in wp-config.php. A key saved here would be used in its place.', 'firehouse' );
		}
		return __( 'The site has no Workers AI key, so Analyze results is turned off.', 'firehouse' );
	}

	/**
	 * Draws one field's row: its label, its box, its error and what describes it.
	 *
	 * @param string                $field       "account" or "token".
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
	 * A field's ID, as "firehouse-workers-ai-account".
	 *
	 * @param string $field The field.
	 */
	private static function field_id( string $field ): string {
		return 'firehouse-workers-ai-' . $field;
	}

	/**
	 * What's saved, each part '' when there's none.
	 *
	 * @return array{account: string, token: string}
	 */
	private static function stored(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'account' => is_string( $stored['account'] ?? null ) ? $stored['account'] : '',
			'token'   => is_string( $stored['token'] ?? null ) ? $stored['token'] : '',
		);
	}

	/**
	 * A token sealed for the database, or null if it can't be.
	 *
	 * @param string $token The token.
	 */
	private static function seal( string $token ): ?string {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return null;
		}
		try {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			return self::SEALED . bin2hex( $nonce . sodium_crypto_secretbox( $token, $nonce, self::key() ) );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * A sealed token opened, or null if it doesn't open: not one sealed here, or sealed
	 * under other salts, or changed since.
	 *
	 * @param string $sealed The sealed token.
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
			$token = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
		} catch ( \Exception $e ) {
			return null;
		}
		return is_string( $token ) ? $token : null;
	}

	/**
	 * The sealing key: made from the site's auth salts, and this plugin's purpose for it.
	 */
	private static function key(): string {
		return sodium_crypto_generichash( self::CONTEXT . wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
