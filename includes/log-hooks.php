<?php
/**
 * How each send gets into the log.
 *
 * A call to wp_mail() passes through four points, and there is a hook on
 * each one:
 *
 *   1. The `wp_mail` filter, with the arguments already built. That is where
 *      the send is recorded — with no outcome yet — and where the plugin's
 *      own `diluxone_mail_atts` filter sits, the point the branded template
 *      will go through one day. It is recorded BEFORE sending, on purpose:
 *      if PHP dies half way through, the row stays as "sending", which is the
 *      truth. Recording it afterwards loses precisely the sends that failed
 *      worst.
 *   2. `pre_wp_mail`, twice. First, to ask the `diluxone_mail_should_send`
 *      filter whether anybody wants to cancel this send — the preference
 *      centre and the suppression list of the future. And last of all, to see
 *      whether another plugin already cut the send short there: if the
 *      accumulated value is not null somebody answered, WordPress is going to
 *      return without sending, and neither of the two hooks below will fire.
 *   3. `wp_mail_succeeded`, which confirms PHPMailer handed it to the server.
 *   4. `wp_mail_failed`, with the error.
 *
 * The id tying the log rows to the detail and to the real email is a UUID of
 * our own, written into the message as its Message-ID as well. That is what
 * will let a bounce arriving over a webhook be matched to the right row: the
 * provider gives back the Message-ID, and the Message-ID is ours.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * The send currently in flight on this request.
 *
 * In practice wp_mail() is not reentrant, so a single slot is enough.
 *
 * @param array<string, mixed>|null $set   Store this.
 * @param bool                      $clear Empty it.
 * @return array<string, mixed>|null
 */
function diluxone_mail_current( ?array $set = null, bool $clear = false ): ?array {
	static $current = null;

	if ( $clear ) {
		$current = null;
	}

	if ( null !== $set ) {
		$current = $set;
	}

	return $current;
}

/**
 * The addresses in a recipient field.
 *
 * A call to wp_mail() accepts a comma-separated string, a list, and in the
 * headers the "Name <address>" form. The addresses are pulled out,
 * lower-cased and de-duplicated, which is how they are indexed.
 *
 * @param string|array<int, string> $raw
 * @return array<int, string>
 */
function diluxone_mail_parse_addresses( $raw ): array {
	$parts = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
	$out   = array();

	foreach ( $parts as $part ) {
		$part = trim( (string) $part );

		if ( '' === $part ) {
			continue;
		}

		if ( preg_match( '/<([^>]+)>/', $part, $m ) ) {
			$part = $m[1];
		}

		$part = strtolower( trim( $part ) );

		if ( is_email( $part ) ) {
			$out[ $part ] = $part;
		}
	}

	return array_values( $out );
}

/**
 * A send's recipients, with their kind.
 *
 * @param array<string, mixed> $atts
 * @return array<int, array{email: string, kind: string}>
 */
function diluxone_mail_recipients( array $atts ): array {
	$out = array();

	foreach ( diluxone_mail_parse_addresses( $atts['to'] ?? '' ) as $email ) {
		$out[] = array(
			'email' => $email,
			'kind'  => 'to',
		);
	}

	foreach ( diluxone_mail_header_lines( $atts['headers'] ?? '' ) as $line ) {
		if ( ! preg_match( '/^(cc|bcc):\s*(.+)$/i', $line, $m ) ) {
			continue;
		}

		foreach ( diluxone_mail_parse_addresses( $m[2] ) as $email ) {
			$out[] = array(
				'email' => $email,
				'kind'  => strtolower( $m[1] ),
			);
		}
	}

	return $out;
}

/**
 * The headers as a list of lines, however they arrive.
 *
 * @param string|array<int|string, string> $headers
 * @return array<int, string>
 */
function diluxone_mail_header_lines( $headers ): array {
	if ( is_array( $headers ) ) {
		$lines = array();

		foreach ( $headers as $key => $value ) {
			$lines[] = is_string( $key ) ? $key . ': ' . $value : (string) $value;
		}

		return $lines;
	}

	$lines = preg_split( '/\r\n|\r|\n/', (string) $headers );

	return array_values( array_filter( array_map( 'trim', false === $lines ? array() : $lines ) ) );
}

/**
 * The sender and content type the headers declare, if any.
 *
 * @param array<string, mixed> $atts
 * @return array{from: string, type: string}
 */
function diluxone_mail_header_meta( array $atts ): array {
	$from = '';
	$type = 'text/plain';

	foreach ( diluxone_mail_header_lines( $atts['headers'] ?? '' ) as $line ) {
		if ( preg_match( '/^from:\s*(.+)$/i', $line, $m ) ) {
			$from = diluxone_mail_parse_addresses( $m[1] )[0] ?? '';
		} elseif ( preg_match( '/^content-type:\s*([^;]+)/i', $line, $m ) ) {
			$type = strtolower( trim( $m[1] ) );
		}
	}

	return array(
		'from' => $from,
		'type' => $type,
	);
}

