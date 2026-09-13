<?php
/**
 * The network screen.
 *
 * On a network the mail server belongs to the network, so the settings live
 * under Network Settings and the super administrator edits them. It is the
 * same form as a site's, saving somewhere else, plus one checkbox deciding
 * whether each site may override what the network fixed.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/** The entry in the network menu. */
function diluxone_mail_network_menu(): void {
	add_submenu_page(
		'settings.php',
		diluxone_mail_plugin_name(),
		diluxone_mail_plugin_name(),
		'manage_network_options',
		'diluxone-mail-network',
		'diluxone_mail_screen_network'
	);
}
add_action( 'network_admin_menu', 'diluxone_mail_network_menu' );

/** The screen. */
function diluxone_mail_screen_network(): void {
	diluxone_mail_screen_open( __( 'Network settings', 'diluxone-mail' ) );
	diluxone_mail_view( 'admin-settings', diluxone_mail_settings_data( 'network' ) );
	diluxone_mail_screen_close();
}
