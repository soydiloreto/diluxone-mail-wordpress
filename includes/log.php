<?php
/**
 * The send log: its tables, and how they are written and read.
 *
 * Its own tables rather than postmeta: this grows to hundreds of thousands of
 * rows on a site with any traffic, and WordPress's metadata table has no
 * index on what gets searched here — an email address and a date.
 *
 * There are two tables, and it is worth understanding why before touching
 * them.
 *
 * The first stores ONE ROW PER RECIPIENT, not per message. A wp_mail() to
 * three people leaves three rows. It looks redundant and it is the decision
 * that makes everything else work:
 *
 *   - The query that matters — "what was sent to this person" — stays on an
 *     index, with no JOIN and no searching inside a field holding every
 *     recipient. It is the screen somebody on support opens while the person
 *     waits on the other end of the line.
 *   - Whoever was in copy shows up too. Indexing only the first recipient
 *     leaves the rest out, and they also ask questions.
 *   - A bounce belongs to an address, not to a message. When the provider
 *     webhooks arrive, marking the right row will be an UPDATE by address and
 *     not a correction of the data model.
 *
 * The second stores each message's detail — the SMTP dialogue with the
 * provider, if the site turned the extended log on — one row per message. It
 * is separate for three reasons: it has its own retention, shorter, because it
 * is the heaviest thing stored; it is not duplicated on a bulk send; and
 * turning it off is emptying a table rather than migrating a column.
 *
 * What the plugin never stores is the content of the messages. A log that
 * keeps bodies keeps password-reset links, and a reset link is not a record of
 * what happened: it is a key to the account, valid for whoever reads the table
 * next — the administrator, a backup, an exported database. There is no
 * setting for it, because the safe answer does not improve by being optional.
 *
 * The index is on the EMAIL ADDRESS and not on a user ID, on purpose: mail
 * goes to addresses that belong to no user — a contact form, a notice to a
 * shop's administrator — and people change their address. A person's profile
 * looks up their current one, and their previous ones if the site keeps them.
 *
 * On a network the tables are ONE for the whole network — on the base prefix
 * and with a site_id column — and not one per site. People belong to the
 * network, not to a site: somebody's profile has to show everything sent to
 * them from any site, and one table per site would mean walking all of them.
 *
 * The whole file talks to the database directly and without caching, and it
 * has to: these are custom tables the WordPress API knows nothing about, and
 * a log that is cached is a log that lies for as long as the cache lasts.
 * Table names go through prepare()'s %i placeholder — WordPress 6.2 — which
 * quotes them as identifiers: that is why the plugin's minimum is 6.2 and not
 * 6.0.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * The schema version.
 *
 * Bumped by hand when a CREATE TABLE below changes. It is what makes an
 * update over FTP or through git — which never fires activation — create the
 * new column anyway.
 */
const DILUXONE_MAIL_DB_VERSION = 3;

/**
 * The states a row can be in, with their human-readable names.
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
		// Nobody writes the two below yet: the bounce webhooks will. They are
		// on the list so the column already has room for them and the screen
		// knows what to call them.
		'bounced'     => __( 'Bounced', 'diluxone-mail' ),
		'complained'  => __( 'Marked as spam', 'diluxone-mail' ),
	);
}

/** The log table's name. Base prefix: one per network. */
function diluxone_mail_log_table(): string {
	global $wpdb;

	return $wpdb->base_prefix . 'diluxone_mail_log';
}

/** The name of the per-message detail table. */
function diluxone_mail_detail_table(): string {
	global $wpdb;

	return $wpdb->base_prefix . 'diluxone_mail_detail';
}

/**
 * Creates or updates the tables.
 *
 * It runs on activation and also on admin_init when the stored version has
 * gone stale. dbDelta() is picky about the SQL's formatting — two spaces
 * after PRIMARY KEY, lower-case types, one definition per line — and when
 * that is not respected it does not fail: it recreates the table on every
 * page load without saying a word. The formatting below is what it expects.
 */
function diluxone_mail_install(): void {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset = $wpdb->get_charset_collate();
	$log     = diluxone_mail_log_table();
	$detail  = diluxone_mail_detail_table();

	// 191 and not 255 on the indexed columns: in utf8mb4 each character takes
	// up to four bytes, and InnoDB's index on the versions still out there
	// does not go past 767. 191 x 4 = 764, which just fits. It is the same
	// arithmetic WordPress does on its own tables.
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
		transcript longtext NOT NULL,
		PRIMARY KEY  (message_id),
		KEY created_at (created_at)
	) {$charset};";

	dbDelta( $sql );
	dbDelta( $sql_detail );
	diluxone_mail_drop_bodies();

	// The version goes on the network when there is one: the tables belong to
	// the network.
	update_site_option( 'diluxone_mail_db_version', DILUXONE_MAIL_DB_VERSION );
}

