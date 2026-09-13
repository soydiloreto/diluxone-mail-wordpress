<?php
/**
 * Cómo entra cada envío al historial.
 *
 * La función wp_mail() pasa por cuatro puntos, y acá hay un gancho en cada uno:
 *
 *   1. El filtro `wp_mail`, con los argumentos ya armados. Es donde se anota
 *      el envío —todavía sin resultado— y donde entra el filtro propio
 *      `diluxone_mail_atts`, que es el punto por el que el día de mañana va
 *      a pasar la plantilla de marca. Se anota ANTES de mandar, a propósito:
 *      si PHP muere en el medio, la fila queda como «enviando», que es la
 *      verdad. Anotarla después es perder justamente los envíos que fallaron
 *      peor.
 *   2. `pre_wp_mail`, dos veces. Al principio, para preguntarle al filtro
 *      `diluxone_mail_should_send` si alguien quiere cancelar este envío —el
 *      centro de preferencias y la lista de supresión del futuro—. Y al
 *      final de todo, para mirar si otro plugin ya cortó el envío ahí: si el
 *      valor acumulado no es null, alguien contestó, WordPress va a hacer
 *      return sin mandar, y ninguno de los dos hooks de abajo va a sonar.
 *   3. `wp_mail_succeeded`, que confirma que PHPMailer entregó al servidor.
 *   4. `wp_mail_failed`, con el error.
 *
 * El id que ata las filas del historial con el detalle y con el correo real
 * es un UUID propio, y se escribe además como Message-ID del mensaje. Eso es
 * lo que va a permitir casar un rebote que llegue por webhook con la fila que
 * corresponde: el proveedor devuelve el Message-ID, y el Message-ID es
 * nuestro.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * El envío que está en curso en esta petición.
 *
 * La función wp_mail() no es reentrante en la práctica, así que alcanza con un lugar.
 *
 * @param array<string, mixed>|null $set   Guardar esto.
 * @param bool                      $clear Vaciar.
 * @return array<string, mixed>|null
 */
function diluxone_mail_current( ?array $set = null, bool $clear = false ): ?array {
	static $actual = null;

	if ( $clear ) {
		$actual = null;
	}

	if ( null !== $set ) {
		$actual = $set;
	}

	return $actual;
}

/**
 * Las direcciones que hay en un campo de destinatarios.
 *
 * La función wp_mail() acepta una cadena separada por comas, una lista, y en las
 * cabeceras el formato «Nombre <dirección>». Se sacan las direcciones, en
 * minúsculas y sin repetidas, que es la forma en la que están indexadas.
 *
 * @param string|array<int, string> $raw
 * @return array<int, string>
 */
function diluxone_mail_parse_addresses( $raw ): array {
	$partes = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
	$salida = array();

	foreach ( $partes as $parte ) {
		$parte = trim( (string) $parte );

		if ( '' === $parte ) {
			continue;
		}

		if ( preg_match( '/<([^>]+)>/', $parte, $m ) ) {
			$parte = $m[1];
		}

		$parte = strtolower( trim( $parte ) );

		if ( is_email( $parte ) ) {
			$salida[ $parte ] = $parte;
		}
	}

	return array_values( $salida );
}

/**
 * Los destinatarios de un envío, con su tipo.
 *
 * @param array<string, mixed> $atts
 * @return array<int, array{email: string, kind: string}>
 */
function diluxone_mail_recipients( array $atts ): array {
	$salida = array();

	foreach ( diluxone_mail_parse_addresses( $atts['to'] ?? '' ) as $email ) {
		$salida[] = array(
			'email' => $email,
			'kind'  => 'to',
		);
	}

	foreach ( diluxone_mail_header_lines( $atts['headers'] ?? '' ) as $linea ) {
		if ( ! preg_match( '/^(cc|bcc):\s*(.+)$/i', $linea, $m ) ) {
			continue;
		}

		foreach ( diluxone_mail_parse_addresses( $m[2] ) as $email ) {
			$salida[] = array(
				'email' => $email,
				'kind'  => strtolower( $m[1] ),
			);
		}
	}

	return $salida;
}

