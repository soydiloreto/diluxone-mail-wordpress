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
 * La segunda guarda el detalle de cada mensaje —el cuerpo, si el sitio lo
 * prendió, y el diálogo SMTP con el proveedor, si prendió el historial
 * extendido—, una fila por mensaje. Está aparte por tres razones: tiene su
 * propia retención —más corta, porque es lo más pesado y lo más sensible—,
 * no se duplica en un envío masivo, y apagarlo es vaciar una tabla y no
 * migrar una columna.
 *
 * El índice va por DIRECCIÓN DE CORREO y no por ID de usuario, a propósito:
 * se le manda correo a direcciones que no son de ningún usuario —un
 * formulario de contacto, un aviso al administrador de una tienda— y la gente
 * cambia su dirección. La ficha de una persona busca por la suya de ahora, y
 * por las anteriores si el sitio las conserva.
 *
 * En una red las tablas son UNA para toda la red —con el prefijo base y una
 * columna site_id— y no una por sitio. Las personas son de la red, no de un
 * sitio: la ficha de alguien tiene que mostrar todo lo que se le mandó desde
 * cualquier sitio, y una tabla por sitio obligaría a recorrerlas todas.
 *
 * Todo el archivo habla con la base directamente y sin caché, y tiene que ser
 * así: es una tabla propia que la API de WordPress no conoce, y un historial
 * que se cachea es un historial que miente durante el tiempo del caché. Los
 * nombres de las tablas van por el marcador %i de prepare() —WordPress 6.2—,
 * que los entrecomilla como identificadores: por eso el mínimo del plugin es
 * 6.2 y no 6.0.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
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
const DILUXONE_MAIL_DB_VERSION = 2;

/**
 * Los estados que puede tener una fila, con su nombre para la gente.
 *
 * @return array<string, string>
 */
function diluxone_mail_log_statuses(): array {
	return array(
		'pending'     => __( 'Sending', 'diluxone-mail' ),
		'sent'        => __( 'Sent', 'diluxone-mail' ),
		'failed'      => __( 'Failed', 'diluxone-mail' ),
		'intercepted' => __( 'Handed to another plugin', 'diluxone-mail' ),
		'suppressed'  => __( 'Not sent (suppressed)', 'diluxone-mail' ),
		// Los dos de abajo no los escribe nadie todavía: los van a escribir
		// los webhooks de rebotes. Están en la lista para que la columna ya
		// tenga su lugar y la pantalla sepa cómo llamarlos.
		'bounced'     => __( 'Bounced', 'diluxone-mail' ),
		'complained'  => __( 'Marked as spam', 'diluxone-mail' ),
	);
}

/** El nombre de la tabla del historial. Prefijo base: una por red. */
function diluxone_mail_log_table(): string {
	global $wpdb;

	return $wpdb->base_prefix . 'diluxone_mail_log';
}

