<?php
defined( 'ABSPATH' ) || exit;

/**
 * Data access for experiments, variants, metrics, snapshots.
 * All wpdb queries pass through here. Higher layers stay schema-agnostic.
 */
final class PBD_Exp_Repo {

	public static function get_experiment( $id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . PBD_Exp_Schema::table( 'experiments' ) . ' WHERE id = %d', (int) $id ),
			ARRAY_A
		);
		return $row ? self::cast_experiment( $row ) : null;
	}

	public static function get_experiment_by_key( $key ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . PBD_Exp_Schema::table( 'experiments' ) . ' WHERE experiment_key = %s LIMIT 1', $key ),
			ARRAY_A
		);
		return $row ? self::cast_experiment( $row ) : null;
	}

	public static function get_active_experiment_for_path( $path ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . PBD_Exp_Schema::table( 'experiments' ) . ' WHERE status = %s AND target_path = %s LIMIT 1',
				'active',
				$path
			),
			ARRAY_A
		);
		return $row ? self::cast_experiment( $row ) : null;
	}

	public static function list_experiments( $statuses = null ) {
		global $wpdb;
		$sql = 'SELECT * FROM ' . PBD_Exp_Schema::table( 'experiments' );
		if ( is_array( $statuses ) && ! empty( $statuses ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
			$sql .= $wpdb->prepare( ' WHERE status IN (' . $placeholders . ')', $statuses );
		}
		$sql .= ' ORDER BY updated_at DESC';
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return array_map( array( __CLASS__, 'cast_experiment' ), $rows ? $rows : array() );
	}

	public static function insert_experiment( $data ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$row = array_merge(
			array(
				'created_at' => $now,
				'updated_at' => $now,
			),
			$data
		);
		$wpdb->insert( PBD_Exp_Schema::table( 'experiments' ), $row );
		return (int) $wpdb->insert_id;
	}

	public static function update_experiment( $id, $data ) {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql' );
		$wpdb->update( PBD_Exp_Schema::table( 'experiments' ), $data, array( 'id' => (int) $id ) );
	}

	public static function delete_experiment( $id ) {
		global $wpdb;
		$wpdb->delete( PBD_Exp_Schema::table( 'experiments' ), array( 'id' => (int) $id ), array( '%d' ) );
		$wpdb->delete( PBD_Exp_Schema::table( 'variants' ), array( 'experiment_id' => (int) $id ), array( '%d' ) );
		$wpdb->delete( PBD_Exp_Schema::table( 'metrics' ), array( 'experiment_id' => (int) $id ), array( '%d' ) );
		$wpdb->delete( PBD_Exp_Schema::table( 'assignments' ), array( 'experiment_id' => (int) $id ), array( '%d' ) );
		$wpdb->delete( PBD_Exp_Schema::table( 'events' ), array( 'experiment_id' => (int) $id ), array( '%d' ) );
		$wpdb->delete( PBD_Exp_Schema::table( 'snapshots' ), array( 'experiment_id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Reset an experiment's collected data back to zero without touching its
	 * configuration. Clears recorded events and visitor assignments only; the
	 * experiment, its variants, and its metrics are left intact so it can keep
	 * collecting cleanly. Snapshots are deliberately left alone: they exist only
	 * for concluded experiments, which are locked against reset upstream.
	 *
	 * @return array Rows removed, keyed 'events' and 'assignments', for feedback.
	 */
	public static function reset_stats( $experiment_id ) {
		global $wpdb;
		$experiment_id = (int) $experiment_id;
		$events = (int) $wpdb->delete( PBD_Exp_Schema::table( 'events' ), array( 'experiment_id' => $experiment_id ), array( '%d' ) );
		$assignments = (int) $wpdb->delete( PBD_Exp_Schema::table( 'assignments' ), array( 'experiment_id' => $experiment_id ), array( '%d' ) );
		return array( 'events' => $events, 'assignments' => $assignments );
	}

	public static function get_variants( $experiment_id ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . PBD_Exp_Schema::table( 'variants' ) . ' WHERE experiment_id = %d ORDER BY sort_order ASC, id ASC',
				(int) $experiment_id
			),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'cast_variant' ), $rows ? $rows : array() );
	}

	public static function get_variant( $id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . PBD_Exp_Schema::table( 'variants' ) . ' WHERE id = %d', (int) $id ),
			ARRAY_A
		);
		return $row ? self::cast_variant( $row ) : null;
	}

	public static function get_variant_by_key( $experiment_id, $variant_key ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . PBD_Exp_Schema::table( 'variants' ) . ' WHERE experiment_id = %d AND variant_key = %s LIMIT 1',
				(int) $experiment_id,
				$variant_key
			),
			ARRAY_A
		);
		return $row ? self::cast_variant( $row ) : null;
	}

	public static function upsert_variant( $experiment_id, $data ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$existing = self::get_variant_by_key( $experiment_id, $data['variant_key'] );

		$payload = array(
			'experiment_id' => (int) $experiment_id,
			'variant_key'   => $data['variant_key'],
			'label'         => $data['label'],
			'weight'        => max( 0, (int) $data['weight'] ),
			'variant_type'  => in_array( $data['variant_type'], array( 'template', 'redirect' ), true ) ? $data['variant_type'] : 'template',
			'template_path' => isset( $data['template_path'] ) ? $data['template_path'] : '',
			'redirect_url'  => isset( $data['redirect_url'] ) ? $data['redirect_url'] : '',
			'sort_order'    => (int) $data['sort_order'],
			'updated_at'    => $now,
		);

		if ( $existing ) {
			$wpdb->update( PBD_Exp_Schema::table( 'variants' ), $payload, array( 'id' => (int) $existing['id'] ) );
			return (int) $existing['id'];
		}

		$payload['created_at'] = $now;
		$wpdb->insert( PBD_Exp_Schema::table( 'variants' ), $payload );
		return (int) $wpdb->insert_id;
	}

	public static function delete_variant( $id ) {
		global $wpdb;
		$wpdb->delete( PBD_Exp_Schema::table( 'variants' ), array( 'id' => (int) $id ), array( '%d' ) );
	}

	public static function delete_variants_except( $experiment_id, $keep_ids ) {
		global $wpdb;
		$experiment_id = (int) $experiment_id;
		$keep_ids = array_map( 'intval', array_filter( $keep_ids ) );

		if ( empty( $keep_ids ) ) {
			$wpdb->delete( PBD_Exp_Schema::table( 'variants' ), array( 'experiment_id' => $experiment_id ), array( '%d' ) );
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $keep_ids ), '%d' ) );
		$sql = $wpdb->prepare(
			'DELETE FROM ' . PBD_Exp_Schema::table( 'variants' ) . ' WHERE experiment_id = %d AND id NOT IN (' . $placeholders . ')',
			array_merge( array( $experiment_id ), $keep_ids )
		);
		$wpdb->query( $sql );
	}

	public static function get_metrics( $experiment_id, $only_active = false ) {
		global $wpdb;
		$sql = 'SELECT * FROM ' . PBD_Exp_Schema::table( 'metrics' ) . ' WHERE experiment_id = %d';
		if ( $only_active ) {
			$sql .= ' AND active = 1';
		}
		$sql .= ' ORDER BY sort_order ASC, id ASC';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, (int) $experiment_id ), ARRAY_A );
		return $rows ? $rows : array();
	}

	public static function upsert_metric( $experiment_id, $data ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . PBD_Exp_Schema::table( 'metrics' ) . ' WHERE experiment_id = %d AND metric_key = %s',
				(int) $experiment_id,
				$data['metric_key']
			)
		);

		$payload = array(
			'experiment_id' => (int) $experiment_id,
			'metric_key'    => $data['metric_key'],
			'name'          => $data['name'],
			'event_name'    => $data['event_name'],
			'trigger_type'  => in_array( $data['trigger_type'] ?? 'page', array( 'page', 'form', 'js' ), true ) ? $data['trigger_type'] : 'page',
			'selector'      => isset( $data['selector'] ) ? $data['selector'] : '',
			'form_type'     => isset( $data['form_type'] ) ? $data['form_type'] : '',
			'active'        => ! empty( $data['active'] ) ? 1 : 0,
			'sort_order'    => isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0,
			'updated_at'    => $now,
		);

		if ( $existing ) {
			$wpdb->update( PBD_Exp_Schema::table( 'metrics' ), $payload, array( 'id' => (int) $existing ) );
			return (int) $existing;
		}

		$payload['created_at'] = $now;
		$wpdb->insert( PBD_Exp_Schema::table( 'metrics' ), $payload );
		return (int) $wpdb->insert_id;
	}

	public static function delete_metric( $id ) {
		global $wpdb;
		$wpdb->delete( PBD_Exp_Schema::table( 'metrics' ), array( 'id' => (int) $id ), array( '%d' ) );
	}

	public static function delete_metrics_except( $experiment_id, $keep_ids ) {
		global $wpdb;
		$experiment_id = (int) $experiment_id;
		$keep_ids = array_map( 'intval', array_filter( $keep_ids ) );

		if ( empty( $keep_ids ) ) {
			$wpdb->delete( PBD_Exp_Schema::table( 'metrics' ), array( 'experiment_id' => $experiment_id ), array( '%d' ) );
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $keep_ids ), '%d' ) );
		$sql = $wpdb->prepare(
			'DELETE FROM ' . PBD_Exp_Schema::table( 'metrics' ) . ' WHERE experiment_id = %d AND id NOT IN (' . $placeholders . ')',
			array_merge( array( $experiment_id ), $keep_ids )
		);
		$wpdb->query( $sql );
	}

	public static function get_assignment( $visitor_id, $experiment_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT a.*, v.variant_key, v.label, v.variant_type, v.template_path, v.redirect_url
				FROM ' . PBD_Exp_Schema::table( 'assignments' ) . ' a
				INNER JOIN ' . PBD_Exp_Schema::table( 'variants' ) . ' v ON v.id = a.variant_id
				WHERE a.visitor_id = %s AND a.experiment_id = %d LIMIT 1',
				$visitor_id,
				(int) $experiment_id
			),
			ARRAY_A
		);
	}

	/**
	 * @param array $source Optional first-touch traffic source from
	 *                      PBD_Exp_Traffic_Source::detect(). Absent keys store ''.
	 *                      Only set on genuinely-new (first-touch) assignments.
	 */
	public static function insert_assignment( $visitor_id, $experiment_id, $variant_id, $source = array() ) {
		global $wpdb;
		$wpdb->insert(
			PBD_Exp_Schema::table( 'assignments' ),
			array(
				'visitor_id'    => $visitor_id,
				'experiment_id' => (int) $experiment_id,
				'variant_id'    => (int) $variant_id,
				'assigned_at'   => current_time( 'mysql' ),
				'channel'       => isset( $source['channel'] ) ? $source['channel'] : '',
				'source'        => isset( $source['source'] ) ? $source['source'] : '',
				'medium'        => isset( $source['medium'] ) ? $source['medium'] : '',
				'campaign'      => isset( $source['campaign'] ) ? $source['campaign'] : '',
				'referrer_host' => isset( $source['referrer_host'] ) ? $source['referrer_host'] : '',
				'device'        => isset( $source['device'] ) ? $source['device'] : '',
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Allowlist guard for a segment column name before it is interpolated into a
	 * GROUP BY. Segment columns can never arrive raw from user input into SQL.
	 */
	private static function safe_segment_col( $col ) {
		return in_array( $col, PBD_Exp_Traffic_Source::SEGMENT_COLS, true ) ? $col : 'channel';
	}

	/**
	 * Visitors per (variant, segment value), grouped on a first-touch source
	 * column of the assignments table.
	 *
	 * @return array Rows of { variant_id, segment, n }.
	 */
	public static function count_assignments_by_segment( $experiment_id, $segment_col, $since = null, $until = null ) {
		global $wpdb;
		$col  = self::safe_segment_col( $segment_col ); // hardcoded literal, safe to interpolate
		$sql  = 'SELECT variant_id, ' . $col . ' AS segment, COUNT(*) AS n FROM '
			. PBD_Exp_Schema::table( 'assignments' ) . ' WHERE experiment_id = %d';
		$args = array( (int) $experiment_id );
		if ( $since ) {
			$sql .= ' AND assigned_at >= %s';
			$args[] = $since;
		}
		if ( $until ) {
			$sql .= ' AND assigned_at <= %s';
			$args[] = $until;
		}
		$sql .= ' GROUP BY variant_id, ' . $col;
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
	}

	/**
	 * Converting visitors per (variant, segment value). Joins events back to the
	 * assignment so a conversion inherits the visitor's first-touch source, even
	 * when the conversion fired off-target (e.g. a thank-you page). Keyed on both
	 * visitor_id and experiment_id so a visitor in several experiments is counted
	 * only via the matching assignment.
	 *
	 * @return array Rows of { variant_id, segment, n }.
	 */
	public static function count_conversions_by_segment( $experiment_id, $event_name, $segment_col, $since = null, $until = null ) {
		global $wpdb;
		$col  = self::safe_segment_col( $segment_col ); // hardcoded literal, safe to interpolate
		$a    = PBD_Exp_Schema::table( 'assignments' );
		$e    = PBD_Exp_Schema::table( 'events' );
		$sql  = 'SELECT a.variant_id AS variant_id, a.' . $col . ' AS segment, COUNT(DISTINCT e.visitor_id) AS n
			FROM ' . $e . ' e
			INNER JOIN ' . $a . ' a ON a.visitor_id = e.visitor_id AND a.experiment_id = e.experiment_id
			WHERE e.experiment_id = %d AND e.event_name = %s';
		$args = array( (int) $experiment_id, $event_name );
		if ( $since ) {
			$sql .= ' AND e.occurred_at >= %s';
			$args[] = $since;
		}
		if ( $until ) {
			$sql .= ' AND e.occurred_at <= %s';
			$args[] = $until;
		}
		$sql .= ' GROUP BY a.variant_id, a.' . $col;
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
	}

	public static function count_assignments( $experiment_id, $variant_id = null, $since = null, $until = null ) {
		global $wpdb;
		$sql = 'SELECT COUNT(*) FROM ' . PBD_Exp_Schema::table( 'assignments' ) . ' WHERE experiment_id = %d';
		$args = array( (int) $experiment_id );
		if ( $variant_id ) {
			$sql .= ' AND variant_id = %d';
			$args[] = (int) $variant_id;
		}
		if ( $since ) {
			$sql .= ' AND assigned_at >= %s';
			$args[] = $since;
		}
		if ( $until ) {
			$sql .= ' AND assigned_at <= %s';
			$args[] = $until;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}

	public static function count_conversions( $experiment_id, $variant_id, $event_name, $since = null, $until = null ) {
		global $wpdb;
		$sql = 'SELECT COUNT(DISTINCT visitor_id) FROM ' . PBD_Exp_Schema::table( 'events' )
			. ' WHERE experiment_id = %d AND variant_id = %d AND event_name = %s';
		$args = array( (int) $experiment_id, (int) $variant_id, $event_name );
		if ( $since ) {
			$sql .= ' AND occurred_at >= %s';
			$args[] = $since;
		}
		if ( $until ) {
			$sql .= ' AND occurred_at <= %s';
			$args[] = $until;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}

	public static function count_unattributed_conversions( $experiment_id, $event_name, $since = null, $until = null ) {
		global $wpdb;
		$sql = 'SELECT COUNT(DISTINCT visitor_id) FROM ' . PBD_Exp_Schema::table( 'events' )
			. ' WHERE experiment_id = %d AND variant_id IS NULL AND event_name = %s';
		$args = array( (int) $experiment_id, $event_name );
		if ( $since ) {
			$sql .= ' AND occurred_at >= %s';
			$args[] = $since;
		}
		if ( $until ) {
			$sql .= ' AND occurred_at <= %s';
			$args[] = $until;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}

	public static function recent_events( $limit = 100 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT e.*, x.experiment_key, v.variant_key
				FROM ' . PBD_Exp_Schema::table( 'events' ) . ' e
				INNER JOIN ' . PBD_Exp_Schema::table( 'experiments' ) . ' x ON x.id = e.experiment_id
				LEFT JOIN ' . PBD_Exp_Schema::table( 'variants' ) . ' v ON v.id = e.variant_id
				ORDER BY e.id DESC LIMIT %d',
				(int) $limit
			),
			ARRAY_A
		);
	}

	public static function event_already_recorded( $visitor_id, $experiment_id, $event_name ) {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . PBD_Exp_Schema::table( 'events' ) . ' WHERE visitor_id = %s AND experiment_id = %d AND event_name = %s LIMIT 1',
				$visitor_id,
				(int) $experiment_id,
				$event_name
			)
		);
	}

	public static function insert_event( $data ) {
		global $wpdb;
		$wpdb->insert( PBD_Exp_Schema::table( 'events' ), $data );
		return (int) $wpdb->insert_id;
	}

	public static function get_snapshot( $experiment_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . PBD_Exp_Schema::table( 'snapshots' ) . ' WHERE experiment_id = %d', (int) $experiment_id ),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$row['snapshot'] = json_decode( $row['snapshot'], true );
		return $row;
	}

	public static function save_snapshot( $experiment_id, $snapshot ) {
		global $wpdb;
		$existing = self::get_snapshot( $experiment_id );
		$payload = array(
			'experiment_id' => (int) $experiment_id,
			'snapshot'      => wp_json_encode( $snapshot ),
			'frozen_at'     => current_time( 'mysql' ),
		);
		if ( $existing ) {
			$wpdb->update( PBD_Exp_Schema::table( 'snapshots' ), $payload, array( 'experiment_id' => (int) $experiment_id ) );
		} else {
			$wpdb->insert( PBD_Exp_Schema::table( 'snapshots' ), $payload );
		}
	}

	private static function cast_experiment( $row ) {
		$row['id']                 = (int) $row['id'];
		$row['cookie_days']        = (int) $row['cookie_days'];
		$row['include_logged_in']  = (int) $row['include_logged_in'];
		$row['winning_variant_id'] = isset( $row['winning_variant_id'] ) && null !== $row['winning_variant_id'] ? (int) $row['winning_variant_id'] : null;
		return $row;
	}

	private static function cast_variant( $row ) {
		$row['id']            = (int) $row['id'];
		$row['experiment_id'] = (int) $row['experiment_id'];
		$row['weight']        = (int) $row['weight'];
		$row['sort_order']    = (int) $row['sort_order'];
		return $row;
	}
}
