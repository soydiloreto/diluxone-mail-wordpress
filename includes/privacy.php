<?php
/**
 * El historial es dato personal.
 *
 * Qué se le mandó a alguien, cuándo y desde dónde es información sobre esa
 * persona. WordPress tiene desde 4.9.6 dos mecanismos para atender a quien
 * pide sus datos o pide que los borren, y este plugin se registra en los dos:
 * el correo de una persona sale en su exportación, y se borra cuando pide
 * que la borren. No es una cortesía, es lo que hace que un sitio pueda
 * contestar un pedido de datos sin que alguien tenga que acordarse de esta
 * tabla.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registra el exportador.
 *
 * @param array<string, array<string, mixed>> $exporters
 * @return array<string, array<string, mixed>>
 */
function diluxone_mail_register_exporter( array $exporters ): array {
	if ( (bool) diluxone_mail_option( 'diluxone_mail_privacy_export' ) ) {
		$exporters['diluxone-mail'] = array(
			'exporter_friendly_name' => __( 'Mail history', 'diluxone-mail' ),
			'callback'               => 'diluxone_mail_export_personal_data',
		);
	}

	return $exporters;
}
add_filter( 'wp_privacy_personal_data_exporters', 'diluxone_mail_register_exporter' );

/**
 * Registra el borrador.
 *
 * @param array<string, array<string, mixed>> $erasers
 * @return array<string, array<string, mixed>>
 */
function diluxone_mail_register_eraser( array $erasers ): array {
	if ( (bool) diluxone_mail_option( 'diluxone_mail_privacy_erase' ) ) {
		$erasers['diluxone-mail'] = array(
			'eraser_friendly_name' => __( 'Mail history', 'diluxone-mail' ),
			'callback'             => 'diluxone_mail_erase_personal_data',
		);
	}

	return $erasers;
}
add_filter( 'wp_privacy_personal_data_erasers', 'diluxone_mail_register_eraser' );

/**
 * Exporta el historial de una dirección, de a cien.
 *
 * @return array{data: array<int, array<string, mixed>>, done: bool}
 */
function diluxone_mail_export_personal_data( string $email_address, int $page = 1 ): array {
	$consulta = diluxone_mail_log_query(
		array(
			'emails'   => array( $email_address ),
			'site_id'  => null,
			'page'     => max( 1, $page ),
			'per_page' => 100,
		)
	);

	$estados = diluxone_mail_log_statuses();
	$items   = array();

	foreach ( $consulta['rows'] as $fila ) {
		$items[] = array(
			'group_id'    => 'diluxone_mail',
			'group_label' => __( 'Mail history', 'diluxone-mail' ),
			'item_id'     => 'diluxone-mail-' . (int) $fila['id'],
			'data'        => array(
				array(
					'name'  => __( 'Date', 'diluxone-mail' ),
					'value' => (string) $fila['sent_at'],
				),
				array(
					'name'  => __( 'Subject', 'diluxone-mail' ),
					'value' => (string) $fila['subject'],
				),
				array(
					'name'  => __( 'From', 'diluxone-mail' ),
					'value' => (string) $fila['from_email'],
				),
				array(
					'name'  => __( 'Status', 'diluxone-mail' ),
					'value' => (string) ( $estados[ (string) $fila['status'] ] ?? $fila['status'] ),
				),
			),
		);
	}

	return array(
		'data' => $items,
		'done' => count( $items ) < 100,
	);
}

/**
 * Borra el historial de una dirección.
 *
 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
 */
function diluxone_mail_erase_personal_data( string $email_address ): array {
	$borradas = diluxone_mail_log_delete_by_email( $email_address );

	return array(
		'items_removed'  => $borradas > 0,
		'items_retained' => false,
		'messages'       => array(),
		'done'           => true,
	);
}
