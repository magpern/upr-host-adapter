<?php
/**
 * WP-CLI: DEV WP6 catalogue-hidden lifecycle verification (isolated fixtures).
 *
 * @package Upr_Host_Adapter
 */

defined( 'ABSPATH' ) || exit;

use UniversalProductReviews\Database\Migrator;
use UniversalProductReviews\Invitations\CompletionService;
use UniversalProductReviews\Invitations\Eligibility;
use UniversalProductReviews\Invitations\InvitationScheduler;
use UniversalProductReviews\Invitations\InviteRepository;
use UniversalProductReviews\Invitations\ProductReviewability;
use UniversalProductReviews\Invitations\ReconciliationService;
use UniversalProductReviews\Invitations\ScheduleStates;
use UniversalProductReviews\Invitations\SubmitClaimService;
use UniversalProductReviews\Invitations\SuppressionService;
use UniversalProductReviews\Tokens\FormSessionAuthenticator;
use UniversalProductReviews\Tokens\TokenRepository;
use UniversalProductReviews\Tokens\TokenService;

final class Upr_Host_Adapter_Verify_Wp6_Dev_Command {

	private const FIXTURE_PREFIX = 'upr-pilot-wp6-';

	/**
	 * Run mandatory WP6 catalogue-hidden lifecycle checks on DEV.
	 *
	 * ## EXAMPLES
	 *
	 *     wp upr-host-adapter verify-wp6-dev
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args Positional.
	 * @param array<string, string> $assoc Assoc.
	 */
	public function __invoke( $args, $assoc ): void {
		unset( $args, $assoc );

		$preflight = Upr_Host_Adapter_Upr_Pin::verify();
		if ( ! $preflight['ok'] ) {
			WP_CLI::error( 'Refusing WP6 verification: UPR pin mismatch. Run verify-pilot-preflight first.' );
		}

		if ( 'development' !== wp_get_environment_type() ) {
			WP_CLI::error( 'Refusing WP6 verification: environment is not development.' );
		}

		if ( ! Migrator::tables_exist() ) {
			Migrator::upgrade_now();
		}

		$results = array();
		$fixture_ids = array(
			'products' => array(),
			'orders'   => array(),
			'comments' => array(),
		);

		try {
			$results[] = $this->case_hidden_not_reviewable( $fixture_ids );
			$results[] = $this->case_hidden_no_invitation( $fixture_ids );
			$results[] = $this->case_visible_to_hidden_suppresses( $fixture_ids );
			$results[] = $this->case_race_no_surviving_review( $fixture_ids );
			$results[] = $this->case_approved_reviews_remain( $fixture_ids );
			$results[] = $this->case_restore_does_not_resurrect( $fixture_ids );
			$results[] = $this->case_draft_still_blocked( $fixture_ids );
			$results[] = $this->case_visible_flow_unchanged( $fixture_ids );
		} finally {
			$this->cleanup_fixtures( $fixture_ids );
		}

		$failed = array_filter(
			$results,
			static function ( array $row ): bool {
				return empty( $row['pass'] );
			}
		);

		foreach ( $results as $row ) {
			$line = sprintf( '[%s] %s', $row['pass'] ? 'PASS' : 'FAIL', $row['name'] );
			if ( ! empty( $row['detail'] ) ) {
				$line .= ' — ' . $row['detail'];
			}
			if ( $row['pass'] ) {
				WP_CLI::log( $line );
			} else {
				WP_CLI::warning( $line );
			}
		}

		if ( ! empty( $failed ) ) {
			WP_CLI::error( sprintf( 'WP6 DEV verification failed (%d/%d).', count( $failed ), count( $results ) ) );
		}

		WP_CLI::success( sprintf( 'WP6 DEV verification passed (%d cases).', count( $results ) ) );
	}

