<?php
/**
 * A component without a view has to say so.
 *
 * The case behind this file: a hero disappeared from a page and nothing was
 * written anywhere — no log line, no marker, the page simply looked a little
 * shorter. Two different faults produce that silence (an unknown type, and a
 * known type whose render.php is gone) and they have to stay distinguishable
 * from a component that renders correctly but output deliberately nothing.
 *
 * @package Sdc_Page_Builder
 */

/**
 * Missing views are logged, and marked for editors only.
 *
 * @covers Sdc_Frontend::has_view
 * @covers Sdc_Frontend::report_missing
 */
class MissingViewTest extends Sdc_TestCase {

	/**
	 * File error_log() is pointed at during one test.
	 *
	 * @var string
	 */
	private $log_file = '';

	/**
	 * Scratch directory holding the views this test writes.
	 *
	 * @var string
	 */
	private $views = '';

	/**
	 * Every scratch directory to remove again, in creation order.
	 *
	 * @var string[]
	 */
	private $view_dirs = array();

	/**
	 * Redirect error_log() into a file for the duration of a test.
	 *
	 * PHPUnit's own expectation helpers cannot do this: error_log() is a
	 * plain function, not a throwable, and `expectError()` in WP_UnitTestCase
	 * catches PHP errors only. An ini redirect is the seam that observes
	 * exactly what an operator would find in the log — the code under test
	 * calls error_log() with no destination, so the ini setting is the only
	 * thing that decides where the line lands.
	 */
	public function set_up() {
		parent::set_up();

		$this->log_file = $this->temp_log();

		ini_set( 'error_log', $this->log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Redirect into a temp file for the assertion.
	}

	/**
	 * Undo the redirection and every file and filter this test installed.
	 */
	public function tear_down() {
		ini_set( 'error_log', '' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Undo the redirect above.

		remove_filter( 'sdc_pb_component_dirs', array( $this, 'prepend_view_dir' ) );

		foreach ( $this->view_dirs as $dir ) {
			self::rmdir_tree( $dir );
		}
		$this->view_dirs = array();
		$this->views     = '';

		Sdc_Schema::flush();

		parent::tear_down();
	}

	/**
	 * Remove a directory and what this test put in it.
	 *
	 * Only ever called on a path built under the system temp dir with the
	 * test's own prefix, so it cannot reach anything a user cares about.
	 *
	 * @param string $dir Directory.
	 */
	private static function rmdir_tree( $dir ) {
		$dir = (string) $dir;
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}

		$items = scandir( $dir );
		foreach ( (array) $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				self::rmdir_tree( $path );
			} else {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Scratch file of this test.
			}
		}

		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Scratch directory of this test.
	}

	/**
	 * A path in the scratch dir that no other test writes to.
	 *
	 * @return string
	 */
	private function temp_log() {
		$file = tempnam( sys_get_temp_dir(), 'sdc-log-' );
		return is_string( $file ) ? $file : sys_get_temp_dir() . '/sdc-log-fallback';
	}

	/**
	 * Everything error_log() wrote since set_up(), trimmed to log lines about
	 * missing views.
	 *
	 * @return string[]
	 */
	private function logged() {
		$file = $this->log_file;
		if ( '' === $file || ! is_readable( $file ) ) {
			return array();
		}

		$text = (string) file_get_contents( $file );
		if ( '' === $text ) {
			return array();
		}

		$out = array();
		foreach ( explode( "\n", $text ) as $line ) {
			if ( false !== strpos( $line, 'hat keine Ansicht' ) ) {
				$out[] = $line;
			}
		}

		return $out;
	}

	/**
	 * A page carrying the given components.
	 *
	 * @param array[] $components Component entries.
	 * @param string  $title      Title.
	 * @return int
	 */
	private function page_with( array $components, $title = 'SDC-CREW-vermisste-ansicht' ) {
		$post_id = $this->make_page( array( 'post_title' => $title ) );

		Sdc_Store::save_data(
			$post_id,
			array(
				'v'          => 1,
				'components' => $components,
			)
		);

		return (int) $post_id;
	}

