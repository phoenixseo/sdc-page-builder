<?php
/**
 * What the block editor loads besides the block itself.
 *
 * @package SdcPageBuilder
 */

/**
 * The editor scripts and the globals they read.
 *
 * `block.js` throws when a module it needs is missing — deliberately, because
 * a silent fallback would drift away from the module and nobody would notice.
 * That makes the enqueue order and the dependency array part of the contract,
 * not a detail: a module that is not listed there is not loaded, and the whole
 * inspector dies with a console error the author will report as "the editor is
 * broken".
 *
 * The media module has one more requirement that has nothing to do with
 * dependencies: `wp.media` exists only after `wp_enqueue_media()`. Without it
 * the picker renders a button that does nothing — so it is asserted here.
 */
class EditorAssetsTest extends Sdc_TestCase {

	/**
	 * The block script is enqueued together with both of its modules.
	 */
	public function test_the_editor_script_depends_on_its_modules() {
		Sdc_Block::enqueue_editor_assets();

		$this->assertTrue( wp_script_is( Sdc_Block::SCRIPT, 'enqueued' ), 'Das Editor-Skript ist eingereiht.' );

		$deps = wp_scripts()->registered[ Sdc_Block::SCRIPT ]->deps;

		$this->assertContains( Sdc_Block::SCRIPT . '-rows', $deps, 'Die Zeilen-Operationen sind Abhängigkeit.' );
		$this->assertContains( Sdc_Block::SCRIPT . '-media', $deps, 'Die Medien-Wertregeln sind Abhängigkeit.' );
		$this->assertContains( 'media-views', $deps, 'Die Mediathek ist Abhängigkeit.' );
	}

	/**
	 * The media module is enqueued and points at the file that ships.
	 *
	 * A handle whose `src` names a file that is not there loads nothing, and
	 * `block.js` then throws on a missing global — an error that names the
	 * wrong cause.
	 */
	public function test_the_media_module_points_at_the_shipped_file() {
		Sdc_Block::enqueue_editor_assets();

		$this->assertTrue( wp_script_is( Sdc_Block::SCRIPT . '-media', 'enqueued' ) );

		$src = (string) wp_scripts()->registered[ Sdc_Block::SCRIPT . '-media' ]->src;

		$this->assertStringEndsWith( 'assets/media-field.js', $src );
		$this->assertFileExists( SDC_PB_DIR . 'assets/media-field.js' );
	}

	/**
	 * The media modal is enqueued, not just referenced.
	 *
	 * This is the line that decides whether the picker opens at all: without
	 * `wp_enqueue_media()` the editor page has no `wp.media`, and the button
	 * stays inert.
	 */
	public function test_the_media_modal_is_enqueued() {
		Sdc_Block::enqueue_editor_assets();

		$this->assertTrue( wp_script_is( 'media-views', 'enqueued' ), 'wp_enqueue_media() wurde aufgerufen.' );
	}

	/**
	 * The block script reads the media module by the name the module exports.
	 *
	 * Two files, one global: renaming the export on one side only leaves the
	 * editor throwing at load. Cheap to assert, and it is the mistake that
	 * already happened once with the row module.
	 */
	public function test_the_block_script_reads_the_media_global() {
		$source = (string) file_get_contents( SDC_PB_DIR . 'assets/block.js' );
		$module = (string) file_get_contents( SDC_PB_DIR . 'assets/media-field.js' );

		$this->assertStringContainsString( 'window.SDCMediaField', $source );
		$this->assertStringContainsString( 'root.SDCMediaField', $module );
	}

	/**
	 * Media field types have a control of their own in the inspector.
	 *
	 * They used to fall through to the text input in `block.js`, so the author
	 * had to type an attachment ID. The failure is invisible in review — the
	 * inspector renders happily, it renders the wrong thing — so the branch is
	 * asserted by name. A rename of the branch has to pass through here.
	 */
	public function test_the_inspector_branches_on_media_types() {
		$source = (string) file_get_contents( SDC_PB_DIR . 'assets/block.js' );

		foreach ( array( 'image', 'file', 'gallery' ) as $type ) {
			$this->assertStringContainsString(
				"case '" . $type . "':",
				$source,
				'Kein eigener Zweig für den Feldtyp ' . $type . '.'
			);
		}
	}
}
