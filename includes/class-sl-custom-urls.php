<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the "Custom URLs" submenu page under the SemanticLinker
 * top-level menu. Allows users to add external/custom URLs for linking.
 */
class SL_Custom_Urls {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
	}

	/**
	 * Register submenu page.
	 */
	public function add_page(): void {
		add_submenu_page(
			'semanticlinker',
			'Custom URLs – SemanticLinker AI',
			'Custom URLs',
			'manage_options',
			'semanticlinker-custom-urls',
			[ $this, 'render' ]
		);
	}

	/**
	 * Render the Custom URLs admin page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Brak uprawnień.' );
		}
		require_once SL_PLUGIN_DIR . 'templates/custom-urls.php';
	}
}
