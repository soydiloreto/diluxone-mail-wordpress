<?php
/**
 * El historial de envíos: sus tablas y cómo se escriben y se leen.
 *
 * Tabla propia y no postmeta: esto crece a cientos de miles de filas en un
 * sitio con algo de movimiento, y la tabla de metadatos de WordPress no tiene
 * índice por lo que acá se busca —una dirección de correo y una fecha—.
 *
 * Son dos tablas, y conviene entender por qué antes de tocarlas.
 *
 * La primera guarda UNA FILA POR DESTINATARIO, no por mensaje. Un wp_mail() a
 * tres personas deja tres filas. Parece redundante y es la decisión que hace
 * funcionar todo lo demás:
 *
 *   - La consulta que importa —«qué se le mandó a esta persona»— queda en un
 *     índice, sin JOIN y sin buscar adentro de un campo con todos los
 *     destinatarios. Es la pantalla que abre alguien de soporte mientras
 *     tiene a la persona esperando del otro lado.
 *   - Quien iba en copia aparece igual. Indexar sólo el primer destinatario
 *     deja afuera a los demás, que también son gente que pregunta.
 *   - Un rebote es de una dirección, no de un mensaje. Cuando entren los
 *     webhooks del proveedor, marcar la fila correcta va a ser un UPDATE por
 *     dirección y no una corrección del modelo de datos.
 *
 * La segunda guarda el cuerpo, una fila por mensaje, y sólo si el sitio lo
 * prendió. Está aparte por tres razones: tiene su propia retención —más corta,
 * porque es el dato más sensible—, no se duplica en un envío masivo, y
 * apagarlo es vaciar una tabla y no migrar una columna.
 *
 * El índice va por DIRECCIÓN DE CORREO y no por ID de usuario, a propósito:
 * se le manda correo a direcciones que no son de ningún usuario —un
 * formulario de contacto, un aviso al administrador de una tienda— y la gente
 * cambia su dirección. La ficha de una persona busca por la suya de ahora, y
 * por las anteriores si el sitio las conserva.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * La versión del esquema.
 *
 * Se sube a mano cuando cambia un CREATE TABLE de acá abajo. Es lo que hace
 * que una actualización por FTP o por git —que no dispara la activación—
 * igual cree la columna nueva.
 */
const DILUXONE_MAIL_DB_VERSION = 1;

/** El nombre de la tabla del historial, con el prefijo de este sitio. */
function diluxone_mail_log_table(): string {
	global $wpdb;

	return $wpdb->prefix . 'diluxone_mail_log';
}

/** El nombre de la tabla de los cuerpos. */
function diluxone_mail_body_table(): string {
	global $wpdb;

	return $wpdb->prefix . 'diluxone_mail_body';
}

/**
 * Crea o actualiza las tablas.
 *
 * Corre en la activación y también en admin_init cuando la versión guardada
 * quedó vieja. dbDelta() es exigente con el formato del SQL —dos espacios
 * después de PRIMARY KEY, el tipo en minúsculas, una definición por renglón—
 * y si no se lo respeta no falla: recrea la tabla en cada carga sin decir
 * nada. El formato de abajo es el que espera.
 */
function diluxone_mail_install(): void {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset = $wpdb->get_charset_collate();
	$log     = diluxone_mail_log_table();
	$body    = diluxone_mail_body_table();

	// 191 y no 255 en las columnas indexadas: en utf8mb4 cada carácter ocupa
	// hasta cuatro bytes, y el índice de InnoDB en las versiones que todavía
	// hay dadas vuelta no pasa de 767. 191 × 4 = 764, que entra justo. Es la
	// misma cuenta que hace WordPress en sus propias tablas.
	$sql = "CREATE TABLE {$log} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		sent_at datetime NOT NULL,
		email varchar(191) NOT NULL,
		kind varchar(4) NOT NULL DEFAULT 'to',
		from_email varchar(191) NOT NULL DEFAULT '',
		subject text NOT NULL,
		status varchar(20) NOT NULL DEFAULT 'unknown',
		error text NOT NULL,
		provider varchar(50) NOT NULL DEFAULT '',
		source varchar(191) NOT NULL DEFAULT '',
		headers longtext NOT NULL,
		attachments text NOT NULL,
		message_id varchar(191) NOT NULL DEFAULT '',
		PRIMARY KEY  (id),
		KEY email_sent (email(191), sent_at),
		KEY sent_at (sent_at),
		KEY status (status),
		KEY message_id (message_id)
	) {$charset};";

	$sql_body = "CREATE TABLE {$body} (
		message_id varchar(191) NOT NULL,
		created_at datetime NOT NULL,
		body longtext NOT NULL,
		body_type varchar(20) NOT NULL DEFAULT 'text/plain',
		PRIMARY KEY  (message_id),
		KEY created_at (created_at)
	) {$charset};";

	dbDelta( $sql );
	dbDelta( $sql_body );

	update_option( 'diluxone_mail_db_version', DILUXONE_MAIL_DB_VERSION );
}

/**
 * Crea las tablas cuando el plugin se actualizó sin pasar por la activación.
 *
 * Una actualización por FTP, por git o por un despliegue automático no
 * dispara register_activation_hook(). Sin esto, la columna que agregue la
 * próxima versión no existe en ninguno de esos sitios, y el primer envío
 * después de actualizar falla con un error de SQL.
 */
function diluxone_mail_maybe_install(): void {
	if ( (int) get_option( 'diluxone_mail_db_version', 0 ) === DILUXONE_MAIL_DB_VERSION ) {
		return;
	}

	diluxone_mail_install();
}
add_action( 'admin_init', 'diluxone_mail_maybe_install' );
