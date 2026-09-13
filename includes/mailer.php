<?php
/**
 * El transporte: le dice a PHPMailer qué servidor usar.
 *
 * Se engancha en phpmailer_init, después de que WordPress armó el mensaje y
 * antes de que lo mande. Ahí se decide una sola cosa —por qué servidor sale—
 * y se hace sólo si este plugin tiene el control: en modo observador no se
 * toca nada, ni siquiera cuando el sitio tiene host configurado.
 *
 * El remitente va por otro lado, y por una razón que enseñó el E2E: WordPress
 * valida el From ANTES de phpmailer_init, y si el sitio está en localhost su
 * remitente por defecto —wordpress@localhost— no pasa la validación y el
 * envío muere antes de que nadie pueda arreglarlo. Los filtros wp_mail_from
 * y wp_mail_from_name corren antes de esa validación, y son además el lugar
 * que WordPress prevé para esto.
 *
 * Lo que también vive acá es el buffer del SMTPDebug. PHPMailer puede contar
 * el diálogo entero con el servidor —cada comando y cada respuesta— y eso es
 * lo que hace falta cuando un envío falla: «535 Authentication failed» dice
 * exactamente qué está mal, «algo salió mal» no dice nada. Se captura a un
 * buffer, se tapa la contraseña, y se muestra plegado en el botón de probar.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * El buffer del diálogo SMTP de esta petición.
 *
 * @param string|null $linea  Una línea para agregar, o null para sólo leer.
 * @param bool        $reset  Vaciar antes.
 */
function diluxone_mail_debug_buffer( ?string $linea = null, bool $reset = false ): string {
	static $buffer = '';

	if ( $reset ) {
		$buffer = '';
	}

	if ( null !== $linea ) {
		$buffer .= rtrim( $linea ) . "\n";
	}

	return $buffer;
}

/**
 * ¿Capturar el diálogo SMTP en esta petición?
 *
 * Apagado salvo que alguien lo prenda —el botón de probar, el comando de
 * WP-CLI, el historial extendido—. Capturarlo siempre sería guardar en
 * memoria el diálogo de cada envío del sitio para no mostrárselo a nadie.
 */
function diluxone_mail_debug_enabled( ?bool $set = null ): bool {
	static $on = false;

	if ( null !== $set ) {
		$on = $set;
	}

	return $on;
}

/**
 * Configura PHPMailer.
 *
 * @param mixed $phpmailer Lo que traiga el hook; se comprueba el tipo adentro.
 */
function diluxone_mail_phpmailer_init( $phpmailer ): void {
	if ( ! $phpmailer instanceof PHPMailer\PHPMailer\PHPMailer ) {
		return;
	}

	// El buffer se engancha aunque el transporte sea de otro: probar un
	// envío en modo observador también tiene que poder mostrar qué pasó.
	if ( diluxone_mail_debug_enabled() ) {
		$phpmailer->SMTPDebug   = 2;
		$phpmailer->Debugoutput = static function ( $str ): void {
			diluxone_mail_debug_buffer( (string) $str );
		};
	}

	if ( ! diluxone_mail_transport_active() ) {
		return;
	}

	$config = diluxone_mail_config();

	if ( '' === $config['host'] ) {
		return;
	}

	$perfil = diluxone_mail_provider( $config['provider'] );

	$phpmailer->isSMTP();
	$phpmailer->Host = $config['host'];
	$phpmailer->Port = (int) $config['port'] > 0 ? (int) $config['port'] : 587;

	// 'none' es cadena vacía para PHPMailer. Y el autoTLS va aparte del
	// cifrado a propósito: un perfil local dice «sin cifrado» y además «no
	// intentes subir a cifrado aunque el servidor lo ofrezca».
	$cifrado                = $config['encryption'];
	$phpmailer->SMTPSecure  = in_array( $cifrado, array( 'tls', 'ssl' ), true ) ? $cifrado : '';
	$phpmailer->SMTPAutoTLS = (bool) $perfil['autotls'];

	$auth = (bool) $perfil['auth'] && (bool) diluxone_mail_option( 'diluxone_mail_auth' ) && '' !== $config['user'];

	$phpmailer->SMTPAuth = $auth;

	if ( $auth ) {
		$phpmailer->Username = $config['user'];
		$phpmailer->Password = $config['pass'];
	}

	$phpmailer->Timeout = max( 5, (int) diluxone_mail_option( 'diluxone_mail_timeout' ) );
}
add_action( 'phpmailer_init', 'diluxone_mail_phpmailer_init', 999 );

/**
 * ¿El remitente que trae el envío es el que WordPress pone por defecto?
 *
 * WordPress firma como wordpress@eldominio si nadie dijo otra cosa. Esa
 * dirección suele no existir, muchos proveedores la rechazan por no estar
 * verificada, y en un sitio en localhost ni siquiera es una dirección válida.
 */
function diluxone_mail_is_default_from( string $from ): bool {
	return '' === $from || 0 === strpos( $from, 'wordpress@' );
}

/**
 * El remitente.
 *
 * Si el sitio configuró uno, se usa cuando el envío trae el de WordPress por
 * defecto y —si «forzar» está prendido— también cuando trae otro. Sólo con
 * el transporte a cargo de este plugin: en modo observador el correo es del
 * otro plugin y no se le cambia nada.
 */
function diluxone_mail_from( string $from ): string {
	if ( ! diluxone_mail_transport_active() ) {
		return $from;
	}

	$configurado = diluxone_mail_config()['from'];

	if ( '' === $configurado || ! is_email( $configurado ) ) {
		return $from;
	}

	if ( diluxone_mail_is_default_from( $from ) || (bool) diluxone_mail_option( 'diluxone_mail_force_from' ) ) {
		return $configurado;
	}

	return $from;
}
add_filter( 'wp_mail_from', 'diluxone_mail_from', 999 );

/**
 * El nombre del remitente, con la misma regla.
 *
 * El nombre por defecto de WordPress es «WordPress», literal. Se cambia junto
 * con la dirección: un nombre configurado sin dirección configurada no dice
 * nada.
 */
function diluxone_mail_from_name( string $name ): string {
	if ( ! diluxone_mail_transport_active() ) {
		return $name;
	}

	$config = diluxone_mail_config();

	if ( '' === $config['from'] || '' === $config['from_name'] ) {
		return $name;
	}

	if ( 'WordPress' === $name || '' === $name || (bool) diluxone_mail_option( 'diluxone_mail_force_from' ) ) {
		return $config['from_name'];
	}

	return $name;
}
add_filter( 'wp_mail_from_name', 'diluxone_mail_from_name', 999 );
