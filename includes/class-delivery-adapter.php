<?php
/**
 * Hybrid MPCF → UPR delivery adapter.
 *
 * @package Upr_Host_Adapter
 */

defined( 'ABSPATH' ) || exit;

final class Upr_Host_Adapter_Delivery_Adapter {

	public const META_HOST_CONFIRMED_AT = '_upr_host_adapter_delivery_confirmed_at';
	public const META_HOST_SOURCE       = '_upr_host_adapter_delivery_source';
	public const META_HOST_FULFILLMENT  = '_upr_host_adapter_fulfillment_id';

	public static function register(): void {
		add_action( 'mpcf_fulfillment_state_changed', array( __CLASS__, 'on_fulfillment_state_changed' ), 10, 1 );
		add_filter( 'upr_is_order_delivered', array( __CLASS__, 'filter_is_order_delivered' ), 10, 2 );
		add_filter( 'upr_order_delivery_confirmed_at', array( __CLASS__, 'filter_confirmed_at' ), 10, 2 );

		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_order_cancelled' ), 20, 1 );
		add_action( 'woocommerce_order_fully_refunded', array( __CLASS__, 'on_order_fully_refunded' ), 20, 1 );
	}

	/**
	 * @param array<string, mixed> $payload MPCF lifecycle payload.
	 */
	public static function on_fulfillment_state_changed( $payload ): void {
		try {
			if ( ! is_array( $payload ) ) {
				return;
			}

			$order_id       = (int) ( $payload['order_id'] ?? 0 );
			$to_state       = (string) ( $payload['to_state'] ?? '' );
			$occurred_at    = (int) ( $payload['occurred_at'] ?? time() );
			$fulfillment_id = (int) ( $payload['fulfillment_id'] ?? 0 );

			if ( $order_id <= 0 || '' === $to_state ) {
				return;
			}

			if ( 'delivered' === $to_state ) {
				self::confirm( $order_id, 'delivered', $occurred_at, $fulfillment_id );
				return;
			}

			if ( 'shipped' === $to_state && Upr_Host_Adapter_Options::confirm_on_shipped() ) {
				self::confirm( $order_id, 'shipped_fallback', $occurred_at, $fulfillment_id );
			}
		} catch ( Throwable $e ) {
			self::log_error( 'delivery listener: ' . $e->getMessage() );
		}
	}

	/**
	 * @param int    $order_id Order ID.
	 * @param string $source   delivered|shipped_fallback.
	 * @param int    $unix_ts  Event time.
	 * @param int    $fulfillment_id Fulfillment ID.
	 */
	private static function confirm( int $order_id, string $source, int $unix_ts, int $fulfillment_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$existing_source = (string) $order->get_meta( self::META_HOST_SOURCE, true );
		// Idempotency: never re-fire when already confirmed from delivered, or
		// when shipped_fallback already ran and later delivered arrives —
		// upgrade source meta but skip duplicate UPR confirm scheduling.
		if ( 'delivered' === $existing_source ) {
			return;
		}
		if ( 'shipped_fallback' === $existing_source && 'shipped_fallback' === $source ) {
			return;
		}
		if ( 'shipped_fallback' === $existing_source && 'delivered' === $source ) {
			$order->update_meta_data( self::META_HOST_SOURCE, 'delivered' );
			$order->update_meta_data( self::META_HOST_CONFIRMED_AT, (string) $unix_ts );
			if ( $fulfillment_id > 0 ) {
				$order->update_meta_data( self::META_HOST_FULFILLMENT, (string) $fulfillment_id );
			}
			$order->save();
			return;
		}

		$order->update_meta_data( self::META_HOST_SOURCE, $source );
		$order->update_meta_data( self::META_HOST_CONFIRMED_AT, (string) $unix_ts );
		if ( $fulfillment_id > 0 ) {
			$order->update_meta_data( self::META_HOST_FULFILLMENT, (string) $fulfillment_id );
		}
		$order->save();

		/**
		 * Bridge to UPR — context may include delivered_at.
		 */
		do_action(
			'upr_order_delivery_confirmed',
			$order_id,
			array(
				'delivered_at' => $unix_ts,
				'source'       => $source,
			)
		);
	}

	/**
	 * @param bool $delivered Prior.
	 * @param int  $order_id  Order ID.
	 */
	public static function filter_is_order_delivered( bool $delivered, int $order_id ): bool {
		if ( $delivered ) {
			return true;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}
		return '' !== (string) $order->get_meta( self::META_HOST_CONFIRMED_AT, true );
	}

	/**
	 * @param int $timestamp Prior.
	 * @param int $order_id  Order ID.
	 */
	public static function filter_confirmed_at( int $timestamp, int $order_id ): int {
		if ( $timestamp > 0 ) {
			return $timestamp;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return 0;
		}
		return (int) $order->get_meta( self::META_HOST_CONFIRMED_AT, true );
	}

	/**
	 * @param int $order_id Order ID.
	 */
	public static function on_order_cancelled( int $order_id ): void {
		self::invalidate( $order_id, 'cancelled' );
	}

	/**
	 * @param int $order_id Order ID.
	 */
	public static function on_order_fully_refunded( int $order_id ): void {
		self::invalidate( $order_id, 'fully_refunded' );
	}

	/**
	 * @param int    $order_id Order ID.
	 * @param string $reason   Reason code.
	 */
	private static function invalidate( int $order_id, string $reason ): void {
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return;
			}
			if ( '' === (string) $order->get_meta( self::META_HOST_CONFIRMED_AT, true ) ) {
				return;
			}
			$order->delete_meta_data( self::META_HOST_CONFIRMED_AT );
			$order->delete_meta_data( self::META_HOST_SOURCE );
			$order->save();
			do_action( 'upr_order_delivery_invalidated', $order_id, $reason );
		} catch ( Throwable $e ) {
			self::log_error( 'invalidate: ' . $e->getMessage() );
		}
	}

	private static function log_error( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, array( 'source' => 'upr-host-adapter' ) );
			return;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[upr-host-adapter] ' . $message );
	}
}
