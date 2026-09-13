<?php
/**
 * Comandos de WP-CLI.
 *
 * Los cuatro:
 *
 *   wp diluxone-mail test <correo>     manda una prueba y muestra el diálogo SMTP
 *   wp diluxone-mail status            el estado, como en la pantalla
 *   wp diluxone-mail dns [<dominio>]   el diagnóstico de entregabilidad
 *   wp diluxone-mail log list          el historial
 *
 * Existen porque el correo se rompe en producción, donde a veces lo único
 * que hay es una terminal. Y porque el diagnóstico de DNS sirve para
 * cualquier dominio, no sólo el del sitio.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Correo saliente: transporte, historial y diagnóstico de entregabilidad.
 */
class DiluxOne_Mail_CLI {

	/**
	 * Manda un correo de prueba y muestra qué pasó, con el diálogo SMTP.
	 *
	 * ## OPTIONS
	 *
	 * <correo>
	 * : Adónde mandarlo.
	 *
	 * ## EXAMPLES
	 *
	 *     wp diluxone-mail test alguien@ejemplo.com
	 *
	 * @param array<int, string> $args
	 */
	public function test( array $args ): void {
		$resultado = diluxone_mail_send_test( (string) ( $args[0] ?? '' ) );

		if ( '' !== $resultado['transcript'] ) {
			WP_CLI::log( $resultado['transcript'] );
		}

		if ( $resultado['ok'] ) {
			/* translators: 1: destinatario, 2: segundos */
			WP_CLI::success( sprintf( 'Sent to %1$s in %2$ss.', $resultado['to'], $resultado['seconds'] ) );
			return;
		}

		WP_CLI::error( '' !== $resultado['error'] ? $resultado['error'] : 'The message was not sent.' );
	}

	/**
	 * Muestra el estado: perfil, de dónde sale cada valor, modo, último envío.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json o yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function status( array $args, array $assoc_args ): void {
		$estado = diluxone_mail_status();
		$filas  = array(
			array(
				'key'   => 'environment',
				'value' => $estado['environment'],
			),
			array(
				'key'   => 'mode',
				'value' => $estado['mode'] . ( $estado['transport'] ? ' (sending)' : ' (observing)' ),
			),
			array(
				'key'   => 'other mailers',
				'value' => implode( ', ', array_column( $estado['others'], 'name' ) ),
			),
			array(
				'key'   => 'profile',
				'value' => (string) $estado['profile']['name'],
			),
		);

		foreach ( $estado['config'] as $campo => $v ) {
			$filas[] = array(
				'key'   => $campo,
				'value' => $v['value'] . ' — ' . $v['label'],
			);
		}

		if ( is_array( $estado['last'] ) ) {
			$filas[] = array(
				'key'   => 'last send',
				'value' => ( ! empty( $estado['last']['ok'] ) ? 'ok' : 'failed: ' . (string) $estado['last']['error'] ) . ' at ' . gmdate( 'c', (int) $estado['last']['time'] ),
			);
		}

		WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $filas, array( 'key', 'value' ) );
	}

	/**
	 * El diagnóstico de DNS: SPF, DKIM, DMARC, explicado.
	 *
	 * ## OPTIONS
	 *
	 * [<dominio>]
	 * : El dominio. Por defecto, el que diagnostica el sitio.
	 *
	 * [--fresh]
	 * : Ignorar el caché.
	 *
	 * [--format=<format>]
	 * : table o json. json trae el informe completo, con el árbol del SPF.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function dns( array $args, array $assoc_args ): void {
		$dominio = strtolower( trim( (string) ( $args[0] ?? diluxone_mail_dns_domain() ) ) );

		if ( '' === $dominio ) {
			WP_CLI::error( 'No domain to diagnose.' );
		}

		$informe = diluxone_mail_diagnose( $dominio, isset( $assoc_args['fresh'] ) );

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::log( (string) wp_json_encode( $informe, JSON_PRETTY_PRINT ) );
			return;
		}

		/* translators: 1: dominio, 2: lookups, 3: origen */
		WP_CLI::log( sprintf( '%1$s — SPF: %2$d/10 lookups — resolver: %3$s', $dominio, (int) $informe['spf']['lookups'], (string) $informe['resolver'] ) );

		foreach ( $informe['findings'] as $hallazgo ) {
			WP_CLI::log( sprintf( '[%s] %s', strtoupper( (string) $hallazgo['level'] ), (string) $hallazgo['title'] ) );
			WP_CLI::log( '    ' . (string) $hallazgo['text'] );
		}
	}

	/**
	 * El historial.
	 *
	 * ## OPTIONS
	 *
	 * <list>
	 * : La única acción por ahora.
	 *
	 * [--email=<email>]
	 * : Sólo esta dirección.
	 *
	 * [--status=<status>]
	 * : Sólo este estado: sent, failed, pending, intercepted, suppressed.
	 *
	 * [--limit=<n>]
	 * : Cuántas filas.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : table, csv, json o yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp diluxone-mail log list --email=alguien@ejemplo.com
	 *     wp diluxone-mail log list --status=failed --format=json
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function log( array $args, array $assoc_args ): void {
		if ( 'list' !== ( $args[0] ?? '' ) ) {
			WP_CLI::error( 'Usage: wp diluxone-mail log list' );
		}

		$email = (string) ( $assoc_args['email'] ?? '' );

		$consulta = diluxone_mail_log_query(
			array(
				'emails'   => '' !== $email ? array( $email ) : array(),
				'status'   => (string) ( $assoc_args['status'] ?? '' ),
				'site_id'  => null,
				'per_page' => max( 1, (int) ( $assoc_args['limit'] ?? 20 ) ),
			)
		);

		$filas = array();

		foreach ( $consulta['rows'] as $fila ) {
			$filas[] = array(
				'id'      => (int) $fila['id'],
				'date'    => (string) $fila['sent_at'],
				'to'      => (string) $fila['email'],
				'subject' => (string) $fila['subject'],
				'status'  => (string) $fila['status'],
				'error'   => (string) $fila['error'],
				'source'  => (string) $fila['source'],
			);
		}

		WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $filas, array( 'id', 'date', 'to', 'subject', 'status', 'error', 'source' ) );
	}
}

WP_CLI::add_command( 'diluxone-mail', 'DiluxOne_Mail_CLI' );
