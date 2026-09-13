<?php
/**
 * SPF: what the record says, and how many lookups it burns.
 *
 * The detail almost nobody knows and that breaks the mail of a great many
 * sites: the standard (RFC 7208, §4.6.4) allows at most 10 DNS queries to
 * evaluate an SPF record, counting those inside `include`s recursively. Going
 * over does not degrade anything: the receiver returns "permerror" and the
 * whole SPF record stops counting, as if it did not exist. And nobody warns
 * you. A site adds one provider, then another, then another, and one day
 * Gmail starts sending everything to spam.
 *
 * `include`, `a`, `mx`, `ptr`, `exists` and the `redirect` modifier count.
 * `ip4`, `ip6` and `all` do not. Here the whole tree is expanded and counted
 * the way a receiver counts it.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/** The limit set by the standard. */
const DILUXONE_MAIL_SPF_MAX_LOOKUPS = 10;

/**
 * A domain's SPF record.
 *
 * @return array{record: string|null, error: string}
 *         Two SPF records is an error in itself — the standard invalidates
 *         them — and it is reported as such.
 */
function diluxone_mail_spf_record( string $domain ): array {
	$spf = array();

	foreach ( diluxone_mail_dns_txt( $domain ) as $txt ) {
		if ( preg_match( '/^v=spf1(\s|$)/i', trim( $txt ) ) ) {
			$spf[] = trim( $txt );
		}
	}

	if ( array() === $spf ) {
		return array(
			'record' => null,
			'error'  => '',
		);
	}

	if ( count( $spf ) > 1 ) {
		return array(
			'record' => $spf[0],
			'error'  => __( 'The domain publishes more than one SPF record. The standard treats that as a permanent error: receivers ignore all of them.', 'diluxone-mail' ),
		);
	}

	return array(
		'record' => $spf[0],
		'error'  => '',
	);
}

/**
 * A record's terms, one by one.
 *
 * @return array<int, array{qualifier: string, mechanism: string, value: string, counts: bool, follow: string}>
 *         follow: the domain to go and look at (include/redirect), or empty.
 */
function diluxone_mail_spf_terms( string $record ): array {
	$terms = array();

	$parts = preg_split( '/\s+/', trim( $record ) );

	foreach ( false === $parts ? array() : $parts as $i => $term ) {
		if ( 0 === $i || '' === $term ) {
			continue;
		}

		$qualifier = '+';

		if ( in_array( $term[0], array( '+', '-', '~', '?' ), true ) ) {
			$qualifier = $term[0];
			$term      = substr( $term, 1 );
		}

		$lower = strtolower( $term );

		if ( preg_match( '/^(include|a|mx|ptr|exists|ip4|ip6|all)(?:[:\/](.*))?$/i', $term, $m ) ) {
			$mechanism = strtolower( $m[1] );
			$value     = (string) ( $m[2] ?? '' );
			$follow    = 'include' === $mechanism ? $value : '';

			$terms[] = array(
				'qualifier' => $qualifier,
				'mechanism' => $mechanism,
				'value'     => $value,
				'counts'    => in_array( $mechanism, array( 'include', 'a', 'mx', 'ptr', 'exists' ), true ),
				'follow'    => $follow,
			);

			continue;
		}

		if ( 0 === strpos( $lower, 'redirect=' ) ) {
			$terms[] = array(
				'qualifier' => '',
				'mechanism' => 'redirect',
				'value'     => substr( $term, 9 ),
				'counts'    => true,
				'follow'    => substr( $term, 9 ),
			);

			continue;
		}

		// exp= and any unknown modifier: they do not count and are not
		// followed.
		$terms[] = array(
			'qualifier' => '',
			'mechanism' => strtolower( (string) strtok( $term, '=' ) ),
			'value'     => (string) substr( $term, (int) strpos( $term, '=' ) + 1 ),
			'counts'    => false,
			'follow'    => '',
		);
	}

	return $terms;
}

/**
 * Expands a domain's tree and counts the lookups.
 *
 * @param string                           $domain
 * @param int                              $depth
 * @param array<string, true>              $seen   Domains already visited, against loops.
 * @param array<int, array<string, mixed>> $tree   Filled in from the top down.
 * @return int Lookups of this domain and of everything hanging off it.
 */
function diluxone_mail_spf_walk( string $domain, int $depth, array &$seen, array &$tree ): int {
	$domain = strtolower( rtrim( $domain, '.' ) );

	if ( isset( $seen[ $domain ] ) ) {
		$tree[] = array(
			'depth'   => $depth,
			'domain'  => $domain,
			'record'  => '',
			'lookups' => 0,
			'error'   => __( 'Already included above: this is a loop.', 'diluxone-mail' ),
		);

		return 0;
	}

	$seen[ $domain ] = true;

	// Twenty levels is more than any sane SPF record has; it is there so a
	// broken tree is not followed forever.
	if ( $depth > 20 ) {
		return 0;
	}

	$spf = diluxone_mail_spf_record( $domain );

	if ( null === $spf['record'] ) {
		$tree[] = array(
			'depth'   => $depth,
			'domain'  => $domain,
			'record'  => '',
			'lookups' => 0,
			'error'   => __( 'No SPF record. An include that leads nowhere still counts as a lookup, and too many of these are an error on their own.', 'diluxone-mail' ),
		);

		return 0;
	}

	$terms = diluxone_mail_spf_terms( $spf['record'] );
	$own   = count( array_filter( $terms, static fn( array $t ): bool => $t['counts'] ) );
	$index = count( $tree );

	$tree[] = array(
		'depth'   => $depth,
		'domain'  => $domain,
		'record'  => $spf['record'],
		'lookups' => $own,
		'error'   => $spf['error'],
	);

	$total = $own;

	foreach ( $terms as $term ) {
		if ( '' !== $term['follow'] ) {
			$total += diluxone_mail_spf_walk( $term['follow'], $depth + 1, $seen, $tree );
		}
	}

	$tree[ $index ]['subtotal'] = $total;

	return $total;
}

/**
 * The full SPF analysis of a domain.
 *
 * @return array{
 *     domain: string,
 *     record: string|null,
 *     error: string,
 *     lookups: int,
 *     over_limit: bool,
 *     all: string,
 *     includes: array<int, string>,
 *     tree: array<int, array<string, mixed>>
 * }
 */
function diluxone_mail_spf_analyse( string $domain ): array {
	$seen = array();
	$tree = array();

	$lookups = diluxone_mail_spf_walk( $domain, 0, $seen, $tree );
	$root    = $tree[0] ?? null;
	$record  = null !== $root && '' !== $root['record'] ? (string) $root['record'] : null;
	$all     = '';
	$incl    = array();

	if ( null !== $record ) {
		foreach ( diluxone_mail_spf_terms( $record ) as $term ) {
			if ( 'all' === $term['mechanism'] ) {
				$all = $term['qualifier'] . 'all';
			}

			if ( '' !== $term['follow'] ) {
				$incl[] = strtolower( $term['follow'] );
			}
		}
	}

	return array(
		'domain'     => $domain,
		'record'     => $record,
		'error'      => null !== $root ? (string) $root['error'] : '',
		'lookups'    => $lookups,
		'over_limit' => $lookups > DILUXONE_MAIL_SPF_MAX_LOOKUPS,
		'all'        => $all,
		'includes'   => array_values( array_unique( $incl ) ),
		'tree'       => $tree,
	);
}
