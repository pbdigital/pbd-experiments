<?php
defined( 'ABSPATH' ) || exit;

/**
 * [pbd_experiment_event experiment="key" event="event_name" once="yes" debug="false"]
 *
 * Silent by default. Records the conversion server-side using the visitor's
 * existing assignment cookie (the off-site checkout pattern). With debug="true"
 * it prints a visible confirmation, for QA.
 */
final class PBD_Exp_Shortcode {

	public function hook() {
		add_shortcode( 'pbd_experiment_event', array( $this, 'handle' ) );
	}

	public function handle( $atts ) {
		$atts = shortcode_atts(
			array(
				'experiment' => '',
				'event'      => '',
				'once'       => 'yes',
				'debug'      => 'false',
			),
			$atts,
			'pbd_experiment_event'
		);

		$experiment_key = sanitize_key( $atts['experiment'] );
		$event_name     = sanitize_key( $atts['event'] );
		$once           = ! in_array( strtolower( (string) $atts['once'] ), array( '0', 'false', 'no' ), true );
		$debug          = in_array( strtolower( (string) $atts['debug'] ), array( '1', 'true', 'yes' ), true );

		if ( ! $experiment_key || ! $event_name ) {
			return $debug ? '<!-- pbd_experiment_event: missing experiment or event -->' : '';
		}

		$result = PBD_Exp_Events::record(
			$experiment_key,
			$event_name,
			array( 'source' => 'shortcode' ),
			$once
		);

		// Also fire client-side via the JS API so dataLayer/GTM see it.
		$client_side = sprintf(
			'<script>window.PBDExperiments&&window.PBDExperiments.track(%s,{experiment:%s,once:%s,metadata:{source:"shortcode"}});</script>',
			wp_json_encode( $event_name ),
			wp_json_encode( $experiment_key ),
			$once ? 'true' : 'false'
		);

		if ( ! $debug ) {
			return $client_side;
		}

		$status = is_wp_error( $result )
			? 'error: ' . $result->get_error_message()
			: ( isset( $result['duplicate'] ) ? 'duplicate (already recorded for this visitor)' :
			  ( isset( $result['excluded'] ) ? 'excluded (logged-in or bot)' :
			  ( isset( $result['event_id'] ) ? 'recorded id=' . (int) $result['event_id'] : 'unknown' ) ) );

		return sprintf(
			'<div style="border:1px dashed #2271b1;background:#f0f6fc;color:#1d2327;padding:8px 12px;margin:8px 0;font:13px/1.4 monospace;">'
			. '<strong>pbd_experiment_event</strong> experiment=<code>%s</code> event=<code>%s</code> once=<code>%s</code> &rarr; %s'
			. '</div>%s',
			esc_html( $experiment_key ),
			esc_html( $event_name ),
			$once ? 'true' : 'false',
			esc_html( $status ),
			$client_side
		);
	}
}
