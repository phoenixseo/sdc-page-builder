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
	 * The POT file exists, names the domain, and is not a hand-written stub.
	 */
	public function test_pot_file_declares_the_domain() {
		$pot = dirname( __DIR__, 2 ) . '/languages/sdc-page-builder.pot';
		$this->assertFileExists( $pot, 'languages/sdc-page-builder.pot is missing' );

		$contents = (string) file_get_contents( $pot );
		$this->assertStringContainsString( 'X-Domain: sdc-page-builder', $contents );

		// Placeholder left by the generator would be a broken report link.
		$this->assertStringNotContainsString( 'wordpress.org/support/plugin/plugin', $contents );
	}

	/**
	 * Every translatable string in the shipped source has a POT entry.
	 *
	 * Before this test the POT was checked for two hand-picked strings, which
	 * is why it could fall 164 strings behind while the suite stayed green: the
	 * two strings it looked for were still there, the rest of the plugin was
	 * not. Both directions are checked — a string without an entry, and an
	 * entry no string produces — because a stale POT is exactly as wrong as a
	 * short one.
	 */
	public function test_pot_covers_every_source_string_and_carries_no_orphans() {
		$pot  = dirname( __DIR__, 2 ) . '/languages/sdc-page-builder.pot';
		$ids  = self::pot_entries( (string) file_get_contents( $pot ) );
		$have = array();

		foreach ( $ids as $entry ) {
			$have[ $entry['msgid'] ] = true;
			if ( '' !== $entry['plural'] ) {
				$have[ $entry['plural'] ] = true;
			}
		}

		$missing = array();
		$seen    = array();
		foreach ( self::translatable_sources() as $file ) {
			foreach ( self::strings_in( $file ) as $string ) {
				$seen[ $string ] = basename( $file );
				if ( ! isset( $have[ $string ] ) ) {
					$missing[ $string ] = basename( $file );
				}
			}
		}

		// A regex that matches nothing would make both comparisons below pass
		// while checking nothing. The plugin has well over a hundred shipped
		// strings; anything far below that means the scanner broke, not that
		// the POT got better.
		$this->assertGreaterThan( 100, count( $seen ), 'The source scanner found almost no strings — the pattern is broken, not the POT' );

		$this->assertSame(
			array(),
			$missing,
			'Translatable strings with no POT entry: ' . wp_json_encode( $missing, JSON_UNESCAPED_UNICODE )
		);

		// Entries from the plugin header are generated from the file comment
		// block, not from a gettext call, so they are not in `$seen`.
		$orphans = array();
		foreach ( $ids as $entry ) {
			$from_header = false;
			foreach ( $entry['refs'] as $ref ) {
				if ( ! preg_match( '/:\d+$/', $ref ) ) {
					$from_header = true;
				}
			}
			if ( $from_header ) {
				continue;
			}
			foreach ( array( $entry['msgid'], $entry['plural'] ) as $string ) {
				if ( '' !== $string && ! isset( $seen[ $string ] ) ) {
					$orphans[ $string ] = $entry['refs'][0] ?? '';
				}
			}
		}

		$this->assertSame(
			array(),
			$orphans,
			'POT entries that no source string produces (regenerate the POT): ' . wp_json_encode( $orphans, JSON_UNESCAPED_UNICODE )
		);
	}

	/**
	 * Files whose strings are shipped and translated.
	 *
	 * @return string[]
	 */
	private static function translatable_sources() {
		$base    = dirname( __DIR__, 2 );
		$files   = glob( $base . '/includes/*.php' );
		$files   = array_merge( $files, glob( $base . '/assets/*.js' ) );
		$files[] = $base . '/sdc-page-builder.php';

		return array_values( array_filter( $files, 'is_file' ) );
	}

	/**
	 * Translatable literals inside a PHP or JS source file.
	 *
	 * Only the literal forms the plugin actually uses are recognised: a
	 * single-quoted first argument, for `__`, `_e`, the escaping variants,
	 * `_x`/`_ex`, and `_n`/`_nx` (whose plural is the second argument). A
	 * string built at runtime cannot be translated and is not expected here.
	 *
	 * @param string $file Path.
	 * @return string[]
	 */
	private static function strings_in( $file ) {
		$source = (string) file_get_contents( $file );
		$out    = array();

		$calls = '/\b(__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e|_x|_ex|_n|_nx)\(\s*\'((?:\\\\.|[^\'\\\\])*)\'(?:\s*,\s*\'((?:\\\\.|[^\'\\\\])*)\')?/';

		if ( preg_match_all( $calls, $source, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$out[] = self::unescape_php( $match[2] );

				// For `_n`/`_nx` the plural is a separate translatable string.
				if ( in_array( $match[1], array( '_n', '_nx' ), true ) && isset( $match[3] ) && '' !== $match[3] ) {
					$out[] = self::unescape_php( $match[3] );
				}
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Undo the escapes a single-quoted PHP/JS literal carries.
	 *
	 * @param string $literal Raw literal body.
	 * @return string
	 */
	private static function unescape_php( $literal ) {
		return str_replace( array( '\\\\', '\\\'' ), array( '\\', "'" ), $literal );
	}

	/**
	 * Parse a PO file into msgid/plural/refs entries.
	 *
	 * @param string $contents PO file.
	 * @return array[] Each with `msgid`, `plural` and `refs`.
	 */
	private static function pot_entries( $contents ) {
		$entries = array();
		$current = array(
			'msgid'  => '',
			'plural' => '',
			'refs'   => array(),
		);
		$field   = null;

		foreach ( preg_split( '/\r\n|\n|\r/', $contents ) as $line ) {
			if ( '' === trim( $line ) ) {
				if ( '' !== $current['msgid'] ) {
					$entries[] = $current;
				}
				$current = array(
					'msgid'  => '',
					'plural' => '',
					'refs'   => array(),
				);
				$field   = null;
				continue;
			}

			if ( 0 === strpos( $line, '#:' ) ) {
				foreach ( preg_split( '/\s+/', trim( substr( $line, 2 ) ) ) as $ref ) {
					if ( '' !== $ref ) {
						$current['refs'][] = $ref;
					}
				}
				continue;
			}
			if ( preg_match( '/^msgid "(.*)"$/', $line, $m ) ) {
				$current['msgid'] = self::unescape_po( $m[1] );
				$field            = 'msgid';
				continue;
			}
			if ( preg_match( '/^msgid_plural "(.*)"$/', $line, $m ) ) {
				$current['plural'] = self::unescape_po( $m[1] );
				$field             = 'plural';
				continue;
			}

			/*
			 * Everything after `msgstr` belongs to the translation. Without
			 * this the header's continuation lines (`"Project-Id-Version: …"`)
			 * were appended to the *msgid* of the header entry, which then
			 * looked like one giant untranslatable string.
			 */
			if ( 0 === strpos( $line, 'msgstr' ) ) {
				$field = null;
				continue;
			}
			// A continuation line continues whichever field was last opened.
			if ( preg_match( '/^"(.*)"$/', $line, $m ) && null !== $field ) {
				$current[ $field ] .= self::unescape_po( $m[1] );
			}
		}

		if ( '' !== $current['msgid'] ) {
			$entries[] = $current;
		}

		return $entries;
	}

	/**
	 * Undo PO escapes.
	 *
	 * @param string $value Escaped value.
	 * @return string
	 */
	private static function unescape_po( $value ) {
		return str_replace(
			array( '\\n', '\	', '\\"', '\\\\' ),
			array( "\n", chr( 9 ), '"', '\\' ),
			$value
		);
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
