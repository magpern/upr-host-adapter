<?php
/**
 * PDP review availability UX via named WooCommerce hooks.
 *
 * Branded messaging + form chrome only. Native submission enforcement and
 * native-PDP form eligibility live in UPR core (v0.2.2+).
 * Does not close comments_open and does not register preprocess_comment.
 *
 * @package Upr_Host_Adapter
 */

defined( 'ABSPATH' ) || exit;

final class Upr_Host_Adapter_Review_Availability_Ux {

	public static function register(): void {
		add_action( 'woocommerce_before_single_product_reviews', array( __CLASS__, 'render_unavailable_message' ), 5 );
		add_filter( 'woocommerce_product_review_comment_form_args', array( __CLASS__, 'filter_comment_form_args' ) );
	}

	/**
	 * Display-only: whether the native PDP review form may be rendered.
	 *
	 * Delegates entirely to UPR `NativePdpForm::should_render()`. Fail-closed when
	 * the required UPR API is unavailable. Does not authorize submission.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function can_submit_for_product( int $product_id ): bool {
		if ( $product_id <= 0 ) {
			return false;
		}

		if ( ! class_exists( \UniversalProductReviews\Submission\NativePdpForm::class ) ) {
			return false;
		}

		return \UniversalProductReviews\Submission\NativePdpForm::should_render( $product_id );
	}

	public static function render_unavailable_message(): void {
		if ( ! is_product() ) {
			return;
		}
		$product = wc_get_product( get_the_ID() );
		if ( ! $product ) {
			return;
		}

		$product_id = (int) $product->get_id();
		if ( self::can_submit_for_product( $product_id ) ) {
			return;
		}

		$user_id      = get_current_user_id();
		$availability = self::availability_for( $product_id, $user_id );

		$message = apply_filters(
			'upr_product_review_unavailable_message',
			null,
			$product_id,
			$user_id,
			$availability
		);

		if ( null === $message || '' === $message ) {
			$code = is_array( $availability ) ? (string) ( $availability['reason_code'] ?? '' ) : '';
			if ( 'guest_requires_invitation' === $code ) {
				$message = __( 'Product reviews are available by invitation after delivery.', 'upr-host-adapter' );
			} elseif ( 'not_verified_purchaser' === $code ) {
				$message = __( 'Only verified purchasers can leave a review for this product.', 'upr-host-adapter' );
			} elseif ( 'product_not_reviewable' === $code ) {
				$message = __( 'This product is no longer accepting new reviews.', 'upr-host-adapter' );
			} elseif ( 'reviews_disabled' === $code ) {
				$message = __( 'Reviews are currently disabled for this product.', 'upr-host-adapter' );
			} else {
				$message = __( 'New reviews are not being accepted for this product.', 'upr-host-adapter' );
			}
		}

		echo '<p class="upr-host-adapter-review-unavailable" role="status">' . esc_html( (string) $message ) . '</p>';
	}

	/**
	 * @param array<string, mixed> $args Form args.
	 * @return array<string, mixed>
	 */
	public static function filter_comment_form_args( array $args ): array {
		if ( ! is_product() ) {
			return $args;
		}
		$product_id = (int) get_the_ID();
		if ( ! self::can_submit_for_product( $product_id ) ) {
			$args['title_reply'] = '';
		}
		return $args;
	}

	/**
	 * @param int $product_id Product ID.
	 * @param int $user_id    User ID (0 = guest).
	 * @return array{can_submit?:bool,reason_code?:?string,context?:array}
	 */
	private static function availability_for( int $product_id, int $user_id ): array {
		$default = array(
			'can_submit'  => false,
			'reason_code' => null,
			'context'     => array(),
		);
		$result  = apply_filters( 'upr_product_review_availability', $default, $product_id, $user_id );
		return is_array( $result ) ? $result : $default;
	}
}
