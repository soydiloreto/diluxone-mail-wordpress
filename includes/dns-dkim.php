<?php
/**
 * DKIM: qué selectores están publicados.
 *
 * No se pueden enumerar por DNS: un selector es un nombre que sólo conoce
 * quien lo publicó. Así que se prueba una lista —los que usan los proveedores
 * grandes, los que declara el perfil del proveedor activo, y los que agregue
 * quien administra— y se informa cuáles existen.
 *
 * Que un selector no aparezca no prueba que no haya DKIM: prueba que no está
 * en la lista. La pantalla lo dice con esas palabras.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Los selectores que usan los proveedores más comunes.
 *
 * @return array<int, string>
 */
function diluxone_mail_dkim_known_selectors(): array {
	return array( 'selector1', 'selector2', 'google', 's1', 's2', 'mailjet', 'k1', 'mail', 'default', 'dkim', 'resend' );
}

/**
 * Todos los selectores a sondear para el proveedor activo.
 *
 * @return array<int, string>
 */
function diluxone_mail_dkim_selectors( string $provider ): array {
	$perfil = diluxone_mail_provider( $provider );
	$lista  = array_merge(
		diluxone_mail_dkim_known_selectors(),
		array_map( 'strval', (array) $perfil['dkim_selectors'] ),
		array_map( 'strval', (array) diluxone_mail_option( 'diluxone_mail_dns_selectors' ) )
	);

	$lista = array_filter( array_map( 'sanitize_key', $lista ) );

	return array_values( array_unique( $lista ) );
}

/**
 * Cuántos bits tiene la clave de un registro DKIM.
 *
 * Se estima por el largo de la clave pública en DER: una RSA de 1024 bits
 * ocupa unos 162 bytes, una de 2048 unos 294. No hace falta parsear el ASN.1
 * para distinguirlas, y distinguirlas es lo que importa: 1024 ya se considera
 * débil y Gmail lo penaliza.
 */
function diluxone_mail_dkim_key_bits( string $p ): int {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Es el formato en el que el estándar publica la clave; no hay otra forma de medirla.
	$der = base64_decode( $p, true );

	if ( false === $der ) {
		return 0;
	}

	$largo = strlen( $der );

	if ( $largo >= 500 ) {
		return 4096;
	}

	if ( $largo >= 250 ) {
		return 2048;
	}

	if ( $largo >= 120 ) {
		return 1024;
	}

	return 0;
}

/**
 * Sondea los selectores de un dominio.
 *
 * @param array<int, string> $selectors
 * @return array<int, array{selector: string, found: bool, via: string, cname: string, record: string, bits: int, revoked: bool}>
 */
function diluxone_mail_dkim_probe( string $domain, array $selectors ): array {
	$salida = array();

	foreach ( $selectors as $selector ) {
		$nombre = $selector . '._domainkey.' . $domain;
		$cname  = diluxone_mail_dns_lookup( $nombre, 'CNAME' )['records'];
		$txt    = diluxone_mail_dns_txt( $nombre );
		$record = '';

		foreach ( $txt as $t ) {
			// Un DKIM válido tiene p=; el v=DKIM1 es opcional en el estándar
			// y muchos proveedores no lo ponen.
			if ( false !== stripos( $t, 'p=' ) ) {
				$record = trim( $t );
				break;
			}
		}

		$p = '';

		if ( '' !== $record && preg_match( '/(?:^|;)\s*p=([^;]*)/i', $record, $m ) ) {
			$p = preg_replace( '/\s+/', '', (string) $m[1] ) ?? '';
		}

		$salida[] = array(
			'selector' => $selector,
			'found'    => '' !== $record,
			'via'      => array() !== $cname ? 'cname' : 'txt',
			'cname'    => (string) ( $cname[0] ?? '' ),
			'record'   => $record,
			'bits'     => '' !== $p ? diluxone_mail_dkim_key_bits( $p ) : 0,
			// Un p= vacío es la forma que da el estándar de decir «esta clave
			// fue revocada»: el selector existe pero ya no firma nada.
			'revoked'  => '' !== $record && '' === $p,
		);
	}

	return $salida;
}
