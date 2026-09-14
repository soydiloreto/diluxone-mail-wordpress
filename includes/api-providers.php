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
			'send_url'  => 'https://send.api.mailtrap.io/api/send',
			'auth'      => 'bearer',
			'build'     => 'diluxone_mail_api_body_mailtrap',
			'error'     => 'diluxone_mail_api_error_mailtrap',
			'ok_status' => array( 200 ),
			'domains'   => 'diluxone_mail_api_domains_mailtrap',
			'key_label' => __( 'API token', 'diluxone-mail' ),
			'key_hint'  => __( 'Sending Domains → your domain → Integration, on the API tab. Careful with the box beside it: the SMTP credentials shown there include a password that looks exactly like a token and is not one — that one works over SMTP, with the username "api", and nowhere else.', 'diluxone-mail' ),
			'docs'      => 'https://docs.mailtrap.io/developers/email-api/introduction',
		),
		'sendgrid'         => array(
			'send_url'  => 'https://api.sendgrid.com/v3/mail/send',
			'auth'      => 'bearer',
			'build'     => 'diluxone_mail_api_body_sendgrid',
			'error'     => 'diluxone_mail_api_error_common',
			'ok_status' => array( 202 ),
			'key_label' => __( 'API key', 'diluxone-mail' ),
			'key_hint'  => __( 'Settings → API Keys, with at least Mail Send permission. It is shown once when it is created and never again.', 'diluxone-mail' ),
			'docs'      => 'https://www.twilio.com/docs/sendgrid/api-reference/mail-send/mail-send',
		),
		'postmark'         => array(
			'send_url'  => 'https://api.postmarkapp.com/email',
			'auth'      => 'X-Postmark-Server-Token',
			'build'     => 'diluxone_mail_api_body_postmark',
			'error'     => 'diluxone_mail_api_error_common',
			'ok_status' => array( 200 ),
			'key_label' => __( 'Server token', 'diluxone-mail' ),
			'key_hint'  => __( 'The server\'s token, on its API Tokens tab. Not the account token, which manages servers and cannot send.', 'diluxone-mail' ),
			'docs'      => 'https://postmarkapp.com/developer/api/email-api',
		),
		'brevo'            => array(
			'send_url'  => 'https://api.brevo.com/v3/smtp/email',
			'auth'      => 'api-key',
			'build'     => 'diluxone_mail_api_body_brevo',
			'error'     => 'diluxone_mail_api_error_common',
			'ok_status' => array( 201 ),
			'key_label' => __( 'API key', 'diluxone-mail' ),
			'key_hint'  => __( 'SMTP & API → API keys. Not the SMTP password from the tab beside it, which only works over SMTP.', 'diluxone-mail' ),
			'docs'      => 'https://developers.brevo.com/reference/sendtransacemail',
		),
		'resend'           => array(
			'send_url'  => 'https://api.resend.com/emails',
			'auth'      => 'bearer',
			'build'     => 'diluxone_mail_api_body_resend',
			'error'     => 'diluxone_mail_api_error_common',
			'ok_status' => array( 200 ),
			'domains'   => 'diluxone_mail_api_domains_resend',
			'key_label' => __( 'API key', 'diluxone-mail' ),
			'key_hint'  => __( 'API Keys, with sending access. It begins with re_.', 'diluxone-mail' ),
			'docs'      => 'https://resend.com/docs/api-reference/emails/send-email',
		),
		'mailjet'          => array(
			'send_url'  => 'https://api.mailjet.com/v3.1/send',
			'auth'      => 'basic',
			'build'     => 'diluxone_mail_api_body_mailjet',
			'error'     => 'diluxone_mail_api_error_common',
			'ok_status' => array( 200 ),
			'key_label' => __( 'API key and secret', 'diluxone-mail' ),
			// Mailjet authenticates with a pair, and the plugin keeps one
			// credential per provider. Joined by a colon is how every HTTP
			// client spells a pair, and how Mailjet's own examples show it.
			'key_hint'  => __( 'Both, joined by a colon: the API key, then ":", then the secret key. Account Settings → API Key Management.', 'diluxone-mail' ),
			'docs'      => 'https://dev.mailjet.com/email/guides/send-api-v31/',
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
	if ( ! diluxone_mail_provider_has_api( $provider ) ) {
		return array(
			'ok'      => true,
			'checked' => false,
			'error'   => '',
		);
	}

	if ( '' === $key ) {
		return array(
			'ok'      => false,
			'checked' => true,
			'error'   => __( 'There is no key to check.', 'diluxone-mail' ),
		);
	}

	// The send endpoint itself, with a body that cannot send anything.
	// A separate "list my account" call looked tidier and was worse: a key
	// scoped to sending is refused there while working perfectly for mail, and
	// a plugin that reads that as a wrong key refuses to store a key that
	// works. Asking the endpoint that will carry the messages is the only
	// check whose answer means what it says — authentication is decided before
	// the payload is looked at, so an empty one is enough to ask the question.
	$request  = diluxone_mail_api_request( $provider, diluxone_mail_api_message( array() ), $key );
	$response = wp_remote_post(
		$request['url'],
		array_merge(
			$request['args'],
			array(
				'body'    => '{}',
				'timeout' => 15,
			)
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
			'error'   => diluxone_mail_api_key_advice( $key ),
		);
	}

	// Anything else got past the door. A 422 over an empty body is the
	// endpoint complaining about the message, which it only bothers to do for
	// a request it accepted.
	return array(
		'ok'      => true,
		'checked' => $status < 500,
		'error'   => '',
	);
}

