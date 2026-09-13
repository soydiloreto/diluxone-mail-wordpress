<?php
/**
 * El historial en la ficha de cada persona.
 *
 * Es la razón de ser del plugin. Todos los competidores muestran una lista
 * global de envíos; acá, abrir un usuario en el escritorio muestra qué se le
 * mandó a él: fecha, asunto, estado y un botón de reenviar. Es lo primero
 * que tiene que ver alguien de soporte cuando le dicen «no me llegó el
 * mail».
 *
 * Se busca por dirección, no por id: por la de ahora y por las anteriores si
 * el sitio las conserva —un plugin que guarde el historial de cambios de
 * correo las suma por el filtro `diluxone_mail_user_emails`—. Y en una red se
 * muestra lo de todos los sitios, porque la persona es de la red.
 *
 * Lo ve quien puede editar a esa persona. No es un permiso aparte: si podés
 * cambiarle la contraseña, podés ver qué correo se le mandó.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Las direcciones por las que se busca el historial de una persona.
 *
 * @return array<int, string>
 */
function diluxone_mail_user_emails( WP_User $user ): array {
	$emails = array( strtolower( (string) $user->user_email ) );

	/**
	 * Filtra las direcciones por las que se busca el historial de una persona.
	 *
	 * Un plugin que conserve las direcciones anteriores las agrega acá.
	 *
	 * @param array<int, string> $emails
	 * @param WP_User            $user
	 */
	$emails = (array) apply_filters( 'diluxone_mail_user_emails', $emails, $user );

	return array_values( array_unique( array_filter( array_map( 'strtolower', array_map( 'strval', $emails ) ) ) ) );
}

/** La sección en la ficha. */
function diluxone_mail_user_profile_section( WP_User $user ): void {
	if ( ! current_user_can( 'edit_user', $user->ID ) ) {
		return;
	}

	$emails   = diluxone_mail_user_emails( $user );
	$consulta = diluxone_mail_log_query(
		array(
			'emails'   => $emails,
			'site_id'  => null,
			'per_page' => 25,
		)
	);

	diluxone_mail_view(
		'user-profile',
		array(
			'user'        => $user,
			'emails'      => $emails,
			'rows'        => $consulta['rows'],
			'total'       => $consulta['total'],
			'statuses'    => diluxone_mail_log_statuses(),
			'log_enabled' => (bool) diluxone_mail_option( 'diluxone_mail_log_enabled' ),
			'log_body'    => (bool) diluxone_mail_option( 'diluxone_mail_log_body' ),
			'multisite'   => is_multisite(),
			'log_url'     => current_user_can( 'manage_options' ) ? diluxone_mail_admin_url( 'diluxone-mail-log', array( 's' => $user->user_email ) ) : '',
		)
	);
}
add_action( 'show_user_profile', 'diluxone_mail_user_profile_section', 100 );
add_action( 'edit_user_profile', 'diluxone_mail_user_profile_section', 100 );

/**
 * Reenvía un mensaje del historial a uno de sus destinatarios.
 *
 * Hace falta el cuerpo, y el cuerpo sólo está si el sitio prendió guardarlo.
 * Sin cuerpo no hay nada que mandar, y se dice con esas palabras en vez de
 * mandar un correo vacío.
 *
 * Los adjuntos no se reenvían: son rutas de archivos temporales que ya no
 * existen. Se anota en el mensaje que faltan.
 *
 * @return array{ok: bool, reason: string}
 */
function diluxone_mail_resend( int $log_id ): array {
	$fila = diluxone_mail_log_get( $log_id );

	if ( null === $fila ) {
		return array(
			'ok'     => false,
			'reason' => 'missing',
		);
	}

	$detalle = diluxone_mail_detail_get( (string) $fila['message_id'] );

	if ( null === $detalle || '' === $detalle['body'] ) {
		return array(
			'ok'     => false,
			'reason' => 'no-body',
		);
	}

	$headers = array( 'Content-Type: ' . $detalle['body_type'] . '; charset=UTF-8' );

	if ( '' !== (string) $fila['from_email'] && is_email( (string) $fila['from_email'] ) ) {
		$headers[] = 'From: ' . (string) $fila['from_email'];
	}

	$headers[] = 'X-DiluxOne-Mail-Resend-Of: ' . (string) $fila['message_id'];

	$ok = wp_mail( (string) $fila['email'], (string) $fila['subject'], $detalle['body'], $headers );

	return array(
		'ok'     => (bool) $ok,
		'reason' => $ok ? '' : 'failed',
	);
}

/** El botón de reenviar. */
function diluxone_mail_resend_action(): void {
	$id   = absint( $_GET['id'] ?? 0 );
	$fila = diluxone_mail_log_get( $id );

	check_admin_referer( 'diluxone_mail_resend_' . $id );

	// Quien administra puede reenviar cualquiera. Quien puede editar a una
	// persona puede reenviarle lo suyo: lo mismo que ve en la ficha.
	$permitido = current_user_can( 'manage_options' );

	if ( ! $permitido && null !== $fila ) {
		$user      = get_user_by( 'email', (string) $fila['email'] );
		$permitido = $user instanceof WP_User && current_user_can( 'edit_user', $user->ID );
	}

	if ( ! $permitido ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'diluxone-mail' ) );
	}

	$resultado = diluxone_mail_resend( $id );
	$done      = $resultado['ok'] ? 'resent' : ( 'no-body' === $resultado['reason'] ? 'no-body' : 'resend-failed' );
	$back      = wp_get_referer();

	wp_safe_redirect( add_query_arg( 'diluxone_mail_done', $done, $back ? $back : diluxone_mail_admin_url( 'diluxone-mail-log' ) ) );
	exit;
}
add_action( 'admin_post_diluxone_mail_resend', 'diluxone_mail_resend_action' );

/** La URL del botón de reenviar de una fila. */
function diluxone_mail_resend_url( int $log_id ): string {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'diluxone_mail_resend',
				'id'     => $log_id,
			),
			admin_url( 'admin-post.php' )
		),
		'diluxone_mail_resend_' . $log_id
	);
}

/**
 * El aviso de reenvío en la ficha de la persona.
 *
 * Las pantallas del plugin lo muestran con diluxone_mail_screen_open(); la
 * ficha de usuario es de WordPress y hay que engancharse a sus avisos.
 */
function diluxone_mail_profile_notices(): void {
	$screen = get_current_screen();

	if ( ! $screen instanceof WP_Screen || ! in_array( $screen->id, array( 'profile', 'user-edit' ), true ) ) {
		return;
	}

	diluxone_mail_done_notice();
}
add_action( 'admin_notices', 'diluxone_mail_profile_notices' );
