<?php
/**
 * Probar un envío.
 *
 * Lo que hace distinto a este botón es lo que muestra cuando falla: el error
 * SMTP completo y el diálogo entero con el servidor, plegado. «535
 * Authentication credentials invalid» dice qué arreglar; «algo salió mal» no
 * dice nada. La contraseña va tapada antes de mostrar nada.
 *
 * Lo usan el botón del admin y el comando de WP-CLI, que por eso comparten
 * la función de abajo.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manda un correo de prueba y cuenta qué pasó.
 *
 * @return array{ok: bool, error: string, transcript: string, seconds: float, to: string}
 */
function diluxone_mail_send_test( string $to ): array {
	$to = sanitize_email( $to );

	if ( '' === $to || ! is_email( $to ) ) {
		return array(
			'ok'         => false,
			'error'      => __( 'That is not a valid email address.', 'diluxone-mail' ),
			'transcript' => '',
			'seconds'    => 0.0,
			'to'         => $to,
		);
	}

	diluxone_mail_debug_buffer( null, true );
	diluxone_mail_debug_enabled( true );

	$error   = '';
	$capture = static function ( WP_Error $e ) use ( &$error ): void {
		$error = $e->get_error_message();
	};

	add_action( 'wp_mail_failed', $capture );

	$inicio = microtime( true );

	$ok = wp_mail(
		$to,
		sprintf(
			/* translators: %s: nombre del sitio */
			__( 'Test message from %s', 'diluxone-mail' ),
			wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES )
		),
		sprintf(
			/* translators: 1: host SMTP, 2: nombre del perfil */
			__( "If this arrived, the site can send mail through %1\$s (%2\$s).\n\nSent by DiluxOne Mail.", 'diluxone-mail' ),
			diluxone_mail_transport_active() ? diluxone_mail_config()['host'] : __( 'another plugin\'s transport', 'diluxone-mail' ),
			diluxone_mail_transport_active() ? (string) diluxone_mail_provider( diluxone_mail_config()['provider'] )['name'] : __( 'observer mode', 'diluxone-mail' )
		)
	);

	$segundos = microtime( true ) - $inicio;

	remove_action( 'wp_mail_failed', $capture );
	diluxone_mail_debug_enabled( false );

	if ( ! $ok && '' === $error ) {
		$error = __( 'wp_mail() returned false without saying why. Another plugin may have intercepted the message.', 'diluxone-mail' );
	}

	return array(
		'ok'         => (bool) $ok,
		'error'      => diluxone_mail_redact( $error ),
		'transcript' => diluxone_mail_redact( diluxone_mail_debug_buffer() ),
		'seconds'    => round( $segundos, 2 ),
		'to'         => $to,
	);
}

/**
 * El resultado de la última prueba de esta persona, una sola vez.
 *
 * Se guarda en un transient corto y se consume al mostrarlo: el diálogo
 * SMTP no tiene por qué quedarse en la base.
 *
 * @return array<string, mixed>|null
 */
function diluxone_mail_test_result_take(): ?array {
	$clave  = 'diluxone_mail_test_' . get_current_user_id();
	$result = get_transient( $clave );

	if ( ! is_array( $result ) ) {
		return null;
	}

	delete_transient( $clave );

	return $result;
}

/** El botón del admin. */
function diluxone_mail_test_action(): void {
	$scope = diluxone_mail_posted_scope();

	diluxone_mail_settings_authorize( $scope, 'diluxone_mail_test' );

	$to = sanitize_email( wp_unslash( $_POST['diluxone_mail_test_to'] ?? '' ) );

	if ( '' === $to ) {
		$to = (string) wp_get_current_user()->user_email;
	}

	set_transient( 'diluxone_mail_test_' . get_current_user_id(), diluxone_mail_send_test( $to ), 5 * MINUTE_IN_SECONDS );

	diluxone_mail_settings_redirect( $scope, 'tested' );
}
add_action( 'admin_post_diluxone_mail_test', 'diluxone_mail_test_action' );
