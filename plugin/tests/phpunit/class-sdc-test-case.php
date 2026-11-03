<?php
/**
 * Shared base for the suite: fixture helpers that every test class needs.
 *
 * @package SdcPageBuilder
 */

/**
 * Base test case.
 */
abstract class Sdc_TestCase extends WP_UnitTestCase {

	/**
	 * Post IDs created by the current test, removed in tear_down().
	 *
	 * @var int[]
	 */
	private $created_posts = array();

	/**
	 * Create a page and remember it for cleanup.
	 *
	 * @param array $args Overrides for wp_insert_post().
	 * @return int Post ID.
	 */
	protected function make_page( array $args = array() ) {
		$post_id = wp_insert_post(
			array_merge(
				array(
					'post_title'  => 'SDC Testseite',
					'post_type'   => 'page',
					'post_status' => 'publish',
				),
				$args
			)
		);
		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );
		$this->created_posts[] = $post_id;

		return $post_id;
	}

	/**
	 * A FAQ component payload, as the editor would post it.
	 *
	 * @param string $heading Heading text.
	 * @param int    $rows    Number of Q/A rows.
	 * @return array
	 */
	protected function faq_component( $heading = 'Häufige Fragen', $rows = 2 ) {
		$items = array();
		for ( $i = 1; $i <= $rows; $i++ ) {
			$items[] = array(
				'q' => 'Frage ' . $i,
				'a' => 'Antwort ' . $i . ' mit Umlauten: äöüß',
			);
		}

		return array(
			'type' => 'faq',
			'data' => array(
				'heading' => $heading,
				'items'   => $items,
			),
		);
	}

	/**
	 * A hero component payload (the second type, schema-only).
	 *
	 * @param string $heading Heading text.
	 * @return array
	 */
	protected function hero_component( $heading = 'Willkommen' ) {
		return array(
			'type' => 'hero',
			'data' => array(
				'heading' => $heading,
				'subline' => 'Zweite Komponente, nur aus JSON-Schema.',
				'cta'     => array(
					'title' => 'Mehr erfahren',
					'url'   => 'https://example.org/ziel?mit=query',
				),
			),
		);
	}

	/**
	 * Build a WP_REST_Request the way the browser does: JSON body.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route below the plugin namespace.
	 * @param array  $body   Body parameters.
	 * @return WP_REST_Request
	 */
	protected function request( $method, $route, array $body = array() ) {
		$request = new WP_REST_Request( $method, '/' . Sdc_Rest::NAMESPACE_V1 . $route );
		if ( ! empty( $body ) ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}

		return $request;
	}

	/**
	 * Dispatch a request as an administrator.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	protected function dispatch_as_admin( WP_REST_Request $request ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Remove everything this test created.
	 */
	public function tear_down() {
		foreach ( $this->created_posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$this->created_posts = array();

		parent::tear_down();
	}
}
