<?php
/**
 * El diagnóstico: los tres análisis juntos, explicados en prosa.
 *
 * SPF, DKIM y DMARC por separado son tres volcados de registros que no le
 * dicen nada a quien no los conoce. Lo que hace falta es la conclusión: «tu
 * SPF gasta 11 lookups de 10 y por eso no vale», «tu DMARC pide alineación
 * y tu proveedor no firma con tu dominio», «los reportes van a un dominio
 * que no los autorizó y se tiran». Cada hallazgo tiene un nivel, un título y
 * un párrafo que explica qué implica y qué hacer.
 *
 * El resultado se cachea entero, además del caché de cada consulta: armar el
 * informe son veinte consultas y no cambia hasta que alguien toca el DNS.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * El informe de un dominio.
 *
 * @param bool $fresh Ignorar el caché.
 * @return array<string, mixed>
 */
function diluxone_mail_diagnose( string $domain, bool $fresh = false ): array {
	$domain = strtolower( trim( $domain ) );
	$clave  = 'diluxone_mail_diagnosis_' . md5( $domain );

	if ( $fresh ) {
		diluxone_mail_dns_flush();
	} else {
		$cacheado = get_site_transient( $clave );

		if ( is_array( $cacheado ) ) {
			$cacheado['cached'] = true;

			return $cacheado;
		}
	}

	$config   = diluxone_mail_config();
	$activo   = diluxone_mail_transport_active() ? $config['provider'] : '';
	$perfil   = diluxone_mail_provider( $activo );
	$spf      = diluxone_mail_spf_analyse( $domain );
	$dkim     = diluxone_mail_dkim_probe( $domain, diluxone_mail_dkim_selectors( $activo ) );
	$dmarc    = diluxone_mail_dmarc_analyse( $domain );
	$mx       = diluxone_mail_dns_lookup( $domain, 'MX' );
	$resolver = diluxone_mail_dns_lookup( $domain, 'TXT' )['source'];

	$return_path = null;

	if ( '' !== (string) $perfil['return_path'] ) {
		$host        = $perfil['return_path'] . '.' . $domain;
		$cname       = diluxone_mail_dns_lookup( $host, 'CNAME' )['records'];
		$return_path = array(
			'host'     => $host,
			'resolves' => array() !== $cname,
			'target'   => (string) ( $cname[0] ?? '' ),
		);
	}

	$informe = array(
		'domain'       => $domain,
		'generated_at' => time(),
		'cached'       => false,
		'resolver'     => 'cache' === $resolver ? 'system' : $resolver,
		'provider'     => $activo,
		'profile_name' => (string) $perfil['name'],
		'spf'          => $spf,
		'dkim'         => $dkim,
		'dmarc'        => $dmarc,
		'mx'           => $mx['records'],
		'return_path'  => $return_path,
		'spf_senders'  => diluxone_mail_spf_senders( $spf['includes'], $activo ),
		'findings'     => array(),
	);

	$informe['findings'] = diluxone_mail_findings( $informe, $perfil );

	set_site_transient( $clave, $informe, max( 1, (int) diluxone_mail_option( 'diluxone_mail_dns_cache_hours' ) ) * HOUR_IN_SECONDS );

	return $informe;
}

/**
 * Qué proveedores declara el SPF, y cuál de ellos es el transporte activo.
 *
 * Es la lista de «candidatos a borrar»: un include de un proveedor que ya no
 * se usa gasta un lookup de los diez y autoriza a mandar en nombre del
 * dominio a alguien que ya no debería.
 *
 * @param array<int, string> $includes
 * @return array<int, array{include: string, provider: string, name: string, active: bool}>
 */
function diluxone_mail_spf_senders( array $includes, string $active ): array {
	$salida = array();

	foreach ( $includes as $include ) {
		$proveedor = '';
		$nombre    = '';

		foreach ( diluxone_mail_providers() as $key => $perfil ) {
			foreach ( (array) $perfil['spf_includes'] as $conocido ) {
				if ( strtolower( (string) $conocido ) === $include ) {
					$proveedor = $key;
					$nombre    = (string) $perfil['name'];
					break 2;
				}
			}
		}

		$salida[] = array(
			'include'  => $include,
			'provider' => $proveedor,
			'name'     => $nombre,
			'active'   => '' !== $proveedor && $proveedor === $active,
		);
	}

	return $salida;
}

/**
 * Un hallazgo.
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
 * Las conclusiones, en el orden en que conviene leerlas.
 *
 * @param array<string, mixed> $r      El informe.
 * @param array<string, mixed> $perfil El perfil del proveedor activo.
 * @return array<int, array{level: string, section: string, title: string, text: string}>
 */
