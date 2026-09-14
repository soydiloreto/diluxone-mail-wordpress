<?php
/**
 * Sending over the provider's HTTP API instead of SMTP.
 *
 * Two reasons this exists at all. Many hosts block outbound 587 and 465, and
 * on those SMTP simply does not work while HTTPS does. And when a send is
 * refused, an API answers with a sentence — the domain is not verified, the
 * sender is not allowed — where SMTP answers `535` and leaves you guessing.
 *
 * The cost is that PHPMailer is not involved, so `phpmailer_init` never runs
 * and the message has to be built here. And the send has to be answered on
 * `pre_wp_mail`, which is the hook this plugin detects other plugins using and
 * reports as an interception. So it does it in the open: the row records that
 * it went out over the API, and the observer never mistakes our own answer for
 * somebody else's.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Did the API transport answer this send?
 *
 * The watcher on `pre_wp_mail` runs after everybody and reads a non-null value
 * as "another plugin took the mail". Ours is not another plugin.
 *
 * @param bool|null $set
 */
function diluxone_mail_api_answered( ?bool $set = null ): bool {
	static $answered = false;

	if ( null !== $set ) {
		$answered = $set;
	}

	return $answered;
}

/** Which way this site sends: `api` or `smtp`. */
function diluxone_mail_transport_kind(): string {
	return 'api' === (string) diluxone_mail_option( 'diluxone_mail_transport' ) ? 'api' : 'smtp';
}

/**
 * Is this send going out over the API?
 *
 * Everything has to line up: the plugin is the transport at all, it is set to
 * the API, the provider has one, and there is a key. Any of those missing and
 * the send falls back to SMTP rather than failing — a half-configured API is
 * not a reason to stop delivering mail.
 */
function diluxone_mail_api_active(): bool {
	if ( ! diluxone_mail_transport_active() || 'api' !== diluxone_mail_transport_kind() ) {
		return false;
	}

	$provider = (string) diluxone_mail_config()['provider'];

	return diluxone_mail_provider_has_api( $provider ) && '' !== diluxone_mail_api_key();
}

/**
 * The API key: from the environment if it is there, decrypted if it is stored.
 *
 * Same precedence as the SMTP password, and for the same reason — the best
 * place for a credential is one the database never sees.
 */
function diluxone_mail_api_key(): string {
	return (string) diluxone_mail_config_value( 'api_key' )['value'];
}

/**
 * The request one provider wants for one message.
 *
 * @param array<string, mixed> $message
 * @return array{url: string, args: array<string, mixed>}
 */
function diluxone_mail_api_request( string $provider, array $message, string $key ): array {
	$api  = diluxone_mail_api_provider( $provider );
	$body = call_user_func( $api['build'], $message );

	$headers = array( 'Content-Type' => 'application/json' );

	switch ( (string) $api['auth'] ) {
		case 'bearer':
			$headers['Authorization'] = 'Bearer ' . $key;
			break;
		case 'basic':
			$headers['Authorization'] = 'Basic ' . base64_encode( $key ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- How HTTP Basic is spelled.
			break;
		default:
			// A provider whose auth is a header of its own: `api-key`,
			// `X-Postmark-Server-Token`. The name is the value of `auth`.
			$headers[ (string) $api['auth'] ] = $key;
			break;
	}

	return array(
		'url'  => (string) $api['send_url'],
		'args' => array(
			'headers' => $headers,
			'body'    => (string) wp_json_encode( $body ),
			'timeout' => max( 5, (int) diluxone_mail_option( 'diluxone_mail_timeout' ) ),
		),
	);
}

/**
 * Posts one message and says what happened.
 *
 * @param array<string, mixed> $atts
 * @return array{ok: bool, error: string, response: string}
 */
function diluxone_mail_api_deliver( array $atts ): array {
	$provider = (string) diluxone_mail_config()['provider'];
	$api      = diluxone_mail_api_provider( $provider );
	$message  = diluxone_mail_api_message( $atts );

	if ( '' === $message['from'] || ! is_email( $message['from'] ) ) {
		return array(
			'ok'       => false,
			'error'    => __( 'There is no valid From address to send with.', 'diluxone-mail' ),
			'response' => '',
		);
	}

	$request  = diluxone_mail_api_request( $provider, $message, diluxone_mail_api_key() );
	$response = wp_remote_post( $request['url'], $request['args'] );

	if ( is_wp_error( $response ) ) {
		return array(
			'ok'       => false,
			'error'    => $response->get_error_message(),
			'response' => '',
		);
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$raw    = (string) wp_remote_retrieve_body( $response );

	if ( in_array( $status, (array) $api['ok_status'], true ) ) {
		return array(
			'ok'       => true,
			'error'    => '',
			'response' => $status . ' ' . $raw,
		);
	}

	$decoded = json_decode( $raw, true );
	$said    = call_user_func( $api['error'], is_array( $decoded ) ? $decoded : array(), $raw );

	return array(
		'ok'       => false,
		/* translators: 1: HTTP status code, 2: what the provider answered */
		'error'    => sprintf( __( 'The provider refused the message (HTTP %1$d): %2$s', 'diluxone-mail' ), $status, $said ),
		'response' => $status . ' ' . $raw,
	);
}

/**
 * The send itself, answered on pre_wp_mail.
 *
 * By the time this runs the rows are already written: WordPress applies the
 * `wp_mail` filter first, and that is where this plugin records the send. So
 * there is nothing to capture here — only to finish. The two actions it fires
 * are the ones WordPress itself fires, so every listener, this plugin's own
 * included, sees an API send exactly as it sees an SMTP one.
 *
 * @param mixed                $pre
 * @param array<string, mixed> $atts
 * @return mixed
 */
function diluxone_mail_api_send( $pre, array $atts ) {
	if ( null !== $pre || ! diluxone_mail_api_active() ) {
		return $pre;
	}

	$result = diluxone_mail_api_deliver( $atts );

	diluxone_mail_api_answered( true );
	diluxone_mail_api_reply( $result['response'] );

	if ( $result['ok'] ) {
		// Core's own hooks on purpose, not prefixed ones: this is the same
		// event, and every listener — this plugin's own included — has to see
		// an API send exactly as it sees an SMTP one.
		do_action( 'wp_mail_succeeded', $atts ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- See above.

		return true;
	}

	$error = new WP_Error( 'diluxone_mail_api_failed', $result['error'], $atts );

	do_action( 'wp_mail_failed', $error ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own hook, for the reason above.

	return false;
}
add_filter( 'pre_wp_mail', 'diluxone_mail_api_send', 20, 2 );

/**
 * What the provider answered, for the log's response column.
 *
 * The SMTP path reads the server's last reply off PHPMailer. This is the same
 * column filled from the same place in the flow, with the HTTP status and the
 * body instead of `250 OK`.
 *
 * @param string|null $set
 */
function diluxone_mail_api_reply( ?string $set = null ): string {
	static $reply = '';

	if ( null !== $set ) {
		$reply = $set;
	}

	return $reply;
}
