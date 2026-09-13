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

	$stored = diluxone_mail_option_stored( $option );

	if ( 'default' !== $stored['scope'] && '' !== (string) $stored['value'] ) {
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
	$pass = diluxone_mail_config_value( 'pass' )['value'];

	if ( '' === $pass ) {
		return $text;
	}

	$user = diluxone_mail_config_value( 'user' )['value'];

	// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Nothing is being obfuscated: this is the format the password appears in inside the SMTP dialogue, and it has to be reproduced to find it and cover it.
	$forms = array(
		$pass,
		base64_encode( $pass ),
		base64_encode( "\0" . $user . "\0" . $pass ),
	);
	// phpcs:enable

	return str_replace( $forms, '***', $text );
}
