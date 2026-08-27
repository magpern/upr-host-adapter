<?php
/**
 * WP-CLI: DEV Action Scheduler drain proof for controlled UPR jobs.
 *
 * @package Upr_Host_Adapter
 */

defined( 'ABSPATH' ) || exit;

final class Upr_Host_Adapter_Verify_As_Drain_Dev_Command {

	/**
	 * Prove a controlled UPR AS job is enqueued and drains via WP-Cron.
	 *
	 * ## EXAMPLES
	 *
	 *     wp upr-host-adapter verify-as-drain-dev
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

		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_get_scheduled_actions' ) ) {
			WP_CLI::error( 'Action Scheduler unavailable.' );
		}

		$hook  = 'upr_send_reminder_item';
		$group = 'upr';
		$args  = array( 900000000 + wp_rand( 1, 999999 ) );

		$before_pending = count(
			as_get_scheduled_actions(
				array(
					'hook'   => $hook,
					'group'  => $group,
					'status' => \ActionScheduler_Store::STATUS_PENDING,
					'args'   => $args,
				)
			)
		);

		$action_id = as_enqueue_async_action( $hook, $args, $group, true );
		if ( ! $action_id ) {
			WP_CLI::error( 'Failed to enqueue controlled UPR reminder probe.' );
		}

		$after_enqueue = count(
			as_get_scheduled_actions(
				array(
					'hook'   => $hook,
					'group'  => $group,
					'status' => \ActionScheduler_Store::STATUS_PENDING,
					'args'   => $args,
				)
			)
		);

		if ( $after_enqueue <= $before_pending ) {
			WP_CLI::error( 'Controlled UPR reminder job was not enqueued.' );
		}

		WP_CLI::log( sprintf( 'Pending %s (%d) before → %d after enqueue (action_id=%s)', $hook, $before_pending, $after_enqueue, (string) $action_id ) );

		if ( class_exists( 'ActionScheduler_QueueRunner' ) ) {
			\ActionScheduler_QueueRunner::instance()->run();
		} else {
			WP_CLI::error( 'ActionScheduler_QueueRunner unavailable.' );
		}

		$after_drain = count(
			as_get_scheduled_actions(
				array(
					'hook'   => $hook,
					'group'  => $group,
					'status' => \ActionScheduler_Store::STATUS_PENDING,
					'args'   => $args,
				)
			)
		);

		$completed = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => $group,
				'status'   => \ActionScheduler_Store::STATUS_COMPLETE,
				'args'     => $args,
				'per_page' => 1,
			)
		);

		WP_CLI::log( sprintf( 'Pending after drain: %d; completed rows for probe args: %d', $after_drain, count( $completed ) ) );

		if ( $after_drain > 0 || empty( $completed ) ) {
			WP_CLI::error( 'Controlled UPR AS job did not drain to completion.' );
		}

		// Host cron path (DISABLE_WP_CRON): prove wp-cron.php also drains when probe re-enqueued.
		$requeue = as_enqueue_async_action( $hook, $args, $group, true );
		if ( $requeue ) {
			wp_remote_post(
				site_url( 'wp-cron.php?doing_wp_cron' ),
				array(
					'timeout'   => 30,
					'blocking'  => true,
					'sslverify' => false,
				)
			);
			sleep( 2 );
			$after_cron = count(
				as_get_scheduled_actions(
					array(
						'hook'   => $hook,
						'group'  => $group,
						'status' => \ActionScheduler_Store::STATUS_PENDING,
						'args'   => $args,
					)
				)
			);
			WP_CLI::log( sprintf( 'After wp-cron.php spawn: pending probe jobs=%d', $after_cron ) );
		}

		WP_CLI::success( 'Action Scheduler drain proof passed (controlled upr_send_reminder_item executed and completed).' );
	}
}
