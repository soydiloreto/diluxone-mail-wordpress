<?php
/**
 * Where each transport value comes from.
 *
 * The whole idea of the plugin in one sentence: production credentials live
 * in the hosting's variables and never touch the database or the repository.
 * The same code serves the local machine and production without anybody
 * going into the dashboard to change something on every deploy.
 *
 * Hence these layers, highest to lowest:
 *
 *   1. A PHP constant defined in wp-config.php          DILUXONE_MAIL_HOST
 *   2. An environment variable with the same name       DILUXONE_MAIL_HOST
 *   3. The site option, editable from the dashboard     diluxone_mail_host
 *   4. On a network, the network option                 diluxone_mail_host
 *
 * The top two layers describe one provider, and a site now keeps a list of
 * them. They apply to the one at the top of that list — the one that sends —
 * and to nothing underneath it. There is only one DILUXONE_MAIL_PASS, and
 * letting it answer for every record would have the fallback authenticate
 * with the credential of the provider that just refused the message: the one
 * configuration that cannot work, applied precisely when the site is relying
 * on it.
 *
 * And hence every read also returns where the value came from: the form needs
 * it to show the read-only control with the "defined by the environment"
 * note, and saving needs it so as not to write a credential into the database
 * that is not going to be used anyway.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * The transport fields, and what each one is called in each layer.
 *
 * The key is the short name the rest of the plugin uses; the value is the
 * suffix, which serves both purposes: `DILUXONE_MAIL_` + suffix gives the
 * constant and the environment variable, and `diluxone_mail_` + the suffix in
 * lower case gives the option.
 *
 * @return array<string, string>
 */
function diluxone_mail_config_fields(): array {
	return array(
		'provider'   => 'PROVIDER',
		'host'       => 'HOST',
		'port'       => 'PORT',
		'user'       => 'USER',
		'pass'       => 'PASS',
		'api_key'    => 'API_KEY',
		'encryption' => 'ENCRYPTION',
		'from'       => 'FROM',
		'from_name'  => 'FROM_NAME',
	);
}

/**
 * One transport value, with its provenance.
 *
 * @param string $field One of the keys of diluxone_mail_config_fields().
 * @return array{value: string, source: string, origin: string}
 *         source: 'constant', 'env', 'site', 'network' or 'default'.
 *         origin: the concrete name of the constant, variable or option.
 */
function diluxone_mail_config_value( string $field ): array {
	$fields = diluxone_mail_config_fields();

	if ( ! isset( $fields[ $field ] ) ) {
		return array(
			'value'  => '',
			'source' => 'default',
			'origin' => '',
		);
	}

	$suffix   = $fields[ $field ];
	$constant = 'DILUXONE_MAIL_' . $suffix;
	$option   = 'diluxone_mail_' . strtolower( $suffix );

	if ( ! diluxone_mail_config_environment_applies( $option ) ) {
		$stored = diluxone_mail_option_stored( $option );

		return diluxone_mail_config_stored( $field, $option, $stored );
	}

	if ( defined( $constant ) ) {
		return array(
			'value'  => (string) constant( $constant ),
			'source' => 'constant',
			'origin' => $constant,
		);
	}

	// getenv() returns false when the variable is not defined, and an empty
	// string when it is defined and empty. An empty variable is the same as
	// not having one: whoever exports DILUXONE_MAIL_HOST= is not configuring
	// an empty host, they left the line half written.
	$from_env = getenv( $constant );

	if ( is_string( $from_env ) && '' !== $from_env ) {
		return array(
			'value'  => $from_env,
			'source' => 'env',
			'origin' => $constant,
		);
	}

	return diluxone_mail_config_stored( $field, $option, diluxone_mail_option_stored( $option ) );
}

/**
 * Does the environment get to answer for the provider being read?
 *
 * There is one constant per field and a list of providers, so the constants
 * belong to the one at the top: every other record is an ordinary stored
 * configuration, editable on its own screen and used as written. Until a site
 * has a list at all — a fresh install, or one whose migration has not run —
 * the question does not arise and the environment answers as it always did.
 *
 * Only fields that describe a provider are affected. The rest do not live in
 * the list in the first place.
 */