	/**
	 * A type nothing knows. Deliberately synthetic: using a real name from
	 * another site would tie the test to that site's component folder.
	 *
	 * @return string
	 */
	private function unknown_type() {
		return 'gone-' . wp_generate_password( 6, false, false );
	}

	/**
	 * Unknown type: logged, and the visitor sees nothing at all.
	 */
	public function test_unknown_type_is_logged_and_invisible_to_visitors() {
		wp_set_current_user( 0 );
		$post_id = $this->page_with(
			array(
				array(
					'type' => $this->unknown_type(),
					'data' => array(),
				),
			)
		);

		$html = Sdc_Frontend::render_post( $post_id );

		$this->assertSame( '', trim( (string) $html ), 'A visitor must not see a marker or stray text.' );
		$this->assertCount( 1, $this->logged(), 'The missing view must reach the log.' );
		$this->assertStringContainsString( (string) $post_id, $this->logged()[0], 'The log line has to name the page.' );
	}

	/**
	 * Same page, an editor looking at it: the marker is in the source text.
	 */
	public function test_unknown_type_is_marked_in_the_source_for_an_editor() {
		$post_id = $this->page_with(
			array(
				array(
					'type' => $this->unknown_type(),
					'data' => array(),
				),
			)
		);

		$this->login_as_editor();

		$html = Sdc_Frontend::render_post( $post_id );

		$this->assertStringContainsString( '<!-- sdc:', $html );
		$this->assertStringContainsString( 'hat keine Ansicht', $html );

		// The marker is a comment, not content: once comments are gone, the
		// only thing left may be the page's own wrapper — no text a reader
		// would mistake for content, and no marker that survived as markup.
		$visible = trim( (string) preg_replace( '/<!--.*?-->/s', '', $html ) );
		$visible = trim( (string) preg_replace( '#<div class="sdc-page sdc-page--\d+">\s*</div>#', '', $visible ) );
		$this->assertSame( '', $visible );
		$this->assertStringNotContainsString( 'hat keine Ansicht', $visible );
	}

	/**
	 * Counter-test: a type that can render must not make noise. Without this
	 * one, "always log and always mark" would pass the tests above.
	 */
	public function test_a_rendering_type_logs_nothing_and_marks_nothing() {
		$this->login_as_editor();

		$type = $this->known_type_with_view();
		if ( '' === $type ) {
			$this->fail( 'No component with a render.php on this installation — the counter-test cannot run.' );
		}

		$post_id = $this->page_with(
			array(
				array(
					'type' => $type,
					'data' => array(),
				),
			)
		);

		$html = Sdc_Frontend::render_post( $post_id );

		$this->assertCount( 0, $this->logged(), 'A working component must stay quiet.' );
		$this->assertStringNotContainsString( '<!-- sdc:', (string) $html );
	}

	/**
	 * Three copies of the same broken type are one fault, one line.
	 */
	public function test_one_log_line_per_type_per_request() {
		$type    = $this->unknown_type();
		$post_id = $this->page_with(
			array(
				array(
					'type' => $type,
					'data' => array(),
				),
				array(
					'type' => $type,
					'data' => array(),
				),
				array(
					'type' => $type,
					'data' => array(),
				),
			)
		);

		Sdc_Frontend::render_post( $post_id );

		$this->assertCount( 1, $this->logged() );
	}

	/**
	 * Two different broken types are two faults, so two lines — the dedupe is
	 * per type, not "once per request".
	 */
	public function test_dedupe_is_per_type_not_per_request() {
		$post_id = $this->page_with(
			array(
				array(
					'type' => $this->unknown_type(),
					'data' => array(),
				),
				array(
					'type' => $this->unknown_type(),
					'data' => array(),
				),
			)
		);

		Sdc_Frontend::render_post( $post_id );

		$this->assertCount( 2, $this->logged() );
	}

