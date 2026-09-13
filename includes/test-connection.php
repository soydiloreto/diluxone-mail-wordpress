<?php
/**
 * Asking the SMTP server whether it accepts these credentials.
 *
 * This is the cheap half of the test message: open the session, upgrade to
 * TLS, authenticate, hang up. Nothing is sent and no mailbox is needed, which
 * is what makes it usable as a gate — the settings of a provider are only
 * stored once the server has confirmed they work, so the screen can never end
 * up showing a saved configuration that has never delivered anything.
 *
 * The second thing it produces is the fingerprint. A credential that was
 * verified and then edited is not verified any more, and there is no way to
 * know that from a boolean: the fingerprint is taken over the values that
 * decide whether a connection succeeds, so any edit to any of them invalidates
 * the verification on its own, without anybody having to remember to clear it.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * The values a successful connection depends on.
 *
 * The password takes part as a hash: this string is stored, and storing a
 * second readable copy of the credential to remember that it worked would be
 * a poor trade.
 *
 * @param array<string, mixed>|null $config Defaults to the effective configuration.
 */
function diluxone_mail_connection_fingerprint( ?array $config = null ): string {
	$config = null === $config ? diluxone_mail_config() : $config;

	return hash(
		'sha256',
		implode(
			"\0",
			array(
				(string) ( $config['host'] ?? '' ),
				(string) (int) ( $config['port'] ?? 0 ),
				(string) ( $config['encryption'] ?? '' ),
				(string) (int) ( $config['auth'] ?? 0 ),
				(string) ( $config['user'] ?? '' ),
				'' === (string) ( $config['pass'] ?? '' ) ? '' : hash( 'sha256', (string) $config['pass'] ),
			)
		)
	);
}

/**
 * What has been proven about the configuration in front of us.
 *
 * Two marks, each holding the fingerprint it was taken against: the server
 * answered, and a real message went out. They are separate because they prove
 * different things — a server that authenticates can still refuse the From
 * address — and because the second one costs a message and the first does not.
 *
 * @return array{connection: string, message: string, time: int}
 */
function diluxone_mail_verification(): array {
	$stored = is_network_admin()
		? get_site_option( 'diluxone_mail_verified', array() )
		: get_option( 'diluxone_mail_verified', array() );

	$stored = is_array( $stored ) ? $stored : array();

	return array(
		'connection' => (string) ( $stored['connection'] ?? '' ),
		'message'    => (string) ( $stored['message'] ?? '' ),
		'time'       => (int) ( $stored['time'] ?? 0 ),
	);
}

/**
 * Records that something was proven about the configuration as it stands now.
 *
 * @param string $what Either `connection` or `message`.
 */
function diluxone_mail_verified( string $what ): void {
	$stored          = diluxone_mail_verification();
	$stored[ $what ] = diluxone_mail_connection_fingerprint();
	$stored['time']  = time();

	if ( is_network_admin() ) {
		update_site_option( 'diluxone_mail_verified', $stored );
		return;
	}

	update_option( 'diluxone_mail_verified', $stored );
}

/** Did the server answer to the credentials that are stored right now? */
function diluxone_mail_connection_verified(): bool {
	$verification = diluxone_mail_verification();

	return '' !== $verification['connection'] && $verification['connection'] === diluxone_mail_connection_fingerprint();
}

/** Did a real message go out with the configuration that is stored right now? */
function diluxone_mail_test_passed(): bool {
	$verification = diluxone_mail_verification();

	return '' !== $verification['message'] && $verification['message'] === diluxone_mail_connection_fingerprint();
}

/**
 * The PHPMailer the connection test drives.
 *
 * Behind a filter so the tests can hand back one that answers without a
 * server. WordPress loads these classes only when wp_mail() runs, and this
 * function is reached from a settings screen, so they may not be in yet.
 *
 * Anything the filter returns that is not a PHPMailer is ignored rather than
 * handed on: the caller sets a dozen properties on it and calling that on
 * whatever came back would fail far from here.
 */
function diluxone_mail_connection_mailer(): PHPMailer\PHPMailer\PHPMailer {
	if ( ! class_exists( 'PHPMailer\PHPMailer\PHPMailer' ) ) {
		require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
		require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
	}

	$mailer = new PHPMailer\PHPMailer\PHPMailer( true );

	/**
	 * Filters the PHPMailer the connection test drives.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $mailer
	 */
	$filtered = apply_filters( 'diluxone_mail_connection_mailer', $mailer );

	/**
	 * Whatever a filter actually returned, which is not necessarily what it
	 * was asked for.
	 *
	 * @var mixed $filtered
	 */

	return $filtered instanceof PHPMailer\PHPMailer\PHPMailer ? $filtered : $mailer;
}

/**
 * Opens a session against the server and reports what happened.
 *
 * The configuration is passed in rather than read, because the point of this
 * is to try values that are not stored yet.
 *
 * @param array<string, mixed> $config Host, port, encryption, auth, user, pass, timeout, provider.
 * @return array{ok: bool, error: string, transcript: string, seconds: float}
 */
function diluxone_mail_test_connection( array $config ): array {
	$host = trim( (string) ( $config['host'] ?? '' ) );

	if ( '' === $host ) {
		return array(
			'ok'         => false,
			'error'      => __( 'There is no host to connect to. Choose a provider profile, or type the server\'s address.', 'diluxone-mail' ),
			'transcript' => '',
			'seconds'    => 0.0,
		);
	}

	$profile = diluxone_mail_provider( (string) ( $config['provider'] ?? '' ) );

	diluxone_mail_debug_buffer( null, true );

	$mailer = diluxone_mail_connection_mailer();

	$mailer->SMTPDebug   = 2;
	$mailer->Debugoutput = static function ( $str ): void {
		diluxone_mail_debug_buffer( (string) $str );
	};

	$mailer->isSMTP();
	$mailer->Host = $host;
	$mailer->Port = (int) ( $config['port'] ?? 0 ) > 0 ? (int) $config['port'] : 587;

	$encryption          = (string) ( $config['encryption'] ?? 'tls' );
	$mailer->SMTPSecure  = in_array( $encryption, array( 'tls', 'ssl' ), true ) ? $encryption : '';
	$mailer->SMTPAutoTLS = (bool) $profile['autotls'];
	$mailer->Timeout     = max( 5, (int) ( $config['timeout'] ?? 0 ) );

	$user = (string) ( $config['user'] ?? '' );
	$auth = (bool) $profile['auth'] && (bool) ( $config['auth'] ?? 0 ) && '' !== $user;

	$mailer->SMTPAuth = $auth;

	if ( $auth ) {
		$mailer->Username = $user;
		$mailer->Password = (string) ( $config['pass'] ?? '' );
	}

	$started = microtime( true );
	$error   = '';

	try {
		$ok = (bool) $mailer->smtpConnect();

		if ( ! $ok ) {
			$error = __( 'The server did not accept the connection and gave no reason. The dialogue below is everything it said.', 'diluxone-mail' );
		}
	} catch ( Exception $e ) {
		$ok    = false;
		$error = $e->getMessage();
	}

	$mailer->smtpClose();

	$seconds = microtime( true ) - $started;

	return array(
		'ok'         => $ok,
		'error'      => diluxone_mail_redact_secret( $error, (string) ( $config['pass'] ?? '' ), $user ),
		'transcript' => diluxone_mail_redact_secret( diluxone_mail_debug_buffer(), (string) ( $config['pass'] ?? '' ), $user ),
		'seconds'    => round( $seconds, 2 ),
	);
}
