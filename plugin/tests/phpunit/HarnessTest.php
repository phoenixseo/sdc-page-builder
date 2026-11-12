<?php
/**
 * Harness self-check: without this passing, nothing else in the suite means
 * anything.
 *
 * @package SdcPageBuilder
 */

/**
 * Proves that WordPress loaded, the host field plugin is active, and the plugin
 * under test booted — in that order, each asserted separately.
 */
class HarnessTest extends Sdc_TestCase {

	/**
	 * WordPress really loaded, and it is not older than the header promises.
	 *
	 * The minimum is read from the plugin header instead of repeated here: a
	 * literal is a second place to forget, and this test is the one that
	 * decides whether the readme's `Requires at least` was ever executed.
	 * `version_compare` rather than a float cast — `(float) '6.10'` is 6.1.
	 */
	public function test_wordpress_meets_the_declared_minimum() {
		global $wp_version;

		$this->assertTrue( function_exists( 'do_action' ) );
		$this->assertNotEmpty( $wp_version );

		$header = get_file_data(
			dirname( __DIR__, 2 ) . '/sdc-page-builder.php',
			array( 'requires_at_least' => 'Requires at least' )
		);

		$this->assertNotEmpty( $header['requires_at_least'], 'the plugin header declares no minimum WordPress version' );
		$this->assertTrue(
			version_compare( $wp_version, $header['requires_at_least'], '>=' ),
			sprintf( 'WordPress %s is below the declared minimum %s', $wp_version, $header['requires_at_least'] )
		);
	}

	/**
	 * The host field plugin is active and reports itself.
	 */
	public function test_host_field_plugin_is_available() {
		$this->assertTrue( Sdc_Field_Adapter::is_available(), 'Neither ACF nor SCF is active in the test install' );
		$this->assertContains( Sdc_Field_Adapter::host(), array( 'acf', 'scf' ) );
	}

	/**
	 * The plugin under test booted from the repository directory, not from a stale copy.
	 */
	public function test_plugin_under_test_boots_from_the_repository() {
		$this->assertTrue( class_exists( 'Sdc_Store' ), 'Sdc_Store missing — plugin did not boot' );
		$this->assertTrue( class_exists( 'Sdc_Schema' ) );
		$this->assertTrue( class_exists( 'Sdc_Renderer' ) );

		$loaded = ( new ReflectionClass( 'Sdc_Store' ) )->getFileName();
		$this->assertStringContainsString(
			dirname( __DIR__, 2 ),
			$loaded,
			'The suite is exercising a different copy of the plugin than the repository'
		);
	}
}