	/**
	 * The schema knows the type but the definition declares no fields and
	 * there is no render.php: the second half of the fault, and the one that
	 * hid behind a definition that still looked healthy.
	 */
	public function test_type_without_template_or_fields_is_reported() {
		$type = $this->type_without_view();
		if ( '' === $type ) {
			$this->fail( 'Could not build a component with a definition and no view.' );
		}

		$post_id = $this->page_with(
			array(
				array(
					'type' => $type,
					'data' => array(),
				),
			)
		);

		$this->assertFalse( Sdc_Frontend::has_view( $type ) );

		Sdc_Frontend::render_post( $post_id );

		$this->assertCount( 1, $this->logged() );
	}

	/**
	 * A type with fields but no render.php renders through the generic field
	 * output and must not be reported.
	 *
	 * This is not hypothetical: on the dev instance `faq` has neither a
	 * render.php on disk nor a folder definition, and pages 98 and 141 show
	 * it correctly through the schema's field list. A check that asked "did
	 * this render produce text" called both pages broken.
	 */
	public function test_type_with_fields_but_no_template_is_not_reported() {
		$type = $this->type_with_fields_no_view();
		if ( '' === $type ) {
			$this->fail( 'Could not build a component with fields and no view.' );
		}

		$this->assertTrue( Sdc_Frontend::has_view( $type ) );

		$post_id = $this->page_with(
			array(
				array(
					'type' => $type,
					'data' => array(),
				),
			)
		);

		Sdc_Schema::flush();

		Sdc_Frontend::render_post( $post_id );

		$this->assertCount( 0, $this->logged(), 'A type the schema can print is not a lost component: ' . implode( ' | ', $this->logged() ) );
	}

	/**
	 * A type name with capitals cannot survive a round trip through the
	 * document, so the schema lookup has to be case-consistent.
	 *
	 * `Sdc_Store::sanitize()` runs the type through `sanitize_key()`, which
	 * lowercases. A component whose type is `HeroBG` therefore comes back as
	 * `herobg` — and if the schema keys it as `HeroBG`, `has_view()` says
	 * "no view" for a component that is right there. This pinned the cause of
	 * a test that failed for an unrelated-looking reason.
	 */
	public function test_the_stored_type_is_lowercased_by_the_document() {
		$post_id = $this->page_with(
			array(
				array(
					'type' => 'MixedCase',
					'data' => array(),
				),
			)
		);

		$doc = Sdc_Store::get_data( $post_id );

		$this->assertSame( 'mixedcase', $doc['components'][0]['type'] );
	}

	/**
	 * The view check answers the same for the unknown and the known case.
	 */
	public function test_has_view_answers_for_known_and_unknown_types() {
		$this->assertFalse( Sdc_Frontend::has_view( $this->unknown_type() ) );
		$this->assertFalse( Sdc_Frontend::has_view( '' ) );

		$type = $this->known_type_with_view();
		if ( '' !== $type ) {
			$this->assertTrue( Sdc_Frontend::has_view( $type ) );
		}
	}

	/**
	 * The block path reports too — a component can be lost without the
	 * document path being involved at all.
	 *
	 * `Sdc_Block::render()` gates unknown types before the renderer sees
	 * them, which would keep the block path silent — and a page built from
	 * blocks is where a renamed type is *most* likely to go unnoticed,
	 * because the editor canvas shows the block's placeholder rather than an
	 * error. So the gate has to report as well.
	 */
	public function test_block_render_reports_a_missing_view() {
		$type = $this->unknown_type();

		$html = Sdc_Block::render( $type, array( 'data' => array() ), 0 );

		$this->assertSame( '', (string) $html, 'Nothing may reach the visitor.' );
		$this->assertCount( 1, $this->logged(), 'The block path must not swallow the loss.' );
	}

	/**
	 * An affected page puts a warning on the page list, once, and the count
	 * is cached so the list can be reloaded without rescanning every page.
	 */
	public function test_notice_names_the_number_of_affected_pages() {
		$this->login_as_editor();
		delete_transient( Sdc_Admin::BROKEN_TRANSIENT );

		$broken = $this->page_with(
			array(
				array(
					'type' => $this->unknown_type(),
					'data' => array(),
				),
			)
		);

		$this->assertGreaterThan( 0, $broken );
		$this->set_page_list_screen();

		$count = Sdc_Admin::count_broken_pages();
		$this->assertGreaterThanOrEqual( 1, $count, 'The page with a lost component has to be counted.' );

		$notice = $this->notice_html();
		$this->assertStringContainsString( 'notice-warning', $notice );
		$this->assertStringContainsString( 'wp sdc-pb repair', $notice );
		$this->assertStringContainsString( (string) $count, $notice );

		// The count is cached: a second ask reads the transient, so the page
		// list can be reloaded without rescanning every page each time.
		$this->assertSame( $count, (int) get_transient( Sdc_Admin::BROKEN_TRANSIENT ) );
	}