/**
 * Removes the message bodies a previous version stored.
 *
 * Until version 3 of the schema the plugin could be told to keep the body of
 * every message. It no longer can, and dbDelta() does not drop columns: an
 * upgrade would leave the bodies sitting in the table for as long as the site
 * lives, which is precisely what the decision to stop storing them was about.
 * So the columns go, and with them everything that was in them.
 *
 * Detail rows that were only there to hold a body are deleted too. What is
 * left of them after the drop is an SMTP dialogue that is empty, which is not
 * a record of anything.
 */
function diluxone_mail_drop_bodies(): void {
	global $wpdb;

	$detail  = diluxone_mail_detail_table();
	$columns = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $detail, 'body%' ) );

	if ( array() !== $columns ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- A one-off migration of our own table; there is nothing to cache and dropping a column is the point.
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN body, DROP COLUMN body_type', $detail ) );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Same table, same migration.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE transcript = %s', $detail, '' ) );

	delete_option( 'diluxone_mail_log_body' );
	delete_site_option( 'diluxone_mail_log_body' );
}

/**
 * Creates the tables when the plugin was updated without going through
 * activation.
 *
 * An update over FTP, through git or from an automated deploy does not fire
 * register_activation_hook(). Without this the column the next version adds
 * does not exist on any of those sites, and the first send after updating
 * fails with an SQL error.
 */
function diluxone_mail_maybe_install(): void {
	if ( (int) get_site_option( 'diluxone_mail_db_version', 0 ) === DILUXONE_MAIL_DB_VERSION ) {
		return;
	}

	diluxone_mail_install();
}
add_action( 'admin_init', 'diluxone_mail_maybe_install' );

/**
 * Writes a message's rows: one per recipient.
 *
 * @param array<int, array<string, mixed>> $rows
 */
