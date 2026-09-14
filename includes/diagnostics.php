<?php
/**
 * The diagnosis: the three analyses together, explained in prose.
 *
 * SPF, DKIM and DMARC on their own are three dumps of DNS records that say
 * nothing to anybody who does not already know them. What is needed is the
 * conclusion: "your SPF burns 11 of 10 lookups and therefore counts for
 * nothing", "your DMARC demands alignment and your provider does not sign
 * with your domain", "the reports go to a domain that never authorised them
 * and are thrown away". Every finding has a level, a title and a paragraph
 * explaining what it implies and what to do.
 *
 * The result is cached as a whole, on top of each lookup's own cache:
 * building the report is twenty lookups and it does not change until somebody
 * touches the DNS.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * A domain's report, only if it is already known.
 *
 * Two screens ask this and neither may pay for it. The Overview counts the
 * findings it already has and says nothing when there are none yet; the
 * deliverability screen draws the shape of the answer and lets the browser
 * fetch it.
 *
 * The diagnosis walks the SPF tree, probes every selector and asks for DMARC,
 * and over DNS-over-HTTPS that is seconds, not milliseconds. Nothing on the
 * page renders while it runs, so the screen answers a click with a white
 * page. This is what lets it answer with the page instead: the screen asks
 * for what is known, draws, and the browser goes and gets the rest.
 *
 * @return array<string, mixed>|null
 */
function diluxone_mail_diagnosis_cached( string $domain ): ?array {
	$domain = strtolower( trim( $domain ) );

	if ( '' === $domain ) {
		return null;
	}

	$cached = get_site_transient( 'diluxone_mail_diagnosis_' . md5( $domain ) );

	if ( ! is_array( $cached ) ) {
		return null;
	}

	$cached['cached'] = true;

	return $cached;
}

/**
 * A domain's report.
 *
 * @param bool $fresh Ignore the cache.
 * @return array<string, mixed>
 */
function diluxone_mail_diagnose( string $domain, bool $fresh = false ): array {
	$domain = strtolower( trim( $domain ) );
	$key    = 'diluxone_mail_diagnosis_' . md5( $domain );

	if ( $fresh ) {
		diluxone_mail_dns_flush();
	} else {
		$cached = get_site_transient( $key );

		if ( is_array( $cached ) ) {
			$cached['cached'] = true;

			return $cached;
		}
	}

	$config   = diluxone_mail_config();
	$active   = diluxone_mail_transport_active() ? $config['provider'] : '';
	$profile  = diluxone_mail_provider( $active );
	$spf      = diluxone_mail_spf_analyse( $domain );
	$dkim     = diluxone_mail_dkim_probe( $domain, diluxone_mail_dkim_selectors( $active ) );
	$dmarc    = diluxone_mail_dmarc_analyse( $domain );
	$mx       = diluxone_mail_dns_lookup( $domain, 'MX' );
	$resolver = diluxone_mail_dns_lookup( $domain, 'TXT' )['source'];

	$return_path = null;

	if ( '' !== (string) $profile['return_path'] ) {
		$host        = $profile['return_path'] . '.' . $domain;
		$cname       = diluxone_mail_dns_lookup( $host, 'CNAME' )['records'];
		$return_path = array(
			'host'     => $host,
			'resolves' => array() !== $cname,
			'target'   => (string) ( $cname[0] ?? '' ),
		);
	}

	$report = array(
		'domain'       => $domain,
		'generated_at' => time(),
		'cached'       => false,
		'resolver'     => 'cache' === $resolver ? 'system' : $resolver,
		'provider'     => $active,
		'profile_name' => (string) $profile['name'],
		'spf'          => $spf,
		'dkim'         => $dkim,
		'dmarc'        => $dmarc,
		'mx'           => $mx['records'],
		'return_path'  => $return_path,
		'spf_senders'  => diluxone_mail_spf_senders( $spf['includes'], $active ),
		'findings'     => array(),
	);

	$report['findings'] = diluxone_mail_findings( $report, $profile );

	set_site_transient( $key, $report, max( 1, (int) diluxone_mail_option( 'diluxone_mail_dns_cache_hours' ) ) * HOUR_IN_SECONDS );

	return $report;
}

/**
 * Which providers the SPF record declares, and which of them is the active
 * transport.
 *
 * It is the list of "candidates for deletion": an include of a provider no
 * longer in use burns one of the ten lookups and authorises somebody who
 * should no longer be sending as the domain.
 *
 * @param array<int, string> $includes
 * @return array<int, array{include: string, provider: string, name: string, active: bool}>
 */