/**
 * Las cabeceras como lista de líneas, vengan como vengan.
 *
 * @param string|array<int|string, string> $headers
 * @return array<int, string>
 */
function diluxone_mail_header_lines( $headers ): array {
	if ( is_array( $headers ) ) {
		$lineas = array();

		foreach ( $headers as $clave => $valor ) {
			$lineas[] = is_string( $clave ) ? $clave . ': ' . $valor : (string) $valor;
		}

		return $lineas;
	}

	$lineas = preg_split( '/\r\n|\r|\n/', (string) $headers );

	return array_values( array_filter( array_map( 'trim', false === $lineas ? array() : $lineas ) ) );
}

/**
 * El remitente y el tipo de contenido que declaran las cabeceras, si alguno.
 *
 * @param array<string, mixed> $atts
 * @return array{from: string, type: string}
 */
function diluxone_mail_header_meta( array $atts ): array {
	$from = '';
	$type = 'text/plain';

	foreach ( diluxone_mail_header_lines( $atts['headers'] ?? '' ) as $linea ) {
		if ( preg_match( '/^from:\s*(.+)$/i', $linea, $m ) ) {
			$from = diluxone_mail_parse_addresses( $m[1] )[0] ?? '';
		} elseif ( preg_match( '/^content-type:\s*([^;]+)/i', $linea, $m ) ) {
			$type = strtolower( trim( $m[1] ) );
		}
	}

	return array(
		'from' => $from,
		'type' => $type,
	);
}

/**
 * Qué plugin o tema originó el envío.
 *
 * Se mira la pila de llamadas y se toma el primer archivo que no sea de este
 * plugin ni del núcleo de WordPress. Sale la carpeta del plugin o el nombre
 * del tema, que es lo que hace falta para decir «esto lo mandó WooCommerce».
 */
function diluxone_mail_caller(): string {
	$plugins = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
	$temas   = trailingslashit( wp_normalize_path( get_theme_root() ) );
	$propio  = wp_normalize_path( DILUXONE_MAIL_DIR );

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- No es depuración: es la forma de saber quién llamó a wp_mail(), y se limita a 40 marcos sin argumentos para que no cueste.
	foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 40 ) as $marco ) {
		$archivo = wp_normalize_path( (string) ( $marco['file'] ?? '' ) );

		if ( '' === $archivo || 0 === strpos( $archivo, $propio ) ) {
			continue;
		}

		if ( 0 === strpos( $archivo, $plugins ) ) {
			return 'plugin:' . (string) strtok( substr( $archivo, strlen( $plugins ) ), '/' );
		}

		if ( 0 === strpos( $archivo, $temas ) ) {
			return 'theme:' . (string) strtok( substr( $archivo, strlen( $temas ) ), '/' );
		}
	}

	return 'core';
}

/**
 * El envío entra al historial.
 *
 * Es también el punto de extensión para transformar el mensaje: lo primero
 * que se hace es pasar los argumentos por `diluxone_mail_atts`, y lo que
 * salga de ahí es lo que se manda y lo que se registra.
 *
 * @param array<string, mixed> $atts
 * @return array<string, mixed>
 */