	/**
	 * A repaired page must stop being reported immediately — the notice
	 * reads the cached count, so that cache has to go with the change.
	 */
	public function test_clear_counts_now_drops_the_broken_page_cache() {
		$this->login_as_editor();
		set_transient( Sdc_Admin::BROKEN_TRANSIENT, 4, MINUTE_IN_SECONDS );

		Sdc_Admin::clear_counts_now();

		$this->assertFalse( get_transient( Sdc_Admin::BROKEN_TRANSIENT ), 'A repaired page must stop being reported at once, not in two minutes.' );
	}

	/**
	 * No affected page, no warning — a notice that is always there is a
	 * notice nobody reads.
	 */
	public function test_notice_stays_away_when_no_page_is_affected() {
		$this->login_as_editor();
		set_transient( Sdc_Admin::BROKEN_TRANSIENT, 0, MINUTE_IN_SECONDS );

		$this->set_page_list_screen();

		$this->assertSame( '', $this->notice_html() );
	}

	/**
	 * The notice belongs to the page list, not to every admin screen.
	 */
	public function test_notice_stays_away_on_another_screen() {
		$this->login_as_editor();
		set_transient( Sdc_Admin::BROKEN_TRANSIENT, 3, MINUTE_IN_SECONDS );

		set_current_screen( 'dashboard' );

		$this->assertSame( '', $this->notice_html() );
	}

	/**
	 * And it belongs to someone who can act on it.
	 */
	public function test_notice_stays_away_for_a_role_without_the_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		set_transient( Sdc_Admin::BROKEN_TRANSIENT, 3, MINUTE_IN_SECONDS );

		$this->set_page_list_screen();