/** El nombre de la tabla del detalle de cada mensaje. */
function diluxone_mail_detail_table(): string {
	global $wpdb;

	return $wpdb->base_prefix . 'diluxone_mail_detail';
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
	$detail  = diluxone_mail_detail_table();

	// 191 y no 255 en las columnas indexadas: en utf8mb4 cada carácter ocupa
	// hasta cuatro bytes, y el índice de InnoDB en las versiones que todavía
	// hay dadas vuelta no pasa de 767. 191 × 4 = 764, que entra justo. Es la
	// misma cuenta que hace WordPress en sus propias tablas.
	$sql = "CREATE TABLE {$log} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		site_id bigint(20) unsigned NOT NULL DEFAULT 1,
		sent_at datetime NOT NULL,
		email varchar(191) NOT NULL,
		kind varchar(4) NOT NULL DEFAULT 'to',
		from_email varchar(191) NOT NULL DEFAULT '',
		subject text NOT NULL,
		status varchar(20) NOT NULL DEFAULT 'pending',
		error text NOT NULL,
		response text NOT NULL,
		provider varchar(50) NOT NULL DEFAULT '',
		source varchar(191) NOT NULL DEFAULT '',
		headers longtext NOT NULL,
		attachments text NOT NULL,
		message_id varchar(191) NOT NULL DEFAULT '',
		PRIMARY KEY  (id),
		KEY email_sent (email(191), sent_at),
		KEY site_sent (site_id, sent_at),
		KEY sent_at (sent_at),
		KEY status (status),
		KEY message_id (message_id)
	) {$charset};";

	$sql_detail = "CREATE TABLE {$detail} (
		message_id varchar(191) NOT NULL,
		created_at datetime NOT NULL,
		body longtext NOT NULL,
		body_type varchar(20) NOT NULL DEFAULT 'text/plain',
		transcript longtext NOT NULL,
		PRIMARY KEY  (message_id),
		KEY created_at (created_at)
	) {$charset};";

	dbDelta( $sql );
	dbDelta( $sql_detail );

	// La versión va en la red cuando hay red: las tablas son de la red.
	update_site_option( 'diluxone_mail_db_version', DILUXONE_MAIL_DB_VERSION );
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
	if ( (int) get_site_option( 'diluxone_mail_db_version', 0 ) === DILUXONE_MAIL_DB_VERSION ) {
		return;
	}

	diluxone_mail_install();
}
add_action( 'admin_init', 'diluxone_mail_maybe_install' );

/**
 * Escribe las filas de un mensaje: una por destinatario.
 *
 * @param array<int, array<string, mixed>> $filas
 */