function diluxone_mail_config_environment_applies( string $option_key ): bool {
	if ( ! diluxone_mail_is_connection_field( $option_key ) ) {
		return true;
	}

	$connections = diluxone_mail_connections();

	if ( array() === $connections ) {
		return true;
	}

	return diluxone_mail_active_id() === diluxone_mail_default_id();
}

/**
 * The value as the database holds it, with its provenance.
 *
 * @param array{value: mixed, scope: string} $stored
 * @return array{value: string, source: string, origin: string}
 */
function diluxone_mail_config_stored( string $field, string $option, array $stored ): array {
	if ( 'default' !== $stored['scope'] && '' !== (string) $stored['value'] ) {
		// The credential is kept encrypted, so what comes out of the option is
		// not usable as it is. A value that cannot be decrypted — rotated
		// salts, a truncated copy — answers as an empty password on purpose:
		// every caller already handles "there is none", and the settings
		// screen asks for it again rather than letting a send fail with an
		// authentication error that explains nothing.
		if ( 'pass' === $field || 'api_key' === $field ) {
			$plain = diluxone_mail_stored_password( (string) $stored['value'] );

			return array(
				'value'  => null === $plain ? '' : $plain,
				'source' => null === $plain ? 'unreadable' : $stored['scope'],
				'origin' => $option,
			);
		}

		return array(
			'value'  => (string) $stored['value'],
			'source' => $stored['scope'],
			'origin' => $option,
		);
	}

	$defaults = diluxone_mail_option_defaults();

	return array(
		'value'  => (string) ( $defaults[ $option ] ?? '' ),
		'source' => 'default',
		'origin' => $option,
	);
}

/**
 * Every transport value, already resolved.
 *
 * @return array<string, string>
 */
function diluxone_mail_config(): array {
	$config = array();

	foreach ( array_keys( diluxone_mail_config_fields() ) as $field ) {
		$config[ $field ] = diluxone_mail_config_value( $field )['value'];
	}

	/**
	 * Filters the resolved transport configuration.
	 *
	 * @param array<string, string> $config
	 */
	return apply_filters( 'diluxone_mail_config', $config );
}

/**
 * Does the environment provide this option, and therefore it must not be
 * stored?
 *
 * Used by the form's save routine. It takes the option name rather than the
 * field name because that is what it has at hand while walking the POST.
 */
function diluxone_mail_option_from_environment( string $option_key ): bool {
	foreach ( diluxone_mail_config_fields() as $field => $suffix ) {
		if ( 'diluxone_mail_' . strtolower( $suffix ) !== $option_key ) {
			continue;
		}

		return in_array( diluxone_mail_config_value( $field )['source'], array( 'constant', 'env' ), true );
	}

	return false;
}

/**
 * Redacts the password from any text about to leave the server.
 *
 * Used in the log, on the status screen, in the SMTPDebug dump and in every
 * export. It looks for the literal value and replaces it; deliberately not
 * printing it is not enough, because whoever prints it by accident is always
 * somebody else — an error trace, the server's dialogue.
 *
 * The second thing it replaces is the password in base64, and that is the
 * case that really matters: the SMTP dialogue does not send the password in
 * the clear, it sends `AUTH LOGIN` and then the user and the password
 * encoded. Redacting only the literal leaves the whole credential in plain
 * sight in the dump, on a line anybody decodes in a second. Same with AUTH
 * PLAIN, which encodes user and password together on a single line.
 */
function diluxone_mail_redact( string $text ): string {
	return diluxone_mail_redact_secret(
		$text,
		(string) diluxone_mail_config_value( 'pass' )['value'],
		(string) diluxone_mail_config_value( 'user' )['value']
	);
}

/**
 * The same, over a credential that is not the stored one.
 *
 * The connection test tries values that have not been saved yet — that is the
 * point of it — so the password to cover is the one that was typed, not the
 * one in the database.
 */
function diluxone_mail_redact_secret( string $text, string $pass, string $user ): string {
	if ( '' === $pass ) {
		return $text;
	}

	// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Nothing is being obfuscated: this is the format the password appears in inside the SMTP dialogue, and it has to be reproduced to find it and cover it.
	$forms = array(
		$pass,
		base64_encode( $pass ),
		base64_encode( "\0" . $user . "\0" . $pass ),
	);
	// phpcs:enable

	return str_replace( $forms, '***', $text );
}
