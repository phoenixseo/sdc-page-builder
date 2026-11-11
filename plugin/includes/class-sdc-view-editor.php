<?php
/**
 * The admin panel for a component's view.
 *
 * A component is a field definition plus, optionally, a `render.php` that says
 * how it looks. This class prints that second half as an editable field: a
 * textarea with the body, a reference for the helpers a view may call, and a
 * button that generates a starting point from the field definitions.
 *
 * The panel is markup only. Reading, linting and writing live in `Sdc_View`;
 * this class is the screen in front of it.
 *
 * @package SdcPageBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the view editor and hands a posted body to `Sdc_View`.
 */
class Sdc_View_Editor {

	/**
	 * Name of the textarea, and the form field the save path reads.
	 */
	const FIELD = 'sdc_view_body';

	/**
	 * Name of the generate button.
	 *
	 * A named submit button, so the save path can tell a normal save from
	 * "replace the body with a generated one" without any JavaScript. It also
	 * submits the rest of the form, which is what generation needs: the
	 * fields it reads are the ones being saved.
	 */
	const GENERATE = 'sdc_generate_view';

	/**
	 * Nothing to hook. Present so the plugin bootstrap reads like the others.
	 */
	public static function init() {
	}

	/**
	 * The view panel.
	 *
	 * A block, not a table row: on the component screen it is the body of its
	 * own tab, so the surrounding markup belongs to the caller.
	 *
	 * @param string      $type   Component type; empty on the create form.
	 * @param array[]     $fields Field definitions, for the generator.
	 * @param string|null $body   Body to show; null reads it from disk.
	 */
	public static function render( $type, array $fields = array(), $body = null ) {
		if ( ! Sdc_View::can_edit( $type ) ) {
			return;
		}

		if ( null === $body ) {
			$body = Sdc_View::read( Sdc_View::file( Sdc_Admin::write_dir(), $type ) );
		}

		printf(
			'<p><label for="%1$s"><strong>%2$s</strong></label></p>',
			esc_attr( self::FIELD ),
			esc_html__( 'render.php', 'sdc-page-builder' )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Bestimmt, wie diese Komponente ausgegeben wird. Markup mit PHP-Inseln, wie ein Theme-Template. Leer lassen heißt: die Felder werden generisch ausgegeben.', 'sdc-page-builder' )
		);

		printf(
			'<textarea id="%1$s" name="%1$s" rows="16" class="large-text code" spellcheck="false" aria-describedby="sdc-view-help">%2$s</textarea>',
			esc_attr( self::FIELD ),
			esc_textarea( (string) $body )
		);

		echo '<p class="description" id="sdc-view-help">';
		printf(
			/* translators: 1: name of the type variable, 2: name of the data variable, 3: name of the component variable. */
			esc_html__( 'Verfügbar sind %1$s (Typ), %2$s (Feldwerte) und %3$s (der Komponenten-Eintrag), dazu die Helfer unten. Ein Syntaxfehler wird beim Speichern abgelehnt, die Seite bleibt also heil.', 'sdc-page-builder' ),
			'<code>$type</code>',
			'<code>$data</code>',
			'<code>$component</code>'
		);
		echo '</p>';

		if ( ! empty( $fields ) ) {
			self::render_generate( $type, $body );
		} else {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Sobald Felder gespeichert sind, lässt sich hier eine Ansicht aus ihnen erzeugen.', 'sdc-page-builder' )
			);
		}