function diluxone_mail_findings( array $r, array $perfil ): array {
	$f      = array();
	$spf    = $r['spf'];
	$dmarc  = $r['dmarc'];
	$activo = (string) $r['provider'];
	$nombre = (string) $r['profile_name'];

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
				/* translators: %d: cantidad de lookups */
				sprintf( __( 'SPF uses %d DNS lookups; the limit is 10', 'diluxone-mail' ), (int) $spf['lookups'] ),
				__( 'Going over the limit does not degrade SPF gracefully: receivers return a permanent error and the whole record stops counting, as if it did not exist. Remove providers you no longer use, or replace include: entries with ip4: entries where the provider documents stable addresses.', 'diluxone-mail' )
			);
		} elseif ( (int) $spf['lookups'] >= 8 ) {
			$f[] = diluxone_mail_finding(
				'warning',
				'spf',
				/* translators: %d: cantidad de lookups */
				sprintf( __( 'SPF uses %d of the 10 allowed DNS lookups', 'diluxone-mail' ), (int) $spf['lookups'] ),
				__( 'One more provider and it will break. Look at the tree below for includes you can remove.', 'diluxone-mail' )
			);
		} else {
			$f[] = diluxone_mail_finding(
				'ok',
				'spf',
				/* translators: %d: cantidad de lookups */
				sprintf( __( 'SPF uses %d of the 10 allowed DNS lookups', 'diluxone-mail' ), (int) $spf['lookups'] ),
				__( 'Within the limit.', 'diluxone-mail' )
			);
		}

		if ( '+all' === $spf['all'] ) {
			$f[] = diluxone_mail_finding( 'error', 'spf', __( 'SPF ends with +all', 'diluxone-mail' ), __( 'That authorises every server on the internet to send as this domain. It is the same as having no SPF, and worse in the eyes of most receivers. Change it to -all.', 'diluxone-mail' ) );
		} elseif ( '?all' === $spf['all'] || '' === $spf['all'] ) {
			$f[] = diluxone_mail_finding( 'warning', 'spf', __( 'SPF does not end with -all or ~all', 'diluxone-mail' ), __( 'Without a failing qualifier at the end, mail from unlisted servers is neither accepted nor rejected — receivers treat the record as saying nothing. Use -all once you are sure every legitimate sender is listed, or ~all while you check.', 'diluxone-mail' ) );
		}

		if ( '' !== $activo && ! (bool) $perfil['local'] && array() !== (array) $perfil['spf_includes'] ) {
			$declarado = false;

			foreach ( $r['spf_senders'] as $sender ) {
				if ( $sender['active'] ) {
					$declarado = true;
				}
			}

			if ( ! $declarado ) {
				$f[] = diluxone_mail_finding(
					'error',
					'spf',
					/* translators: %s: nombre del proveedor */
					sprintf( __( '%s is not authorised in the SPF record', 'diluxone-mail' ), $nombre ),
					sprintf(
						/* translators: 1: nombre del proveedor, 2: include a agregar */
						__( 'This site sends through %1$s, but the SPF record does not include it, so receivers will see the mail as coming from an unauthorised server. Add include:%2$s to the record.', 'diluxone-mail' ),
						$nombre,
						(string) ( (array) $perfil['spf_includes'] )[0]
					)
				);
			}
		}

		foreach ( $r['spf_senders'] as $sender ) {
			if ( '' !== $sender['provider'] && ! $sender['active'] && '' !== $activo && ! (bool) $perfil['local'] ) {
				$f[] = diluxone_mail_finding(
					'warning',
					'spf',
					/* translators: %s: nombre del proveedor */
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
	$encontrados = array_filter( (array) $r['dkim'], static fn( array $d ): bool => (bool) $d['found'] );

	if ( array() === $encontrados ) {
		$f[] = diluxone_mail_finding(
			'warning',
			'dkim',
			__( 'No DKIM selector was found', 'diluxone-mail' ),
			sprintf(
				/* translators: %d: cantidad de selectores probados */
				__( 'None of the %d selectors probed exists on this domain. That does not prove there is no DKIM — selectors cannot be listed over DNS, only guessed — but if your provider gave you DKIM records to publish and they are not here, mail is going out unsigned. Add your provider\'s selector in the settings and revalidate.', 'diluxone-mail' ),
				count( $r['dkim'] )
			)
		);
	} else {
		foreach ( $encontrados as $d ) {
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
					/* translators: 1: selector, 2: bits */
					sprintf( __( 'Selector %1$s is published (%2$s)', 'diluxone-mail' ), $d['selector'], $d['bits'] > 0 ? $d['bits'] . ' bits' : __( 'key size unknown', 'diluxone-mail' ) ),
					'cname' === $d['via']
						/* translators: %s: destino del CNAME */
						? sprintf( __( 'Delegated by CNAME to %s, so the provider rotates the key for you.', 'diluxone-mail' ), $d['cname'] )
						: __( 'Published directly on the domain.', 'diluxone-mail' )
				);
			}
		}
	}

	$selectores_proveedor = array_map( 'strval', (array) $perfil['dkim_selectors'] );
	$firma_proveedor      = false;

	foreach ( $encontrados as $d ) {
		if ( in_array( $d['selector'], $selectores_proveedor, true ) && ! $d['revoked'] ) {
			$firma_proveedor = true;
		}
	}

	if ( '' !== $activo && array() !== $selectores_proveedor && ! $firma_proveedor ) {
		$f[] = diluxone_mail_finding(
			'error',
			'dkim',
			/* translators: %s: nombre del proveedor */
			sprintf( __( 'No DKIM record for %s', 'diluxone-mail' ), $nombre ),
			sprintf(
				/* translators: 1: nombre del proveedor, 2: selectores */
				__( '%1$s signs mail with the selector(s) %2$s, and none of them is published on this domain. Mail goes out signed by the provider\'s own domain instead of yours, which does not count for DMARC alignment. Copy the DKIM records from the provider\'s dashboard into your DNS.', 'diluxone-mail' ),
				$nombre,
				implode( ', ', $selectores_proveedor )
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

		$politica = (string) $dmarc['policy'];

		if ( 'none' === $politica ) {
			$f[] = diluxone_mail_finding( 'info', 'dmarc', __( 'DMARC policy is p=none: monitoring only', 'diluxone-mail' ), __( 'Receivers report but do nothing. It is the right first step; once the reports show only legitimate senders, move to p=quarantine.', 'diluxone-mail' ) );
		} elseif ( in_array( $politica, array( 'quarantine', 'reject' ), true ) ) {
			$accion = 'reject' === $politica ? __( 'rejected', 'diluxone-mail' ) : __( 'sent to spam', 'diluxone-mail' );

			if ( '' === $activo || (bool) $perfil['local'] ) {
				$f[] = diluxone_mail_finding(
					'info',
					'dmarc',
					/* translators: %s: política */
					sprintf( __( 'DMARC policy is p=%s: alignment is required', 'diluxone-mail' ), $politica ),
					sprintf(
						/* translators: %s: qué pasa con el correo */
						__( 'Mail that fails both SPF and DKIM alignment will be %s. With no transport configured here, this plugin cannot tell whether your sender aligns.', 'diluxone-mail' ),
						$accion
					)
				);
			} elseif ( $firma_proveedor ) {
				$f[] = diluxone_mail_finding(
					'ok',
					'dmarc',
					/* translators: %s: política */
					sprintf( __( 'DMARC policy is p=%s and mail aligns through DKIM', 'diluxone-mail' ), $politica ),
					sprintf(
						/* translators: %s: nombre del proveedor */
						__( 'SPF alignment usually fails with a third-party provider — the bounce address is theirs, not yours — but %s signs with your domain\'s DKIM key, and one aligned mechanism is enough.', 'diluxone-mail' ),
						$nombre
					)
				);
			} else {
				$f[] = diluxone_mail_finding(
					'error',
					'dmarc',
					/* translators: %s: política */
					sprintf( __( 'DMARC policy is p=%s but nothing aligns', 'diluxone-mail' ), $politica ),
					sprintf(
						/* translators: 1: nombre del proveedor, 2: qué pasa con el correo */
						__( 'With %1$s as the transport, SPF is not aligned (the bounce address belongs to the provider) and no DKIM key of yours is published for it. Every message will be %2$s. Publish the provider\'s DKIM records — that is the fix — or drop the policy to p=none until you do.', 'diluxone-mail' ),
						$nombre,
						$accion
					)
				);
			}
		}

		if ( (int) $dmarc['pct'] < 100 ) {
			$f[] = diluxone_mail_finding(
				'info',
				'dmarc',
				/* translators: %d: porcentaje */
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
					/* translators: %s: dirección */
					sprintf( __( 'Reports to %s are being discarded', 'diluxone-mail' ), $ext['address'] ),
					sprintf(
						/* translators: 1: dominio del reporte, 2: nombre del registro que falta */
						__( 'That address is on another domain (%1$s), and the standard requires that domain to say it accepts reports for yours. It does not: there is no TXT record at %2$s with v=DMARC1. Until it is published, receivers throw the reports away.', 'diluxone-mail' ),
						$ext['domain'],
						$r['domain'] . '._report._dmarc.' . $ext['domain']
					)
				);
			}
		}
	}

	// ── MX y return-path ─────────────────────────────────────────────
	if ( array() === $r['mx'] ) {
		$f[] = diluxone_mail_finding( 'warning', 'mx', __( 'The domain has no MX record', 'diluxone-mail' ), __( 'Sending still works, but nothing can be received at this domain: replies and bounces to your From address go nowhere, and some receivers distrust senders that cannot receive.', 'diluxone-mail' ) );
	}

	if ( is_array( $r['return_path'] ) ) {
		$rp = $r['return_path'];

		$f[] = $rp['resolves']
			/* translators: 1: host, 2: destino */
			? diluxone_mail_finding( 'ok', 'mx', sprintf( __( 'Return-path %1$s points to %2$s', 'diluxone-mail' ), $rp['host'], $rp['target'] ), __( 'The provider\'s custom bounce domain is set up, so SPF can align too.', 'diluxone-mail' ) )
			/* translators: %s: host */
			: diluxone_mail_finding( 'warning', 'mx', sprintf( __( 'Return-path %s does not resolve', 'diluxone-mail' ), $rp['host'] ), __( 'The provider offers a custom bounce domain that makes SPF align with your domain. It is optional — DKIM alignment is enough for DMARC — but publishing the CNAME the provider gives you improves reputation.', 'diluxone-mail' ) );
	}

	return $f;
}
