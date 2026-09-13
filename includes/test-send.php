<?php
/**
 * Sending a test message.
 *
 * What makes this button different is what it shows when it fails: the full
 * SMTP error and the whole dialogue with the server, collapsed. "535
 * Authentication credentials invalid" says what to fix; "something went
 * wrong" says nothing. The password is redacted before anything is shown.
 *
 * The dashboard button and the WP-CLI command both use it, which is why they
 * share the function below.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends a test message and reports what happened.
 *
 * @return array{ok: bool, error: string, transcript: string, seconds: float, to: string}
 */
function diluxone_mail_send_test( string $to ): array {
	$to = sanitize_email( $to );

	if ( '' === $to || ! is_email( $to ) ) {
		return array(
			'ok'         => false,
			'error'      => __( 'That is not a valid email address.', 'diluxone-mail' ),
			'transcript' => '',
			'seconds'    => 0.0,
			'to'         => $to,
		);
	}

	diluxone_mail_debug_buffer( null, true );
	diluxone_mail_debug_enabled( true );

	$error   = '';
	$capture = static function ( WP_Error $e ) use ( &$error ): void {
		$error = $e->get_error_message();
	};

	add_action( 'wp_mail_failed', $capture );

	$started = microtime( true );

	$ok = wp_mail(
		$to,
		sprintf(
			/* translators: %s: site name */
			__( 'Test message from %s', 'diluxone-mail' ),
			wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES )
		),
		sprintf(
			/* translators: 1: SMTP host, 2: profile name */
			__( "If this arrived, the site can send mail through %1\$s (%2\$s).\n\nSent by DiluxOne Mail.", 'diluxone-mail' ),
			diluxone_mail_transport_active() ? diluxone_mail_config()['host'] : __( 'another plugin\'s transport', 'diluxone-mail' ),
			diluxone_mail_transport_active() ? (string) diluxone_mail_provider( diluxone_mail_config()['provider'] )['name'] : __( 'observer mode', 'diluxone-mail' )
		)
	);

	$seconds = microtime( true ) - $started;

	remove_action( 'wp_mail_failed', $capture );
	diluxone_mail_debug_enabled( false );

	if ( ! $ok && '' === $error ) {
		$error = __( 'wp_mail() returned false without saying why. Another plugin may have intercepted the message.', 'diluxone-mail' );
	}

	return array(
		'ok'         => (bool) $ok,
		'error'      => diluxone_mail_redact( $error ),
		'transcript' => diluxone_mail_redact( diluxone_mail_debug_buffer() ),
		'seconds'    => round( $seconds, 2 ),
		'to'         => $to,
	);
}

/**
 * This person's last test result, once.
 *
 * It is kept in a short-lived transient and consumed when shown: the SMTP
 * dialogue has no business staying in the database.
 *
 * @return array<string, mixed>|null
 */
function diluxone_mail_test_result_take(): ?array {
	$key    = 'diluxone_mail_test_' . get_current_user_id();
	$result = get_transient( $key );

	if ( ! is_array( $result ) ) {
		return null;
	}

	delete_transient( $key );

	return $result;
}

/** The dashboard button. */
function diluxone_mail_test_action(): void {
	check_admin_referer( 'diluxone_mail_test' );

	$scope = diluxone_mail_posted_scope();

	diluxone_mail_settings_authorize( $scope );

	$to = sanitize_email( wp_unslash( $_POST['diluxone_mail_test_to'] ?? '' ) );

	if ( '' === $to ) {
		$to = (string) wp_get_current_user()->user_email;
	}

	$result = diluxone_mail_send_test( $to );

	set_transient( 'diluxone_mail_test_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );

	// A message that went out is the last of the four steps, and it is marked
	// against the configuration that sent it: change the server or the sender
	// and the step is open again, because what was proven was proven about
	// values that are no longer there.
	if ( $result['ok'] ) {
		diluxone_mail_verified( 'message' );
	}

	// The same button lives on the overview, and whoever pressed it there is
	// asking about the site, not about the settings screen.
	$from_overview = isset( $_POST['return'] ) && 'overview' === sanitize_key( wp_unslash( $_POST['return'] ) );

	if ( $from_overview ) {
		wp_safe_redirect( diluxone_mail_admin_url( DILUXONE_MAIL_MENU ) );
		exit;
	}

	diluxone_mail_settings_redirect( $scope, 'tested', 'test' );
}
add_action( 'admin_post_diluxone_mail_test', 'diluxone_mail_test_action' );
