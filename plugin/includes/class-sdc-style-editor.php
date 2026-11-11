<?php
/**
 * The admin panel for a component's stylesheet.
 *
 * A component is three files: `definition.json`, `render.php` and
 * `component.css`. The first two are edited on this screen already; this
 * class adds the third. It is the screen only — reading, linting and writing
 * live in `Sdc_View`, next to the same three for the view.
 *
 * Nothing here is per-component plumbing that had to be invented: the
 * stylesheet the panel edits is the one `Sdc_Block::styles()` already
 * delivers, so a saved file is live on the next page load without a cache to
 * clear (the version is an md5 of the contents).
 *
 * @package SdcPageBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the stylesheet panel and hands a posted stylesheet to `Sdc_View`.
 */
class Sdc_Style_Editor {

	/**
	 * Name of the textarea, and the form field the save path reads.
	 */
	const FIELD = 'sdc_css_body';

	/**
	 * Name of the scaffold button.
	 *
	 * A named submit button, like the view's generator: the click travels as
	 * a normal form submission, so the save path can tell it apart without
	 * any JavaScript to keep working.
	 */
	const SCAFFOLD = 'sdc_css_scaffold';

	/**
	 * Nothing to hook. Present so the plugin bootstrap reads like the others.
	 */
	public static function init() {
	}

