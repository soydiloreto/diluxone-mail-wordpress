<?php
/**
 * WP-CLI commands.
 *
 * There are four of them:
 *
 *   wp diluxone-mail test <email>     sends a test and shows the SMTP dialogue
 *   wp diluxone-mail status           the status, as on the screen
 *   wp diluxone-mail dns [<domain>]   the deliverability diagnosis
 *   wp diluxone-mail log list         the log
 *
 * They exist because mail breaks in production, where sometimes a terminal is
 * all there is. And because the DNS diagnosis works for any domain, not only
 * the site's.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Outgoing mail: transport, log and deliverability diagnosis.
 */
class DiluxOne_Mail_CLI {

	/**
	 * Sends a test message and shows what happened, with the SMTP dialogue.
	 *
	 * ## OPTIONS
	 *
	 * <email>
	 * : Where to send it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp diluxone-mail test somebody@example.com
	 *
	 * @param array<int, string> $args
	 */
	public function test( array $args ): void {
		$result = diluxone_mail_send_test( (string) ( $args[0] ?? '' ) );

		if ( '' !== $result['transcript'] ) {
			WP_CLI::log( $result['transcript'] );
		}

		if ( $result['ok'] ) {
			/* translators: 1: recipient, 2: seconds */
			WP_CLI::success( sprintf( 'Sent to %1$s in %2$ss.', $result['to'], $result['seconds'] ) );
			return;
		}

		WP_CLI::error( '' !== $result['error'] ? $result['error'] : 'The message was not sent.' );
	}

	/**
	 * Shows the status: profile, where each value comes from, mode, last send.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function status( array $args, array $assoc_args ): void {
		$status = diluxone_mail_status();
		$rows   = array(
			array(
				'key'   => 'environment',
				'value' => $status['environment'],
			),
			array(
				'key'   => 'mode',
				'value' => $status['mode'] . ( $status['transport'] ? ' (sending)' : ' (observing)' ),
			),
			array(
				'key'   => 'other mailers',
				'value' => implode( ', ', array_column( $status['others'], 'name' ) ),
			),
			array(
				'key'   => 'profile',
				'value' => (string) $status['profile']['name'],
			),
		);

		foreach ( $status['config'] as $field => $v ) {
			$rows[] = array(
				'key'   => $field,
				'value' => $v['value'] . ' — ' . $v['label'],
			);
		}

		if ( is_array( $status['last'] ) ) {
			$rows[] = array(
				'key'   => 'last send',
				'value' => ( ! empty( $status['last']['ok'] ) ? 'ok' : 'failed: ' . (string) $status['last']['error'] ) . ' at ' . gmdate( 'c', (int) $status['last']['time'] ),
			);
		}

		WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'key', 'value' ) );
	}

	/**
	 * The DNS diagnosis: SPF, DKIM, DMARC, explained.
	 *
	 * ## OPTIONS
	 *
	 * [<domain>]
	 * : The domain. Defaults to the one the site diagnoses.
	 *
	 * [--fresh]
	 * : Ignore the cache.
	 *
	 * [--format=<format>]
	 * : table or json. json carries the full report, including the SPF tree.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function dns( array $args, array $assoc_args ): void {
		$domain = strtolower( trim( (string) ( $args[0] ?? diluxone_mail_dns_domain() ) ) );

		if ( '' === $domain ) {
			WP_CLI::error( 'No domain to diagnose.' );
		}

		$report = diluxone_mail_diagnose( $domain, isset( $assoc_args['fresh'] ) );

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::log( (string) wp_json_encode( $report, JSON_PRETTY_PRINT ) );
			return;
		}

		/* translators: 1: domain, 2: lookups, 3: resolver */
		WP_CLI::log( sprintf( '%1$s — SPF: %2$d/10 lookups — resolver: %3$s', $domain, (int) $report['spf']['lookups'], (string) $report['resolver'] ) );

		foreach ( $report['findings'] as $finding ) {
			WP_CLI::log( sprintf( '[%s] %s', strtoupper( (string) $finding['level'] ), (string) $finding['title'] ) );
			WP_CLI::log( '    ' . (string) $finding['text'] );
		}
	}

	/**
	 * The log.
	 *
	 * ## OPTIONS
	 *
	 * <list>
	 * : The only action for now.
	 *
	 * [--email=<email>]
	 * : Only this address.
	 *
	 * [--status=<status>]
	 * : Only this status: sent, failed, pending, intercepted, suppressed.
	 *
	 * [--limit=<n>]
	 * : How many rows.
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
	 *     wp diluxone-mail log list --email=somebody@example.com
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

		$query = diluxone_mail_log_query(
			array(
				'emails'   => '' !== $email ? array( $email ) : array(),
				'status'   => (string) ( $assoc_args['status'] ?? '' ),
				'site_id'  => null,
				'per_page' => max( 1, (int) ( $assoc_args['limit'] ?? 20 ) ),
			)
		);

		$rows = array();

		foreach ( $query['rows'] as $row ) {
			$rows[] = array(
				'id'      => (int) $row['id'],
				'date'    => (string) $row['sent_at'],
				'to'      => (string) $row['email'],
				'subject' => (string) $row['subject'],
				'status'  => (string) $row['status'],
				'error'   => (string) $row['error'],
				'source'  => (string) $row['source'],
			);
		}

		WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'id', 'date', 'to', 'subject', 'status', 'error', 'source' ) );
	}
}

WP_CLI::add_command( 'diluxone-mail', 'DiluxOne_Mail_CLI' );
