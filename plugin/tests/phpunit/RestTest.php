<?php
/**
 * REST: persistence and permissions over the real routes.
 *
 * @package SdcPageBuilder
 */

/**
 * The REST layer is what the editor talks to. These tests dispatch through
 * `rest_get_server()` — the same dispatcher a browser request hits — instead
 * of calling the callbacks directly, so routing and permission callbacks are
 * included in the result.
 */
class RestTest extends Sdc_TestCase {

	/**
	 * Every route the editor uses is registered.
	 */
	public function test_expected_routes_are_registered() {
		$routes = array_keys( rest_get_server()->get_routes() );
		$ns     = '/' . Sdc_Rest::NAMESPACE_V1;

		foreach ( array( '/pages/(?P<id>\d+)', '/components', '/pages/(?P<id>\d+)/history', '/pages/(?P<id>\d+)/restore', '/pages/(?P<id>\d+)/import', '/pages/(?P<id>\d+)/export' ) as $route ) {
			$this->assertContains( $ns . $route, $routes, "Route $route is not registered" );
		}
	}

	/**
	 * GET returns the stored document plus the editor's component view.
	 */
	public function test_get_returns_the_stored_document() {
		$post_id = $this->make_page();
		Sdc_Store::save_data( $post_id, array( 'components' => array( $this->faq_component( 'Gelesen' ) ) ) );

		$response = $this->dispatch_as_admin( $this->request( 'GET', '/pages/' . $post_id ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( $post_id, $data['post_id'] );
		$this->assertSame( 'Gelesen', $data['document']['components'][0]['data']['heading'] );
		$this->assertSame( 'faq', $data['components'][0]['type'] );
		$this->assertSame( 'faq@1', $data['components'][0]['current'] );
	}

	/**
	 * POST persists what the editor sent, and a later GET reads it back.
	 */
	public function test_post_persists_the_document() {
		$post_id = $this->make_page();
		$payload = array(
			'components' => array(
				$this->faq_component( 'Erste Komponente', 1 ),
				$this->hero_component( 'Zweite Komponente' ),
			),
		);

		$response = $this->dispatch_as_admin( $this->request( 'POST', '/pages/' . $post_id, $payload ) );
		$this->assertSame( 200, $response->get_status(), 'Save was refused: ' . wp_json_encode( $response->get_data() ) );

		$stored = Sdc_Store::get_data( $post_id );
		$this->assertCount( 2, $stored['components'] );
		$this->assertSame( 'faq', $stored['components'][0]['type'] );
		$this->assertSame( 'hero', $stored['components'][1]['type'] );
		$this->assertSame( 'Erste Komponente', $stored['components'][0]['data']['heading'] );
	}

	/**
	 * A user who cannot edit the page gets 403 on both verbs.
	 */
	public function test_subscriber_cannot_read_or_write() {
		$post_id = $this->make_page();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$get  = rest_get_server()->dispatch( $this->request( 'GET', '/pages/' . $post_id ) );
		$post = rest_get_server()->dispatch(
			$this->request( 'POST', '/pages/' . $post_id, array( 'components' => array() ) )
		);

		$this->assertSame( 403, $get->get_status() );
		$this->assertSame( 403, $post->get_status() );
	}

	/**
	 * An unknown type is refused with 400, a bad document with 422 — the two
	 * are different failures and the editor distinguishes them.
	 */
	public function test_unknown_type_is_a_400_not_a_422() {
		$post_id = $this->make_page();

		$response = $this->dispatch_as_admin(
			$this->request(
				'POST',
				'/pages/' . $post_id,
				array(
					'components' => array(
						array(
							'type' => 'gibt-es-nicht',
							'data' => array(),
						),
					),
				)
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'sdc_unknown_component', $response->get_data()['code'] );
	}

	/**
	 * A required field can no longer be cleared through the save route.
	 *
	 * Measured before the fix: a hero without its required heading returned 200
	 * and was stored, while the same document was rejected by import.
	 */
	public function test_required_field_cannot_be_cleared_on_save() {
		$post_id = $this->make_page();
		Sdc_Store::save_data( $post_id, array( 'components' => array( $this->hero_component( 'Vorher' ) ) ) );

		$response = $this->dispatch_as_admin(
			$this->request(
				'POST',
				'/pages/' . $post_id,
				array(
					'components' => array(
						array(
							'type' => 'hero',
							'data' => array( 'subline' => 'ohne Überschrift' ),
						),
					),
				)
			)
		);

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'sdc_invalid_document', $response->get_data()['code'] );
		$this->assertSame( 'Vorher', Sdc_Store::get_data( $post_id )['components'][0]['data']['heading'], 'The rejected save must not touch the stored document' );
	}

	/**
	 * The component catalogue is readable by anyone who may edit posts.
	 */
	public function test_component_catalogue_lists_both_types() {
		$response = $this->dispatch_as_admin( $this->request( 'GET', '/components' ) );

		$this->assertSame( 200, $response->get_status() );
		$types = array_column( $response->get_data()['components'] ?? $response->get_data(), 'type' );
		$this->assertContains( 'faq', $types );
		$this->assertContains( 'hero', $types );
	}
}
