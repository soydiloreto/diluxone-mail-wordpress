<?php
/**
 * How DNS gets queried.
 *
 * Every lookup the diagnosis makes goes through here, for one reason: a lot
 * of hosts have dns_get_record() disabled, and without an alternative path
 * the feature does not work on half the market. So PHP's function is tried
 * first and, if it is missing or fails, it falls back to DNS-over-HTTPS
 * against a public resolver through wp_remote_get(). Cloudflare and Google
 * both serve the same DoH JSON format, so the endpoint is a setting.
 *
 * Results are cached. DNS does not change every time somebody opens the
 * dashboard, and each diagnosis is twenty lookups or more; there is a
 * revalidate button for when it did change.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * The record types that get queried, with their numeric DNS code.
 *
 * @return array<string, int>
 */
function diluxone_mail_dns_types(): array {
	return array(
		'A'     => 1,
		'CNAME' => 5,
		'MX'    => 15,
		'TXT'   => 16,
	);
}

/**
 * Can dns_get_record() be used on this host?
 *
 * Checking function_exists() is not enough: the function exists and is on
 * the disable_functions list, and calling it is a warning and a false.
 */
function diluxone_mail_dns_system_available(): bool {
	if ( ! function_exists( 'dns_get_record' ) ) {
		return false;
	}

	$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

	return ! in_array( 'dns_get_record', $disabled, true );
}

/**
 * The domain being diagnosed.
 *
 * The configured one if there is one; failing that, the transport's sender
 * domain, which is what shows up in the From header and what the mail is
 * judged by; and failing that, the site's.
 */
function diluxone_mail_dns_domain(): string {
	$configured = strtolower( trim( (string) diluxone_mail_option( 'diluxone_mail_dns_domain' ) ) );

	if ( '' !== $configured ) {
		return $configured;
	}

	$from = diluxone_mail_config()['from'];

	if ( '' !== $from && false !== strpos( $from, '@' ) ) {
		return strtolower( (string) substr( $from, (int) strpos( $from, '@' ) + 1 ) );
	}

	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	$host = is_string( $host ) ? strtolower( $host ) : '';

	return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
}

/**
 * A lookup through dns_get_record().
 *
 * @return array<int, string>|null null when the lookup could not be made.
 */
function diluxone_mail_dns_system( string $name, string $type ): ?array {
	if ( ! diluxone_mail_dns_system_available() ) {
		return null;
	}

	$codes = array(
		'A'     => DNS_A,
		'CNAME' => DNS_CNAME,
		'MX'    => DNS_MX,
		'TXT'   => DNS_TXT,
	);

	if ( ! isset( $codes[ $type ] ) ) {
		return null;
	}

	// The @ is deliberate: a domain that does not exist makes
	// dns_get_record() raise a warning on top of returning false, and the
	// warning is of no use to anybody.
	$records = @dns_get_record( $name, $codes[ $type ] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	if ( false === $records ) {
		return null;
	}

	$out = array();

	foreach ( $records as $record ) {
		switch ( $type ) {
			case 'TXT':
				// 'txt' comes with the chunks already joined; a long record
				// arrives split into 255-character strings and has to be seen
				// whole.
				$out[] = (string) ( $record['txt'] ?? implode( '', (array) ( $record['entries'] ?? array() ) ) );
				break;
			case 'CNAME':
				$out[] = strtolower( rtrim( (string) ( $record['target'] ?? '' ), '.' ) );
				break;
			case 'MX':
				$out[] = (int) ( $record['pri'] ?? 0 ) . ' ' . strtolower( rtrim( (string) ( $record['target'] ?? '' ), '.' ) );
				break;
			default:
				$out[] = (string) ( $record['ip'] ?? '' );
		}
	}

	return array_values( array_filter( $out, static fn( string $v ): bool => '' !== $v ) );
}

/**
 * A lookup over DNS-over-HTTPS.
 *
 * @return array<int, string>|null null when the lookup could not be made.
 */
function diluxone_mail_dns_doh( string $name, string $type ): ?array {
	$endpoint = (string) diluxone_mail_option( 'diluxone_mail_dns_doh_endpoint' );

	if ( '' === $endpoint ) {
		return null;
	}

	$response = wp_remote_get(
		add_query_arg(
			array(
				'name' => rawurlencode( $name ),
				'type' => $type,
			),
			$endpoint
		),
		array(
			'timeout' => 8,
			'headers' => array( 'Accept' => 'application/dns-json' ),
		)
	);

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}

	$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );

	if ( ! is_array( $json ) || ! isset( $json['Status'] ) ) {
		return null;
	}

	// 3 is NXDOMAIN: the name does not exist. That is a valid, empty answer,
	// not a failure of the resolver.
	if ( 3 === (int) $json['Status'] ) {
		return array();
	}

	if ( 0 !== (int) $json['Status'] ) {
		return null;
	}

	$code = diluxone_mail_dns_types()[ $type ];
	$out  = array();

	foreach ( (array) ( $json['Answer'] ?? array() ) as $record ) {
		if ( ! is_array( $record ) || (int) ( $record['type'] ?? 0 ) !== $code ) {
			continue;
		}

		$data = (string) ( $record['data'] ?? '' );

		if ( 'TXT' === $type ) {
			// It arrives with the quotes of zone-file syntax: "abc" "def".
			// They are stripped and the parts joined, which is what a mail
			// client does.
			$chunks = preg_split( '/"\s+"/', trim( $data, '"' ) );
			$data   = implode( '', array_map( 'stripcslashes', false === $chunks ? array() : $chunks ) );
		} elseif ( 'CNAME' === $type ) {
			$data = strtolower( rtrim( $data, '.' ) );
		} elseif ( 'MX' === $type ) {
			$data = strtolower( rtrim( $data, '.' ) );
		}

		if ( '' !== $data ) {
			$out[] = $data;
		}
	}

	return $out;
}

