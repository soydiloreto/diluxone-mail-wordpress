<?php
/**
 * DMARC: which policy is in place, where the reports go, and what it implies.
 *
 * DMARC is what decides what the receiver does when SPF and DKIM are not
 * enough. With `p=none` it does nothing — it only reports; with
 * `p=quarantine` the mail goes to spam; with `p=reject` it does not arrive.
 * And for a message to pass DMARC it is not enough for SPF or DKIM to be
 * valid: one of the two has to be ALIGNED, that is, to talk about the same
 * domain that appears in the From header. That is what people do not know and
 * what this file explains.
 *
 * The other thing nobody looks at: if the reports go to an address on another
 * domain, that other domain has to authorise it with a record of its own
 * (RFC 7489, §7.1). If it does not publish one, the reports are discarded
 * without notice, and the domain's owner believes nobody is forging their
 * mail because nothing ever reaches them.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * A domain's DMARC record, parsed.
 *
 * @return array{
 *     record: string|null,
 *     policy: string,
 *     subdomain_policy: string,
 *     pct: int,
 *     adkim: string,
 *     aspf: string,
 *     rua: array<int, string>,
 *     ruf: array<int, string>,
 *     external: array<int, array{address: string, domain: string, authorized: bool}>,
 *     errors: array<int, string>
 * }
 */
function diluxone_mail_dmarc_analyse( string $domain ): array {
	$out = array(
		'record'           => null,
		'policy'           => '',
		'subdomain_policy' => '',
		'pct'              => 100,
		'adkim'            => 'r',
		'aspf'             => 'r',
		'rua'              => array(),
		'ruf'              => array(),
		'external'         => array(),
		'errors'           => array(),
	);

	$records = array();

	foreach ( diluxone_mail_dns_txt( '_dmarc.' . $domain ) as $txt ) {
		if ( preg_match( '/^v=DMARC1(\s|;|$)/i', trim( $txt ) ) ) {
			$records[] = trim( $txt );
		}
	}

	if ( array() === $records ) {
		return $out;
	}

	if ( count( $records ) > 1 ) {
		$out['errors'][] = __( 'There is more than one DMARC record. Receivers ignore all of them when that happens.', 'diluxone-mail' );
	}

	$out['record'] = $records[0];
	$tags          = array();

	foreach ( explode( ';', $records[0] ) as $pair ) {
		$pair = trim( $pair );

		if ( '' === $pair || false === strpos( $pair, '=' ) ) {
			continue;
		}

		list( $key, $value ) = explode( '=', $pair, 2 );

		$tags[ strtolower( trim( $key ) ) ] = trim( $value );
	}

	$out['policy']           = strtolower( (string) ( $tags['p'] ?? '' ) );
	$out['subdomain_policy'] = strtolower( (string) ( $tags['sp'] ?? $out['policy'] ) );
	$out['pct']              = isset( $tags['pct'] ) ? max( 0, min( 100, (int) $tags['pct'] ) ) : 100;
	$out['adkim']            = 's' === strtolower( (string) ( $tags['adkim'] ?? 'r' ) ) ? 's' : 'r';
	$out['aspf']             = 's' === strtolower( (string) ( $tags['aspf'] ?? 'r' ) ) ? 's' : 'r';

	if ( '' === $out['policy'] ) {
		$out['errors'][] = __( 'The record has no policy (p=). Without it the record is invalid and receivers ignore it.', 'diluxone-mail' );
	}

	foreach ( array( 'rua', 'ruf' ) as $tag ) {
		if ( ! isset( $tags[ $tag ] ) ) {
			continue;
		}

		foreach ( explode( ',', (string) $tags[ $tag ] ) as $uri ) {
			$uri = trim( $uri );

			if ( 0 !== stripos( $uri, 'mailto:' ) ) {
				continue;
			}

			// The !10m suffix is the report's maximum size, not part of the
			// address.
			$address = strtolower( (string) preg_replace( '/!.*$/', '', substr( $uri, 7 ) ) );

			if ( is_email( $address ) ) {
				$out[ $tag ][] = $address;
			}
		}
	}

	$out['external'] = diluxone_mail_dmarc_external_authorizations( $domain, array_unique( array_merge( $out['rua'], $out['ruf'] ) ) );

	return $out;
}

/**
 * Did the third-party domains receiving reports authorise it?
 *
 * For every address whose domain is not the record's own, there has to be a
 * `<domain>._report._dmarc.<report-domain>` record carrying `v=DMARC1`. It is
 * a record nobody remembers to publish and that, when missing, throws the
 * reports away.
 *
 * @param array<int, string> $addresses
 * @return array<int, array{address: string, domain: string, authorized: bool}>
 */
function diluxone_mail_dmarc_external_authorizations( string $domain, array $addresses ): array {
	$out = array();

	foreach ( $addresses as $address ) {
		$report_domain = strtolower( (string) substr( $address, (int) strrpos( $address, '@' ) + 1 ) );

		// The same domain, or a subdomain of it, needs no authorisation: the
		// standard treats them as the same organisation.
		if ( $report_domain === $domain || str_ends_with( $report_domain, '.' . $domain ) || str_ends_with( $domain, '.' . $report_domain ) ) {
			continue;
		}

		$authorized = false;

		foreach ( diluxone_mail_dns_txt( $domain . '._report._dmarc.' . $report_domain ) as $txt ) {
			if ( preg_match( '/^v=DMARC1/i', trim( $txt ) ) ) {
				$authorized = true;
				break;
			}
		}

		$out[] = array(
			'address'    => $address,
			'domain'     => $report_domain,
			'authorized' => $authorized,
		);
	}

	return $out;
}
