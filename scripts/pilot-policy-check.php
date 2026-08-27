<?php
/**
 * Standalone decision-matrix checks for pilot send policy (no WordPress bootstrap).
 *
 * @package Upr_Host_Adapter
 */

declare( strict_types=1 );

define( 'ABSPATH', '/tmp/' );

/**
 * Minimal option stand-in for offline checks.
 */
final class Upr_Host_Adapter_Options {
	/** @var array<string, mixed> */
	public static array $state = array(
		'pilot_invitation_sending_authorised' => false,
		'pilot_order_id_allowlist'            => array(),
	);

	public static function pilot_invitation_sending_authorised(): bool {
		return (bool) self::$state['pilot_invitation_sending_authorised'];
	}

	/** @return list<int> */
	public static function pilot_order_id_allowlist(): array {
		return array_values( array_map( 'intval', (array) self::$state['pilot_order_id_allowlist'] ) );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-invitation-send-policy.php';

/**
 * @param string               $label Case label.
 * @param array<string, mixed> $state Options state.
 * @param int                  $order_id Order under test.
 * @param string               $expected_decision Expected decision.
 */
function assert_case( string $label, array $state, int $order_id, string $expected_decision ): void {
	Upr_Host_Adapter_Options::$state = $state;
	$got = Upr_Host_Adapter_Invitation_Send_Policy::evaluate_pilot( array( 'order_id' => $order_id ) );
	if ( ( $got['decision'] ?? '' ) !== $expected_decision ) {
		fwrite( STDERR, "FAIL {$label}: expected {$expected_decision}, got " . wp_json_encode( $got ) . PHP_EOL );
		exit( 1 );
	}
	echo "OK {$label}\n";
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $data Data.
	 */
	function wp_json_encode( $data ): string {
		return (string) json_encode( $data );
	}
}

assert_case(
	'H1 defaults deny',
	array(
		'pilot_invitation_sending_authorised' => false,
		'pilot_order_id_allowlist'            => array(),
	),
	100,
	'not_authorised'
);

assert_case(
	'H2 empty allowlist deny',
	array(
		'pilot_invitation_sending_authorised' => true,
		'pilot_order_id_allowlist'            => array(),
	),
	100,
	'not_authorised'
);

assert_case(
	'H3 auth false with allowlist deny',
	array(
		'pilot_invitation_sending_authorised' => false,
		'pilot_order_id_allowlist'            => array( 100 ),
	),
	100,
	'not_authorised'
);

assert_case(
	'H4 allowlisted + authorised allow',
	array(
		'pilot_invitation_sending_authorised' => true,
		'pilot_order_id_allowlist'            => array( 100, 200 ),
	),
	100,
	'allow'
);

assert_case(
	'H5 non-allowlisted deny',
	array(
		'pilot_invitation_sending_authorised' => true,
		'pilot_order_id_allowlist'            => array( 100 ),
	),
	999,
	'not_authorised'
);

// H6/H8: filter must not upgrade any core denial (incl. boundary).
Upr_Host_Adapter_Options::$state = array(
	'pilot_invitation_sending_authorised' => true,
	'pilot_order_id_allowlist'            => array( 100 ),
);
foreach (
	array(
		'paused'                      => 'paused',
		'email_disabled'              => 'email_disabled',
		'not_authorised'              => 'not_authorised',
		'outside_scheduling_boundary' => 'outside_scheduling_boundary',
	) as $label => $decision
) {
	$got = Upr_Host_Adapter_Invitation_Send_Policy::filter_authorisation(
		array( 'decision' => $decision, 'reason_code' => $label ),
		array( 'order_id' => 100 )
	);
	if ( ( $got['decision'] ?? '' ) !== $decision ) {
		fwrite( STDERR, "FAIL H8 cannot upgrade {$label}\n" );
		exit( 1 );
	}
}
echo "OK H8 cannot upgrade core denials\n";

echo "All pilot decision checks passed\n";
