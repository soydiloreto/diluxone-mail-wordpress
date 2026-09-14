<?php
/**
 * When the first provider will not send, the next one is asked.
 *
 * The list is the whole of it: the first sends, and what is under it is what
 * gets tried, in order, until one of them takes the message or there is nobody
 * left. No setting decides this, because the order already did.
 *
 * It hangs off `wp_mail_failed`, which is where both paths end up — the SMTP
 * one through WordPress, the API one through this plugin firing the same
 * action — so a retry is the same code whichever way the first attempt went
 * out. The error carries the message that failed, which is what makes sending
 * it again possible at all.
 *
 * Every attempt is a row in the log, on purpose. A message that went out
 * through the second provider after the first refused it is two events, and a
 * log that showed one of them would be hiding the reason the site has a second
 * provider configured.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is a retry already under way?
 *
 * Without this the second provider failing would call the handler again from
 * inside itself, and a site with a bad configuration would walk its list once
 * per level rather than once.
 *
 * @param bool|null $set
 */
function diluxone_mail_failing_over( ?bool $set = null ): bool {
	static $under_way = false;

	if ( null !== $set ) {
		$under_way = $set;
	}

	return $under_way;
}

/**
 * The message a failure carries, if it carries one that can be sent again.
 *
 * WordPress puts the arguments of the send in the error's data, and so does
 * this plugin's own API transport. Anything else — a WP_Error from somewhere
 * else on the same hook — is not a message and is left alone.
 *
 * @param WP_Error $error
 * @return array<string, mixed>|null
 */
function diluxone_mail_failed_message( WP_Error $error ): ?array {
	$data = $error->get_error_data();

	if ( ! is_array( $data ) || ! isset( $data['to'], $data['subject'] ) ) {
		return null;
	}

	return array(
		'to'          => $data['to'],
		'subject'     => (string) $data['subject'],
		'message'     => (string) ( $data['message'] ?? '' ),
		'headers'     => $data['headers'] ?? '',
		'attachments' => $data['attachments'] ?? array(),
	);
}

/**
 * Tries the next provider down the list.
 *
 * @param WP_Error $error
 */
function diluxone_mail_failover( WP_Error $error ): void {
	if ( diluxone_mail_failing_over() || ! diluxone_mail_transport_active() ) {
		return;
	}

	$next = diluxone_mail_fallback_id( diluxone_mail_active_id() );

	if ( '' === $next ) {
		return;
	}

	$message = diluxone_mail_failed_message( $error );

	if ( null === $message ) {
		return;
	}

	$was = diluxone_mail_active_id();

	diluxone_mail_failing_over( true );
	diluxone_mail_active_id( $next );

	/**
	 * Fires before a message is handed to the next provider on the list.
	 *
	 * @param string               $next    The provider about to be tried.
	 * @param string               $failed  The one that would not send it.
	 * @param array<string, mixed> $message The message being sent again.
	 */
	do_action( 'diluxone_mail_failover', $next, $was, $message );

	wp_mail( $message['to'], $message['subject'], $message['message'], $message['headers'], $message['attachments'] );

	// Back to where it was, whatever happened: the next send in this request
	// is a new message and belongs to the provider that is in charge, not to
	// the one a retry happened to end on.
	diluxone_mail_active_id( $was );
	diluxone_mail_failing_over( false );
}
add_action( 'wp_mail_failed', 'diluxone_mail_failover', 20 );
