<?php
/**
 * The providers that can be reached over HTTP instead of SMTP.
 *
 * A profile in providers.php describes an SMTP server. This describes the same
 * provider's API: where to post, how to authenticate, how to shape the message
 * and how to read back what went wrong. They are separate lists on purpose —
 * not every provider has an API worth using, and several have one that needs
 * request signing or an OAuth dance rather than a header, which is a different
 * kind of work and does not belong in the same table.
 *
 * What is here is the tier where a send is one header and one JSON body. SES
 * and Azure Communication Services sign every request; Microsoft 365 and Gmail
 * need OAuth with a consent screen. Those stay on SMTP until somebody asks for
 * them specifically.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * The API each provider exposes, keyed by the same slug as its SMTP profile.
 *
 * `build` turns a normalised message into the body that provider expects;
 * `error` turns its error response into a sentence. Both are named so a
 * provider can be added without touching the transport.
 *
 * @return array<string, array<string, mixed>>
 */
function diluxone_mail_api_providers(): array {
	$providers = array(
		'mailtrap_sending' => array(
			'send_url'   => 'https://send.api.mailtrap.io/api/send',
			'auth'       => 'bearer',
			'build'      => 'diluxone_mail_api_body_mailtrap',
			'error'      => 'diluxone_mail_api_error_mailtrap',
			'ok_status'  => array( 200 ),
			// A cheap authenticated call, used to tell a working key from a
			// wrong one before anything is stored. The same token authenticates
			// the whole Mailtrap API, not only the send endpoint.
			'verify_url' => 'https://mailtrap.io/api/accounts',
			'key_label'  => __( 'API token', 'diluxone-mail' ),
			'key_hint'   => __( 'The token of the sending domain, from Sending Domains → your domain → Integration. Not a token from the general API Tokens page, and not an Email Testing one.', 'diluxone-mail' ),
			'docs'       => 'https://docs.mailtrap.io/developers/email-api/introduction',
		),
	);

	/**
	 * Filters the providers reachable over HTTP.
	 *
	 * @param array<string, array<string, mixed>> $providers
	 */
	return (array) apply_filters( 'diluxone_mail_api_providers', $providers );
}

/**
 * One provider's API, or an empty array when it has none here.
 *
 * @return array<string, mixed>
 */
function diluxone_mail_api_provider( string $key ): array {
	return diluxone_mail_api_providers()[ $key ] ?? array();
}

/** Can this provider be reached over HTTP at all? */
function diluxone_mail_provider_has_api( string $key ): bool {
	return array() !== diluxone_mail_api_provider( $key );
}

/**
 * The message, in a shape no provider uses and all of them can be built from.
 *
 * WordPress hands over whatever the calling plugin felt like passing: headers
 * as a string or as a list, recipients comma-separated, the content type
 * hidden in a header. This is that, resolved once, so each provider's builder
 * is only a mapping and not a second parser.
 *
 * @param array<string, mixed> $atts
 * @return array<string, mixed>
 */
function diluxone_mail_api_message( array $atts ): array {
	$meta   = diluxone_mail_header_meta( $atts );
	$config = diluxone_mail_config();

	$from = diluxone_mail_from( '' !== $meta['from'] ? $meta['from'] : '' );
	$from = '' !== $from && is_email( $from ) ? $from : (string) $config['from'];

	$to  = array();
	$cc  = array();
	$bcc = array();

	foreach ( diluxone_mail_recipients( $atts ) as $recipient ) {
		${$recipient['kind']}[] = $recipient['email'];
	}

	$reply_to = array();
	$extra    = array();

	foreach ( diluxone_mail_header_lines( $atts['headers'] ?? '' ) as $line ) {
		if ( preg_match( '/^reply-to:\s*(.+)$/i', $line, $m ) ) {
			$reply_to = diluxone_mail_parse_addresses( $m[1] );
			continue;
		}

		// Everything the transport does not map itself travels as a header,
		// which is what keeps a plugin's own X- headers working over the API.
		if ( preg_match( '/^(x-[^:]+):\s*(.*)$/i', $line, $m ) ) {
			$extra[ $m[1] ] = $m[2];
		}
	}

	return array(
		'from'      => $from,
		'from_name' => (string) diluxone_mail_from_name( '' ),
		'to'        => $to,
		'cc'        => $cc,
		'bcc'       => $bcc,
		'reply_to'  => $reply_to[0] ?? '',
		'subject'   => (string) ( $atts['subject'] ?? '' ),
		'body'      => (string) ( $atts['message'] ?? '' ),
		'html'      => 'text/html' === $meta['type'],
		'headers'   => $extra,
		'files'     => diluxone_mail_api_attachments( $atts['attachments'] ?? array() ),
	);
}