function diluxone_mail_log_insert( array $rows ): void {
	global $wpdb;

	foreach ( $rows as $row ) {
		$wpdb->insert(
			diluxone_mail_log_table(),
			array(
				'site_id'     => (int) ( $row['site_id'] ?? get_current_blog_id() ),
				'sent_at'     => (string) ( $row['sent_at'] ?? current_time( 'mysql', true ) ),
				'email'       => (string) $row['email'],
				'kind'        => (string) ( $row['kind'] ?? 'to' ),
				'from_email'  => (string) ( $row['from_email'] ?? '' ),
				'subject'     => (string) ( $row['subject'] ?? '' ),
				'status'      => (string) ( $row['status'] ?? 'pending' ),
				'error'       => (string) ( $row['error'] ?? '' ),
				'response'    => (string) ( $row['response'] ?? '' ),
				'provider'    => (string) ( $row['provider'] ?? '' ),
				'source'      => (string) ( $row['source'] ?? '' ),
				'headers'     => (string) ( $row['headers'] ?? '' ),
				'attachments' => (string) ( $row['attachments'] ?? '' ),
				'message_id'  => (string) ( $row['message_id'] ?? '' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}
}

/**
 * Changes the status of every row of a message.
 *
 * The error and the response go through diluxone_mail_redact() here and not
 * in the caller, because the caller is the one who forgets: the error text
 * comes from the server and may repeat the credential it rejected.
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
 * Log rows, filtered and paginated.
 *
 * @param array<string, mixed> $args
 *        emails:   array<string>  only these addresses (a person's profile).
 *        status:   string         only this status.
 *        search:   string         free text in subject or address.
 *        site_id:  int|null       only this site; null = the whole network.
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

	$table = diluxone_mail_log_table();
	$sql   = 'WHERE ' . implode( ' AND ', $where );

	// The table name goes through %i, which prepare() quotes as an
	// identifier. $sql is the only part built at run time, and it is built
	// exclusively from literals written above and placeholders: not one value
	// from anybody enters the string, they all travel in $params and
	// prepare() puts them in. The sniffs cannot follow that — they see an
	// implode() and warn — so they are switched off by name, with the reason.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i {$sql}", array_merge( array( $table ), $params ) ) );

	$params[] = $per_page;
	$params[] = ( $page - 1 ) * $per_page;

	$rows = $wpdb->get_results(
		$wpdb->prepare( "SELECT * FROM %i {$sql} ORDER BY sent_at DESC, id DESC LIMIT %d OFFSET %d", array_merge( array( $table ), $params ) ),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

	return array(
		'rows'  => is_array( $rows ) ? $rows : array(),
		'total' => $total,
	);
}

/**
 * One row, by its id.
 *
 * @return array<string, mixed>|null
 */
function diluxone_mail_log_get( int $id ): ?array {
	global $wpdb;

	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', diluxone_mail_log_table(), $id ), ARRAY_A );

	return is_array( $row ) ? $row : null;
}

/**
 * Every row of the same message: each recipient with their status.
 *
 * @return array<int, array<string, mixed>>
 */
function diluxone_mail_log_recipients_of( string $message_id ): array {
	global $wpdb;

	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE message_id = %s ORDER BY id ASC', diluxone_mail_log_table(), $message_id ), ARRAY_A );

	return is_array( $rows ) ? $rows : array();
}

/**
 * How many sends an address has. For a person's profile.
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
 * How many rows there are per status. For the status screen.
 *
 * @return array<string, int>
 */
function diluxone_mail_log_totals( ?int $site_id ): array {
	global $wpdb;

	$table = diluxone_mail_log_table();

	$rows = null === $site_id
		? $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i GROUP BY status', $table ), ARRAY_A )
		: $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i WHERE site_id = %d GROUP BY status', $table, $site_id ), ARRAY_A );

	$out = array();

	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$out[ (string) $row['status'] ] = (int) $row['n'];
	}

	return $out;
}

/**
 * Stores or completes a message's detail.
 *
 * Only the SMTP dialogue with the provider lives here. The content of the
 * message does not: a mail log that keeps bodies keeps password-reset links,
 * which are not a record of what happened but a key to the account, sitting
 * in the database for whoever reads it next.
 *
 * @param array{transcript?: string} $fields
 */
function diluxone_mail_detail_save( string $message_id, array $fields ): void {
	global $wpdb;

	if ( '' === $message_id ) {
		return;
	}

	$current = diluxone_mail_detail_get( $message_id );

	$wpdb->replace(
		diluxone_mail_detail_table(),
		array(
			'message_id' => $message_id,
			'created_at' => current_time( 'mysql', true ),
			'transcript' => diluxone_mail_redact( (string) ( $fields['transcript'] ?? $current['transcript'] ?? '' ) ),
		),
		array( '%s', '%s', '%s' )
	);
}

/**
 * A message's detail, if anything was stored.
 *
 * @return array{transcript: string}|null
 */
function diluxone_mail_detail_get( string $message_id ): ?array {
	global $wpdb;

	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT transcript FROM %i WHERE message_id = %s', diluxone_mail_detail_table(), $message_id ), ARRAY_A );

	if ( ! is_array( $row ) ) {
		return null;
	}

	return array(
		'transcript' => (string) $row['transcript'],
	);
}

/**
 * Deletes what has expired, according to the configured retention.
 *
 * The detail first and with its own, shorter date. And if nothing justifies
 * keeping it — the extended log is off — the whole table is emptied: a
 * dialogue captured while the checkbox was on has no business surviving
 * somebody turning it off.
 *
 * @return array{log: int, details: int}
 */
function diluxone_mail_log_purge(): array {
	global $wpdb;

	$log_days    = max( 1, (int) diluxone_mail_option( 'diluxone_mail_log_retention_days' ) );
	$detail_days = max( 1, (int) diluxone_mail_option( 'diluxone_mail_log_detail_retention_days' ) );
	$log         = diluxone_mail_log_table();
	$detail      = diluxone_mail_detail_table();
	$keeps_any   = (bool) diluxone_mail_option( 'diluxone_mail_log_extended' );

	$details = $keeps_any
		? (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $detail, gmdate( 'Y-m-d H:i:s', time() - $detail_days * DAY_IN_SECONDS ) ) )
		: (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $detail ) );

	$rows = (int) $wpdb->query(
		$wpdb->prepare( 'DELETE FROM %i WHERE sent_at < %s', $log, gmdate( 'Y-m-d H:i:s', time() - $log_days * DAY_IN_SECONDS ) )
	);

	return array(
		'log'     => $rows,
		'details' => $details,
	);
}

/**
 * Deletes everything belonging to one address. For the personal-data eraser.
 *
 * The detail of messages that went only to that person goes too; the detail
 * of a message that went to more people stays, because it belongs to the
 * other recipients as much as to them.
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

	$deleted = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE email = %s', $log, $email ) );

	return $deleted;
}
