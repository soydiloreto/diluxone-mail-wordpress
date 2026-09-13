<?php
/**
 * The history on each person's profile.
 *
 * This is the plugin's reason for existing. Every competitor shows one global
 * list of sends; here, opening a user in the dashboard shows what was sent to
 * them: date, subject, status and a resend button. It is the first thing
 * somebody on support should see when they are told "I never got the email".
 *
 * The lookup is by address, not by id: by their current one and by their
 * previous ones if the site keeps them — a plugin that records email changes
 * adds them through the `diluxone_mail_user_emails` filter. And on a network
 * it shows every site's, because the person belongs to the network.
 *
 * Whoever can edit that person can see it. It is not a separate capability:
 * if you can change their password, you can see what mail was sent to them.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * The addresses a person's history is looked up by.
 *
 * @return array<int, string>
 */
function diluxone_mail_user_emails( WP_User $user ): array {
	$emails = array( strtolower( (string) $user->user_email ) );

	/**
	 * Filters the addresses a person's history is looked up by.
	 *
	 * A plugin that keeps previous addresses adds them here.
	 *
	 * @param array<int, string> $emails
	 * @param WP_User            $user
	 */
	$emails = (array) apply_filters( 'diluxone_mail_user_emails', $emails, $user );

	return array_values( array_unique( array_filter( array_map( 'strtolower', array_map( 'strval', $emails ) ) ) ) );
}

/** The section on the profile. */
function diluxone_mail_user_profile_section( WP_User $user ): void {
	if ( ! current_user_can( 'edit_user', $user->ID ) ) {
		return;
	}

	$emails = diluxone_mail_user_emails( $user );
	$query  = diluxone_mail_log_query(
		array(
			'emails'   => $emails,
			'site_id'  => null,
			'per_page' => 25,
		)
	);

	diluxone_mail_view(
		'user-profile',
		array(
			'user'        => $user,
			'emails'      => $emails,
			'rows'        => $query['rows'],
			'total'       => $query['total'],
			'statuses'    => diluxone_mail_log_statuses(),
			'log_enabled' => (bool) diluxone_mail_option( 'diluxone_mail_log_enabled' ),
			'log_body'    => (bool) diluxone_mail_option( 'diluxone_mail_log_body' ),
			'multisite'   => is_multisite(),
			'log_url'     => current_user_can( 'manage_options' ) ? diluxone_mail_admin_url( 'diluxone-mail-log', array( 's' => $user->user_email ) ) : '',
		)
	);
}
add_action( 'show_user_profile', 'diluxone_mail_user_profile_section', 100 );
add_action( 'edit_user_profile', 'diluxone_mail_user_profile_section', 100 );

/**
 * Resends a message from the log to one of its recipients.
 *
 * The body is needed, and the body is only there if the site turned storing
 * it on. Without a body there is nothing to send, and that is said in those
 * words rather than sending an empty message.
 *
 * Attachments are not resent: they are paths to temporary files that no
 * longer exist. The message notes that they are missing.
 *
 * @return array{ok: bool, reason: string}
 */
function diluxone_mail_resend( int $log_id ): array {
	$row = diluxone_mail_log_get( $log_id );

	if ( null === $row ) {
		return array(
			'ok'     => false,
			'reason' => 'missing',
		);
	}

	$detail = diluxone_mail_detail_get( (string) $row['message_id'] );

	if ( null === $detail || '' === $detail['body'] ) {
		return array(
			'ok'     => false,
			'reason' => 'no-body',
		);
	}

	$headers = array( 'Content-Type: ' . $detail['body_type'] . '; charset=UTF-8' );

	if ( '' !== (string) $row['from_email'] && is_email( (string) $row['from_email'] ) ) {
		$headers[] = 'From: ' . (string) $row['from_email'];
	}

	$headers[] = 'X-DiluxOne-Mail-Resend-Of: ' . (string) $row['message_id'];

	$ok = wp_mail( (string) $row['email'], (string) $row['subject'], $detail['body'], $headers );

	return array(
		'ok'     => (bool) $ok,
		'reason' => $ok ? '' : 'failed',
	);
}

/** The resend button. */
function diluxone_mail_resend_action(): void {
	$id  = absint( $_GET['id'] ?? 0 );
	$row = diluxone_mail_log_get( $id );

	check_admin_referer( 'diluxone_mail_resend_' . $id );

	// An administrator can resend anything. Whoever can edit a person can
	// resend what is theirs: the same thing they see on the profile.
	$allowed = current_user_can( 'manage_options' );

	if ( ! $allowed && null !== $row ) {
		$user    = get_user_by( 'email', (string) $row['email'] );
		$allowed = $user instanceof WP_User && current_user_can( 'edit_user', $user->ID );
	}

	if ( ! $allowed ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'diluxone-mail' ) );
	}

	$result = diluxone_mail_resend( $id );
	$done   = $result['ok'] ? 'resent' : ( 'no-body' === $result['reason'] ? 'no-body' : 'resend-failed' );
	$back   = wp_get_referer();

	wp_safe_redirect( add_query_arg( 'diluxone_mail_done', $done, $back ? $back : diluxone_mail_admin_url( 'diluxone-mail-log' ) ) );
	exit;
}
add_action( 'admin_post_diluxone_mail_resend', 'diluxone_mail_resend_action' );

/** The URL of a row's resend button. */
function diluxone_mail_resend_url( int $log_id ): string {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'diluxone_mail_resend',
				'id'     => $log_id,
			),
			admin_url( 'admin-post.php' )
		),
		'diluxone_mail_resend_' . $log_id
	);
}

/**
 * The resend notice on a person's profile.
 *
 * The plugin's own screens show it through diluxone_mail_screen_open(); the
 * user profile belongs to WordPress and its notices have to be hooked.
 */
function diluxone_mail_profile_notices(): void {
	$screen = get_current_screen();

	if ( ! $screen instanceof WP_Screen || ! in_array( $screen->id, array( 'profile', 'user-edit' ), true ) ) {
		return;
	}

	diluxone_mail_done_notice();
}
add_action( 'admin_notices', 'diluxone_mail_profile_notices' );
