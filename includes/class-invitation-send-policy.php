<?php
/**
 * Temporary pilot invitation send authorisation (UPR contract).
 *
 * @package Upr_Host_Adapter
 */

defined( 'ABSPATH' ) || exit;

final class Upr_Host_Adapter_Invitation_Send_Policy {

	public const FILTER = 'upr_invitation_send_authorisation';

	public static function register(): void {
		if ( ! self::contract_available() ) {
			return;
		}
		add_filter( self::FILTER, array( __CLASS__, 'filter_authorisation' ), 10, 2 );
	}

	/**
	 * UPR invitation authorisation API present.
	 */
	public static function contract_available(): bool {
		return class_exists( '\UniversalProductReviews\Invitations\InvitationAuthorisation' );
	}

	/**
	 * @param array<string, mixed> $decision Core provisional decision.
	 * @param array<string, mixed> $context Generic schedule/send context.
	 * @return array<string, mixed>
	 */
	public static function filter_authorisation( array $decision, array $context ): array {
		$incoming = isset( $decision['decision'] ) ? (string) $decision['decision'] : 'allow';

		// Restrictive only: never upgrade a core denial into allow (including
		// email_disabled, paused, not_authorised, outside_scheduling_boundary).
		if ( 'allow' !== $incoming ) {
			return $decision;
		}

		return self::evaluate_pilot( $context );
	}

	/**
	 * Pure pilot decision (testable without WordPress filter stack).
	 *
	 * @param array<string, mixed> $context Context with order_id.
	 * @return array{decision:string,reason_code?:string}
	 */
	public static function evaluate_pilot( array $context ): array {
		if ( ! Upr_Host_Adapter_Options::pilot_invitation_sending_authorised() ) {
			return array(
				'decision'    => 'not_authorised',
				'reason_code' => 'pilot_not_authorised',
			);
		}

		$order_id = (int) ( $context['order_id'] ?? 0 );
		$allowed  = Upr_Host_Adapter_Options::pilot_order_id_allowlist();
		if ( array() === $allowed || ! in_array( $order_id, $allowed, true ) ) {
			return array(
				'decision'    => 'not_authorised',
				'reason_code' => 'pilot_order_not_allowlisted',
			);
		}

		return array( 'decision' => 'allow' );
	}
}
