<?php
/**
 * La pantalla de la red.
 *
 * En una red el servidor de correo es de la red, así que los ajustes viven
 * en Ajustes de la red y los edita el superadministrador. Es el mismo
 * formulario que el de un sitio, guardando en otro lado, más una casilla
 * que decide si cada sitio puede pisar lo que la red fijó.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/** La entrada en el menú de la red. */
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

/** La pantalla. */
function diluxone_mail_screen_network(): void {
	diluxone_mail_screen_open( __( 'Network settings', 'diluxone-mail' ) );
	diluxone_mail_view( 'admin-settings', diluxone_mail_settings_data( 'network' ) );
	diluxone_mail_screen_close();
}