function diluxone_mail_capture( array $atts ): array {
	/**
	 * Filtra los argumentos de wp_mail() antes de mandarlos y de anotarlos.
	 *
	 * Es el lugar para transformar el cuerpo —una plantilla de marca, por
	 * ejemplo— sin tocar a quien llamó a wp_mail().
	 *
	 * @param array<string, mixed> $atts to, subject, message, headers, attachments.
	 */
	$atts = (array) apply_filters( 'diluxone_mail_atts', $atts );

	diluxone_mail_current( null, true );

	if ( ! (bool) diluxone_mail_option( 'diluxone_mail_log_enabled' ) ) {
		return $atts;
	}

	$uuid      = wp_generate_uuid4();
	$meta      = diluxone_mail_header_meta( $atts );
	$cfg       = diluxone_mail_config();
	$from      = '' !== $meta['from'] ? $meta['from'] : $cfg['from'];
	$extendido = (bool) diluxone_mail_option( 'diluxone_mail_log_extended' );

	$adjuntos = array_map( 'basename', array_map( 'strval', (array) ( $atts['attachments'] ?? array() ) ) );
	$filas    = array();

	foreach ( diluxone_mail_recipients( $atts ) as $destinatario ) {
		$filas[] = array(
			'email'       => $destinatario['email'],
			'kind'        => $destinatario['kind'],
			'from_email'  => $from,
			'subject'     => (string) ( $atts['subject'] ?? '' ),
			'status'      => 'pending',
			'provider'    => diluxone_mail_transport_active() ? $cfg['provider'] : 'observer',
			'source'      => diluxone_mail_caller(),
			'headers'     => $extendido ? (string) wp_json_encode( diluxone_mail_header_lines( $atts['headers'] ?? '' ) ) : '',
			'attachments' => $extendido ? (string) wp_json_encode( array_values( $adjuntos ) ) : '',
			'message_id'  => $uuid,
		);
	}

	if ( array() === $filas ) {
		return $atts;
	}

	diluxone_mail_log_insert( $filas );

	if ( (bool) diluxone_mail_option( 'diluxone_mail_log_body' ) ) {
		diluxone_mail_detail_save(
			$uuid,
			array(
				'body'      => (string) ( $atts['message'] ?? '' ),
				'body_type' => $meta['type'],
			)
		);
	}

	// El historial extendido guarda el diálogo SMTP entero, y para eso hay
	// que pedirle a PHPMailer que lo cuente desde antes de conectar.
	if ( $extendido ) {
		diluxone_mail_debug_buffer( null, true );
		diluxone_mail_debug_enabled( true );
	}

	diluxone_mail_current(
		array(
			'uuid'     => $uuid,
			'atts'     => $atts,
			'extended' => $extendido,
		)
	);

	return $atts;
}
add_filter( 'wp_mail', 'diluxone_mail_capture', PHP_INT_MAX );

/**
 * ¿Alguien quiere cancelar este envío?
 *
 * Corre primero de todos en pre_wp_mail. Si el filtro dice que no se mande,
 * se devuelve false —WordPress lo toma como «ya me ocupé»— y la fila queda
 * como suprimida, que es distinto de fallida: nadie intentó mandarla.
 *
 * @param mixed                $pre
 * @param array<string, mixed> $atts
 * @return mixed
 */
function diluxone_mail_maybe_suppress( $pre, array $atts ) {
	if ( null !== $pre ) {
		return $pre;
	}

	/**
	 * Filtra si un envío tiene que salir.
	 *
	 * Devolver false lo cancela y lo deja anotado como suprimido. Es el
	 * lugar para una lista de supresión o las preferencias de una persona.
	 *
	 * @param bool                 $send
	 * @param array<string, mixed> $atts
	 */
	if ( (bool) apply_filters( 'diluxone_mail_should_send', true, $atts ) ) {
		return null;
	}

	$actual = diluxone_mail_current();

	if ( null !== $actual ) {
		diluxone_mail_log_set_status( (string) $actual['uuid'], 'suppressed' );
		diluxone_mail_current( null, true );
	}

	return false;
}
add_filter( 'pre_wp_mail', 'diluxone_mail_maybe_suppress', 1, 2 );

/**
 * ¿Otro plugin cortó el envío antes de PHPMailer?
 *
 * Corre último en pre_wp_mail. Si a esta altura el valor no es null, alguien
 * más contestó y WordPress no va a mandar nada por su cuenta. El envío se
 * anota como entregado a ese plugin: lo que pasó después, no lo sabe nadie
 * desde acá.
 *
 * @param mixed                $pre
 * @param array<string, mixed> $atts
 * @return mixed
 */
