<?php
/**
 * WP-CLI: DEV token redaction verification against operator-supplied log paths.
 *
 * Requires env UPR_HOST_ADAPTER_ACCESS_LOG_DIR pointing at a directory of
 * readable access/cache logs. No default host paths are embedded.
 *
 * @package Upr_Host_Adapter
 */

defined( 'ABSPATH' ) || exit;

final class Upr_Host_Adapter_Verify_Token_Redaction_Dev_Command {

	/**
	 * Probe invite URL and verify raw token absent from configured access logs.
	 *
	 * ## EXAMPLES
	 *
	 *     UPR_HOST_ADAPTER_ACCESS_LOG_DIR=/path/to/logs wp upr-host-adapter verify-token-redaction-dev
	 *
	 * ## OPTIONS
	 *
	 * [--token=<token>]
	 * : Probe token segment (default: generated).
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args Positional.
	 * @param array<string, string> $assoc Assoc.
	 */
	public function __invoke( $args, $assoc ): void {
		unset( $args );

		if ( 'development' !== wp_get_environment_type() ) {
			WP_CLI::error( 'Refusing: environment is not development.' );
		}

		$log_root = getenv( 'UPR_HOST_ADAPTER_ACCESS_LOG_DIR' );
		if ( ! is_string( $log_root ) || '' === $log_root || ! is_dir( $log_root ) ) {
			WP_CLI::error( 'Set UPR_HOST_ADAPTER_ACCESS_LOG_DIR to a readable log directory (no default path).' );
		}

		$token = isset( $assoc['token'] ) ? (string) $assoc['token'] : 'm3reval' . wp_generate_password( 24, false, false );
		$paths = array(
			'token' => home_url( '/upr-review/' . rawurlencode( $token ) . '/' ),
			'form'  => home_url( '/upr-review/form/' ),
		);

		$candidates = array( 'access.log', 'cache.log', 'bp-cache.log' );
		$log_files  = array();
		foreach ( $candidates as $name ) {
			$full = rtrim( $log_root, '/' ) . '/' . $name;
			if ( is_readable( $full ) ) {
				$log_files[ $name ] = $full;
			}
		}
		if ( array() === $log_files ) {
			WP_CLI::error( 'No readable access/cache log files found under UPR_HOST_ADAPTER_ACCESS_LOG_DIR.' );
		}

		wp_remote_head(
			$paths['token'],
			array(
				'timeout'   => 15,
				'sslverify' => false,
			)
		);
		wp_remote_head(
			$paths['form'],
			array(
				'timeout'   => 15,
				'sslverify' => false,
			)
		);

		sleep( 1 );

		$failures = array();
		foreach ( $log_files as $label => $file ) {
			$tail = $this->read_tail( $file, 4096 );
			if ( false !== strpos( $tail, $token ) ) {
				$failures[] = "{$label}: raw token found";
			}
			if ( false === strpos( $tail, '[redacted]' ) && false !== strpos( $tail, 'upr-review' ) ) {
				$failures[] = "{$label}: upr-review present but [redacted] marker missing in recent tail";
			}
		}

		if ( ! empty( $failures ) ) {
			foreach ( $failures as $msg ) {
				WP_CLI::warning( $msg );
			}
			WP_CLI::error( 'Token redaction verification failed for readable logs.' );
		}

		WP_CLI::log( 'Probe token length: ' . strlen( $token ) . ' (value not printed).' );
		WP_CLI::success( 'Token redaction verification passed for configured log files.' );
	}

	private function read_tail( string $file, int $bytes ): string {
		$size = filesize( $file );
		if ( false === $size || $size <= $bytes ) {
			return (string) file_get_contents( $file );
		}
		$fh = fopen( $file, 'rb' );
		if ( ! $fh ) {
			return '';
		}
		fseek( $fh, -$bytes, SEEK_END );
		$data = fread( $fh, $bytes );
		fclose( $fh );
		return false === $data ? '' : $data;
	}
}