		$this->assertSame( '', $this->notice_html() );
	}

	/**
	 * Put the current screen on the page list, where the notice lives.
	 */
	private function set_page_list_screen() {
		set_current_screen( 'edit-page' );
	}

	/**
	 * Whatever `render_broken_notice()` prints right now.
	 *
	 * @return string
	 */
	private function notice_html() {
		ob_start();
		Sdc_Admin::render_broken_notice();
		return (string) ob_get_clean();
	}

	/**
	 * An administrator, for the capability-gated marker.
	 */
	private function login_as_editor() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}

	/**
	 * First installed type that would really render, asked the way the
	 * renderer asks it, with the view directory pointed at a scratch folder.
	 *
	 * The test install ships definitions without render.php, so a lookup over
	 * the real directories finds nothing to compare against. Building the
	 * positive case is the only honest counter-test: "logs nothing" means
	 * nothing only when something *could* have been logged.
	 *
	 * @return string Type name, or '' when no type is installed.
	 */
	private function known_type_with_view() {
		$types = array_keys( Sdc_Schema::components() );
		if ( ! $types ) {
			return '';
		}

		$type = (string) $types[0];
		$dir  = $this->view_dir();

		if ( ! wp_mkdir_p( $dir . '/' . $type ) ) {
			return '';
		}

		$written     = file_put_contents( $dir . '/' . $type . '/render.php', "<?php echo 'sdc-view';\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Scratch file of this test.
		$this->views = $dir;
		add_filter( 'sdc_pb_component_dirs', array( $this, 'prepend_view_dir' ) );
		Sdc_Schema::flush();

		return false === $written ? '' : $type;
	}

	/**
	 * Scratch directory for views written by this test.
	 *
	 * @return string
	 */
	private function view_dir() {
		$dir               = sys_get_temp_dir() . '/sdc-k5-views-' . wp_generate_password( 6, false, false );
		$this->view_dirs[] = $dir;

		return $dir;
	}

	/**
	 * Put the test's view directory in front of the real ones.
	 *
	 * @param string[] $dirs Directories.
	 * @return string[]
	 */
	public function prepend_view_dir( $dirs ) {
		$dirs   = (array) $dirs;
		$dirs[] = $this->views;

		return $dirs;
	}

	/**
	 * A type in the schema with a definition and no view at all.
	 *
	 * Built through the same write dir the installer uses, so it exercises
	 * the real lookup instead of a filter stub. The type name is unique per
	 * test and the folder is removed again in tear_down().
	 *
	 * @return string Type name, or '' on failure.
	 */
	private function type_without_view() {
		return $this->definition_type( array() );
	}

	/**
	 * The same, but the definition declares a field the generic output can
	 * print — a component without a render.php that still works.
	 *
	 * @return string Type name, or '' on failure.
	 */
	private function type_with_fields_no_view() {
		return $this->definition_type(
			array(
				array(
					'key'   => 'field_sdc_k5_heading',
					'label' => 'Überschrift',
					'name'  => 'heading',
					'type'  => 'text',
				),
			)
		);
	}

	/**
	 * Write a component definition and make the schema see it.
	 *
	 * The definition is written directly instead of through
	 * `Sdc_Admin::create()`: that path runs a form validation chain (label,
	 * field list, type name) whose failures are not the subject of this
	 * test, and a rejected create here would turn a missing view test into a
	 * form test. What matters is the shape ACF's Local JSON loader reads —
	 * `group_sdc_component_<type>` as key, the fields as declared — and that
	 * the folder is one the schema scans.
	 *
	 * @param array[] $fields Field definitions for the component.
	 * @return string Type name, or '' on failure.
	 */
	private function definition_type( array $fields ) {
		// Lowercase only: `Sdc_Store::sanitize()` runs the type through
		// `sanitize_key()`, so a mixed-case name would come back lowercased
		// and stop matching its own schema key — measured, and it looks
		// exactly like "the schema does not know the type".
		$type = 'norender-' . strtolower( wp_generate_password( 6, false, false ) );
		$root = $this->view_dir();

		add_filter( 'sdc_pb_component_dirs', array( $this, 'prepend_view_dir' ) );
		$this->views = $root;
		$folder      = untrailingslashit( $root ) . '/' . $type;
		if ( ! wp_mkdir_p( $folder ) ) {
			return '';
		}

		foreach ( $fields as $index => $field ) {
			if ( ! isset( $field['key'] ) || '' === $field['key'] ) {
				$fields[ $index ]['key'] = 'field_sdc_' . $type . '_' . $index;
			}
		}

		$definition = array(
			'key'      => Sdc_Schema::KEY_PREFIX . $type,
			'title'    => 'SDC test',
			'fields'   => $fields,
			'location' => array(
				array(
					array(
						'param'    => 'sdc_component',
						'operator' => '==',
						'value'    => '1',
					),
				),
			),
			'active'   => true,
		);

		$json = wp_json_encode( $definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return '';
		}

		$written = file_put_contents( $folder . '/definition.json', $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Scratch file of this test.
		if ( false === $written ) {
			return '';
		}

		$this->reload_host();

		return $type;
	}

	/**
	 * Make the host read the component files again.
	 *
	 * ACF/SCF load Local JSON through their own stores and keep the groups
	 * for the process; a file written by the code under test stays invisible
	 * until the stores are reset and the include hook runs again. Measured
	 * without this: the type was a file on disk, `Sdc_Schema::get()` did not
	 * know it, and `has_view()` answered "no view" for the wrong reason.
	 */
	private function reload_host() {
		Sdc_Schema::flush();

		if ( function_exists( 'acf_get_store' ) ) {
			foreach ( array( 'field-groups', 'fields' ) as $store ) {
				$instance = acf_get_store( $store );
				if ( $instance && method_exists( $instance, 'reset' ) ) {
					$instance->reset();
				}
			}
		}

		// The hook name comes from the host and cannot be renamed.
		do_action( 'acf/include_fields' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Hook defined by ACF/SCF.

		Sdc_Schema::flush();
	}
}