/**
 * Which plugin or theme originated the send.
 *
 * The call stack is walked and the first file that belongs neither to this
 * plugin nor to WordPress core is taken. Out comes the plugin's folder or the
 * theme's name, which is what you need in order to say "WooCommerce sent
 * this".
 */
function diluxone_mail_caller(): string {
	$plugins = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
	$themes  = trailingslashit( wp_normalize_path( get_theme_root() ) );
	$own     = wp_normalize_path( DILUXONE_MAIL_DIR );

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- This is not debugging: it is how we know who called wp_mail(), and it is capped at 40 frames with no arguments so it stays cheap.
	foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 40 ) as $frame ) {
		$file = wp_normalize_path( (string) ( $frame['file'] ?? '' ) );

		if ( '' === $file || 0 === strpos( $file, $own ) ) {
			continue;
		}

		if ( 0 === strpos( $file, $plugins ) ) {
			return 'plugin:' . (string) strtok( substr( $file, strlen( $plugins ) ), '/' );
		}

		if ( 0 === strpos( $file, $themes ) ) {
			return 'theme:' . (string) strtok( substr( $file, strlen( $themes ) ), '/' );
		}
	}

	return 'core';
}

/**
 * The send enters the log.
 *
 * It is also the extension point for transforming the message: the first
 * thing done is passing the arguments through `diluxone_mail_atts`, and
 * whatever comes out is what gets sent and what gets recorded.
 *
 * @param array<string, mixed> $atts
 * @return array<string, mixed>
 */
function diluxone_mail_capture( array $atts ): array {
	/**
	 * Filters wp_mail()'s arguments before sending and before recording them.
	 *
	 * This is the place to transform the body — a branded template, for
	 * instance — without touching whoever called wp_mail().
	 *
	 * @param array<string, mixed> $atts to, subject, message, headers, attachments.
	 */
	$atts = (array) apply_filters( 'diluxone_mail_atts', $atts );

	diluxone_mail_current( null, true );

	if ( ! (bool) diluxone_mail_option( 'diluxone_mail_log_enabled' ) ) {
		return $atts;
	}

	$uuid     = wp_generate_uuid4();
	$meta     = diluxone_mail_header_meta( $atts );
	$cfg      = diluxone_mail_config();
	$from     = '' !== $meta['from'] ? $meta['from'] : $cfg['from'];
	$extended = (bool) diluxone_mail_option( 'diluxone_mail_log_extended' );

	$attachments = array_map( 'basename', array_map( 'strval', (array) ( $atts['attachments'] ?? array() ) ) );
	$rows        = array();

	foreach ( diluxone_mail_recipients( $atts ) as $recipient ) {
		$rows[] = array(
			'email'       => $recipient['email'],
			'kind'        => $recipient['kind'],
			'from_email'  => $from,
			'subject'     => (string) ( $atts['subject'] ?? '' ),
			'status'      => 'pending',
			'provider'    => diluxone_mail_transport_active() ? $cfg['provider'] : 'observer',
			'connection'  => diluxone_mail_transport_active() ? diluxone_mail_active_id() : '',
			'source'      => diluxone_mail_caller(),
			'headers'     => $extended ? (string) wp_json_encode( diluxone_mail_header_lines( $atts['headers'] ?? '' ) ) : '',
			'attachments' => $extended ? (string) wp_json_encode( array_values( $attachments ) ) : '',
			'message_id'  => $uuid,
		);
	}

	if ( array() === $rows ) {
		return $atts;
	}

	diluxone_mail_log_insert( $rows );

	// The extended log stores the whole SMTP dialogue, and for that PHPMailer
	// has to be asked to narrate it from before it connects.
	if ( $extended ) {
		diluxone_mail_debug_buffer( null, true );
		diluxone_mail_debug_enabled( true );
	}

	diluxone_mail_current(
		array(
			'uuid'     => $uuid,
			'atts'     => $atts,
			'extended' => $extended,
		)
	);

	return $atts;
}
add_filter( 'wp_mail', 'diluxone_mail_capture', PHP_INT_MAX );

/**
 * Does anybody want to cancel this send?
 *
 * It runs first of all on pre_wp_mail. If the filter says not to send, false
 * is returned — WordPress reads that as "I already took care of it" — and the
 * row is left as suppressed, which is not the same as failed: nobody tried to
 * send it.
 *
 * @param mixed                $pre
 * @param array<string, mixed> $atts
 * @return mixed
 */
function diluxone_mail_maybe_suppress( $pre, array $atts ) {
	if ( null !== $pre ) {
		return $pre;
	}

	/**
	 * Filters whether a send should go out.
	 *
	 * Returning false cancels it and records it as suppressed. This is the
	 * place for a suppression list or a person's preferences.
	 *
	 * @param bool                 $send
	 * @param array<string, mixed> $atts
	 */
	if ( (bool) apply_filters( 'diluxone_mail_should_send', true, $atts ) ) {
		return null;
	}

	$current = diluxone_mail_current();

	if ( null !== $current ) {
		diluxone_mail_log_set_status( (string) $current['uuid'], 'suppressed' );
		diluxone_mail_current( null, true );
	}

	return false;
}
add_filter( 'pre_wp_mail', 'diluxone_mail_maybe_suppress', 1, 2 );

