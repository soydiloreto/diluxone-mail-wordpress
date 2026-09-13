<?php
/**
 * The global log screen and the detail of one message.
 *
 * It is the usual list, the one everybody has: by date, with a status filter
 * and a search box. It is not the differentiator — that one is on each
 * person's profile — and that is why it is a plain WP_List_Table.
 *
 * On a network, whoever administers the network can see every site's from
 * here with "all sites"; everybody else sees their own.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/** The screen: the list, or the detail of one row. */
function diluxone_mail_screen_log(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- It only picks which row to show; the menu grants the capability.
	$id = isset( $_GET['view'] ) ? absint( $_GET['view'] ) : 0;

	if ( $id > 0 ) {
		diluxone_mail_screen_log_detail( $id );
		return;
	}

	diluxone_mail_screen_open( __( 'Mail log', 'diluxone-mail' ) );

	require_once DILUXONE_MAIL_DIR . 'includes/log-list-table.php';

	$table = new DiluxOne_Mail_Log_Table();
	$table->prepare_items();

	diluxone_mail_view(
		'admin-log',
		array(
			'table'       => $table,
			'statuses'    => diluxone_mail_log_statuses(),
			'log_enabled' => (bool) diluxone_mail_option( 'diluxone_mail_log_enabled' ),
			'all_sites'   => is_multisite() && is_super_admin(),
		)
	);

	diluxone_mail_screen_close();
}

/** The detail of one row: every recipient of the message, and its body. */
function diluxone_mail_screen_log_detail( int $id ): void {
	$row = diluxone_mail_log_get( $id );

	diluxone_mail_screen_open( __( 'Message', 'diluxone-mail' ) );

	if ( null === $row ) {
		diluxone_mail_notice( __( 'That message is no longer in the log.', 'diluxone-mail' ), 'warning' );
		diluxone_mail_screen_close();
		return;
	}

	// A row from another site is not visible from this one, unless you
	// administer the network.
	if ( is_multisite() && ! is_super_admin() && (int) $row['site_id'] !== get_current_blog_id() ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'diluxone-mail' ) );
	}

	$headers     = json_decode( (string) $row['headers'], true );
	$attachments = json_decode( (string) $row['attachments'], true );

	diluxone_mail_view(
		'admin-log-detail',
		array(
			'row'         => $row,
			'recipients'  => diluxone_mail_log_recipients_of( (string) $row['message_id'] ),
			'detail'      => diluxone_mail_detail_get( (string) $row['message_id'] ),
			'headers'     => is_array( $headers ) ? array_map( 'strval', $headers ) : array(),
			'attachments' => is_array( $attachments ) ? array_map( 'strval', $attachments ) : array(),
			'statuses'    => diluxone_mail_log_statuses(),
			'resend_url'  => diluxone_mail_resend_url( $id ),
			'back_url'    => diluxone_mail_admin_url( 'diluxone-mail-log' ),
		)
	);

	diluxone_mail_screen_close();
}