/**
 * The attachments, read and encoded.
 *
 * An API send carries the file in the request, so what SMTP streamed from disk
 * has to fit in memory. A file that cannot be read is skipped rather than
 * failing the send: the message with one attachment missing is worth more than
 * no message, and the log records what was asked for.
 *
 * @param string|array<int, string> $attachments
 * @return array<int, array{name: string, type: string, content: string}>
 */
function diluxone_mail_api_attachments( $attachments ): array {
	$out = array();

	foreach ( (array) $attachments as $path ) {
		$path = (string) $path;

		if ( '' === $path || ! is_readable( $path ) ) {
			continue;
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file being attached to a message, not a remote request.

		if ( false === $contents ) {
			continue;
		}

		$out[] = array(
			'name'    => basename( $path ),
			// wp_check_filetype() answers false for a type it does not know,
			// and a provider will not take false as a MIME type.
			'type'    => '' !== (string) wp_check_filetype( $path )['type'] ? (string) wp_check_filetype( $path )['type'] : 'application/octet-stream',
			'content' => base64_encode( $contents ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The encoding every mail API asks attachments in.
		);
	}

	return $out;
}

/**
 * Mailtrap's body.
 *
 * @param array<string, mixed> $message
 * @return array<string, mixed>
 */
function diluxone_mail_api_body_mailtrap( array $message ): array {
	$body = array(
		'from'    => array_filter(
			array(
				'email' => $message['from'],
				'name'  => $message['from_name'],
			)
		),
		'to'      => array_map( static fn( string $email ): array => array( 'email' => $email ), $message['to'] ),
		'subject' => $message['subject'],
	);

	foreach ( array( 'cc', 'bcc' ) as $kind ) {
		if ( array() !== $message[ $kind ] ) {
			$body[ $kind ] = array_map( static fn( string $email ): array => array( 'email' => $email ), $message[ $kind ] );
		}
	}

	$body[ $message['html'] ? 'html' : 'text' ] = $message['body'];

	if ( '' !== $message['reply_to'] ) {
		$body['headers']['Reply-To'] = $message['reply_to'];
	}

	foreach ( $message['headers'] as $name => $value ) {
		$body['headers'][ $name ] = $value;
	}

	if ( array() !== $message['files'] ) {
		$body['attachments'] = array_map(
			static fn( array $file ): array => array(
				'filename' => $file['name'],
				'type'     => $file['type'],
				'content'  => $file['content'],
			),
			$message['files']
		);
	}

	return $body;
}

/**
 * What Mailtrap said went wrong.
 *
 * @param array<string, mixed> $decoded
 */
function diluxone_mail_api_error_mailtrap( array $decoded, string $raw ): string {
	$errors = $decoded['errors'] ?? ( $decoded['error'] ?? '' );

	if ( is_array( $errors ) ) {
		return implode( ' ', array_map( 'strval', $errors ) );
	}

	return '' !== (string) $errors ? (string) $errors : $raw;
}

/**
 * Does the provider recognise this key?
 *
 * Three answers, not two. A 401 or a 403 is the provider saying the key is
 * wrong, and that is worth refusing to store. Anything else that is not a
 * success — an endpoint that moved, a provider with nothing cheap to call — is
 * "cannot tell", and refusing to store a key over that would be inventing a
 * problem. The screen says which of the two happened.
 *
 * @return array{ok: bool, checked: bool, error: string}
 */
function diluxone_mail_api_verify( string $provider, string $key ): array {
	$api = diluxone_mail_api_provider( $provider );

	if ( '' === $key ) {
		return array(
			'ok'      => false,
			'checked' => true,
			'error'   => __( 'There is no key to check.', 'diluxone-mail' ),
		);
	}

	if ( ! isset( $api['verify_url'] ) ) {
		return array(
			'ok'      => true,
			'checked' => false,
			'error'   => '',
		);
	}

	$response = wp_remote_get(
		(string) $api['verify_url'],
		array(
			'headers' => array( 'Authorization' => 'Bearer ' . $key ),
			'timeout' => 15,
		)
	);

	if ( is_wp_error( $response ) ) {
		return array(
			'ok'      => true,
			'checked' => false,
			'error'   => $response->get_error_message(),
		);
	}

	$status = (int) wp_remote_retrieve_response_code( $response );

	if ( in_array( $status, array( 401, 403 ), true ) ) {
		return array(
			'ok'      => false,
			'checked' => true,
			'error'   => __( 'The provider does not recognise this key.', 'diluxone-mail' ),
		);
	}

	return array(
		'ok'      => true,
		'checked' => $status >= 200 && $status < 300,
		'error'   => '',
	);
}