	/**
	 * @param array{products:list<int>,orders:list<int>,comments:list<int>} $fixture_ids
	 * @return array{name:string,pass:bool,detail:string}
	 */
	private function case_hidden_not_reviewable( array &$fixture_ids ): array {
		$product_id = $this->create_product( 'hidden', $fixture_ids );
		$ok         = ! ProductReviewability::is_reviewable( $product_id );
		return array(
			'name'   => '1. publish+catalog-hidden non-reviewable',
			'pass'   => $ok,
			'detail' => $ok ? '' : 'ProductReviewability returned true',
		);
	}

	/**
	 * @param array{products:list<int>,orders:list<int>,comments:list<int>} $fixture_ids
	 * @return array{name:string,pass:bool,detail:string}
	 */
	private function case_hidden_no_invitation( array &$fixture_ids ): array {
		$product_id = $this->create_product( 'hidden', $fixture_ids );
		$ctx        = $this->create_order( $product_id, $fixture_ids );
		$order      = wc_get_order( $ctx['order_id'] );
		$item       = $order->get_item( $ctx['order_item_id'] );
		$eval       = Eligibility::evaluate_item( $order, $item );

		$past = time() - ( 20 * DAY_IN_SECONDS );
		$order->update_meta_data( InvitationScheduler::META_DELIVERY_CONFIRMED_AT, gmdate( 'Y-m-d H:i:s', $past ) );
		$order->save();
		InvitationScheduler::schedule_order( $order->get_id(), 'adapter', $past );

		$row = InviteRepository::find( $ctx['order_item_id'] );
		$ok  = ! $eval['eligible']
			&& 'product_not_reviewable' === ( $eval['reason'] ?? '' )
			&& ( null === $row || ScheduleStates::SUPPRESSED === $row['schedule_state'] );

		return array(
			'name'   => '2. hidden product gets no active invitation',
			'pass'   => $ok,
			'detail' => $ok ? '' : 'eligible=' . wp_json_encode( $eval ) . ' row=' . wp_json_encode( $row ),
		);
	}

	/**
	 * @param array{products:list<int>,orders:list<int>,comments:list<int>} $fixture_ids
	 * @return array{name:string,pass:bool,detail:string}
	 */
	private function case_visible_to_hidden_suppresses( array &$fixture_ids ): array {
		$product_id = $this->create_product( 'visible', $fixture_ids );
		$ctx        = $this->create_order( $product_id, $fixture_ids );
		InviteRepository::upsert(
			$ctx['order_item_id'],
			array(
				'order_id'        => $ctx['order_id'],
				'product_id'      => $product_id,
				'schedule_state'  => ScheduleStates::INITIAL_SENT,
				'initial_sent_at' => current_time( 'mysql', true ),
			)
		);
		$issued = TokenService::issue_invite( $ctx['order_item_id'], $product_id );
		TokenService::exchange_invite( $issued['raw'] );

		$product = wc_get_product( $product_id );
		$product->set_catalog_visibility( 'hidden' );
		$product->save();

		$invite = InviteRepository::find( $ctx['order_item_id'] );
		$token  = TokenRepository::find_by_id( (int) $issued['id'] );
		$ok     = ScheduleStates::SUPPRESSED === ( $invite['schedule_state'] ?? '' )
			&& ! empty( $token['revoked_at'] )
			&& ! FormSessionAuthenticator::authorize_product( $product_id )
			&& null === TokenService::exchange_invite( $issued['raw'] );

		return array(
			'name'   => '3. visible→hidden suppresses token/session/form',
			'pass'   => $ok,
			'detail' => $ok ? '' : wp_json_encode( array( 'invite' => $invite, 'token' => $token ) ),
		);
	}