/**
 * Did another plugin cut the send short before PHPMailer?
 *
 * It runs last on pre_wp_mail. If the value is not null by this point,
 * somebody else answered and WordPress is not going to send anything itself.
 * The send is recorded as handed to that plugin: what happened afterwards is
 * something nobody here can know.
 *
 * @param mixed                $pre
 * @param array<string, mixed> $atts
 * @return mixed
 */
function diluxone_mail_watch_pre_wp_mail( $pre, array $atts ) {
	if ( null === $pre ) {
		return $pre;
	}

	// Our own API transport answers this hook too, and it is not another
	// plugin taking the mail away. It leaves a mark on the way out and this
	// consumes it, so a second send in the same request is judged on its own.
	if ( diluxone_mail_api_answered() ) {
		diluxone_mail_api_answered( false );

		return $pre;
	}

	$current = diluxone_mail_current();

	if ( null !== $current ) {
		diluxone_mail_log_set_status( (string) $current['uuid'], 'intercepted', '', diluxone_mail_pre_wp_mail_culprit() );
		diluxone_mail_current( null, true );
	}

	return $pre;
}
add_filter( 'pre_wp_mail', 'diluxone_mail_watch_pre_wp_mail', PHP_INT_MAX, 2 );

/**
 * The email's Message-ID is the log's id.
 *
 * Set last on phpmailer_init so nobody overwrites it. This is not touching
 * the transport: it is a header of the message, and it is the one providers
 * give back when they report a bounce.
 *
 * @param mixed $phpmailer Whatever the hook passes; the type is checked inside.
 */
function diluxone_mail_stamp_message_id( $phpmailer ): void {
	$current = diluxone_mail_current();

	if ( null === $current || ! $phpmailer instanceof PHPMailer\PHPMailer\PHPMailer ) {
		return;
	}

	$host = wp_parse_url( home_url(), PHP_URL_HOST );

	$phpmailer->MessageID = sprintf( '<%s@%s>', (string) $current['uuid'], is_string( $host ) && '' !== $host ? $host : 'localhost' );
}
add_action( 'phpmailer_init', 'diluxone_mail_stamp_message_id', PHP_INT_MAX );

/**
 * The last thing the server said, to store alongside the row.
 *
 * It is the provider's "250 OK queued as …", which carries their own queue
 * id.
 */
function diluxone_mail_last_smtp_reply(): string {
	global $phpmailer;

	// An API send has no SMTP dialogue; what it has is the status and body the
	// provider answered, which is the same kind of evidence and belongs in the
	// same column.
	$api = diluxone_mail_api_reply();

	if ( '' !== $api ) {
		diluxone_mail_api_reply( '' );

		return $api;
	}

	if ( ! $phpmailer instanceof PHPMailer\PHPMailer\PHPMailer ) {
		return '';
	}

	try {
		return trim( (string) $phpmailer->getSMTPInstance()->getLastReply() );
	} catch ( Throwable $e ) {
		return '';
	}
}

/**
 * Records what happened on the last send, for the status screen.
 *
 * Separate from the log because the log can be turned off and this cannot:
 * a site knowing whether its mail works is the bare minimum.
 */
function diluxone_mail_remember_result( bool $ok, string $error ): void {
	update_option(
		'diluxone_mail_last_result',
		array(
			'ok'       => $ok ? 1 : 0,
			'time'     => time(),
			'error'    => diluxone_mail_redact( $error ),
			'provider' => diluxone_mail_transport_active() ? diluxone_mail_config()['provider'] : 'observer',
		),
		false
	);
}

/**
 * Closes the in-flight send with its outcome.
 *
 * It is what "sent" and "failed" have in common: the rows' status, the SMTP
 * dialogue if the extended log asked for it, and letting go of the in-flight
 * send.
 */
function diluxone_mail_finish( string $status, string $error ): void {
	$current = diluxone_mail_current();

	if ( null === $current ) {
		return;
	}

	diluxone_mail_log_set_status( (string) $current['uuid'], $status, $error, diluxone_mail_last_smtp_reply() );

	if ( ! empty( $current['extended'] ) ) {
		diluxone_mail_detail_save( (string) $current['uuid'], array( 'transcript' => diluxone_mail_debug_buffer() ) );
	}

	diluxone_mail_current( null, true );
}

/** Handed to the server. */
function diluxone_mail_on_succeeded(): void {
	diluxone_mail_finish( 'sent', '' );
	diluxone_mail_remember_result( true, '' );
}
add_action( 'wp_mail_succeeded', 'diluxone_mail_on_succeeded' );

/**
 * It failed, and this is why.
 *
 * @param WP_Error $error
 */
function diluxone_mail_on_failed( WP_Error $error ): void {
	diluxone_mail_finish( 'failed', $error->get_error_message() );
	diluxone_mail_remember_result( false, $error->get_error_message() );
}
add_action( 'wp_mail_failed', 'diluxone_mail_on_failed' );