	/**
	 * The stylesheet panel.
	 *
	 * Renders a block, not a table row: on the component screen it is the
	 * body of its own tab.
	 *
	 * @param string      $type Component type.
	 * @param string|null $css  Stylesheet to show; null reads it from disk.
	 */
	public static function render( $type, $css = null ) {
		if ( ! Sdc_View::can_edit_style( $type ) ) {
			return;
		}

		$own       = Sdc_View::style_file( Sdc_Admin::write_dir(), $type );
		$effective = Sdc_View::style( $type );

		/*
		 * A theme may ship the stylesheet, and the theme comes first in the
		 * lookup order. Writing our copy would then change a file the page
		 * never reads — a save that reports success and changes nothing,
		 * which is the one outcome that makes an editor untrustworthy. So
		 * the panel says where the file comes from and stays read-only.
		 */
		if ( '' !== $effective && wp_normalize_path( $effective ) !== wp_normalize_path( $own ) ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: absolute path of the stylesheet. */
						__( 'Die Styles dieser Komponente kommen aus %s. Diese Datei gehört zum Theme und wird beim nächsten Deploy überschrieben, deshalb ist sie hier nicht bearbeitbar.', 'sdc-page-builder' ),
						self::relative( $effective )
					)
				)
			);

			printf(
				'<textarea rows="18" class="large-text code" readonly spellcheck="false" aria-label="%s">%s</textarea>',
				esc_attr__( 'Styles, schreibgeschützt', 'sdc-page-builder' ),
				esc_textarea( Sdc_View::read_style( $effective ) )
			);

			return;
		}

		if ( null === $css ) {
			$css = Sdc_View::read_style( $own );
		}

		printf(
			'<p><label for="%1$s"><strong>%2$s</strong></label></p>',
			esc_attr( self::FIELD ),
			esc_html__( 'CSS', 'sdc-page-builder' )
		);

		printf(
			'<textarea id="%1$s" name="%1$s" rows="20" class="large-text code" spellcheck="false" aria-describedby="sdc-css-help">%2$s</textarea>',
			esc_attr( self::FIELD ),
			esc_textarea( (string) $css )
		);

		echo '<p class="description" id="sdc-css-help">';
		printf(
			/* translators: 1: opening code tag, 2: closing code tag, 3: component type. */
			esc_html__( 'Alles, was in die Datei gehört, ohne %1$sstyle%2$s-Tag. Wird inline im Kopf der Seite ausgegeben, und nur für die Komponenten, die auf ihr stehen. Der Wrapper heißt %3$s: Regeln darunter greifen nur in dieser Komponente.', 'sdc-page-builder' ),
			'<code>&lt;style&gt;</code>',
			'<code>&lt;/style&gt;</code>',
			'<code>.sdc-component--' . esc_html( (string) $type ) . '</code>'
		);
		echo '</p>';

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: path relative to the WordPress root. */
					__( 'Datei: %s. Leer lassen heißt: die Komponente bringt keine eigenen Styles mit.', 'sdc-page-builder' ),
					self::relative( $own )
				)
			)
		);

		self::render_scaffold( $type, (string) $css );
	}

	/**
	 * The "start from a skeleton" button and its warning.
	 *
	 * @param string $type Component type.
	 * @param string $css  Current stylesheet.
	 */
	private static function render_scaffold( $type, $css ) {
		printf(
			'<p><button type="submit" name="%1$s" value="1" class="button">%2$s</button></p>',
			esc_attr( self::SCAFFOLD ),
			esc_html__( 'Startgerüst einfügen', 'sdc-page-builder' )
		);

		printf(
			'<p class="description">%s</p>',
			'' !== trim( $css )
				? esc_html__(
					'Speichert die Komponente und ersetzt den Text oben durch ein leeres Gerüst mit dem richtigen Selektor. Was jetzt dort steht, geht dabei verloren.',
					'sdc-page-builder'
				)
				: esc_html__(
					'Speichert die Komponente und füllt den Text oben mit einem Gerüst, das mit dem Selektor dieser Komponente anfängt.',
					'sdc-page-builder'
				)
		);
	}

	/**
	 * A path as it reads in a sentence: relative to the WordPress root.
	 *
	 * @param string $file Absolute path.
	 * @return string
	 */
	private static function relative( $file ) {
		$file = (string) $file;

		if ( 0 === strpos( $file, ABSPATH ) ) {
			$file = substr( $file, strlen( ABSPATH ) );
		}

		return $file;
	}

	/**
	 * The stylesheet a save should store.
	 *
	 * @param string $type Component type.
	 * @return string
	 */
	public static function posted_style( $type ) {
		if ( self::wants_scaffold() ) {
			return Sdc_View::style_scaffold( $type );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() in Sdc_Admin::handle().
		return isset( $_POST[ self::FIELD ] ) ? (string) wp_unslash( $_POST[ self::FIELD ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- It is CSS; lint_style() is what judges it.
	}

	/**
	 * Was the scaffold button the one that submitted the form?
	 *
	 * @return bool
	 */
	public static function wants_scaffold() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() in Sdc_Admin::handle().
		return isset( $_POST[ self::SCAFFOLD ] );
	}

	/**
	 * Did the form carry anything about the stylesheet?
	 *
	 * @return bool
	 */
	private static function posted() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() in Sdc_Admin::handle().
		return isset( $_POST[ self::FIELD ] ) || self::wants_scaffold();
	}

	/**
	 * Would a posted stylesheet survive a write?
	 *
	 * Called before `definition.json` is written, so a stylesheet with an
	 * unbalanced brace does not leave the component saved with half of the
	 * edit applied.
	 *
	 * @param string $type Component type.
	 * @return true|WP_Error
	 */
	public static function validate( $type ) {
		if ( ! self::posted() ) {
			return true;
		}

		if ( ! Sdc_View::can_edit_style( $type ) ) {
			return new WP_Error(
				'sdc_style_forbidden',
				__( 'Diese Styles darfst du nicht ändern.', 'sdc-page-builder' )
			);
		}

		return Sdc_View::lint_style( self::posted_style( $type ) );
	}

	/**
	 * Write the stylesheet, but only when the form actually carried one.
	 *
	 * Same distinction as the view: an absent field means "say nothing about
	 * the stylesheet"; an empty one means "this component has none". Without
	 * it, any form that renders no panel would delete the file.
	 *
	 * @param string $dir  Component root directory.
	 * @param string $type Component type.
	 * @return true|WP_Error
	 */
	public static function save( $dir, $type ) {
		$valid = self::validate( $type );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( ! self::posted() ) {
			return true;
		}

		return Sdc_View::write_style( $dir, $type, self::posted_style( $type ) );
	}
}