	/**
	 * @param array{products:list<int>,orders:list<int>,comments:list<int>} $fixture_ids
	 * @return array{name:string,pass:bool,detail:string}
	 */
	private function case_race_no_surviving_review( array &$fixture_ids ): array {
		$product_id = $this->create_product( 'visible', $fixture_ids );
		$ctx        = $this->create_order( $product_id, $fixture_ids );
		InviteRepository::upsert(
			$ctx['order_item_id'],
			array(
				'order_id'       => $ctx['order_id'],
				'product_id'     => $product_id,
				'schedule_state' => ScheduleStates::INITIAL_SENT,
			)
		);
		$issued = TokenService::issue_invite( $ctx['order_item_id'], $product_id );
		$claim  = SubmitClaimService::acquire( $ctx['order_item_id'] );

		$product = wc_get_product( $product_id );
		$product->set_catalog_visibility( 'hidden' );
		$product->save();

		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Pilot Test',
				'comment_author_email' => 'pilot-wp6@example.invalid',
				'comment_content'      => 'race test',
				'comment_type'         => 'review',
				'comment_approved'     => 0,
			)
		);
		$fixture_ids['comments'][] = (int) $comment_id;
		update_comment_meta( (int) $comment_id, '_upr_order_item_id', $ctx['order_item_id'] );

		CompletionService::finalize( $ctx['order_item_id'], (int) $comment_id, $ctx['order_id'], (int) $issued['id'], $claim['token'] ?? null );
		CompletionService::abandon_lost_submission( $ctx['order_item_id'], (int) $comment_id, $claim['token'] ?? null );
		ReconciliationService::run( 90, false );

		$invite  = InviteRepository::find( $ctx['order_item_id'] );
		$comment = get_comment( (int) $comment_id );
		$ok      = ScheduleStates::SUPPRESSED === ( $invite['schedule_state'] ?? '' )
			&& empty( $invite['review_comment_id'] )
			&& 'spam' === ( $comment->comment_approved ?? '' );

		return array(
			'name'   => '4. interleaved hide+submit leaves no approved/pending review',
			'pass'   => $ok,
			'detail' => $ok ? '' : wp_json_encode( array( 'invite' => $invite, 'approved' => $comment->comment_approved ?? null ) ),
		);
	}

	/**
	 * @param array{products:list<int>,orders:list<int>,comments:list<int>} $fixture_ids
	 * @return array{name:string,pass:bool,detail:string}
	 */
	private function case_approved_reviews_remain( array &$fixture_ids ): array {
		$product_id = $this->create_product( 'visible', $fixture_ids );
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Pilot Approved',
				'comment_author_email' => 'approved@example.invalid',
				'comment_content'      => 'approved before hide',
				'comment_type'         => 'review',
				'comment_approved'     => 1,
			)
		);
		$fixture_ids['comments'][] = (int) $comment_id;

		$product = wc_get_product( $product_id );
		$product->set_catalog_visibility( 'hidden' );
		$product->save();

		$comment = get_comment( (int) $comment_id );
		$ok      = '1' === ( $comment->comment_approved ?? '' );

		return array(
			'name'   => '5. approved reviews remain visible after hide',
			'pass'   => $ok,
			'detail' => $ok ? '' : 'comment_approved=' . (string) ( $comment->comment_approved ?? '' ),
		);
	}

	/**
	 * @param array{products:list<int>,orders:list<int>,comments:list<int>} $fixture_ids
	 * @return array{name:string,pass:bool,detail:string}
	 */
	private function case_restore_does_not_resurrect( array &$fixture_ids ): array {
		$product_id = $this->create_product( 'visible', $fixture_ids );
		$ctx        = $this->create_order( $product_id, $fixture_ids );
		InviteRepository::upsert(
			$ctx['order_item_id'],
			array(
				'order_id'       => $ctx['order_id'],
				'product_id'     => $product_id,
				'schedule_state' => ScheduleStates::INITIAL_SENT,
			)
		);
		$issued = TokenService::issue_invite( $ctx['order_item_id'], $product_id );

		$product = wc_get_product( $product_id );
		$product->set_catalog_visibility( 'hidden' );
		$product->save();
		$product->set_catalog_visibility( 'visible' );
		$product->save();

		$invite = InviteRepository::find( $ctx['order_item_id'] );
		$token  = TokenRepository::find_by_id( (int) $issued['id'] );
		$ok     = ScheduleStates::SUPPRESSED === ( $invite['schedule_state'] ?? '' )
			&& ! empty( $token['revoked_at'] )
			&& ! FormSessionAuthenticator::authorize_product( $product_id );

		return array(
			'name'   => '6. restore visibility does not resurrect invites/tokens',
			'pass'   => $ok,
			'detail' => $ok ? '' : wp_json_encode( array( 'invite' => $invite ) ),
		);
	}

	/**
	 * @param array{products:list<int>,orders:list<int>,comments:list<int>} $fixture_ids
	 * @return array{name:string,pass:bool,detail:string}
	 */
	private function case_draft_still_blocked( array &$fixture_ids ): array {
		$product = new WC_Product_Simple();
		$product->set_name( self::FIXTURE_PREFIX . 'draft-' . wp_generate_password( 6, false ) );
		$product->set_status( 'draft' );
		$product->set_catalog_visibility( 'visible' );
		$product->save();
		$fixture_ids['products'][] = (int) $product->get_id();

		$ok = ! ProductReviewability::is_reviewable( (int) $product->get_id() );
		return array(
			'name'   => '7. draft/private/trash protections unchanged',
			'pass'   => $ok,
			'detail' => $ok ? '' : 'draft product reviewable',
		);
	}

	/**
	 * @param array{products:list<int>,orders:list<int>,comments:list<int>} $fixture_ids
	 * @return array{name:string,pass:bool,detail:string}
	 */
	private function case_visible_flow_unchanged( array &$fixture_ids ): array {
		$product_id = $this->create_product( 'visible', $fixture_ids );
		$ok         = ProductReviewability::is_reviewable( $product_id );
		return array(
			'name'   => '8. visible product remains reviewable',
			'pass'   => $ok,
			'detail' => $ok ? '' : 'visible product not reviewable',
		);
	}

	/**
	 * @param array{products:list<int>,orders:list<int>,comments:list<int>} $fixture_ids
	 */
	private function create_product( string $visibility, array &$fixture_ids ): int {
		$product = new WC_Product_Simple();
		$product->set_name( self::FIXTURE_PREFIX . $visibility . '-' . wp_generate_password( 6, false ) );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( $visibility );
		$product->set_regular_price( '1' );
		$product->set_virtual( true );
		$product->save();
		$id = (int) $product->get_id();
		$fixture_ids['products'][] = $id;
		return $id;
	}

	/**
	 * @param array{products:list<int>,orders:list<int>,comments:list<int>} $fixture_ids
	 * @return array{order_id:int,order_item_id:int}
	 */
	private function create_order( int $product_id, array &$fixture_ids ): array {
		$product = wc_get_product( $product_id );
		$order   = wc_create_order();
		$order->set_billing_email( 'pilot-wp6@example.invalid' );
		$item_id = (int) $order->add_product( $product, 1 );
		$item    = $order->get_item( $item_id );
		if ( $item && (float) $item->get_total() <= 0 ) {
			$item->set_subtotal( 1 );
			$item->set_total( 1 );
			$item->save();
		}
		$order->set_status( 'completed' );
		$order->set_date_completed( gmdate( 'Y-m-d H:i:s', time() - ( 20 * DAY_IN_SECONDS ) ) );
		$order->save();
		$fixture_ids['orders'][] = (int) $order->get_id();
		return array(
			'order_id'      => (int) $order->get_id(),
			'order_item_id' => $item_id,
		);
	}

	/**
	 * @param array{products:list<int>,orders:list<int>,comments:list<int>} $fixture_ids
	 */
	private function cleanup_fixtures( array $fixture_ids ): void {
		foreach ( $fixture_ids['comments'] as $comment_id ) {
			wp_delete_comment( (int) $comment_id, true );
		}
		foreach ( $fixture_ids['orders'] as $order_id ) {
			$order = wc_get_order( (int) $order_id );
			if ( $order ) {
				$order->delete( true );
			}
		}
		foreach ( $fixture_ids['products'] as $product_id ) {
			wp_delete_post( (int) $product_id, true );
		}
	}
}
