<?php
/**
 * Host options.
 *
 * @package Upr_Host_Adapter
 */

defined( 'ABSPATH' ) || exit;

final class Upr_Host_Adapter_Options {

	public const OPTION_KEY = 'upr_host_adapter_settings';

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'confirm_on_shipped'                 => true,
			'support_delay_days'                 => 14,
			'support_delay_tags'                 => array( 'order_issue', 'review_delay' ),
			'support_suppress_tags'              => array( 'chargeback', 'compliance', 'safety' ),
			'support_open_statuses'              => array( 'open', 'pending' ),
			'enable_pdp_summary'                 => true,
			'enable_card_ratings'                => false,
			'card_ratings_min_count'             => 3,
			'pilot_invitation_sending_authorised' => false,
			'pilot_order_id_allowlist'           => array(),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * @param string $key Option key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	public static function confirm_on_shipped(): bool {
		return (bool) self::get( 'confirm_on_shipped', true );
	}

	public static function support_delay_days(): int {
		return max( 1, (int) self::get( 'support_delay_days', 14 ) );
	}

	/**
	 * @return list<string>
	 */
	public static function support_delay_tags(): array {
		return self::string_list( self::get( 'support_delay_tags', array() ) );
	}

	/**
	 * @return list<string>
	 */
	public static function support_suppress_tags(): array {
		return self::string_list( self::get( 'support_suppress_tags', array() ) );
	}

	/**
	 * @return list<string>
	 */
	public static function support_open_statuses(): array {
		return self::string_list( self::get( 'support_open_statuses', array( 'open', 'pending' ) ) );
	}

	public static function pilot_invitation_sending_authorised(): bool {
		return (bool) self::get( 'pilot_invitation_sending_authorised', false );
	}

	/**
	 * @return list<int>
	 */
	public static function pilot_order_id_allowlist(): array {
		$raw = self::get( 'pilot_order_id_allowlist', array() );
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,]+/', $raw ) ?: array();
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$out[] = $id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param mixed $value Raw list.
	 * @return list<string>
	 */
	private static function string_list( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,]+/', $value ) ?: array();
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $item ) {
			$item = sanitize_key( (string) $item );
			if ( '' !== $item ) {
				$out[] = $item;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
