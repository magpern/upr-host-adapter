<?php
/**
 * Required UPR release pin (packaged install + DEV).
 *
 * Production packages have no `.git`. The host verifies generic
 * `release.meta.json` beside the UPR plugin bootstrap (see UPR package-meta docs).
 * DEV checkouts must use the same metadata file (write from a verified Git HEAD
 * via the documented development helper — this class does not read `.git`).
 *
 * @package Upr_Host_Adapter
 */

defined( 'ABSPATH' ) || exit;

final class Upr_Host_Adapter_Upr_Pin {

	public const REQUIRED_VERSION = '0.3.0';

	public const REQUIRED_COMMIT = 'b2abc2defc30fc023601593aa1720cbfdd0a4f3c';

	public const REQUIRED_TAG = 'v0.3.0';

	/** Generic package metadata filename (no host/brand names). */
	public const PACKAGE_META_BASENAME = 'release.meta.json';

	/** Expected schema id inside release.meta.json. */
	public const PACKAGE_META_SCHEMA = 'universal-product-reviews.package-meta/v1';

	/**
	 * @return array{ok:bool,version:string,commit:?string,tag:?string,errors:list<string>}
	 */
	public static function verify(): array {
		$errors  = array();
		$version = defined( 'UPR_VERSION' ) ? (string) UPR_VERSION : '';

		if ( self::REQUIRED_VERSION !== $version ) {
			$errors[] = sprintf(
				'UPR_VERSION is "%s"; required "%s".',
				$version,
				self::REQUIRED_VERSION
			);
		}

		$meta   = self::load_package_meta();
		$commit = null;
		$tag    = null;

		if ( ! $meta['ok'] ) {
			$errors[] = $meta['error'];
		} else {
			$tag    = $meta['tag'];
			$commit = $meta['commit'];
			$meta_v = $meta['version'];

			if ( self::REQUIRED_VERSION !== $meta_v ) {
				$errors[] = sprintf(
					'UPR package metadata version is "%s"; required "%s".',
					$meta_v,
					self::REQUIRED_VERSION
				);
			}
			if ( self::REQUIRED_TAG !== $tag ) {
				$errors[] = sprintf(
					'UPR package metadata tag is "%s"; required "%s".',
					$tag,
					self::REQUIRED_TAG
				);
			}
			if ( 0 !== strcasecmp( self::REQUIRED_COMMIT, $commit ) ) {
				$errors[] = sprintf(
					'UPR package metadata commit is "%s"; required "%s".',
					$commit,
					self::REQUIRED_COMMIT
				);
			}
		}

		if ( ! class_exists( \UniversalProductReviews\Submission\NativePdpForm::class ) ) {
			$errors[] = 'UPR NativePdpForm API is unavailable (required for host display helper).';
		}
		if ( ! class_exists( \UniversalProductReviews\Submission\NativeSubmissionGuard::class ) ) {
			$errors[] = 'UPR NativeSubmissionGuard API is unavailable (required for native enforcement).';
		}
		if ( ! class_exists( \UniversalProductReviews\Invitations\InvitationAuthorisation::class ) ) {
			$errors[] = 'UPR InvitationAuthorisation API is unavailable (required for invitation send policy).';
		}

		return array(
			'ok'      => empty( $errors ),
			'version' => $version,
			'commit'  => $commit,
			'tag'     => $tag,
			'errors'  => $errors,
		);
	}

	/**
	 * Fail-closed readiness for native-PDP display decisions.
	 */
	public static function display_api_ready(): bool {
		return class_exists( \UniversalProductReviews\Submission\NativePdpForm::class )
			&& defined( 'UPR_VERSION' )
			&& self::REQUIRED_VERSION === (string) UPR_VERSION;
	}

	/**
	 * Absolute path to UPR release.meta.json, or null if UPR_PLUGIN_DIR unset.
	 */
	public static function package_meta_path(): ?string {
		if ( ! defined( 'UPR_PLUGIN_DIR' ) ) {
			return null;
		}
		return rtrim( (string) UPR_PLUGIN_DIR, '/' ) . '/' . self::PACKAGE_META_BASENAME;
	}

	/**
	 * Load and structurally validate package metadata (fail-closed).
	 *
	 * @return array{
	 *   ok:bool,
	 *   error:string,
	 *   schema?:string,
	 *   version?:string,
	 *   tag?:string,
	 *   commit?:string
	 * }
	 */
	public static function load_package_meta(): array {
		$path = self::package_meta_path();
		if ( null === $path || ! is_readable( $path ) ) {
			return array(
				'ok'    => false,
				'error' => 'UPR package metadata missing or unreadable (release.meta.json).',
			);
		}

		$raw = file_get_contents( $path );
		if ( false === $raw || '' === trim( $raw ) ) {
			return array(
				'ok'    => false,
				'error' => 'UPR package metadata is empty or unreadable.',
			);
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return array(
				'ok'    => false,
				'error' => 'UPR package metadata is not valid JSON object.',
			);
		}

		$schema = isset( $data['schema'] ) ? (string) $data['schema'] : '';
		if ( self::PACKAGE_META_SCHEMA !== $schema ) {
			return array(
				'ok'    => false,
				'error' => sprintf(
					'UPR package metadata schema is "%s"; required "%s".',
					$schema,
					self::PACKAGE_META_SCHEMA
				),
			);
		}

		foreach ( array( 'version', 'tag', 'commit' ) as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_string( $data[ $key ] ) || '' === $data[ $key ] ) {
				return array(
					'ok'    => false,
					'error' => sprintf( 'UPR package metadata missing string field "%s".', $key ),
				);
			}
		}

		$commit = strtolower( (string) $data['commit'] );
		if ( ! preg_match( '/^[0-9a-f]{40}$/', $commit ) ) {
			return array(
				'ok'    => false,
				'error' => 'UPR package metadata commit must be a 40-char hex SHA.',
			);
		}

		return array(
			'ok'      => true,
			'error'   => '',
			'schema'  => $schema,
			'version' => (string) $data['version'],
			'tag'     => (string) $data['tag'],
			'commit'  => $commit,
		);
	}

	/**
	 * Commit from package metadata only (no `.git`).
	 */
	public static function resolve_installed_commit(): ?string {
		$meta = self::load_package_meta();
		if ( ! $meta['ok'] ) {
			return null;
		}
		return $meta['commit'];
	}
}