function diluxone_mail_spf_senders( array $includes, string $active ): array {
	$out = array();

	foreach ( $includes as $include ) {
		$provider = '';
		$name     = '';

		foreach ( diluxone_mail_providers() as $key => $profile ) {
			foreach ( (array) $profile['spf_includes'] as $known ) {
				if ( strtolower( (string) $known ) === $include ) {
					$provider = $key;
					$name     = (string) $profile['name'];
					break 2;
				}
			}
		}

		$out[] = array(
			'include'  => $include,
			'provider' => $provider,
			'name'     => $name,
			'active'   => '' !== $provider && $provider === $active,
		);
	}

	return $out;
}

/**
 * One finding.
 *
 * @return array{level: string, section: string, title: string, text: string}
 */
function diluxone_mail_finding( string $level, string $section, string $title, string $text ): array {
	return array(
		'level'   => $level,
		'section' => $section,
		'title'   => $title,
		'text'    => $text,
	);
}

/**
 * The conclusions, in the order they are worth reading in.
 *
 * @param array<string, mixed> $r       The report.
 * @param array<string, mixed> $profile The active provider's profile.
 * @return array<int, array{level: string, section: string, title: string, text: string}>
 */
function diluxone_mail_findings( array $r, array $profile ): array {
	$f      = array();
	$spf    = $r['spf'];
	$dmarc  = $r['dmarc'];
	$active = (string) $r['provider'];
	$name   = (string) $r['profile_name'];

	// ── The provider's own opinion ───────────────────────────────────
	// Before any of the receiver-side reasoning: a provider that has not been
	// given this domain refuses to send from it, and that is a wall rather
	// than a risk. The two marks it leaves in DNS when a domain is set up with
	// it are its SPF include and its DKIM selectors, and neither of them being
	// there means the domain was never finished on their side — which is the
	// difference between mail that lands in spam and mail that never leaves.
	$f = array_merge( $f, diluxone_mail_findings_provider_side( $r, $profile ) );

	// ── SPF ──────────────────────────────────────────────────────────
	if ( null === $spf['record'] ) {
		$f[] = diluxone_mail_finding( 'error', 'spf', __( 'There is no SPF record', 'diluxone-mail' ), __( 'Receivers have no way to know which servers may send mail for this domain, and most of them treat that as a bad sign. Publish a TXT record starting with v=spf1 that lists your provider and ends with -all.', 'diluxone-mail' ) );
	} else {
		if ( '' !== $spf['error'] ) {
			$f[] = diluxone_mail_finding( 'error', 'spf', __( 'The SPF record is invalid', 'diluxone-mail' ), (string) $spf['error'] );
		}

		if ( $spf['over_limit'] ) {
			$f[] = diluxone_mail_finding(
				'error',
				'spf',
				/* translators: %d: number of lookups */
				sprintf( __( 'SPF uses %d DNS lookups; the limit is 10', 'diluxone-mail' ), (int) $spf['lookups'] ),
				__( 'Going over the limit does not degrade SPF gracefully: receivers return a permanent error and the whole record stops counting, as if it did not exist. Remove providers you no longer use, or replace include: entries with ip4: entries where the provider documents stable addresses.', 'diluxone-mail' )
			);
		} elseif ( (int) $spf['lookups'] >= 8 ) {
			$f[] = diluxone_mail_finding(
				'warning',
				'spf',
				/* translators: %d: number of lookups */
				sprintf( __( 'SPF uses %d of the 10 allowed DNS lookups', 'diluxone-mail' ), (int) $spf['lookups'] ),
				__( 'One more provider and it will break. Look at the tree below for includes you can remove.', 'diluxone-mail' )
			);
		} else {
			$f[] = diluxone_mail_finding(
				'ok',
				'spf',
				/* translators: %d: number of lookups */
				sprintf( __( 'SPF uses %d of the 10 allowed DNS lookups', 'diluxone-mail' ), (int) $spf['lookups'] ),
				__( 'Within the limit.', 'diluxone-mail' )
			);
		}

		if ( '+all' === $spf['all'] ) {
			$f[] = diluxone_mail_finding( 'error', 'spf', __( 'SPF ends with +all', 'diluxone-mail' ), __( 'That authorises every server on the internet to send as this domain. It is the same as having no SPF, and worse in the eyes of most receivers. Change it to -all.', 'diluxone-mail' ) );
		} elseif ( '?all' === $spf['all'] || '' === $spf['all'] ) {
			$f[] = diluxone_mail_finding( 'warning', 'spf', __( 'SPF does not end with -all or ~all', 'diluxone-mail' ), __( 'Without a failing qualifier at the end, mail from unlisted servers is neither accepted nor rejected — receivers treat the record as saying nothing. Use -all once you are sure every legitimate sender is listed, or ~all while you check.', 'diluxone-mail' ) );
		}

		if ( '' !== $active && ! (bool) $profile['local'] && array() !== (array) $profile['spf_includes'] ) {
			$declared = false;

			foreach ( $r['spf_senders'] as $sender ) {
				if ( $sender['active'] ) {
					$declared = true;
				}
			}

			if ( ! $declared ) {
				$f[] = diluxone_mail_finding(
					'error',
					'spf',
					/* translators: %s: provider name */
					sprintf( __( '%s is not authorised in the SPF record', 'diluxone-mail' ), $name ),
					sprintf(
						/* translators: 1: provider name, 2: include to add */
						__( 'This site sends through %1$s, but the SPF record does not include it, so receivers will see the mail as coming from an unauthorised server. Add include:%2$s to the record.', 'diluxone-mail' ),
						$name,
						(string) ( (array) $profile['spf_includes'] )[0]
					)
				);
			}
		}

		foreach ( $r['spf_senders'] as $sender ) {
			if ( '' !== $sender['provider'] && ! $sender['active'] && '' !== $active && ! (bool) $profile['local'] ) {
				$f[] = diluxone_mail_finding(
					'warning',
					'spf',
					/* translators: %s: provider name */
					sprintf( __( 'SPF authorises %s, which is not the configured transport', 'diluxone-mail' ), $sender['name'] ),
					sprintf(
						/* translators: %s: include */
						__( 'If nothing else on this domain sends through it any more, remove include:%s — it spends one of the 10 lookups and lets that provider send as you. If another system still uses it, leave it.', 'diluxone-mail' ),
						$sender['include']
					)
				);
			}
		}
	}

	// ── DKIM ─────────────────────────────────────────────────────────
	$found = array_filter( (array) $r['dkim'], static fn( array $d ): bool => (bool) $d['found'] );

	if ( array() === $found ) {
		$f[] = diluxone_mail_finding(
			'warning',
			'dkim',
			__( 'No DKIM selector was found', 'diluxone-mail' ),
			sprintf(
				/* translators: %d: number of selectors probed */
				__( 'None of the %d selectors probed exists on this domain. That does not prove there is no DKIM — selectors cannot be listed over DNS, only guessed — but if your provider gave you DKIM records to publish and they are not here, mail is going out unsigned. Add your provider\'s selector in the settings and revalidate.', 'diluxone-mail' ),
				count( $r['dkim'] )
			)
		);
	} else {
		foreach ( $found as $d ) {
			if ( $d['revoked'] ) {
				$f[] = diluxone_mail_finding(
					'warning',
					'dkim',
					/* translators: %s: selector */
					sprintf( __( 'Selector %s is revoked', 'diluxone-mail' ), $d['selector'] ),
					__( 'It exists but carries an empty key, which is the standard way of saying "this key no longer signs anything". If your provider uses it, mail is going out with a signature nobody can verify.', 'diluxone-mail' )
				);
			} elseif ( 1024 === (int) $d['bits'] ) {
				$f[] = diluxone_mail_finding(
					'warning',
					'dkim',
					/* translators: %s: selector */
					sprintf( __( 'Selector %s uses a 1024-bit key', 'diluxone-mail' ), $d['selector'] ),
					__( 'It works, but 1024 bits is considered weak and some receivers score it down. Most providers can rotate to 2048 from their dashboard.', 'diluxone-mail' )
				);
			} else {
				$f[] = diluxone_mail_finding(
					'ok',
					'dkim',
					/* translators: 1: selector, 2: key size */
					sprintf( __( 'Selector %1$s is published (%2$s)', 'diluxone-mail' ), $d['selector'], $d['bits'] > 0 ? $d['bits'] . ' bits' : __( 'key size unknown', 'diluxone-mail' ) ),
					'cname' === $d['via']
						/* translators: %s: CNAME target */
						? sprintf( __( 'Delegated by CNAME to %s, so the provider rotates the key for you.', 'diluxone-mail' ), $d['cname'] )
						: __( 'Published directly on the domain.', 'diluxone-mail' )
				);
			}
		}
	}

	$provider_selectors = array_map( 'strval', (array) $profile['dkim_selectors'] );
	$provider_signs     = false;

	foreach ( $found as $d ) {
		if ( in_array( $d['selector'], $provider_selectors, true ) && ! $d['revoked'] ) {
			$provider_signs = true;
		}
	}

	if ( '' !== $active && array() !== $provider_selectors && ! $provider_signs ) {
		$f[] = diluxone_mail_finding(
			'error',
			'dkim',
			/* translators: %s: provider name */
			sprintf( __( 'No DKIM record for %s', 'diluxone-mail' ), $name ),
			sprintf(
				/* translators: 1: provider name, 2: selectors */
				__( '%1$s signs mail with the selector(s) %2$s, and none of them is published on this domain. Mail goes out signed by the provider\'s own domain instead of yours, which does not count for DMARC alignment. Copy the DKIM records from the provider\'s dashboard into your DNS.', 'diluxone-mail' ),
				$name,
				implode( ', ', $provider_selectors )
			)
		);
	}

	// ── DMARC ────────────────────────────────────────────────────────
	if ( null === $dmarc['record'] ) {
		$f[] = diluxone_mail_finding( 'warning', 'dmarc', __( 'There is no DMARC record', 'diluxone-mail' ), __( 'Without DMARC, receivers decide on their own what to do when SPF and DKIM do not add up, and you get no reports about who is sending as your domain. Start with v=DMARC1; p=none; rua=mailto:you@yourdomain to receive reports without affecting delivery, then tighten it.', 'diluxone-mail' ) );
	} else {
		foreach ( (array) $dmarc['errors'] as $error ) {
			$f[] = diluxone_mail_finding( 'error', 'dmarc', __( 'The DMARC record is invalid', 'diluxone-mail' ), (string) $error );
		}

		$policy = (string) $dmarc['policy'];

		if ( 'none' === $policy ) {
			$f[] = diluxone_mail_finding( 'info', 'dmarc', __( 'DMARC policy is p=none: monitoring only', 'diluxone-mail' ), __( 'Receivers report but do nothing. It is the right first step; once the reports show only legitimate senders, move to p=quarantine.', 'diluxone-mail' ) );
		} elseif ( in_array( $policy, array( 'quarantine', 'reject' ), true ) ) {
			$action = 'reject' === $policy ? __( 'rejected', 'diluxone-mail' ) : __( 'sent to spam', 'diluxone-mail' );

			if ( '' === $active || (bool) $profile['local'] ) {
				$f[] = diluxone_mail_finding(
					'info',
					'dmarc',
					/* translators: %s: policy */
					sprintf( __( 'DMARC policy is p=%s: alignment is required', 'diluxone-mail' ), $policy ),
					sprintf(
						/* translators: %s: what happens to the mail */
						__( 'Mail that fails both SPF and DKIM alignment will be %s. With no transport configured here, this plugin cannot tell whether your sender aligns.', 'diluxone-mail' ),
						$action
					)
				);
			} elseif ( $provider_signs ) {
				$f[] = diluxone_mail_finding(
					'ok',
					'dmarc',
					/* translators: %s: policy */
					sprintf( __( 'DMARC policy is p=%s and mail aligns through DKIM', 'diluxone-mail' ), $policy ),
					sprintf(
						/* translators: %s: provider name */
						__( 'SPF alignment usually fails with a third-party provider — the bounce address is theirs, not yours — but %s signs with your domain\'s DKIM key, and one aligned mechanism is enough.', 'diluxone-mail' ),
						$name
					)
				);
			} else {
				$f[] = diluxone_mail_finding(
					'error',
					'dmarc',
					/* translators: %s: policy */
					sprintf( __( 'DMARC policy is p=%s but nothing aligns', 'diluxone-mail' ), $policy ),
					sprintf(
						/* translators: 1: provider name, 2: what happens to the mail */
						__( 'With %1$s as the transport, SPF is not aligned (the bounce address belongs to the provider) and no DKIM key of yours is published for it. Every message will be %2$s. Publish the provider\'s DKIM records — that is the fix — or drop the policy to p=none until you do.', 'diluxone-mail' ),
						$name,
						$action
					)
				);
			}
		}

		if ( (int) $dmarc['pct'] < 100 ) {
			$f[] = diluxone_mail_finding(
				'info',
				'dmarc',
				/* translators: %d: percentage */
				sprintf( __( 'The policy applies to %d%% of mail', 'diluxone-mail' ), (int) $dmarc['pct'] ),
				__( 'pct= below 100 means receivers apply the policy to a sample and let the rest through. Fine while ramping up; set it to 100 when you are done.', 'diluxone-mail' )
			);
		}

		if ( array() === $dmarc['rua'] ) {
			$f[] = diluxone_mail_finding( 'warning', 'dmarc', __( 'DMARC has no report address (rua)', 'diluxone-mail' ), __( 'You will never learn who is sending as your domain or what fails. Add rua=mailto:an-address-you-read.', 'diluxone-mail' ) );
		}

		foreach ( (array) $dmarc['external'] as $ext ) {
			if ( ! $ext['authorized'] ) {
				$f[] = diluxone_mail_finding(
					'error',
					'dmarc',
					/* translators: %s: address */
					sprintf( __( 'Reports to %s are being discarded', 'diluxone-mail' ), $ext['address'] ),
					sprintf(
						/* translators: 1: report domain, 2: name of the missing record */
						__( 'That address is on another domain (%1$s), and the standard requires that domain to say it accepts reports for yours. It does not: there is no TXT record at %2$s with v=DMARC1. Until it is published, receivers throw the reports away.', 'diluxone-mail' ),
						$ext['domain'],
						$r['domain'] . '._report._dmarc.' . $ext['domain']
					)
				);
			}
		}
	}

	// ── MX and return-path ───────────────────────────────────────────
	if ( array() === $r['mx'] ) {
		$f[] = diluxone_mail_finding( 'warning', 'mx', __( 'The domain has no MX record', 'diluxone-mail' ), __( 'Sending still works, but nothing can be received at this domain: replies and bounces to your From address go nowhere, and some receivers distrust senders that cannot receive.', 'diluxone-mail' ) );
	}

	if ( is_array( $r['return_path'] ) ) {
		$rp = $r['return_path'];

		$f[] = $rp['resolves']
			/* translators: 1: host, 2: target */
			? diluxone_mail_finding( 'ok', 'mx', sprintf( __( 'Return-path %1$s points to %2$s', 'diluxone-mail' ), $rp['host'], $rp['target'] ), __( 'The provider\'s custom bounce domain is set up, so SPF can align too.', 'diluxone-mail' ) )
			/* translators: %s: host */
			: diluxone_mail_finding( 'warning', 'mx', sprintf( __( 'Return-path %s does not resolve', 'diluxone-mail' ), $rp['host'] ), __( 'The provider offers a custom bounce domain that makes SPF align with your domain. It is optional — DKIM alignment is enough for DMARC — but publishing the CNAME the provider gives you improves reputation.', 'diluxone-mail' ) );
	}

	return $f;
}