function diluxone_mail_log_insert( array $filas ): void {
	global $wpdb;

	foreach ( $filas as $fila ) {
		$wpdb->insert(
			diluxone_mail_log_table(),
			array(
				'site_id'     => (int) ( $fila['site_id'] ?? get_current_blog_id() ),
				'sent_at'     => (string) ( $fila['sent_at'] ?? current_time( 'mysql', true ) ),
				'email'       => (string) $fila['email'],
				'kind'        => (string) ( $fila['kind'] ?? 'to' ),
				'from_email'  => (string) ( $fila['from_email'] ?? '' ),
				'subject'     => (string) ( $fila['subject'] ?? '' ),
				'status'      => (string) ( $fila['status'] ?? 'pending' ),
				'error'       => (string) ( $fila['error'] ?? '' ),
				'response'    => (string) ( $fila['response'] ?? '' ),
				'provider'    => (string) ( $fila['provider'] ?? '' ),
				'source'      => (string) ( $fila['source'] ?? '' ),
				'headers'     => (string) ( $fila['headers'] ?? '' ),
				'attachments' => (string) ( $fila['attachments'] ?? '' ),
				'message_id'  => (string) ( $fila['message_id'] ?? '' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}
}

/**
 * Cambia el estado de todas las filas de un mensaje.
 *
 * El error y la respuesta pasan por diluxone_mail_redact() acá y no en quien
 * llama, porque quien llama es el que se olvida: el texto del error viene del
 * servidor y puede repetir la credencial que rechazó.
 */
function diluxone_mail_log_set_status( string $message_id, string $status, string $error = '', string $response = '' ): void {
	global $wpdb;

	if ( '' === $message_id ) {
		return;
	}

	$wpdb->update(
		diluxone_mail_log_table(),
		array(
			'status'   => $status,
			'error'    => diluxone_mail_redact( $error ),
			'response' => diluxone_mail_redact( $response ),
		),
		array( 'message_id' => $message_id ),
		array( '%s', '%s', '%s' ),
		array( '%s' )
	);
}

/**
 * Filas del historial, filtradas y paginadas.
 *
 * @param array<string, mixed> $args
 *        emails:   array<string>  sólo estas direcciones (la ficha de una persona).
 *        status:   string         sólo este estado.
 *        search:   string         texto libre en asunto o dirección.
 *        site_id:  int|null       sólo este sitio; null = toda la red.
 *        page:     int
 *        per_page: int
 * @return array{rows: array<int, array<string, mixed>>, total: int}
 */
function diluxone_mail_log_query( array $args = array() ): array {
	global $wpdb;

	$emails   = array_values( array_filter( array_map( 'strtolower', array_map( 'strval', (array) ( $args['emails'] ?? array() ) ) ) ) );
	$status   = sanitize_key( (string) ( $args['status'] ?? '' ) );
	$search   = trim( (string) ( $args['search'] ?? '' ) );
	$site_id  = array_key_exists( 'site_id', $args ) ? $args['site_id'] : get_current_blog_id();
	$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
	$per_page = max( 1, min( 500, (int) ( $args['per_page'] ?? 20 ) ) );

	$where  = array( '1=1' );
	$params = array();

	if ( array() !== $emails ) {
		$where[] = 'email IN (' . implode( ',', array_fill( 0, count( $emails ), '%s' ) ) . ')';
		$params  = array_merge( $params, $emails );
	}

	if ( '' !== $status ) {
		$where[]  = 'status = %s';
		$params[] = $status;
	}

	if ( '' !== $search ) {
		$like     = '%' . $wpdb->esc_like( $search ) . '%';
		$where[]  = '( subject LIKE %s OR email LIKE %s OR from_email LIKE %s )';
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
	}

	if ( null !== $site_id ) {
		$where[]  = 'site_id = %d';
		$params[] = (int) $site_id;
	}

	$tabla = diluxone_mail_log_table();
	$sql   = 'WHERE ' . implode( ' AND ', $where );

	// El nombre de la tabla va por %i, que prepare() entrecomilla como
	// identificador. $sql se arma sólo con marcadores y $params los llena.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $sql son marcadores; los valores van en $params.
	$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i {$sql}", array_merge( array( $tabla ), $params ) ) );

	$params[] = $per_page;
	$params[] = ( $page - 1 ) * $per_page;

	$rows = $wpdb->get_results(
		$wpdb->prepare( "SELECT * FROM %i {$sql} ORDER BY sent_at DESC, id DESC LIMIT %d OFFSET %d", array_merge( array( $tabla ), $params ) ),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

	return array(
		'rows'  => is_array( $rows ) ? $rows : array(),
		'total' => $total,
	);
}

/**
 * Una fila por su id.
 *
 * @return array<string, mixed>|null
 */
function diluxone_mail_log_get( int $id ): ?array {
	global $wpdb;

	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', diluxone_mail_log_table(), $id ), ARRAY_A );

	return is_array( $row ) ? $row : null;
}

/**
 * Todas las filas de un mismo mensaje: cada destinatario con su estado.
 *
 * @return array<int, array<string, mixed>>
 */
function diluxone_mail_log_recipients_of( string $message_id ): array {
	global $wpdb;

	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE message_id = %s ORDER BY id ASC', diluxone_mail_log_table(), $message_id ), ARRAY_A );

	return is_array( $rows ) ? $rows : array();
}

/**
 * Cuántos envíos tiene una dirección. Para la ficha de la persona.
 *
 * @param array<int, string> $emails
 */
function diluxone_mail_log_count( array $emails ): int {
	return diluxone_mail_log_query(
		array(
			'emails'   => $emails,
			'site_id'  => null,
			'per_page' => 1,
		)
	)['total'];
}

/**
 * Cuántas filas hay por estado. Para la pantalla de estado.
 *
 * @return array<string, int>
 */
function diluxone_mail_log_totals( ?int $site_id ): array {
	global $wpdb;

	$tabla = diluxone_mail_log_table();

	$rows = null === $site_id
		? $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i GROUP BY status', $tabla ), ARRAY_A )
		: $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i WHERE site_id = %d GROUP BY status', $tabla, $site_id ), ARRAY_A );

	$salida = array();

	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$salida[ (string) $row['status'] ] = (int) $row['n'];
	}

	return $salida;
}

/**
 * Guarda o completa el detalle de un mensaje.
 *
 * Se escribe en dos momentos —el cuerpo al anotar, el diálogo SMTP al
 * terminar— así que lo que llega se funde con lo que ya había.
 *
 * @param array{body?: string, body_type?: string, transcript?: string} $campos
 */
function diluxone_mail_detail_save( string $message_id, array $campos ): void {
	global $wpdb;

	if ( '' === $message_id ) {
		return;
	}

	$actual = diluxone_mail_detail_get( $message_id );

	$wpdb->replace(
		diluxone_mail_detail_table(),
		array(
			'message_id' => $message_id,
			'created_at' => current_time( 'mysql', true ),
			'body'       => (string) ( $campos['body'] ?? $actual['body'] ?? '' ),
			'body_type'  => (string) ( $campos['body_type'] ?? $actual['body_type'] ?? 'text/plain' ),
			'transcript' => diluxone_mail_redact( (string) ( $campos['transcript'] ?? $actual['transcript'] ?? '' ) ),
		),
		array( '%s', '%s', '%s', '%s', '%s' )
	);
}

/**
 * El detalle de un mensaje, si se guardó algo.
 *
 * @return array{body: string, body_type: string, transcript: string}|null
 */
function diluxone_mail_detail_get( string $message_id ): ?array {
	global $wpdb;

	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT body, body_type, transcript FROM %i WHERE message_id = %s', diluxone_mail_detail_table(), $message_id ), ARRAY_A );

	if ( ! is_array( $row ) ) {
		return null;
	}

	return array(
		'body'       => (string) $row['body'],
		'body_type'  => (string) $row['body_type'],
		'transcript' => (string) $row['transcript'],
	);
}

/**
 * Borra lo que venció, según las retenciones configuradas.
 *
 * El detalle primero y con su propia fecha, que es más corta. Y si no hay
 * nada que lo justifique —ni cuerpo ni historial extendido prendidos— se
 * vacía la tabla entera: un cuerpo guardado cuando la casilla estaba
 * prendida no tiene por qué sobrevivir a que la apaguen.
 *
 * @return array{log: int, details: int}
 */
function diluxone_mail_log_purge(): array {
	global $wpdb;

	$dias_log     = max( 1, (int) diluxone_mail_option( 'diluxone_mail_log_retention_days' ) );
	$dias_detalle = max( 1, (int) diluxone_mail_option( 'diluxone_mail_log_detail_retention_days' ) );
	$log          = diluxone_mail_log_table();
	$detail       = diluxone_mail_detail_table();
	$guarda_algo  = (bool) diluxone_mail_option( 'diluxone_mail_log_body' ) || (bool) diluxone_mail_option( 'diluxone_mail_log_extended' );

	$detalles = $guarda_algo
		? (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $detail, gmdate( 'Y-m-d H:i:s', time() - $dias_detalle * DAY_IN_SECONDS ) ) )
		: (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $detail ) );

	$filas = (int) $wpdb->query(
		$wpdb->prepare( 'DELETE FROM %i WHERE sent_at < %s', $log, gmdate( 'Y-m-d H:i:s', time() - $dias_log * DAY_IN_SECONDS ) )
	);

	return array(
		'log'     => $filas,
		'details' => $detalles,
	);
}

/**
 * Borra todo lo de una dirección. Para el borrador de datos personales.
 *
 * El detalle de los mensajes que sólo iban a esa persona se va también; el
 * de un mensaje que iba a más gente se queda, porque es de los otros
 * destinatarios tanto como de ella.
 */
function diluxone_mail_log_delete_by_email( string $email ): int {
	global $wpdb;

	$email = strtolower( trim( $email ) );

	if ( '' === $email ) {
		return 0;
	}

	$log    = diluxone_mail_log_table();
	$detail = diluxone_mail_detail_table();

	$wpdb->query(
		$wpdb->prepare(
			'DELETE d FROM %i d
			  WHERE EXISTS ( SELECT 1 FROM %i l WHERE l.message_id = d.message_id AND l.email = %s )
			    AND NOT EXISTS ( SELECT 1 FROM %i l2 WHERE l2.message_id = d.message_id AND l2.email <> %s )',
			$detail,
			$log,
			$email,
			$log,
			$email
		)
	);

	$borradas = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE email = %s', $log, $email ) );

	return $borradas;
}
