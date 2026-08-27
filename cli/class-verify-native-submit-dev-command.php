<?php
/**
 * WP-CLI: DEV native PDP boundary proof against UPR v0.2.2 (B2).
 *
 * Proves host owns UX/pin only; UPR owns native enforcement + display helper.
 *
 * @package Upr_Host_Adapter
 */

defined( 'ABSPATH' ) || exit;

final class Upr_Host_Adapter_Verify_Native_Submit_Dev_Command {

	/**
	 * Verify UPR pin, host non-ownership of security hooks, and UPR native/display APIs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp upr-host-adapter verify-native-submit-dev
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

		$pin = Upr_Host_Adapter_Upr_Pin::verify();
		if ( ! $pin['ok'] ) {
			WP_CLI::error( 'Refusing: UPR pin mismatch: ' . implode( '; ', $pin['errors'] ) );
		}

		$this->assert_host_does_not_own_security_hooks();

		$fixture_ids = array(
			'products' => array(),
			'users'    => array(),
			'orders'   => array(),
		);

		$prev_verification = get_option( 'woocommerce_review_rating_verification_required', 'no' );
		update_option( 'woocommerce_review_rating_verification_required', 'yes' );

		try {
			$product_id = $this->create_reviewable_product( $fixture_ids );
			$hidden_id  = $this->create_hidden_product( $fixture_ids );

			wp_set_current_user( 0 );

			$open_guest = comments_open( $product_id );
			WP_CLI::log( '[CHECK] guest comments_open on reviewable product → ' . ( $open_guest ? 'true' : 'false' ) );
			if ( ! $open_guest ) {
				WP_CLI::error( 'comments_open was closed for guests — host must not gate availability via comments_open.' );
			}

			$guest_can = Upr_Host_Adapter_Review_Availability_Ux::can_submit_for_product( $product_id );
			WP_CLI::log( '[CHECK] guest NativePdpForm via host thin wrapper → ' . ( $guest_can ? 'true' : 'false' ) );
			if ( $guest_can ) {
				WP_CLI::error( 'Guest must not get native PDP form (M2 path is /upr-review/form/ only).' );
			}

			$this->assert_preprocess_dies( $product_id, 0, 'guest native POST (UPR guards)' );

			$non_buyer = $this->create_customer( 'nonbuyer', $fixture_ids );
			wp_set_current_user( $non_buyer );
			$nb_can = Upr_Host_Adapter_Review_Availability_Ux::can_submit_for_product( $product_id );
			WP_CLI::log( '[CHECK] non-purchaser display helper → ' . ( $nb_can ? 'true' : 'false' ) );
			if ( $nb_can ) {
				WP_CLI::error( 'Non-purchaser must not get native form when verification is required.' );
			}
			if ( ! comments_open( $product_id ) ) {
				WP_CLI::error( 'comments_open closed for non-purchaser — display decoupling failed.' );
			}
			$this->assert_preprocess_dies( $product_id, $non_buyer, 'non-purchaser native POST (UPR NativeSubmissionGuard)' );

			$buyer = $this->create_customer( 'buyer', $fixture_ids );
			$this->grant_purchase( $buyer, $product_id, $fixture_ids );
			wp_set_current_user( $buyer );
			$buyer_can = Upr_Host_Adapter_Review_Availability_Ux::can_submit_for_product( $product_id );
			WP_CLI::log( '[CHECK] verified purchaser display helper on reviewable → ' . ( $buyer_can ? 'true' : 'false' ) );
			if ( ! $buyer_can ) {
				WP_CLI::error( 'Verified purchaser on reviewable product must get display helper true.' );
			}
			$this->assert_preprocess_allows( $product_id, $buyer, 'verified purchaser on reviewable (UPR allows)' );

			wp_set_current_user( $buyer );
			$hidden_can = Upr_Host_Adapter_Review_Availability_Ux::can_submit_for_product( $hidden_id );
			WP_CLI::log( '[CHECK] verified purchaser display helper on catalog-hidden → ' . ( $hidden_can ? 'true' : 'false' ) );
			if ( $hidden_can ) {
				WP_CLI::error( 'Catalog-hidden product must not allow native form for any identity.' );
			}
			if ( ! comments_open( $hidden_id ) ) {
				WP_CLI::error( 'comments_open closed on hidden product — approved list would be suppressed.' );
			}
			$this->assert_preprocess_dies( $hidden_id, $buyer, 'logged-in on catalog-hidden (UPR)' );
			wp_set_current_user( 0 );
			$this->assert_preprocess_dies( $hidden_id, 0, 'guest on catalog-hidden (UPR)' );

			WP_CLI::success(
				sprintf(
					'B2 native submit / display-helper DEV verification passed (UPR=%s @ %s, host=%s).',
					$pin['version'],
					$pin['commit'],
					UPR_HOST_ADAPTER_VERSION
				)
			);
		} finally {
			wp_set_current_user( 0 );
			update_option( 'woocommerce_review_rating_verification_required', $prev_verification );
			$this->cleanup( $fixture_ids );
		}
	}

	private function assert_host_does_not_own_security_hooks(): void {
		$comments_open = has_filter(
			'comments_open',
			array( 'Upr_Host_Adapter_Review_Availability_Ux', 'maybe_close_product_reviews' )
		);
		WP_CLI::log( '[CHECK] host comments_open availability filter → ' . ( false === $comments_open ? 'absent' : 'PRESENT' ) );
		if ( false !== $comments_open ) {
			WP_CLI::error( 'Host must not register comments_open availability gating.' );
		}

		$preprocess = has_filter(
			'preprocess_comment',
			array( 'Upr_Host_Adapter_Review_Availability_Ux', 'reject_unavailable_native_product_review' )
		);
		WP_CLI::log( '[CHECK] host preprocess_comment guard → ' . ( false === $preprocess ? 'absent' : 'PRESENT' ) );
		if ( false !== $preprocess ) {
			WP_CLI::error( 'Host must not register a competing preprocess_comment guard.' );
		}

		$upr_guest = has_filter(
			'preprocess_comment',
			array( \UniversalProductReviews\Submission\GuestSubmissionGuard::class, 'block_guest_product_reviews' )
		);
		$upr_native = has_filter(
			'preprocess_comment',
			array( \UniversalProductReviews\Submission\NativeSubmissionGuard::class, 'reject_unavailable_product_reviews' )
		);
		WP_CLI::log( '[CHECK] UPR GuestSubmissionGuard → ' . ( false !== $upr_guest ? 'registered' : 'MISSING' ) );
		WP_CLI::log( '[CHECK] UPR NativeSubmissionGuard → ' . ( false !== $upr_native ? 'registered' : 'MISSING' ) );
		if ( false === $upr_guest || false === $upr_native ) {
			WP_CLI::error( 'Required UPR preprocess_comment guards are not registered.' );
		}

		if ( ! class_exists( \UniversalProductReviews\Submission\NativePdpForm::class ) ) {
			WP_CLI::error( 'NativePdpForm missing — cannot delegate display helper.' );
		}
		WP_CLI::log( '[CHECK] display helper delegated to UniversalProductReviews\\Submission\\NativePdpForm' );
	}

	/**
	 * @param array{products:list<int>,users:list<int>} $fixture_ids
	 */
	private function create_reviewable_product( array &$fixture_ids ): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'upr-b2-native-' . wp_generate_password( 6, false ) );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_reviews_allowed( true );
		$product->set_regular_price( '10' );
		$id = (int) $product->save();
		$fixture_ids['products'][] = $id;
		return $id;
	}

	/**
	 * @param array{products:list<int>,users:list<int>} $fixture_ids
	 */
	private function create_hidden_product( array &$fixture_ids ): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'upr-b2-hidden-' . wp_generate_password( 6, false ) );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_reviews_allowed( true );
		$product->set_regular_price( '10' );
		$id = (int) $product->save();
		$fixture_ids['products'][] = $id;
		return $id;
	}

	/**
	 * @param array{products:list<int>,users:list<int>} $fixture_ids
	 */
	private function create_customer( string $suffix, array &$fixture_ids ): int {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'upr-b2-' . $suffix . '-' . wp_generate_password( 6, false ),
				'user_email' => 'upr-b2-' . $suffix . '-' . wp_generate_password( 4, false ) . '@example.invalid',
				'user_pass'  => wp_generate_password( 16, true, true ),
				'role'       => 'customer',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			WP_CLI::error( $user_id->get_error_message() );
		}
		$fixture_ids['users'][] = (int) $user_id;
		return (int) $user_id;
	}

	/**
	 * @param array{products:list<int>,users:list<int>,orders:list<int>} $fixture_ids
	 */
	private function grant_purchase( int $user_id, int $product_id, array &$fixture_ids ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			WP_CLI::error( 'Missing buyer user.' );
		}
		$order = wc_create_order(
			array(
				'customer_id' => $user_id,
				'status'      => 'completed',
			)
		);
		$order->set_billing_email( $user->user_email );
		$order->add_product( wc_get_product( $product_id ), 1 );
		$order->calculate_totals();
		$order->save();
		$fixture_ids['orders'][] = (int) $order->get_id();
	}

	private function assert_preprocess_dies( int $product_id, int $user_id, string $label ): void {
		wp_set_current_user( $user_id );
		$died = false;
		$code = 0;
		$handler = static function ( $message, $title = '', $args = array() ) use ( &$died, &$code ) {
			unset( $message, $title );
			$died = true;
			$code = (int) ( $args['response'] ?? 0 );
			throw new RuntimeException( 'upr_host_adapter_expected_wp_die' );
		};
		$filter = static function () use ( $handler ) {
			return $handler;
		};
		add_filter( 'wp_die_handler', $filter );
		try {
			apply_filters(
				'preprocess_comment',
				array(
					'comment_post_ID'      => $product_id,
					'comment_author'       => 'B2 Fixture',
					'comment_author_email' => 'b2-fixture@example.invalid',
					'comment_content'      => 'native submit probe',
					'comment_type'         => 'review',
					'user_ID'              => $user_id,
				)
			);
		} catch ( RuntimeException $e ) {
			if ( 'upr_host_adapter_expected_wp_die' !== $e->getMessage() ) {
				remove_filter( 'wp_die_handler', $filter );
				throw $e;
			}
		}
		remove_filter( 'wp_die_handler', $filter );

		WP_CLI::log( sprintf( '[CHECK] %s → died=%s response=%d', $label, $died ? 'yes' : 'no', $code ) );
		if ( ! $died || 403 !== $code ) {
			WP_CLI::error( $label . ' did not wp_die(403).' );
		}
	}

	private function assert_preprocess_allows( int $product_id, int $user_id, string $label ): void {
		wp_set_current_user( $user_id );
		$died = false;
		$filter = static function () use ( &$died ) {
			return static function () use ( &$died ) {
				$died = true;
				throw new RuntimeException( 'upr_host_adapter_unexpected_wp_die' );
			};
		};
		add_filter( 'wp_die_handler', $filter );
		$result = null;
		try {
			$result = apply_filters(
				'preprocess_comment',
				array(
					'comment_post_ID'      => $product_id,
					'comment_author'       => 'B2 Buyer',
					'comment_author_email' => 'b2-buyer@example.invalid',
					'comment_content'      => 'allowed native submit probe',
					'comment_type'         => 'review',
					'user_ID'              => $user_id,
				)
			);
		} catch ( RuntimeException $e ) {
			remove_filter( 'wp_die_handler', $filter );
			WP_CLI::error( $label . ' unexpectedly died: ' . $e->getMessage() );
		}
		remove_filter( 'wp_die_handler', $filter );
		WP_CLI::log( sprintf( '[CHECK] %s → allowed=%s', $label, is_array( $result ) && ! $died ? 'yes' : 'no' ) );
		if ( $died || ! is_array( $result ) ) {
			WP_CLI::error( $label . ' should allow preprocess_comment.' );
		}
	}

	/**
	 * @param array{products:list<int>,users:list<int>,orders:list<int>} $fixture_ids
	 */
	private function cleanup( array $fixture_ids ): void {
		foreach ( $fixture_ids['orders'] as $id ) {
			$order = wc_get_order( (int) $id );
			if ( $order ) {
				$order->delete( true );
			}
		}
		foreach ( $fixture_ids['products'] as $id ) {
			wp_delete_post( (int) $id, true );
		}
		foreach ( $fixture_ids['users'] as $id ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( (int) $id );
		}
	}
}
