<?php
/**
 * WP-CLI: fail-closed DEV pilot preflight (UPR pin + environment).
 *
 * @package Upr_Host_Adapter
 */

defined( 'ABSPATH' ) || exit;

final class Upr_Host_Adapter_Verify_Pilot_Preflight_Command {

	/**
	 * Verify DEV environment, UPR v0.3.0 pin, and host adapter readiness.
	 *
	 * ## EXAMPLES
	 *
	 *     wp upr-host-adapter verify-pilot-preflight
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args Positional.
	 * @param array<string, string> $assoc Assoc.
	 */
	public function __invoke( $args, $assoc ): void {
		unset( $args, $assoc );

		$env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		if ( 'development' !== $env ) {
			WP_CLI::error(
				sprintf(
					'Refusing DEV pilot preflight: wp_get_environment_type() is "%s" (required: development).',
					$env
				)
			);
		}

		if ( ! is_plugin_active( 'universal-product-reviews/universal-product-reviews.php' ) ) {
			WP_CLI::error( 'universal-product-reviews is not active.' );
		}

		if ( ! is_plugin_active( 'upr-host-adapter/upr-host-adapter.php' ) ) {
			WP_CLI::error( 'upr-host-adapter is not active.' );
		}

		$pin = Upr_Host_Adapter_Upr_Pin::verify();
		if ( ! $pin['ok'] ) {
			foreach ( $pin['errors'] as $error ) {
				WP_CLI::warning( $error );
			}
			WP_CLI::error(
				sprintf(
					'UPR pin mismatch — required annotated tag %s @ %s.',
					Upr_Host_Adapter_Upr_Pin::REQUIRED_TAG,
					Upr_Host_Adapter_Upr_Pin::REQUIRED_COMMIT
				)
			);
		}

		if ( ! has_action( 'mpcf_fulfillment_state_changed' ) ) {
			WP_CLI::warning( 'No listener registered for mpcf_fulfillment_state_changed (MPCF may be inactive).' );
		}

		WP_CLI::success(
			sprintf(
				'DEV pilot preflight passed (env=development, UPR=%s @ %s, host=%s).',
				$pin['version'],
				$pin['commit'],
				UPR_HOST_ADAPTER_VERSION
			)
		);
	}
}