/**
 * Why a key was refused, in the words of the mistake almost everybody makes.
 *
 * Providers show the API token and the SMTP password in the same panel, often
 * side by side and in the same shape, and only one of them opens the API. A
 * refusal that says "the provider does not recognise this key" is true and
 * useless: this says which credential to go back for.
 */
function diluxone_mail_api_key_advice( string $key ): string {
	if ( 1 === preg_match( '/^[0-9a-f]{32}$/i', $key ) ) {
		return __( 'The provider does not recognise this key. What was pasted has the shape of an SMTP password — thirty-two hexadecimal characters — and that is a different credential: it works over SMTP together with a username, never over the API. The API token is in the same panel, usually on a tab of its own.', 'diluxone-mail' );
	}

	return __( 'The provider does not recognise this key. Check that it is the API token of the sending domain, and not one from a testing or sandbox environment, which belongs to a different endpoint.', 'diluxone-mail' );
}

/**
 * The domains Mailtrap will actually accept mail from.
 *
 * Two calls: the accounts the token reaches, and each one's sending domains.
 * What comes back is not a yes or a no but three states worth telling apart —
 * verified, not verified yet, and the demo domain, which is verified and still
 * refuses everything once its allowance is spent. A screen that offers all
 * three equally is a screen that lets you pick the one that cannot send.
 *
 * @return array{ok: bool, domains: array<int, array{name: string, usable: bool, note: string}>, error: string}
 */