/**
 * One lookup, cached, through whichever path is available.
 *
 * @return array{records: array<int, string>, source: string, error: string}
 *         source: 'system', 'doh' or 'cache'; error: empty, or the reason.
 */
function diluxone_mail_dns_lookup( string $name, string $type ): array {
	$name = strtolower( rtrim( trim( $name ), '.' ) );
	$type = strtoupper( $type );
	$key  = 'diluxone_mail_dns_' . md5( $type . '|' . $name );

	$cached = get_site_transient( $key );

	if ( is_array( $cached ) && isset( $cached['records'] ) ) {
		return array(
			'records' => (array) $cached['records'],
			'source'  => 'cache',
			'error'   => '',
		);
	}

	$mode    = (string) diluxone_mail_option( 'diluxone_mail_dns_resolver' );
	$records = null;
	$source  = '';

	if ( 'doh' !== $mode ) {
		$records = diluxone_mail_dns_system( $name, $type );
		$source  = 'system';
	}

	if ( null === $records && 'system' !== $mode ) {
		$records = diluxone_mail_dns_doh( $name, $type );
		$source  = 'doh';
	}

	if ( null === $records ) {
		return array(
			'records' => array(),
			'source'  => $source,
			'error'   => __( 'The DNS could not be queried: dns_get_record() is unavailable and the DNS-over-HTTPS resolver did not answer.', 'diluxone-mail' ),
		);
	}

	$hours = max( 1, (int) diluxone_mail_option( 'diluxone_mail_dns_cache_hours' ) );

	set_site_transient( $key, array( 'records' => $records ), $hours * HOUR_IN_SECONDS );
	diluxone_mail_dns_remember_key( $key );

	return array(
		'records' => $records,
		'source'  => $source,
		'error'   => '',
	);
}

/**
 * Records which transients exist, so the button can empty all of them.
 *
 * WordPress cannot list transients by prefix without going straight to the
 * database, and with an object cache not even that works. A list is cheaper
 * and more honest.
 */
function diluxone_mail_dns_remember_key( string $key ): void {
	$keys = (array) get_site_option( 'diluxone_mail_dns_cache_keys', array() );

	if ( in_array( $key, $keys, true ) ) {
		return;
	}

	$keys[] = $key;

	update_site_option( 'diluxone_mail_dns_cache_keys', array_slice( $keys, -500 ) );
}

/** Empties the DNS cache. This is what the "revalidate" button does. */
function diluxone_mail_dns_flush(): void {
	foreach ( (array) get_site_option( 'diluxone_mail_dns_cache_keys', array() ) as $key ) {
		delete_site_transient( (string) $key );
	}

	delete_site_option( 'diluxone_mail_dns_cache_keys' );
	delete_site_transient( 'diluxone_mail_diagnosis_' . md5( diluxone_mail_dns_domain() ) );
}

/**
 * The TXT records of a name. A shortcut for the diagnosis.
 *
 * @return array<int, string>
 */
function diluxone_mail_dns_txt( string $name ): array {
	return diluxone_mail_dns_lookup( $name, 'TXT' )['records'];
}
