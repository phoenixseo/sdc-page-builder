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
	 * WordPress really loaded, and it is the version the matrix claims.
	 */
	public function test_wordpress_loaded() {
		global $wp_version;

		$this->assertTrue( function_exists( 'do_action' ) );
		$this->assertNotEmpty( $wp_version );
		$this->assertGreaterThanOrEqual( 6.4, (float) $wp_version, "WordPress $wp_version is below the plugin's minimum" );
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
