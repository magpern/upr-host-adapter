<?php
/**
 * Offline package-pin checks for upr-host-adapter 0.1.0+ (no WordPress bootstrap).
 *
 * @package Upr_Host_Adapter
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Submission {
	class NativePdpForm {}
	class NativeSubmissionGuard {}
}

namespace UniversalProductReviews\Invitations {
	class InvitationAuthorisation {}
}

namespace {
	define( 'ABSPATH', '/tmp/' );

	require_once dirname( __DIR__ ) . '/includes/class-upr-pin.php';

	/**
	 * @param string $label Case label.
	 * @param bool   $ok Expected ok.
	 * @param string $needle Optional substring required in errors.
	 */
	function assert_verify( string $label, bool $ok, string $needle = '' ): void {
		$got = \Upr_Host_Adapter_Upr_Pin::verify();
		if ( (bool) $got['ok'] !== $ok ) {
			fwrite( STDERR, "FAIL {$label}: expected ok=" . ( $ok ? 'true' : 'false' ) . ' got ' . json_encode( $got ) . PHP_EOL );
			exit( 1 );
		}
		if ( '' !== $needle ) {
			$blob = implode( ' ', $got['errors'] );
			if ( false === strpos( $blob, $needle ) ) {
				fwrite( STDERR, "FAIL {$label}: expected error containing {$needle}; got " . json_encode( $got['errors'] ) . PHP_EOL );
				exit( 1 );
			}
		}
		echo "OK {$label}\n";
	}

	/**
	 * @param array<string,mixed>|null $meta Meta or null to delete file.
	 */
	function write_meta( ?array $meta ): void {
		$path = rtrim( (string) UPR_PLUGIN_DIR, '/' ) . '/' . \Upr_Host_Adapter_Upr_Pin::PACKAGE_META_BASENAME;
		if ( null === $meta ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
			return;
		}
		file_put_contents( $path, json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}

	$dir = sys_get_temp_dir() . '/upr-pin-check-' . bin2hex( random_bytes( 4 ) );
	if ( ! mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
		fwrite( STDERR, "FAIL mkdir {$dir}\n" );
		exit( 1 );
	}
	define( 'UPR_PLUGIN_DIR', $dir );
	define( 'UPR_VERSION', \Upr_Host_Adapter_Upr_Pin::REQUIRED_VERSION );

	$valid = array(
		'schema'  => \Upr_Host_Adapter_Upr_Pin::PACKAGE_META_SCHEMA,
		'version' => \Upr_Host_Adapter_Upr_Pin::REQUIRED_VERSION,
		'tag'     => \Upr_Host_Adapter_Upr_Pin::REQUIRED_TAG,
		'commit'  => \Upr_Host_Adapter_Upr_Pin::REQUIRED_COMMIT,
	);

	write_meta( $valid );
	assert_verify( 'packaged valid meta no .git accepted', true );

	$git = $dir . '/.git';
	mkdir( $git . '/refs/heads', 0700, true );
	file_put_contents( $git . '/HEAD', "ref: refs/heads/main\n" );
	file_put_contents( $git . '/refs/heads/main', "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n" );
	assert_verify( 'valid meta ignores decoy .git', true );

	write_meta( null );
	assert_verify( 'missing metadata denied', false, 'missing or unreadable' );

	file_put_contents(
		$dir . '/' . \Upr_Host_Adapter_Upr_Pin::PACKAGE_META_BASENAME,
		"{not-json\n"
	);
	assert_verify( 'malformed metadata denied', false, 'not valid JSON' );

	write_meta(
		array(
			'schema'  => 'other.schema/v1',
			'version' => $valid['version'],
			'tag'     => $valid['tag'],
			'commit'  => $valid['commit'],
		)
	);
	assert_verify( 'untrusted schema denied', false, 'schema' );

	$wrong            = $valid;
	$wrong['version'] = '0.2.9';
	write_meta( $wrong );
	assert_verify( 'wrong version denied', false, 'version' );

	$wrong        = $valid;
	$wrong['tag'] = 'v0.2.9';
	write_meta( $wrong );
	assert_verify( 'wrong tag denied', false, 'tag' );

	$wrong           = $valid;
	$wrong['commit'] = '1111111111111111111111111111111111111111';
	write_meta( $wrong );
	assert_verify( 'wrong commit denied', false, 'commit' );

	$child = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/package-pin-check-missing-api.php' );
	exec( $child . ' 2>&1', $out, $code );
	if ( 0 !== $code ) {
		fwrite( STDERR, "FAIL required API missing child:\n" . implode( "\n", $out ) . PHP_EOL );
		exit( 1 );
	}
	echo "OK required API missing denied\n";

	require_once dirname( __DIR__ ) . '/includes/class-invitation-send-policy.php';

	/**
	 * Minimal options for policy immutability check.
	 */
	final class Upr_Host_Adapter_Options {
		/** @var array<string, mixed> */
		public static array $state = array(
			'pilot_invitation_sending_authorised' => true,
			'pilot_order_id_allowlist'            => array( 100 ),
		);

		public static function pilot_invitation_sending_authorised(): bool {
			return (bool) self::$state['pilot_invitation_sending_authorised'];
		}

		/** @return list<int> */
		public static function pilot_order_id_allowlist(): array {
			return array_values( array_map( 'intval', (array) self::$state['pilot_order_id_allowlist'] ) );
		}
	}

	foreach ( array( 'email_disabled', 'paused', 'outside_scheduling_boundary' ) as $decision ) {
		$got = \Upr_Host_Adapter_Invitation_Send_Policy::filter_authorisation(
			array(
				'decision'    => $decision,
				'reason_code' => $decision,
			),
			array( 'order_id' => 100 )
		);
		if ( ( $got['decision'] ?? '' ) !== $decision ) {
			fwrite( STDERR, "FAIL immutable core denial {$decision}\n" );
			exit( 1 );
		}
		echo "OK immutable core denial {$decision}\n";
	}

	echo "All package-pin checks passed\n";
}
