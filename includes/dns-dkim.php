<?php
/**
 * DKIM: which selectors are published.
 *
 * They cannot be enumerated over DNS: a selector is a name only whoever
 * published it knows. So a list is probed — the ones the big providers use,
 * the ones the active provider profile declares, and the ones the
 * administrator adds — and the existing ones are reported.
 *
 * A selector not showing up does not prove there is no DKIM: it proves it is
 * not on the list. The screen says so in those words.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * The selectors the most common providers use.
 *
 * @return array<int, string>
 */
function diluxone_mail_dkim_known_selectors(): array {
	return array( 'selector1', 'selector2', 'google', 's1', 's2', 'mailjet', 'k1', 'mail', 'default', 'dkim', 'resend' );
}

/**
 * Every selector to probe for the active provider.
 *
 * @return array<int, string>
 */
function diluxone_mail_dkim_selectors( string $provider ): array {
	$profile = diluxone_mail_provider( $provider );
	$list    = array_merge(
		diluxone_mail_dkim_known_selectors(),
		array_map( 'strval', (array) $profile['dkim_selectors'] ),
		array_map( 'strval', (array) diluxone_mail_option( 'diluxone_mail_dns_selectors' ) )
	);

	$list = array_filter( array_map( 'sanitize_key', $list ) );

	return array_values( array_unique( $list ) );
}

/**
 * How many bits the key of a DKIM record has.
 *
 * Estimated from the length of the public key in DER: a 1024-bit RSA key
 * takes about 162 bytes, a 2048-bit one about 294. There is no need to parse
 * the ASN.1 to tell them apart, and telling them apart is what matters: 1024
 * is already considered weak and Gmail scores it down.
 */
function diluxone_mail_dkim_key_bits( string $p ): int {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- This is the format the standard publishes the key in; there is no other way to measure it.
	$der = base64_decode( $p, true );

	if ( false === $der ) {
		return 0;
	}

	$length = strlen( $der );

	if ( $length >= 500 ) {
		return 4096;
	}

	if ( $length >= 250 ) {
		return 2048;
	}

	if ( $length >= 120 ) {
		return 1024;
	}

	return 0;
}

/**
 * Probes a domain's selectors.
 *
 * @param array<int, string> $selectors
 * @return array<int, array{selector: string, found: bool, via: string, cname: string, record: string, bits: int, revoked: bool}>
 */
function diluxone_mail_dkim_probe( string $domain, array $selectors ): array {
	$out = array();

	foreach ( $selectors as $selector ) {
		$name   = $selector . '._domainkey.' . $domain;
		$cname  = diluxone_mail_dns_lookup( $name, 'CNAME' )['records'];
		$txt    = diluxone_mail_dns_txt( $name );
		$record = '';

		foreach ( $txt as $t ) {
			// A valid DKIM record has p=; v=DKIM1 is optional in the standard
			// and many providers leave it out.
			if ( false !== stripos( $t, 'p=' ) ) {
				$record = trim( $t );
				break;
			}
		}

		$p = '';

		if ( '' !== $record && preg_match( '/(?:^|;)\s*p=([^;]*)/i', $record, $m ) ) {
			$p = preg_replace( '/\s+/', '', (string) $m[1] ) ?? '';
		}

		$out[] = array(
			'selector' => $selector,
			'found'    => '' !== $record,
			'via'      => array() !== $cname ? 'cname' : 'txt',
			'cname'    => (string) ( $cname[0] ?? '' ),
			'record'   => $record,
			'bits'     => '' !== $p ? diluxone_mail_dkim_key_bits( $p ) : 0,
			// An empty p= is the standard's way of saying "this key has been
			// revoked": the selector exists but no longer signs anything.
			'revoked'  => '' !== $record && '' === $p,
		);
	}

	return $out;
}