function diluxone_mail_api_domains_mailtrap( string $key ): array {
	$accounts = diluxone_mail_api_get( 'https://mailtrap.io/api/accounts', $key );

	if ( ! $accounts['ok'] ) {
		return array(
			'ok'      => false,
			'domains' => array(),
			'error'   => $accounts['error'],
		);
	}

	$domains = array();

	foreach ( (array) $accounts['data'] as $account ) {
		$id = (int) ( $account['id'] ?? 0 );

		if ( 0 === $id ) {
			continue;
		}

		$listed = diluxone_mail_api_get( 'https://mailtrap.io/api/accounts/' . $id . '/sending_domains', $key );

		if ( ! $listed['ok'] ) {
			continue;
		}

		// The list arrives bare on some accounts and wrapped in `data` on
		// others; both are the same list.
		$rows = isset( $listed['data']['data'] ) && is_array( $listed['data']['data'] ) ? $listed['data']['data'] : $listed['data'];

		foreach ( (array) $rows as $row ) {
			$name = (string) ( $row['domain_name'] ?? '' );

			if ( '' === $name ) {
				continue;
			}

			$verified   = (bool) ( $row['dns_verified'] ?? false );
			$compliance = (string) ( $row['compliance_status'] ?? '' );
			$demo       = (bool) ( $row['demo'] ?? false );

			$usable = $verified && 'demo_exhausted' !== $compliance;
			$note   = '';

			if ( ! $verified ) {
				$note = __( 'the DNS is not verified yet', 'diluxone-mail' );
			} elseif ( 'demo_exhausted' === $compliance ) {
				$note = __( 'demo domain, allowance spent', 'diluxone-mail' );
			} elseif ( $demo ) {
				$note = __( 'demo domain: it only delivers to the address the account was registered with', 'diluxone-mail' );
			}

			$domains[] = array(
				'name'   => $name,
				'usable' => $usable,
				'note'   => $note,
			);
		}
	}

	return array(
		'ok'      => true,
		'domains' => $domains,
		'error'   => '',
	);
}

/**
 * One authenticated GET, decoded.
 *
 * @return array{ok: bool, data: array<mixed>, error: string}
 */
function diluxone_mail_api_get( string $url, string $key ): array {
	$response = wp_remote_get(
		$url,
		array(
			'headers' => array( 'Authorization' => 'Bearer ' . $key ),
			'timeout' => 15,
		)
	);

	if ( is_wp_error( $response ) ) {
		return array(
			'ok'    => false,
			'data'  => array(),
			'error' => $response->get_error_message(),
		);
	}

	$status  = (int) wp_remote_retrieve_response_code( $response );
	$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

	if ( $status < 200 || $status >= 300 || ! is_array( $decoded ) ) {
		return array(
			'ok'    => false,
			'data'  => array(),
			/* translators: %d: HTTP status code */
			'error' => sprintf( __( 'The provider answered HTTP %d.', 'diluxone-mail' ), $status ),
		);
	}

	return array(
		'ok'    => true,
		'data'  => $decoded,
		'error' => '',
	);
}

/**
 * SendGrid's body.
 *
 * Recipients live inside a "personalization" rather than at the top level:
 * their model is one message sent in several versions, and a plain send is
 * the case where there is one version.
 *
 * @param array<string, mixed> $message
 * @return array<string, mixed>
 */
function diluxone_mail_api_body_sendgrid( array $message ): array {
	$to = array( 'to' => array_map( static fn( string $email ): array => array( 'email' => $email ), $message['to'] ) );

	foreach ( array( 'cc', 'bcc' ) as $kind ) {
		if ( array() !== $message[ $kind ] ) {
			$to[ $kind ] = array_map( static fn( string $email ): array => array( 'email' => $email ), $message[ $kind ] );
		}
	}

	$body = array(
		'personalizations' => array( $to ),
		'from'             => array_filter(
			array(
				'email' => $message['from'],
				'name'  => $message['from_name'],
			)
		),
		'subject'          => $message['subject'],
		'content'          => array(
			array(
				'type'  => $message['html'] ? 'text/html' : 'text/plain',
				'value' => $message['body'],
			),
		),
	);

	if ( '' !== $message['reply_to'] ) {
		$body['reply_to'] = array( 'email' => $message['reply_to'] );
	}

	if ( array() !== $message['headers'] ) {
		$body['headers'] = $message['headers'];
	}

	if ( array() !== $message['files'] ) {
		$body['attachments'] = array_map(
			static fn( array $file ): array => array(
				'filename'    => $file['name'],
				'type'        => $file['type'],
				'content'     => $file['content'],
				'disposition' => 'attachment',
			),
			$message['files']
		);
	}

	return $body;
}

/**
 * Postmark's body.
 *
 * Capitalised keys, recipients as one comma-separated string, and a message
 * stream that has to be named: a server can have several and a send with no
 * stream is refused.
 *
 * @param array<string, mixed> $message
 * @return array<string, mixed>
 */
