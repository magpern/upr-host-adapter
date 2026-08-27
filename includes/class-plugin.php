<?php
/**
 * Plugin bootstrap.
 *
 * @package Upr_Host_Adapter
 */

defined( 'ABSPATH' ) || exit;

final class Upr_Host_Adapter_Plugin {

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init(): void {
		Upr_Host_Adapter_Delivery_Adapter::register();
		Upr_Host_Adapter_Support_Adapter::register();
		Upr_Host_Adapter_Invitation_Send_Policy::register();
		Upr_Host_Adapter_Review_Availability_Ux::register();
		if ( is_admin() ) {
			Upr_Host_Adapter_Admin_Settings::register();
		}
	}
}
