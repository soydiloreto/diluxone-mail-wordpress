<?php
/**
 * The transport: it tells PHPMailer which server to use.
 *
 * It hooks phpmailer_init, after WordPress has built the message and before
 * it sends it. Exactly one thing is decided there — which server it leaves
 * through — and only if this plugin is in charge: in observer mode nothing is
 * touched, not even when the site has a host configured.
 *
 * The sender goes somewhere else, for a reason the E2E suite taught us:
 * WordPress validates the From address BEFORE phpmailer_init, and on a site
 * running on localhost its default sender — wordpress@localhost — fails that
 * validation and the send dies before anybody can fix it. The wp_mail_from
 * and wp_mail_from_name filters run before that validation, and they are also
 * the place WordPress provides for this.
 *
 * The SMTPDebug buffer lives here too. PHPMailer can narrate the whole
 * dialogue with the server — every command and every reply — and that is what
 * is needed when a send fails: "535 Authentication failed" says exactly what
 * is wrong, "something went wrong" says nothing. It is captured into a
 * buffer, the password is redacted, and it is shown collapsed under the test
 * button.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * The SMTP dialogue buffer for this request.
 *
 * @param string|null $line  A line to append, or null to only read.
 * @param bool        $reset Empty it first.
 */
function diluxone_mail_debug_buffer( ?string $line = null, bool $reset = false ): string {
	static $buffer = '';

	if ( $reset ) {
		$buffer = '';
	}

	if ( null !== $line ) {
		$buffer .= rtrim( $line ) . "\n";
	}

	return $buffer;
}

/**
 * Should the SMTP dialogue be captured on this request?
 *
 * Off unless somebody turns it on — the test button, the WP-CLI command, the
 * extended log. Capturing it always would mean keeping the dialogue of every
 * send on the site in memory only to show it to nobody.
 */
function diluxone_mail_debug_enabled( ?bool $set = null ): bool {
	static $on = false;

	if ( null !== $set ) {
		$on = $set;
	}

	return $on;
}

/**
 * Configures PHPMailer.
 *
 * @param mixed $phpmailer Whatever the hook passes; the type is checked inside.
 */
function diluxone_mail_phpmailer_init( $phpmailer ): void {
	if ( ! $phpmailer instanceof PHPMailer\PHPMailer\PHPMailer ) {
		return;
	}

	// The buffer is attached even when the transport belongs to somebody
	// else: testing a send in observer mode also has to show what happened.
	if ( diluxone_mail_debug_enabled() ) {
		$phpmailer->SMTPDebug   = 2;
		$phpmailer->Debugoutput = static function ( $str ): void {
			diluxone_mail_debug_buffer( (string) $str );
		};
	}

	if ( ! diluxone_mail_transport_active() ) {
		return;
	}

	$config = diluxone_mail_config();

	if ( '' === $config['host'] ) {
		return;
	}

	$profile = diluxone_mail_provider( $config['provider'] );

	$phpmailer->isSMTP();
	$phpmailer->Host = $config['host'];
	$phpmailer->Port = (int) $config['port'] > 0 ? (int) $config['port'] : 587;

	// 'none' is an empty string for PHPMailer. And autoTLS is separate from
	// the encryption setting on purpose: a local profile says "no encryption"
	// and also "do not try to upgrade even if the server offers it".
	$encryption             = $config['encryption'];
	$phpmailer->SMTPSecure  = in_array( $encryption, array( 'tls', 'ssl' ), true ) ? $encryption : '';
	$phpmailer->SMTPAutoTLS = (bool) $profile['autotls'];

	// A password is as necessary as a username. Authenticating with an empty
	// one is not a login attempt that fails, it is a malformed exchange: the
	// server reads the blank line as another command and answers something
	// about syntax, which sends whoever reads the transcript looking for a
	// problem in the wrong place.
	$auth = (bool) $profile['auth']
		&& (bool) diluxone_mail_option( 'diluxone_mail_auth' )
		&& '' !== $config['user']
		&& '' !== $config['pass'];

	$phpmailer->SMTPAuth = $auth;

	if ( $auth ) {
		$phpmailer->Username = $config['user'];
		$phpmailer->Password = $config['pass'];
	}

	$phpmailer->Timeout = max( 5, (int) diluxone_mail_option( 'diluxone_mail_timeout' ) );
}
add_action( 'phpmailer_init', 'diluxone_mail_phpmailer_init', 999 );

/**
 * Is the sender this send carries the one WordPress puts in by default?
 *
 * WordPress signs as wordpress@thedomain unless told otherwise. That address
 * usually does not exist, many providers reject it as unverified, and on a
 * site running on localhost it is not even a valid address.
 */
function diluxone_mail_is_default_from( string $from ): bool {
	return '' === $from || 0 === strpos( $from, 'wordpress@' );
}

/**
 * The sender address.
 *
 * If the site configured one, it is used when the send carries the WordPress
 * default and — if "force" is on — also when it carries another. Only while
 * this plugin is in charge of the transport: in observer mode the mail
 * belongs to the other plugin and nothing about it is changed.
 */
function diluxone_mail_from( string $from ): string {
	if ( ! diluxone_mail_transport_active() ) {
		return $from;
	}

	$configured = diluxone_mail_config()['from'];

	if ( '' === $configured || ! is_email( $configured ) ) {
		return $from;
	}

	if ( diluxone_mail_is_default_from( $from ) || (bool) diluxone_mail_option( 'diluxone_mail_force_from' ) ) {
		return $configured;
	}

	return $from;
}
add_filter( 'wp_mail_from', 'diluxone_mail_from', 999 );

/**
 * The sender name, by the same rule.
 *
 * WordPress's default name is "WordPress", literally. It changes together
 * with the address: a configured name without a configured address says
 * nothing.
 */
function diluxone_mail_from_name( string $name ): string {
	if ( ! diluxone_mail_transport_active() ) {
		return $name;
	}

	$config = diluxone_mail_config();

	if ( '' === $config['from'] || '' === $config['from_name'] ) {
		return $name;
	}

	if ( 'WordPress' === $name || '' === $name || (bool) diluxone_mail_option( 'diluxone_mail_force_from' ) ) {
		return $config['from_name'];
	}

	return $name;
}
add_filter( 'wp_mail_from_name', 'diluxone_mail_from_name', 999 );