function diluxone_mail_api_body_postmark( array $message ): array {
	$body = array(
		'From'          => '' === $message['from_name'] ? $message['from'] : $message['from_name'] . ' <' . $message['from'] . '>',
		'To'            => implode( ',', $message['to'] ),
		'Subject'       => $message['subject'],
		'MessageStream' => 'outbound',
	);

	$body[ $message['html'] ? 'HtmlBody' : 'TextBody' ] = $message['body'];

	$copias = array(
		'cc'  => 'Cc',
		'bcc' => 'Bcc',
	);

	foreach ( $copias as $kind => $key ) {
		if ( array() !== $message[ $kind ] ) {
			$body[ $key ] = implode( ',', $message[ $kind ] );
		}
	}

	if ( '' !== $message['reply_to'] ) {
		$body['ReplyTo'] = $message['reply_to'];
	}

	foreach ( $message['headers'] as $name => $value ) {
		$body['Headers'][] = array(
			'Name'  => $name,
			'Value' => $value,
		);
	}

	foreach ( $message['files'] as $file ) {
		$body['Attachments'][] = array(
			'Name'        => $file['name'],
			'Content'     => $file['content'],
			'ContentType' => $file['type'],
		);
	}

	return $body;
}

/**
 * Brevo's body.
 *
 * @param array<string, mixed> $message
 * @return array<string, mixed>
 */
function diluxone_mail_api_body_brevo( array $message ): array {
	$body = array(
		'sender'  => array_filter(
			array(
				'email' => $message['from'],
				'name'  => $message['from_name'],
			)
		),
		'to'      => array_map( static fn( string $email ): array => array( 'email' => $email ), $message['to'] ),
		'subject' => $message['subject'],
	);

	$body[ $message['html'] ? 'htmlContent' : 'textContent' ] = $message['body'];

	foreach ( array( 'cc', 'bcc' ) as $kind ) {
		if ( array() !== $message[ $kind ] ) {
			$body[ $kind ] = array_map( static fn( string $email ): array => array( 'email' => $email ), $message[ $kind ] );
		}
	}

	if ( '' !== $message['reply_to'] ) {
		$body['replyTo'] = array( 'email' => $message['reply_to'] );
	}

	if ( array() !== $message['headers'] ) {
		$body['headers'] = $message['headers'];
	}

	foreach ( $message['files'] as $file ) {
		$body['attachment'][] = array(
			'name'    => $file['name'],
			'content' => $file['content'],
		);
	}

	return $body;
}

/**
 * Resend's body.
 *
 * The sender is one string with the name in it, the way a mail header spells
 * it, rather than an object.
 *
 * @param array<string, mixed> $message
 * @return array<string, mixed>
 */
function diluxone_mail_api_body_resend( array $message ): array {
	$body = array(
		'from'    => '' === $message['from_name'] ? $message['from'] : $message['from_name'] . ' <' . $message['from'] . '>',
		'to'      => $message['to'],
		'subject' => $message['subject'],
	);

	$body[ $message['html'] ? 'html' : 'text' ] = $message['body'];

	foreach ( array( 'cc', 'bcc' ) as $kind ) {
		if ( array() !== $message[ $kind ] ) {
			$body[ $kind ] = $message[ $kind ];
		}
	}

	if ( '' !== $message['reply_to'] ) {
		$body['reply_to'] = $message['reply_to'];
	}

	if ( array() !== $message['headers'] ) {
		$body['headers'] = $message['headers'];
	}

	foreach ( $message['files'] as $file ) {
		$body['attachments'][] = array(
			'filename' => $file['name'],
			'content'  => $file['content'],
		);
	}

	return $body;
}

/**
 * Mailjet's body.
 *
 * A list of messages, always, even when there is one.
 *
 * @param array<string, mixed> $message
 * @return array<string, mixed>
 */