function diluxone_mail_watch_pre_wp_mail( $pre, array $atts ) {
	if ( null === $pre ) {
		return $pre;
	}

	$actual = diluxone_mail_current();

	if ( null !== $actual ) {
		diluxone_mail_log_set_status( (string) $actual['uuid'], 'intercepted', '', diluxone_mail_pre_wp_mail_culprit() );
		diluxone_mail_current( null, true );
	}

	return $pre;
}
add_filter( 'pre_wp_mail', 'diluxone_mail_watch_pre_wp_mail', PHP_INT_MAX, 2 );

/**
 * El Message-ID del correo es el id del historial.
 *
 * Se pone último en phpmailer_init para que no lo pise nadie. No es tocar el
 * transporte: es una cabecera del mensaje, y es la que los proveedores
 * devuelven cuando avisan de un rebote.
 *
 * @param mixed $phpmailer Lo que traiga el hook; se comprueba el tipo adentro.
 */
function diluxone_mail_stamp_message_id( $phpmailer ): void {
	$actual = diluxone_mail_current();

	if ( null === $actual || ! $phpmailer instanceof PHPMailer\PHPMailer\PHPMailer ) {
		return;
	}

	$host = wp_parse_url( home_url(), PHP_URL_HOST );

	$phpmailer->MessageID = sprintf( '<%s@%s>', (string) $actual['uuid'], is_string( $host ) && '' !== $host ? $host : 'localhost' );
}
add_action( 'phpmailer_init', 'diluxone_mail_stamp_message_id', PHP_INT_MAX );

/**
 * Lo último que dijo el servidor, para guardarlo con la fila.
 *
 * Es el «250 OK queued as …» del proveedor, que trae su propio id de cola.
 */
function diluxone_mail_last_smtp_reply(): string {
	global $phpmailer;

	if ( ! $phpmailer instanceof PHPMailer\PHPMailer\PHPMailer ) {
		return '';
	}

	try {
		return trim( (string) $phpmailer->getSMTPInstance()->getLastReply() );
	} catch ( Throwable $e ) {
		return '';
	}
}

/**
 * Anota lo que pasó en el último envío, para la pantalla de estado.
 *
 * Aparte del historial porque el historial se puede apagar y esto no: que
 * un sitio sepa si su correo anda es lo mínimo.
 */
function diluxone_mail_remember_result( bool $ok, string $error ): void {
	update_option(
		'diluxone_mail_last_result',
		array(
			'ok'       => $ok ? 1 : 0,
			'time'     => time(),
			'error'    => diluxone_mail_redact( $error ),
			'provider' => diluxone_mail_transport_active() ? diluxone_mail_config()['provider'] : 'observer',
		),
		false
	);
}

/**
 * Cierra el envío en curso con su resultado.
 *
 * Es lo común a «salió» y «falló»: el estado de las filas, el diálogo SMTP
 * si el historial extendido lo pidió, y soltar el envío en curso.
 */
function diluxone_mail_finish( string $status, string $error ): void {
	$actual = diluxone_mail_current();

	if ( null === $actual ) {
		return;
	}

	diluxone_mail_log_set_status( (string) $actual['uuid'], $status, $error, diluxone_mail_last_smtp_reply() );

	if ( ! empty( $actual['extended'] ) ) {
		diluxone_mail_detail_save( (string) $actual['uuid'], array( 'transcript' => diluxone_mail_debug_buffer() ) );
	}

	diluxone_mail_current( null, true );
}

/** Entregado al servidor. */
function diluxone_mail_on_succeeded(): void {
	diluxone_mail_finish( 'sent', '' );
	diluxone_mail_remember_result( true, '' );
}
add_action( 'wp_mail_succeeded', 'diluxone_mail_on_succeeded' );

/**
 * Falló, y esto es por qué.
 *
 * @param WP_Error $error
 */
function diluxone_mail_on_failed( WP_Error $error ): void {
	diluxone_mail_finish( 'failed', $error->get_error_message() );
	diluxone_mail_remember_result( false, $error->get_error_message() );
}
add_action( 'wp_mail_failed', 'diluxone_mail_on_failed' );