/**
 * What the provider itself will make of this domain.
 *
 * Everything else in the diagnosis is about what receivers think. This is
 * about whether the message gets out of the building at all: SendGrid,
 * Mailtrap, Postmark and the rest refuse a domain that has not been added and
 * verified on their side, and they refuse it with a number.
 *
 * There is no way to ask over SMTP and no key to ask over HTTP here, so it is
 * read off DNS, which the diagnosis has already looked up. A domain set up
 * with a provider carries that provider's marks: its include in the SPF
 * record, its selectors in DKIM. Neither present is not proof — a provider can
 * be configured in ways that leave neither — but it is the shape of a domain
 * nobody finished, and saying so is worth more than the false negative costs.
 *
 * @param array<string, mixed> $r       The report.
 * @param array<string, mixed> $profile The active provider's profile.
 * @return array<int, array{level: string, section: string, title: string, text: string}>
 */
function diluxone_mail_findings_provider_side( array $r, array $profile ): array {
	$active = (string) $r['provider'];
	$name   = (string) $r['profile_name'];

	// Nothing configured, a local mailbox, or a provider that leaves no marks:
	// there is nothing to read.
	if ( '' === $active || (bool) $profile['local'] ) {
		return array();
	}

	$includes  = array_map( 'strval', (array) $profile['spf_includes'] );
	$selectors = array_map( 'strval', (array) $profile['dkim_selectors'] );

	if ( array() === $includes && array() === $selectors ) {
		return array();
	}

	foreach ( (array) $r['spf_senders'] as $sender ) {
		if ( $sender['active'] ) {
			return array();
		}
	}

	foreach ( (array) $r['dkim'] as $dkim ) {
		if ( (bool) $dkim['found'] && in_array( (string) $dkim['selector'], $selectors, true ) ) {
			return array();
		}
	}

	return array(
		diluxone_mail_finding(
			'error',
			'spf',
			/* translators: %s: provider name */
			sprintf( __( '%s does not look set up for this domain', 'diluxone-mail' ), $name ),
			sprintf(
				/* translators: 1: provider name, 2: the domain */
				__( 'Neither %1$s\'s SPF include nor any of its DKIM selectors is published for %2$s, which is what a domain that was never added on their side looks like. Providers refuse mail from a domain they have not verified, so this is not about landing in spam: the message does not leave at all, and what comes back is a number. Add the domain in your account there, publish the DNS records it gives you, and wait for it to read as verified before looking at anything else on this screen.', 'diluxone-mail' ),
				$name,
				(string) $r['domain']
			)
		),
	);
}
