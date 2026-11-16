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
 * "Submit a Finding" control, the navigation labels, the survey form's question
 * wording, and, while the site has a Workers AI key, the prompt Analyze results sends.
 *
 * A survey question's wording and help are stored only where they differ from the
 * plugin's own (Survey\Questions::all()), and so is the prompt (Analysis::own_prompt()),
 * so wording nobody changed follows the plugin's when it's updated. The footer
 * settings of the React site aren't here: GSL's footer takes its place. The three
 * navigation links are fixed in code, because their routes are the site's structure;
 * only their labels are settings.
 *
 * Firehouse Editors can change them, through their own capability rather than
 * manage_options, which would open every setting on the site; all but the switch that
 * opens the survey form, which takes manage_options, an administrator's.
 */
final class Settings {

	/**
	 * The option, and the settings group.
	 */
	public const OPTION = 'firehouse_settings';

	/**
	 * The capability that opens and closes the survey form: an administrator's.
	 */
	public const OPEN_SURVEY = 'manage_options';

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
	 * The most characters a survey question's wording or help may have: the longest the
	 * plugin ships, the needs question's help, is under a thousand.
	 */
	public const LONGEST_WORDING = 2000;

	/**
	 * What was typed into each field of a refused save, by field ID, while the screen
	 * is drawn.
	 *
	 * @var array<string, string>
	 */
	private static array $rejected = array();

	/**
	 * Whether a field in error has taken the focus yet, while the screen is drawn.
	 *
	 * @var bool
	 */
	private static bool $focused = false;

	/**
	 * Hooks the setting, the screen and the capability.
	 */
	public static function hook(): void {
		add_action( 'admin_init', array( self::class, 'register' ) );
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_filter( 'option_page_capability_' . self::OPTION, array( self::class, 'capability' ) );
	}

	/**
	 * The settings before anyone has saved them: the React site's wording, and the
	 * survey questions' own. The submit address has no default, so a site can't point at
	 * a wrong one.
	 *
	 * @return array{siteName: string, submitLabel: string, submitFormUrl: string, surveyOpen: bool, navLabels: array<string, string>, questions: array<string, array<string, string>>, synthesisPrompt: string}
	 */
	public static function defaults(): array {
		return array(
			'siteName'        => 'The FireHouse',
			'submitLabel'     => 'Submit a Finding',
			'submitFormUrl'   => '',
			'surveyOpen'      => false,
			'navLabels'       => array(
				'home'     => 'Home',
				'projects' => 'Explore Projects',
				'about'    => 'About',
			),
			'questions'       => array(),
			'synthesisPrompt' => '',
		);
	}

	/**
	 * The settings as the pages use them: what is stored, except that while the survey
	 * form takes submissions and its page exists, the survey address is that page's,
	 * so every Submit a Finding button leads to it.
	 *
	 * @return array{siteName: string, submitLabel: string, submitFormUrl: string, surveyOpen: bool, navLabels: array<string, string>, questions: array<string, array<string, string>>, synthesisPrompt: string}
	 */
	public static function get(): array {
		$settings = self::stored();
		if ( $settings['surveyOpen'] ) {
			$page = Survey\Survey::page();
			if ( null !== $page ) {
				$settings['submitFormUrl'] = (string) get_permalink( $page );
			}
		}
		return $settings;
	}

