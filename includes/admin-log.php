<?php
/**
 * La pantalla del historial global y el detalle de un mensaje.
 *
 * Es la lista de siempre, la que tienen todos: por fecha, con filtro por
 * estado y búsqueda. No es el diferencial —ése está en la ficha de cada
 * persona— y por eso es una WP_List_Table sin más vueltas.
 *
 * En una red, quien administra la red puede ver lo de todos los sitios
 * desde acá con «todos los sitios»; los demás ven lo del suyo.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/** La pantalla: la lista, o el detalle de una fila. */
function diluxone_mail_screen_log(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sólo elige qué fila mostrar; el permiso lo da el menú.
	$id = isset( $_GET['view'] ) ? absint( $_GET['view'] ) : 0;

	if ( $id > 0 ) {
		diluxone_mail_screen_log_detail( $id );
		return;
	}

	diluxone_mail_screen_open( __( 'Mail log', 'diluxone-mail' ) );

	require_once DILUXONE_MAIL_DIR . 'includes/log-list-table.php';

	$tabla = new DiluxOne_Mail_Log_Table();
	$tabla->prepare_items();

	diluxone_mail_view(
		'admin-log',
		array(
			'table'       => $tabla,
			'statuses'    => diluxone_mail_log_statuses(),
			'log_enabled' => (bool) diluxone_mail_option( 'diluxone_mail_log_enabled' ),
			'all_sites'   => is_multisite() && is_super_admin(),
		)
	);

	diluxone_mail_screen_close();
}

/** El detalle de una fila: todos los destinatarios del mensaje y su cuerpo. */
function diluxone_mail_screen_log_detail( int $id ): void {
	$fila = diluxone_mail_log_get( $id );

	diluxone_mail_screen_open( __( 'Message', 'diluxone-mail' ) );

	if ( null === $fila ) {
		diluxone_mail_notice( __( 'That message is no longer in the log.', 'diluxone-mail' ), 'warning' );
		diluxone_mail_screen_close();
		return;
	}

	// Una fila de otro sitio no se ve desde éste, salvo que administres la red.
	if ( is_multisite() && ! is_super_admin() && (int) $fila['site_id'] !== get_current_blog_id() ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'diluxone-mail' ) );
	}

	$headers     = json_decode( (string) $fila['headers'], true );
	$attachments = json_decode( (string) $fila['attachments'], true );

	diluxone_mail_view(
		'admin-log-detail',
		array(
			'row'         => $fila,
			'recipients'  => diluxone_mail_log_recipients_of( (string) $fila['message_id'] ),
			'detail'      => diluxone_mail_detail_get( (string) $fila['message_id'] ),
			'headers'     => is_array( $headers ) ? array_map( 'strval', $headers ) : array(),
			'attachments' => is_array( $attachments ) ? array_map( 'strval', $attachments ) : array(),
			'statuses'    => diluxone_mail_log_statuses(),
			'resend_url'  => diluxone_mail_resend_url( $id ),
			'back_url'    => diluxone_mail_admin_url( 'diluxone-mail-log' ),
		)
	);

	diluxone_mail_screen_close();
}