		self::render_helpers();
	}

	/**
	 * The "generate from fields" button and its warning.
	 *
	 * @param string $type Component type.
	 * @param string $body Current body.
	 */
	private static function render_generate( $type, $body ) {
		$replaces = '' !== trim( (string) $body );

		printf(
			'<p><button type="submit" name="%1$s" value="1" class="button">%2$s</button></p>',
			esc_attr( self::GENERATE ),
			esc_html__( 'Ansicht aus Feldern erzeugen', 'sdc-page-builder' )
		);

		printf(
			'<p class="description">%s</p>',
			$replaces
				? esc_html(
					sprintf(
						/* translators: %s: component type. */
						__( 'Speichert die Komponente und ersetzt den Text oben durch eine Ansicht, die aus den Feldern von „%s" erzeugt ist. Was jetzt dort steht, geht dabei verloren.', 'sdc-page-builder' ),
						$type
					)
				)
				: esc_html(
					sprintf(
						/* translators: %s: component type. */
						__( 'Speichert die Komponente und füllt den Text oben mit einer Ansicht, die aus den Feldern von „%s" erzeugt ist.', 'sdc-page-builder' ),
						$type
					)
				)
		);
	}

	/**
	 * The helper reference.
	 *
	 * Built from `Sdc_View::helpers()`, the same list the code documents
	 * itself with, so the panel cannot fall behind the API.
	 */
	private static function render_helpers() {
		$helpers = Sdc_View::helpers();

		if ( empty( $helpers ) ) {
			return;
		}

		printf( '<h4>%s</h4>', esc_html__( 'Helfer', 'sdc-page-builder' ) );
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Jeder Helfer escapt von sich aus. Wer eigenes Markup braucht, nimmt sdc_value() und escapt selbst.', 'sdc-page-builder' )
		);

		echo '<table class="widefat striped" style="max-width:56em;">';

		foreach ( $helpers as $helper ) {
			if ( ! isset( $helper['signature'] ) ) {
				continue;
			}

			printf(
				'<tr><td style="width:45%%;"><code>%s</code></td><td>%s</td></tr>',
				esc_html( (string) $helper['signature'] ),
				esc_html( isset( $helper['description'] ) ? (string) $helper['description'] : '' )
			);
		}

		echo '</table>';
	}

	/**
	 * The body a save should store.
	 *
	 * Reads the textarea, unless the generate button was pressed: then the
	 * stored body is derived from the fields that are being saved, so the
	 * scaffold matches them instead of the ones from before the save.
	 *
	 * @param string  $type   Component type.
	 * @param array[] $fields Parsed field definitions.
	 * @return string
	 */
	public static function posted_body( $type, array $fields ) {
		if ( self::wants_generate() ) {
			return Sdc_View::scaffold( $type, $fields );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() in handle().
		return isset( $_POST[ self::FIELD ] ) ? (string) wp_unslash( $_POST[ self::FIELD ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- It is PHP source; lint() is what judges it.
	}

	/**
	 * Was the generate button the one that submitted the form?
	 *
	 * @return bool
	 */
	public static function wants_generate() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() in handle().
		return isset( $_POST[ self::GENERATE ] );
	}

	/**
	 * Would a posted view survive a write?
	 *
	 * Called before `definition.json` is written, so a view with a syntax
	 * error does not leave a component saved with half its edit applied. The
	 * actual write lints again — the check here is about the order of the two
	 * files, not about trusting this answer.
	 *
	 * @param string  $type   Component type.
	 * @param array[] $fields Parsed field definitions, for the generator.
	 * @return true|WP_Error
	 */
	public static function validate( $type, array $fields ) {
		if ( ! self::posted() ) {
			return true;
		}

		if ( ! Sdc_View::can_edit( $type ) ) {
			return new WP_Error(
				'sdc_view_forbidden',
				__( 'Diese Ansicht darfst du nicht ändern.', 'sdc-page-builder' )
			);
		}

		return Sdc_View::lint( Sdc_View::normalize( self::posted_body( $type, $fields ) ) );
	}

	/**
	 * Did the form carry anything about the view?
	 *
	 * @return bool
	 */
	private static function posted() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() in handle().
		return isset( $_POST[ self::FIELD ] ) || self::wants_generate();
	}

	/**
	 * Write a view, but only when the form actually carried one.
	 *
	 * A form without the panel (no capability, or a component outside the
	 * writable directory) must not delete a view it never showed: an absent
	 * field means "say nothing about the view", an empty one means "remove
	 * it". Without that distinction, saving a component whose form renders no
	 * panel would silently drop its `render.php`.
	 *
	 * @param string  $dir    Component root directory.
	 * @param string  $type   Component type.
	 * @param array[] $fields Parsed fields, for the generator.
	 * @return true|WP_Error
	 */
	public static function save( $dir, $type, array $fields ) {
		$valid = self::validate( $type, $fields );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( ! self::posted() ) {
			return true;
		}

		return Sdc_View::write( $dir, $type, self::posted_body( $type, $fields ) );
	}
}
