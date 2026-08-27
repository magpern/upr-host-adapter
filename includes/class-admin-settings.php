<?php
/**
 * Admin settings (manage_woocommerce).
 *
 * @package Upr_Host_Adapter
 */

defined( 'ABSPATH' ) || exit;

final class Upr_Host_Adapter_Admin_Settings {

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'UPR Host', 'upr-host-adapter' ),
			__( 'UPR Host', 'upr-host-adapter' ),
			'manage_woocommerce',
			'upr-host-adapter',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			'upr_host_adapter',
			Upr_Host_Adapter_Options::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => Upr_Host_Adapter_Options::defaults(),
			)
		);
	}

	/**
	 * @param mixed $input Raw POST.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$defaults = Upr_Host_Adapter_Options::defaults();
		if ( ! is_array( $input ) ) {
			return $defaults;
		}

		$out                          = $defaults;
		$out['confirm_on_shipped']    = ! empty( $input['confirm_on_shipped'] );
		$out['support_delay_days']    = max( 1, (int) ( $input['support_delay_days'] ?? 14 ) );
		$out['enable_pdp_summary']    = ! empty( $input['enable_pdp_summary'] );
		$out['enable_card_ratings']   = ! empty( $input['enable_card_ratings'] );
		$out['card_ratings_min_count'] = max( 1, (int) ( $input['card_ratings_min_count'] ?? 3 ) );
		// Allowlist changes must never auto-enable pilot authorisation.
		$out['pilot_invitation_sending_authorised'] = ! empty( $input['pilot_invitation_sending_authorised'] );
		$out['pilot_order_id_allowlist']            = self::sanitize_order_id_list( $input['pilot_order_id_allowlist'] ?? array() );

		foreach ( array( 'support_delay_tags', 'support_suppress_tags', 'support_open_statuses' ) as $list_key ) {
			$raw = $input[ $list_key ] ?? $defaults[ $list_key ];
			if ( is_string( $raw ) ) {
				$raw = preg_split( '/[\s,]+/', $raw ) ?: array();
			}
			$clean = array();
			if ( is_array( $raw ) ) {
				foreach ( $raw as $item ) {
					$item = sanitize_key( (string) $item );
					if ( '' !== $item ) {
						$clean[] = $item;
					}
				}
			}
			$out[ $list_key ] = array_values( array_unique( $clean ) );
		}

		return $out;
	}

	/**
	 * @param mixed $raw Raw allowlist.
	 * @return list<int>
	 */
	private static function sanitize_order_id_list( $raw ): array {
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

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$settings = Upr_Host_Adapter_Options::all();
		$contract = class_exists( 'Upr_Host_Adapter_Invitation_Send_Policy' )
			? Upr_Host_Adapter_Invitation_Send_Policy::contract_available()
			: false;
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'UPR Host Adapter', 'upr-host-adapter' ); ?></h1>
			<?php if ( ! $contract ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						echo esc_html__(
							'UPR invitation send-authorisation contract is unavailable. Pilot send policy is fail-closed until Universal Product Reviews exposes InvitationAuthorisation (v0.3.0+). Master enable and emergency pause remain UPR-core settings.',
							'upr-host-adapter'
						);
						?>
					</p>
				</div>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'upr_host_adapter' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Confirm on shipped', 'upr-host-adapter' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Upr_Host_Adapter_Options::OPTION_KEY ); ?>[confirm_on_shipped]" value="1" <?php checked( ! empty( $settings['confirm_on_shipped'] ) ); ?> />
								<?php echo esc_html__( 'Treat MPCF shipped as delivery confirmation (shipped_fallback)', 'upr-host-adapter' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Support delay days', 'upr-host-adapter' ); ?></th>
						<td>
							<input type="number" min="1" name="<?php echo esc_attr( Upr_Host_Adapter_Options::OPTION_KEY ); ?>[support_delay_days]" value="<?php echo esc_attr( (string) $settings['support_delay_days'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Delay tags', 'upr-host-adapter' ); ?></th>
						<td>
							<input type="text" class="large-text" name="<?php echo esc_attr( Upr_Host_Adapter_Options::OPTION_KEY ); ?>[support_delay_tags]" value="<?php echo esc_attr( implode( ', ', (array) $settings['support_delay_tags'] ) ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Suppress tags', 'upr-host-adapter' ); ?></th>
						<td>
							<input type="text" class="large-text" name="<?php echo esc_attr( Upr_Host_Adapter_Options::OPTION_KEY ); ?>[support_suppress_tags]" value="<?php echo esc_attr( implode( ', ', (array) $settings['support_suppress_tags'] ) ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'PDP rating summary', 'upr-host-adapter' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Upr_Host_Adapter_Options::OPTION_KEY ); ?>[enable_pdp_summary]" value="1" <?php checked( ! empty( $settings['enable_pdp_summary'] ) ); ?> />
								<?php echo esc_html__( 'Enable PDP rating summary flag', 'upr-host-adapter' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Card ratings', 'upr-host-adapter' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Upr_Host_Adapter_Options::OPTION_KEY ); ?>[enable_card_ratings]" value="1" <?php checked( ! empty( $settings['enable_card_ratings'] ) ); ?> />
								<?php echo esc_html__( 'Enable product-card ratings (requires minimum approved reviews)', 'upr-host-adapter' ); ?>
							</label>
							<p>
								<label>
									<?php echo esc_html__( 'Minimum approved reviews', 'upr-host-adapter' ); ?>
									<input type="number" min="1" name="<?php echo esc_attr( Upr_Host_Adapter_Options::OPTION_KEY ); ?>[card_ratings_min_count]" value="<?php echo esc_attr( (string) $settings['card_ratings_min_count'] ); ?>" />
								</label>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Pilot invitation sending authorised', 'upr-host-adapter' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Upr_Host_Adapter_Options::OPTION_KEY ); ?>[pilot_invitation_sending_authorised]" value="1" <?php checked( ! empty( $settings['pilot_invitation_sending_authorised'] ) ); ?> />
								<?php echo esc_html__( 'Authorise pilot invitation sending for allowlisted order IDs only', 'upr-host-adapter' ); ?>
							</label>
							<p class="description">
								<?php echo esc_html__( 'Temporary limited-rollout control. Does not replace UPR “Enable review invitation emails” or “Emergency pause invitations”. Changing the allowlist does not turn this on.', 'upr-host-adapter' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Pilot order-ID allowlist', 'upr-host-adapter' ); ?></th>
						<td>
							<input type="text" class="large-text" name="<?php echo esc_attr( Upr_Host_Adapter_Options::OPTION_KEY ); ?>[pilot_order_id_allowlist]" value="<?php echo esc_attr( implode( ', ', array_map( 'strval', (array) ( $settings['pilot_order_id_allowlist'] ?? array() ) ) ) ); ?>" />
							<p class="description">
								<?php echo esc_html__( 'Comma-separated WooCommerce order IDs. Empty deny. Order IDs only — do not store email addresses here.', 'upr-host-adapter' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