function diluxone_mail_api_body_mailjet( array $message ): array {
	$one = array(
		'From'    => array_filter(
			array(
				'Email' => $message['from'],
				'Name'  => $message['from_name'],
			)
		),
		'To'      => array_map( static fn( string $email ): array => array( 'Email' => $email ), $message['to'] ),
		'Subject' => $message['subject'],
	);

	$one[ $message['html'] ? 'HTMLPart' : 'TextPart' ] = $message['body'];

	$copias = array(
		'cc'  => 'Cc',
		'bcc' => 'Bcc',
	);

	foreach ( $copias as $kind => $key ) {
		if ( array() !== $message[ $kind ] ) {
			$one[ $key ] = array_map( static fn( string $email ): array => array( 'Email' => $email ), $message[ $kind ] );
		}
	}

	if ( '' !== $message['reply_to'] ) {
		$one['ReplyTo'] = array( 'Email' => $message['reply_to'] );
	}

	if ( array() !== $message['headers'] ) {
		$one['Headers'] = $message['headers'];
	}

	foreach ( $message['files'] as $file ) {
		$one['Attachments'][] = array(
			'ContentType'   => $file['type'],
			'Filename'      => $file['name'],
			'Base64Content' => $file['content'],
		);
	}

	return array( 'Messages' => array( $one ) );
}

/**
 * What a provider said went wrong, whichever way it spells it.
 *
 * Five providers, five shapes, and all of them a sentence somewhere under a
 * key whose name is a matter of taste. Rather than five readers that each know
 * one house style, this walks the response for the first string that reads
 * like an explanation — and falls back to the raw body, which is worse to read
 * and better than nothing.
 *
 * @param array<string, mixed> $decoded
 */
function diluxone_mail_api_error_common( array $decoded, string $raw ): string {
	$said = diluxone_mail_api_error_walk( $decoded );

	return array() === $said ? $raw : implode( ' ', array_unique( $said ) );
}

/**
 * Every sentence a decoded response carries, in the order it carries them.
 *
 * @param mixed $value
 * @return array<int, string>
 */
function diluxone_mail_api_error_walk( $value, int $depth = 0 ): array {
	if ( $depth > 6 || ! is_array( $value ) ) {
		return array();
	}

	$keys = array( 'message', 'Message', 'ErrorMessage', 'error', 'detail', 'Detail', 'ErrorCode' );
	$said = array();

	foreach ( $value as $key => $item ) {
		if ( is_string( $item ) && '' !== $item && in_array( (string) $key, $keys, true ) ) {
			$said[] = $item;
			continue;
		}

		$said = array_merge( $said, diluxone_mail_api_error_walk( $item, $depth + 1 ) );
	}

	return $said;
}

/**
 * The domains Resend will accept mail from.
 *
 * One call, and a status per domain: anything but `verified` is a domain whose
 * DNS is not finished, which the provider refuses to send from.
 *
 * @return array{ok: bool, domains: array<int, array{name: string, usable: bool, note: string}>, error: string}
 */
function diluxone_mail_api_domains_resend( string $key ): array {
	$listed = diluxone_mail_api_get( 'https://api.resend.com/domains', $key );

	if ( ! $listed['ok'] ) {
		return array(
			'ok'      => false,
			'domains' => array(),
			'error'   => $listed['error'],
		);
	}

	$domains = array();

	foreach ( (array) ( $listed['data']['data'] ?? array() ) as $row ) {
		$name = (string) ( $row['name'] ?? '' );

		if ( '' === $name ) {
			continue;
		}

		$status = (string) ( $row['status'] ?? '' );

		$domains[] = array(
			'name'   => $name,
			'usable' => 'verified' === $status,
			'note'   => 'verified' === $status
				? ''
				/* translators: %s: the status the provider reports for a domain */
				: sprintf( __( 'not ready: %s', 'diluxone-mail' ), '' === $status ? __( 'unknown', 'diluxone-mail' ) : $status ),
		);
	}

	return array(
		'ok'      => true,
		'domains' => $domains,
		'error'   => '',
	);
}
