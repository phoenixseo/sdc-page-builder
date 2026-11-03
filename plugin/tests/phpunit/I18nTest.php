<?php
/**
 * I18n: the textdomain is real, not just declared.
 *
 * @package SdcPageBuilder
 */

/**
 * A `Text Domain:` header alone translates nothing. These tests assert the
 * domain is actually loaded, at the right moment (WordPress 6.7 warns when it
 * is touched before `init`), and that the POT file covers the strings.
 */
class I18nTest extends Sdc_TestCase {

	/**
	 * The plugin declares a text domain and a domain path.
	 */
	public function test_plugin_header_declares_text_domain_and_path() {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$data = get_plugin_data( dirname( __DIR__, 2 ) . '/sdc-page-builder.php', false, false );

		$this->assertSame( 'sdc-page-builder', $data['TextDomain'] );
		$this->assertSame( '/languages', $data['DomainPath'] );
	}

	/**
	 * The loading function is hooked to `init`, where WordPress 6.7+ expects it.
	 */
	public function test_textdomain_loading_is_hooked_to_init() {
		$this->assertNotFalse(
			has_action( 'init', 'sdc_pb_load_textdomain' ),
			'sdc_pb_load_textdomain() is not attached to init'
		);
	}

	/**
	 * The textdomain is not touched before `init`.
	 *
	 * WordPress 6.7+ logs "translation loading … triggered too early" when it
	 * is. This asserts the negative directly: at `plugins_loaded`, where the
	 * plugin boots, the domain is still untouched.
	 */
	public function test_textdomain_is_not_loaded_before_init() {
		$this->assertFalse(
			is_textdomain_loaded( 'sdc-page-builder' ),
			'The textdomain was loaded before init — WordPress 6.7+ logs a notice for that'
		);
	}

	/**
	 * With no compiled catalog the domain is not "loaded" — that is correct and
	 * worth pinning: `load_plugin_textdomain()` returns false when there is no
	 * `.mo`, so a missing catalog fails silently rather than erroring, and the
	 * plugin keeps working with its source strings.
	 *
	 * The plugin's source strings are German, so no de_DE catalog ships: a
	 * translation to the same text would be dead weight. Language packs from
	 * wp.org fill this in per locale.
	 */
	public function test_textdomain_loads_without_a_catalog_at_no_cost() {
		do_action( 'init' );

		// Nothing thrown, and the source string is returned unchanged.
		$this->assertSame( 'Gespeichert.', __( 'Gespeichert.', 'sdc-page-builder' ) );
	}

	/**
	 * A compiled catalog does get picked up — proven with a throwaway one, so
	 * the loading path is tested rather than assumed.
	 */
	public function test_a_compiled_catalog_is_used_when_present() {
		$lang_dir = dirname( __DIR__, 2 ) . '/languages';
		$mo_file  = $lang_dir . '/sdc-probe-de_DE.mo';

		if ( ! class_exists( 'MO' ) ) {
			require_once ABSPATH . WPINC . '/pomo/mo.php';
		}
		$mo = new MO();
		$mo->add_entry(
			new Translation_Entry(
				array(
					'singular'     => 'Gespeichert.',
					'translations' => array( 'PROBE-OK' ),
				)
			)
		);
		$this->assertTrue( $mo->export_to_file( $mo_file ) );

		unload_textdomain( 'sdc-page-builder' );
		$loaded = load_textdomain( 'sdc-page-builder', $mo_file, 'de_DE' );
		$result = __( 'Gespeichert.', 'sdc-page-builder' );

		wp_delete_file( $mo_file );

		$this->assertTrue( $loaded, 'load_textdomain() did not accept the catalog' );
		$this->assertSame( 'PROBE-OK', $result, 'The translated string was not used' );
	}

	/**
	 * The POT file exists, names the domain, and carries the plugin's strings.
	 */
	public function test_pot_file_covers_the_plugin_strings() {
		$pot = dirname( __DIR__, 2 ) . '/languages/sdc-page-builder.pot';
		$this->assertFileExists( $pot, 'languages/sdc-page-builder.pot is missing' );

		$contents = (string) file_get_contents( $pot );
		$this->assertStringContainsString( 'X-Domain: sdc-page-builder', $contents );
		$this->assertStringContainsString( 'msgid "Gespeichert."', $contents );
		$this->assertStringContainsString( 'msgid "Speichern fehlgeschlagen."', $contents );

		// Placeholder left by the generator would be a broken report link.
		$this->assertStringNotContainsString( 'wordpress.org/support/plugin/plugin', $contents );
	}

	/**
	 * Every translatable string in the plugin uses the plugin's domain.
	 */
	public function test_all_translation_calls_use_the_plugin_domain() {
		$files   = glob( dirname( __DIR__, 2 ) . '/includes/*.php' );
		$files[] = dirname( __DIR__, 2 ) . '/sdc-page-builder.php';

		$wrong = array();
		foreach ( $files as $file ) {
			$source = (string) file_get_contents( $file );
			if ( preg_match_all( '/(?:__|_e|esc_html__|esc_attr__|_x|_n)\(\s*[^,]+,\s*[\'"]([^\'"]+)[\'"]/', $source, $m ) ) {
				foreach ( $m[1] as $domain ) {
					// `_n()` puts the domain third; the pattern above catches the
					// singular and plural string, so only real differences count.
					if ( 'sdc-page-builder' !== $domain && false === strpos( $domain, ' ' ) ) {
						$wrong[ $domain ] = basename( $file );
					}
				}
			}
		}

		$this->assertSame( array(), $wrong, 'Translatable strings with a foreign text domain: ' . wp_json_encode( $wrong ) );
	}
}