	/**
	 * The settings as stored, over the defaults. Anything stored that isn't a setting is
	 * left out, and so is a survey address the screen would refuse, which only a write
	 * that skipped it could have stored: the public pages show no Submit button rather
	 * than one that goes nowhere (rule 6).
	 *
	 * @return array{siteName: string, submitLabel: string, submitFormUrl: string, surveyOpen: bool, navLabels: array<string, string>, questions: array<string, array<string, string>>, synthesisPrompt: string}
	 */
	public static function stored(): array {
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
		$settings['surveyOpen'] = true === ( $stored['surveyOpen'] ?? false );
		foreach ( array_keys( self::NAV ) as $key ) {
			if ( is_string( $stored['navLabels'][ $key ] ?? null ) ) {
				$settings['navLabels'][ $key ] = $stored['navLabels'][ $key ];
			}
		}
		if ( is_array( $stored['questions'] ?? null ) ) {
			foreach ( $stored['questions'] as $name => $parts ) {
				foreach ( Survey\Questions::WORDING as $part ) {
					$text = is_array( $parts ) ? ( $parts[ $part ] ?? null ) : null;
					// A question's wording is never empty; its help may be.
					if ( is_string( $name ) && is_string( $text ) && ( 'help' === $part || '' !== $text ) ) {
						$settings['questions'][ $name ][ $part ] = $text;
					}
				}
			}
		}
		if ( is_string( $stored['synthesisPrompt'] ?? null ) ) {
			$settings['synthesisPrompt'] = $stored['synthesisPrompt'];
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
		self::add_field( 'firehouse_submit', array( 'submitFormUrl' ), __( 'Survey address', 'firehouse' ), __( 'Where every Submit a Finding button goes while the FireHouse survey form doesn’t accept submissions: the survey form used until then. It must start with https://.', 'firehouse' ), 'url' );
		add_settings_field(
			self::field_id( array( 'surveyOpen' ) ),
			__( 'Survey form', 'firehouse' ),
			array( self::class, 'survey_field' ),
			self::PAGE,
			'firehouse_submit'
		);

		add_settings_section( 'firehouse_nav', __( 'Navigation', 'firehouse' ), array( self::class, 'nav_section' ), self::PAGE );
		self::add_field( 'firehouse_nav', array( 'navLabels', 'home' ), __( 'Home link', 'firehouse' ), __( 'Goes to the FireHouse landing page.', 'firehouse' ) );
		self::add_field( 'firehouse_nav', array( 'navLabels', 'projects' ), __( 'Projects link', 'firehouse' ), __( 'Goes to the list of projects.', 'firehouse' ) );
		self::add_field( 'firehouse_nav', array( 'navLabels', 'about' ), __( 'About link', 'firehouse' ), __( 'Goes to the About page.', 'firehouse' ) );

		// Drawn by its own callback, so the questions are read only to draw the screen.
		add_settings_section( 'firehouse_questions', __( 'Survey questions', 'firehouse' ), array( self::class, 'questions_section' ), self::PAGE );

		// Only while Analyze results can run: without the key, the prompt does nothing.
		if ( Workers_Ai::configured() ) {
			add_settings_section( 'firehouse_synthesis', __( 'Synthesis prompt', 'firehouse' ), '__return_null', self::PAGE );
			$id = self::field_id( array( 'synthesisPrompt' ) );
			add_settings_field(
				$id,
				__( 'Prompt', 'firehouse' ),
				array( self::class, 'prompt_field' ),
				self::PAGE,
				'firehouse_synthesis',
				array(
					'label_for' => $id,
					'class'     => isset( self::errors()[ $id ] ) ? 'form-invalid form-required' : '',
				)
			);
		}
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
					// same red border every field in error has; this puts the ring back. Its
					// red border leaves out textareas, so a survey question's box in error
					// gets one here. A field a link in the summary goes to stops below the
					// toolbar, with its label in view. A question's heading looks like the
					// other rows' names.
					wp_add_inline_style( 'common', '.form-table tr.form-invalid.form-required input:focus,.form-table tr.form-invalid.form-required textarea:focus{box-shadow:0 0 0 2px var(--wp-admin-theme-color,#2271b1)}.form-table tr.form-invalid textarea[aria-invalid=true]{border-color:#d63638}.form-table input,.form-table textarea{scroll-margin-top:6em}#firehouse-questions th h3{font-size:inherit;font-weight:inherit;line-height:inherit;margin:0}' );
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
		self::$focused  = false;
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
			<p><?php Workers_Ai::configured() ? esc_html_e( 'Every field is required, except a survey question’s help and the synthesis prompt.', 'firehouse' ) : esc_html_e( 'Every field is required, except a survey question’s help.', 'firehouse' ); ?></p>
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
	 * Draws the switch that opens the FireHouse survey form, with the mark that the
	 * screen sent it.
	 */
	public static function survey_field(): void {
		$id      = self::field_id( array( 'surveyOpen' ) );
		$may     = current_user_can( self::OPEN_SURVEY );
		$details = __( 'The FireHouse survey form at /firehouse/submit/ takes submissions, and every Submit a Finding button leads to it; turning this on creates that page if it’s missing. Leave it off on gsl.noaa.gov until the privacy review and the Paperwork Reduction Act answer are in hand.', 'firehouse' );
		if ( $may ) {
			printf( '<input type="hidden" name="%s" value="1" />', esc_attr( self::OPTION . '[surveyOpenShown]' ) );
		} else {
			$details .= ' ' . __( 'Only an administrator can change this.', 'firehouse' );
		}
		printf(
			'<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1" aria-describedby="%1$s-description"%3$s%4$s /> %5$s</label>',
			esc_attr( $id ),
			esc_attr( self::OPTION . '[surveyOpen]' ),
			checked( self::stored()['surveyOpen'], true, false ),
			$may ? '' : ' disabled',
			esc_html__( 'Accept submissions', 'firehouse' )
		);
		printf( '<p class="description" id="%1$s-description">%2$s</p>', esc_attr( $id ), esc_html( $details ) );
	}

	/**
	 * Draws one text field, its label pointing at it and its description tied to it.
	 *
	 * @param array{path: string[], description: string, type: string} $args The field.
	 */
	public static function text_field( array $args ): void {
		$id        = self::field_id( $args['path'] );
		$error     = self::errors()[ $id ] ?? null;
		$value     = self::$rejected[ $id ] ?? self::value_at( self::stored(), $args['path'] );
		$described = null === $error ? $id . '-description' : $id . '-error ' . $id . '-description';
		$state     = null === $error ? '' : self::invalid();
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
	 * Draws the survey questions, a row each: the question as the form asks it, and the
	 * help under it. A field changed from the plugin's wording says so and gives the
	 * plugin's, so it can be put back; a private answer's help says what the form always
	 * adds after it.
	 */
	public static function questions_section(): void {
		echo '<div id="firehouse-questions"><p>' . esc_html__( 'The wording of each question on the FireHouse survey form, and the help under it. A question’s choices, and its place in the form, are fixed: changing them is a code change. Clear a question’s help to show none.', 'firehouse' ) . '</p>';
		$changed = self::stored()['questions'];
		$errors  = self::errors();
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( Survey\Questions::all() as $name => $question ) {
			$invalid = isset( $errors[ self::field_id( array( 'questions', $name, 'label' ) ) ] ) || isset( $errors[ self::field_id( array( 'questions', $name, 'help' ) ) ] );
			// A heading each, so a screen reader's heading keys go question by question.
			printf(
				'<tr%1$s><th scope="row"><h3>%2$s</h3></th><td><fieldset><legend class="screen-reader-text">%2$s</legend>',
				$invalid ? ' class="form-invalid form-required"' : '',
				esc_html( (string) $question['name'] )
			);
			self::wording_field( $name, 'label', $question, $changed );
			self::wording_field( $name, 'help', $question, $changed );
			echo '</fieldset></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Draws the prompt Analyze results sends: the one changed here, or the plugin's own.
	 */
	public static function prompt_field(): void {
		$id      = self::field_id( array( 'synthesisPrompt' ) );
		$error   = self::errors()[ $id ] ?? null;
		$changed = self::stored()['synthesisPrompt'];
		$value   = self::$rejected[ $id ] ?? ( '' !== $changed ? $changed : Analysis::own_prompt() );
		$notes   = array();
		if ( null !== $error ) {
			$notes[ $id . '-error' ] = '<strong>' . esc_html__( 'Error:', 'firehouse' ) . '</strong> ' . esc_html( $error );
		}
		$notes[ $id . '-description' ] = esc_html__( 'What Analyze results on FireHouse → Synthesis asks the model to do. The published projects go before it, and the screen tells the model, in words this can’t change, that they’re data and never instructions. Each draft keeps the prompt it was given; to compare a change fairly, run the same model before and after it.', 'firehouse' );
		$notes[ $id . '-own' ]         = esc_html(
			'' !== $changed && Analysis::own_prompt() !== $changed
				? __( 'Changed from the plugin’s own prompt, which NotebookLM is given too. To go back to it, clear the box and save.', 'firehouse' )
				: __( 'The plugin’s own prompt, which NotebookLM is given too. Clear the box and save to go back to it after a change.', 'firehouse' )
		);
		printf(
			'<textarea id="%1$s" name="%2$s" rows="18" class="large-text code" aria-describedby="%3$s"%4$s>%5$s</textarea>',
			esc_attr( $id ),
			esc_attr( self::OPTION . '[synthesisPrompt]' ),
			esc_attr( implode( ' ', array_keys( $notes ) ) ),
			null === $error ? '' : self::invalid(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed attributes.
			esc_textarea( $value )
		);
		foreach ( $notes as $note_id => $note ) {
			printf( '<p class="description" id="%1$s">%2$s</p>', esc_attr( $note_id ), $note ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped as each note was made.
		}
	}

	/**
	 * Draws one question's wording or help: its box, its error, and what describes it.
	 *
	 * @param string                               $name     The question's name.
	 * @param string                               $part     "label" or "help".
	 * @param array<string, mixed>                 $question The question, in the plugin's wording.
	 * @param array<string, array<string, string>> $changed  The stored changes.
	 */
	private static function wording_field( string $name, string $part, array $question, array $changed ): void {
		$id    = self::field_id( array( 'questions', $name, $part ) );
		$error = self::errors()[ $id ] ?? null;
		$own   = (string) ( $question[ $part ] ?? '' );
		$value = self::$rejected[ $id ] ?? $changed[ $name ][ $part ] ?? $own;
		$notes = array();
		if ( null !== $error ) {
			$notes[ $id . '-error' ] = '<strong>' . esc_html__( 'Error:', 'firehouse' ) . '</strong> ' . esc_html( $error );
		}
		if ( isset( $changed[ $name ][ $part ] ) && $changed[ $name ][ $part ] !== $own ) {
			$notes[ $id . '-own' ] = esc_html(
				'' === $own
					? __( 'Changed: the plugin’s own wording has no help here.', 'firehouse' )
					/* translators: %s: the plugin's own wording of the question or its help. */
					: sprintf( __( 'Changed. The plugin’s own wording: “%s”', 'firehouse' ), $own )
			);
		}
		if ( 'help' === $part && isset( $question['privacy'] ) ) {
			/* translators: %s: the fixed line saying who sees a private answer. */
			$notes[ $id . '-privacy' ] = esc_html( sprintf( __( 'Under the help, the form always says, and this screen can’t change: “%s”', 'firehouse' ), (string) $question['privacy'] ) );
		}

		// Named for its question too, so a list of the screen's fields tells the thirty
		// boxes apart; the visible words still start the name.
		printf(
			'<p><label for="%1$s">%2$s<span class="screen-reader-text"> (%3$s)</span></label></p>',
			esc_attr( $id ),
			esc_html( 'label' === $part ? __( 'Question', 'firehouse' ) : __( 'Help under it', 'firehouse' ) ),
			esc_html( (string) $question['name'] )
		);
		printf(
			'<p><textarea id="%1$s" name="%2$s" rows="%3$d" class="large-text"%4$s%5$s%6$s>%7$s</textarea></p>',
			esc_attr( $id ),
			esc_attr( self::OPTION . '[questions][' . $name . '][' . $part . ']' ),
			'label' === $part ? 2 : 3,
			'label' === $part ? ' required' : '',
			array() === $notes ? '' : ' aria-describedby="' . esc_attr( implode( ' ', array_keys( $notes ) ) ) . '"',
			null === $error ? '' : self::invalid(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed attributes.
			esc_textarea( $value )
		);
		foreach ( $notes as $note_id => $note ) {
			printf( '<p class="description" id="%1$s">%2$s</p>', esc_attr( $note_id ), $note ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped as each note was made.
		}
	}

	/**
	 * A field in error's attributes: invalid, and, for the first on the screen, the
	 * focus.
	 */
	private static function invalid(): string {
		$state         = ' aria-invalid="true"' . ( self::$focused ? '' : ' autofocus' );
		self::$focused = true;
		return $state;
	}

	/**
	 * Checks a save. If anything is wrong, nothing is changed: each error is recorded
	 * against its field, and what was typed is kept for a minute so the screen can
	 * show it again.
	 *
	 * @param mixed $input The submitted settings.
	 * @return array{siteName: string, submitLabel: string, submitFormUrl: string, surveyOpen: bool, navLabels: array<string, string>, questions: array<string, array<string, string>>, synthesisPrompt: string}
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
		return self::stored();
	}

	/**
	 * Checks settings, from the screen or the Import screen, without saving them.
	 *
	 * @param mixed $input The settings to check.
	 * @return array{0: array{siteName: string, submitLabel: string, submitFormUrl: string, surveyOpen: bool, navLabels: array<string, string>, questions: array<string, array<string, string>>, synthesisPrompt: string}, 1: array<string, string>}
	 *         The settings, with each valid field changed; and each error, by field ID.
	 */
	public static function validate( mixed $input ): array {
		$settings = self::stored();
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
		// Only the settings screen sends the switch, with a mark that it did: an unticked
		// box sends nothing, and the Import screen's settings have no switch to keep.
		// Only an administrator changes it: opening the form starts collecting people's
		// details, which waits on the privacy review and the PRA answer.
		if ( isset( $input['surveyOpenShown'] ) && current_user_can( self::OPEN_SURVEY ) ) {
			$settings['surveyOpen'] = '1' === ( $input['surveyOpen'] ?? null );
		}
		foreach ( array_keys( self::NAV ) as $key ) {
			$text = self::text( $input['navLabels'][ $key ] ?? null );
			if ( '' === $text ) {
				$errors[ self::field_id( array( 'navLabels', $key ) ) ] = sprintf( $empty, $labels[ $key ] );
			} else {
				$settings['navLabels'][ $key ] = $text;
			}
		}
		// Only the settings screen sends the survey's wording: the Import screen's
		// settings have none, and keep what's stored. A part the input doesn't hold, or
		// that's the plugin's own wording, isn't stored, so it follows the plugin's.
		if ( is_array( $input['questions'] ?? null ) ) {
			$was                   = $settings['questions'];
			$settings['questions'] = array();
			foreach ( Survey\Questions::all() as $name => $question ) {
				foreach ( Survey\Questions::WORDING as $part ) {
					if ( ! isset( $input['questions'][ $name ][ $part ] ) ) {
						continue;
					}
					list( $text, $problem ) = self::wording( $input['questions'][ $name ][ $part ], $part );
					if ( null !== $problem ) {
						/* translators: 1: the question's short name, such as "Your name"; 2: what's wrong. */
						$errors[ self::field_id( array( 'questions', $name, $part ) ) ] = sprintf( __( '%1$s: %2$s', 'firehouse' ), $question['name'], $problem );
						if ( isset( $was[ $name ][ $part ] ) ) {
							$settings['questions'][ $name ][ $part ] = $was[ $name ][ $part ];
						}
					} elseif ( (string) ( $question[ $part ] ?? '' ) !== $text ) {
						$settings['questions'][ $name ][ $part ] = $text;
					}
				}
			}
		}
		// Likewise the prompt, which only the settings screen sends, and only while the
		// site has a key. Empty, or the plugin's own, stores nothing, so it follows the
		// plugin's.
		if ( isset( $input['synthesisPrompt'] ) ) {
			list( $text, $problem ) = self::prompt( $input['synthesisPrompt'] );
			if ( null !== $problem ) {
				$errors[ self::field_id( array( 'synthesisPrompt' ) ) ] = $problem;
			} else {
				$settings['synthesisPrompt'] = Analysis::own_prompt() === $text ? '' : $text;
			}
		}

		return array( $settings, $errors );
	}

	/**
	 * A typed prompt, its lines kept, and what's wrong with it, if anything: a character
	 * that doesn't show, which a model would read though the team doesn't see it; or too
	 * long.
	 *
	 * @param mixed $value What was typed.
	 * @return array{0: string, 1: string|null} The prompt, "" for the plugin's own, and what's wrong with it.
	 */
	private static function prompt( mixed $value ): array {
		$value = is_string( $value ) ? $value : '';
		if ( 1 !== preg_match( '//u', $value ) || 1 === preg_match( Survey\Submission::HIDDEN, $value ) ) {
			return array( '', __( 'The synthesis prompt has a character that doesn’t show, such as a direction mark. Type it again.', 'firehouse' ) );
		}
		$text = trim( str_replace( array( "\r\n", "\r" ), "\n", $value ) );
		if ( mb_strlen( $text ) > Analysis::LONGEST_PROMPT ) {
			/* translators: %s: the most characters allowed. */
			return array( $text, sprintf( __( 'The synthesis prompt has more than %s characters.', 'firehouse' ), number_format_i18n( Analysis::LONGEST_PROMPT ) ) );
		}
		return array( $text, null );
	}

	/**
	 * A survey question's typed wording or help as one line, as the form shows it, and
	 * what's wrong with it, if anything: empty, for the wording; a character that
	 * doesn't show, as a submission refuses one; too long; or an email address, which
	 * the site shows only on the About page.
	 *
	 * @param mixed  $value What was typed.
	 * @param string $part  "label" (the question, which can't be empty) or "help".
	 * @return array{0: string, 1: string|null} The text, and what's wrong with it.
	 */
	private static function wording( mixed $value, string $part ): array {
		$value   = is_string( $value ) ? $value : '';
		$subject = 'label' === $part ? __( 'the question', 'firehouse' ) : __( 'the help', 'firehouse' );
		if ( 1 !== preg_match( '//u', $value ) || 1 === preg_match( Survey\Submission::HIDDEN, $value ) ) {
			/* translators: %s: "the question" or "the help". */
			return array( '', sprintf( __( '%s has a character that doesn’t show, such as a direction mark. Type it again.', 'firehouse' ), $subject ) );
		}
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
		if ( 'label' === $part && '' === $text ) {
			/* translators: %s: "the question". */
			return array( $text, sprintf( __( '%s can’t be empty.', 'firehouse' ), $subject ) );
		}
		if ( mb_strlen( $text ) > self::LONGEST_WORDING ) {
			/* translators: 1: "the question" or "the help"; 2: the most characters allowed. */
			return array( $text, sprintf( __( '%1$s has more than %2$s characters.', 'firehouse' ), $subject, number_format_i18n( self::LONGEST_WORDING ) ) );
		}
		if ( Domain\Email_Shape::has_email( $text ) ) {
			/* translators: %s: "the question" or "the help". */
			return array( $text, sprintf( __( '%s can’t hold an email address. The About page gives the team’s.', 'firehouse' ), $subject ) );
		}
		return array( $text, null );
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
		if ( is_array( $input['questions'] ?? null ) ) {
			foreach ( array_keys( Survey\Questions::all() ) as $name ) {
				foreach ( Survey\Questions::WORDING as $part ) {
					if ( isset( $input['questions'][ $name ][ $part ] ) ) {
						$typed[ self::field_id( array( 'questions', $name, $part ) ) ] = self::value_at( $input, array( 'questions', $name, $part ) );
					}
				}
			}
		}
		if ( isset( $input['synthesisPrompt'] ) ) {
			$typed[ self::field_id( array( 'synthesisPrompt' ) ) ] = self::value_at( $input, array( 'synthesisPrompt' ) );
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
