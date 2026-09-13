<?php
/**
 * The log is personal data.
 *
 * What was sent to somebody, when and from where is information about that
 * person. Since 4.9.6 WordPress has two mechanisms for serving whoever asks
 * for their data or asks to be erased, and this plugin registers with both:
 * a person's mail comes out in their export, and is deleted when they ask to
 * be erased. It is not a courtesy; it is what lets a site answer a data
 * request without somebody having to remember this table exists.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the exporter.
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
 * Registers the eraser.
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
 * Exports one address's history, a hundred rows at a time.
 *
 * @return array{data: array<int, array<string, mixed>>, done: bool}
 */
function diluxone_mail_export_personal_data( string $email_address, int $page = 1 ): array {
	$query = diluxone_mail_log_query(
		array(
			'emails'   => array( $email_address ),
			'site_id'  => null,
			'page'     => max( 1, $page ),
			'per_page' => 100,
		)
	);

	$statuses = diluxone_mail_log_statuses();
	$items    = array();

	foreach ( $query['rows'] as $row ) {
		$items[] = array(
			'group_id'    => 'diluxone_mail',
			'group_label' => __( 'Mail history', 'diluxone-mail' ),
			'item_id'     => 'diluxone-mail-' . (int) $row['id'],
			'data'        => array(
				array(
					'name'  => __( 'Date', 'diluxone-mail' ),
					'value' => (string) $row['sent_at'],
				),
				array(
					'name'  => __( 'Subject', 'diluxone-mail' ),
					'value' => (string) $row['subject'],
				),
				array(
					'name'  => __( 'From', 'diluxone-mail' ),
					'value' => (string) $row['from_email'],
				),
				array(
					'name'  => __( 'Status', 'diluxone-mail' ),
					'value' => (string) ( $statuses[ (string) $row['status'] ] ?? $row['status'] ),
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
 * Deletes one address's history.
 *
 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
 */
function diluxone_mail_erase_personal_data( string $email_address ): array {
	$deleted = diluxone_mail_log_delete_by_email( $email_address );

	return array(
		'items_removed'  => $deleted > 0,
		'items_retained' => false,
		'messages'       => array(),
		'done'           => true,
	);
}
