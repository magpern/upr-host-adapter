<?php
/**
 * WP-CLI: DEV support adapter verification (structured fields + lookup failure).
 *
 * @package Upr_Host_Adapter
 */

defined( 'ABSPATH' ) || exit;

final class Upr_Host_Adapter_Verify_Support_Dev_Command {

	private const FIXTURE_ORDER = 987654321;

	/**
	 * Verify support delay/suppress allowlists, lookup failure → delay, free text ignored.
	 *
	 * ## EXAMPLES
	 *
	 *     wp upr-host-adapter verify-support-dev
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args Positional.
	 * @param array<string, string> $assoc Assoc.
	 */
	public function __invoke( $args, $assoc ): void {
		unset( $args, $assoc );

		if ( 'development' !== wp_get_environment_type() ) {
			WP_CLI::error( 'Refusing: environment is not development.' );
		}

		$pin = Upr_Host_Adapter_Upr_Pin::verify();
		if ( ! $pin['ok'] ) {
			WP_CLI::error( 'Refusing: UPR pin mismatch.' );
		}

		global $wpdb;
		$details = $wpdb->prefix . 'fluentform_entry_details';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $details ) );
		if ( $details !== $table ) {
			WP_CLI::error( 'Fluent entry_details table missing — cannot run support integration proof.' );
		}

		$submission_id = (int) ( time() % 1000000000 );
		$this->cleanup_fixture( $submission_id );

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$wpdb->prefix . 'fluentform_submissions',
				array(
					'form_id'    => 1,
					'serial_number' => $submission_id,
					'response'   => wp_json_encode( array( 'ignored' => 'chargeback in free text must not match' ) ),
					'status'     => 'read',
					'created_at' => current_time( 'mysql' ),
				)
			);

			$rows = array(
				array( 'submission_id' => $submission_id, 'field_name' => 'wc_related_order', 'field_value' => (string) self::FIXTURE_ORDER ),
				array( 'submission_id' => $submission_id, 'field_name' => 'message', 'field_value' => 'chargeback safety compliance in body — must be ignored' ),
				array( 'submission_id' => $submission_id, 'field_name' => 'description', 'field_value' => 'order_issue review_delay keywords in prose only' ),
			);
			foreach ( $rows as $row ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->insert( $details, $row );
			}

			$none = apply_filters( 'upr_review_invitation_action', array( 'action' => 'none' ), self::FIXTURE_ORDER, 1 );
			WP_CLI::log( '[CHECK] free-text fields only → ' . wp_json_encode( $none ) );
			if ( 'none' !== ( $none['action'] ?? '' ) ) {
				WP_CLI::error( 'Free-text fields incorrectly influenced invitation action.' );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$details,
				array(
					'submission_id' => $submission_id,
					'field_name'    => 'reason_for_contact',
					'field_value'   => 'chargeback',
				)
			);

			$suppress = apply_filters( 'upr_review_invitation_action', array( 'action' => 'none' ), self::FIXTURE_ORDER, 1 );
			WP_CLI::log( '[CHECK] allowlisted suppress tag → ' . wp_json_encode( $suppress ) );
			if ( 'suppress' !== ( $suppress['action'] ?? '' ) || 'support_chargeback' !== ( $suppress['code'] ?? '' ) ) {
				WP_CLI::error( 'Allowlisted suppress tag did not produce suppress action.' );
			}

			$this->cleanup_fixture( $submission_id );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$wpdb->prefix . 'fluentform_submissions',
				array(
					'form_id'    => 1,
					'serial_number' => $submission_id + 1,
					'response'   => '{}',
					'status'     => 'read',
					'created_at' => current_time( 'mysql' ),
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$details,
				array(
					'submission_id' => $submission_id + 1,
					'field_name'    => 'wc_related_order',
					'field_value'   => (string) ( self::FIXTURE_ORDER + 1 ),
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$details,
				array(
					'submission_id' => $submission_id + 1,
					'field_name'    => 'reason_for_contact',
					'field_value'   => 'order_issue',
				)
			);

			$delay = apply_filters( 'upr_review_invitation_action', array( 'action' => 'none' ), self::FIXTURE_ORDER + 1, 1 );
			WP_CLI::log( '[CHECK] allowlisted delay tag (open ticket simulated absent) → ' . wp_json_encode( $delay ) );
			// Without open ticket, delay tags alone do not delay — only suppress tags fire without open ticket.
			// order_issue requires open ticket per adapter logic.
			if ( 'none' !== ( $delay['action'] ?? '' ) ) {
				WP_CLI::warning( 'Delay tag without open ticket returned ' . wp_json_encode( $delay ) . ' (expected none when no open ticket).' );
			}

			$this->cleanup_fixture( $submission_id + 1 );

			add_filter(
				'upr_host_adapter_dev_force_support_lookup_failure',
				static function ( $force, $order_id ): bool {
					return $force || ( self::FIXTURE_ORDER + 99 === (int) $order_id );
				},
				10,
				2
			);

			$fail = apply_filters( 'upr_review_invitation_action', array( 'action' => 'none' ), self::FIXTURE_ORDER + 99, 1 );
			WP_CLI::log( '[CHECK] lookup failure → ' . wp_json_encode( $fail ) );
			if ( 'delay' !== ( $fail['action'] ?? '' ) || 'support_lookup_failure' !== ( $fail['code'] ?? '' ) ) {
				WP_CLI::error( 'Support lookup failure did not map to delay.' );
			}

			WP_CLI::success( 'Support DEV verification passed (free-text ignored, suppress allowlist, lookup failure → delay).' );
		} finally {
			$this->cleanup_fixture( $submission_id );
			$this->cleanup_fixture( $submission_id + 1 );
		}
	}

	private function cleanup_fixture( int $submission_id ): void {
		global $wpdb;
		$details = $wpdb->prefix . 'fluentform_entry_details';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$details} WHERE submission_id = %d", $submission_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}fluentform_submissions WHERE serial_number = %d", $submission_id ) );
	}
}
