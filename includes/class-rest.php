<?php
defined( 'ABSPATH' ) || exit;

final class PBD_Exp_Rest {

	const NS = 'pbd-experiments/v1';

	public function hook() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NS,
			'/events',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'record_event' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function record_event( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}

		$result = PBD_Exp_Events::record(
			isset( $params['experiment'] ) ? $params['experiment'] : '',
			isset( $params['event'] ) ? $params['event'] : '',
			isset( $params['metadata'] ) && is_array( $params['metadata'] ) ? $params['metadata'] : array(),
			! empty( $params['once'] ),
			isset( $params['variant'] ) ? $params['variant'] : ''
		);

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'view'    => 'event',
					'data'    => null,
					'errors'  => array( $result->get_error_message() ),
				),
				400
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'view'    => 'event',
				'data'    => $result,
				'errors'  => array(),
			),
			200
		);
	}
}
