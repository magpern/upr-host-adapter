<?php
/**
 * Support desk → UPR invitation action adapter.
 *
 * Matches only structured Fluent entry_details (wc_related_order + tag fields)
 * and desk ticket status. Never inspects ticket body / free text.
 *
 * @package Upr_Host_Adapter
 */

defined( 'ABSPATH' ) || exit;

final class Upr_Host_Adapter_Support_Adapter {

	public static function register(): void {
		add_filter( 'upr_review_invitation_action', array( __CLASS__, 'filter_invitation_action' ), 10, 3 );
	}

	/**
	 * @param array<string, mixed> $decision Prior decision.
	 * @param int                  $order_id Order ID.
	 * @param int                  $order_item_id Order item ID.
	 * @return array<string, mixed>
	 */
	public static function filter_invitation_action( array $decision, int $order_id, int $order_item_id ): array {
		unset( $order_item_id );
		try {
			$signals = self::lookup_structured_signals( $order_id );
		} catch ( Throwable $e ) {
			return self::delay_decision( 'support_lookup_failure' );
		}

		if ( null === $signals ) {
			return self::delay_decision( 'support_lookup_failure' );
		}

		$tags = $signals['tags'];
		foreach ( Upr_Host_Adapter_Options::support_suppress_tags() as $suppress_tag ) {
			if ( in_array( $suppress_tag, $tags, true ) ) {
				return array(
					'action' => 'suppress',
					'code'   => 'support_' . $suppress_tag,
				);
			}
		}

		$has_open = (bool) $signals['has_open_ticket'];
		if ( $has_open ) {
			foreach ( Upr_Host_Adapter_Options::support_delay_tags() as $delay_tag ) {
				if ( in_array( $delay_tag, $tags, true ) ) {
					return self::delay_decision( 'support_' . $delay_tag );
				}
			}
		}

		return is_array( $decision ) ? $decision : array( 'action' => 'none' );
	}

	/**
	 * @param string $code Delay code.
	 * @return array<string, mixed>
	 */
	private static function delay_decision( string $code ): array {
		$days = Upr_Host_Adapter_Options::support_delay_days();
		return array(
			'action'      => 'delay',
			'code'        => $code,
			'delay_until' => time() + ( $days * DAY_IN_SECONDS ),
		);
	}

	/**
	 * @param int $order_id Order ID.
	 * @return array{tags:list<string>,has_open_ticket:bool}|null Null on lookup failure.
	 */
	private static function lookup_structured_signals( int $order_id ): ?array {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return null;
		}

		if ( apply_filters( 'upr_host_adapter_dev_force_support_lookup_failure', false, $order_id ) ) {
			return null;
		}

		$order_key = (string) $order_id;
		$details   = $wpdb->prefix . 'fluentform_entry_details';
		/**
		 * Optional support ticket table name without $wpdb->prefix (e.g. `inbox_tickets`).
		 * Empty string skips open-ticket status checks (tags from Fluent entry_details still apply).
		 *
		 * @param string $table_suffix Table suffix without prefix.
		 * @param int    $order_id     Order ID.
		 */
		$ticket_suffix = (string) apply_filters( 'upr_host_adapter_support_tickets_table', '', $order_id );
		$tickets       = '' !== $ticket_suffix ? $wpdb->prefix . sanitize_key( $ticket_suffix ) : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $details ) );
		if ( $details !== $exists ) {
			// Desk not present — treat as no structured signal (none), not outage.
			return array(
				'tags'            => array(),
				'has_open_ticket' => false,
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$submission_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT submission_id FROM {$details} WHERE field_name = %s AND field_value = %s",
				'wc_related_order',
				$order_key
			)
		);

		if ( false === $submission_ids ) {
			return null;
		}

		if ( empty( $submission_ids ) ) {
			return array(
				'tags'            => array(),
				'has_open_ticket' => false,
			);
		}

		$tags = array();
		foreach ( $submission_ids as $submission_id ) {
			$submission_id = (int) $submission_id;
			// Structured tag fields only — never response/body JSON.
			foreach ( array( 'reason_for_contact', 'upr_support_tag', 'support_tag' ) as $field_name ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$value = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT field_value FROM {$details} WHERE submission_id = %d AND field_name = %s LIMIT 1",
						$submission_id,
						$field_name
					)
				);
				if ( is_string( $value ) && '' !== $value ) {
					$tags[] = sanitize_key( $value );
				}
			}
		}
		$tags = array_values( array_unique( array_filter( $tags ) ) );

		$has_open = false;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( '' !== $tickets ) {
			$ticket_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tickets ) );
			if ( $tickets === $ticket_table ) {
				$open_statuses = Upr_Host_Adapter_Options::support_open_statuses();
				if ( ! empty( $open_statuses ) ) {
					$placeholders = implode( ',', array_fill( 0, count( $open_statuses ), '%s' ) );
					foreach ( $submission_ids as $submission_id ) {
						$params   = array_merge( array( 'fluent', (string) (int) $submission_id ), $open_statuses );
						$sql      = "SELECT id FROM {$tickets} WHERE source = %s AND source_ref = %s AND archived_at IS NULL AND status IN ({$placeholders}) LIMIT 1";
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
						$found = $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
						if ( $wpdb->last_error ) {
							return null;
						}
						if ( ! empty( $found ) ) {
							$has_open = true;
							break;
						}
					}
				}
			}
		}

		if ( $wpdb->last_error ) {
			return null;
		}

		return array(
			'tags'            => $tags,
			'has_open_ticket' => $has_open,
		);
	}
}
