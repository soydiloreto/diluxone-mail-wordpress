<?php
/**
 * DMARC: qué política hay, a dónde van los reportes, y qué implica.
 *
 * DMARC es el que decide qué hace el receptor cuando SPF y DKIM no
 * alcanzan. Con `p=none` no hace nada —sólo reporta—; con `p=quarantine` va a
 * spam; con `p=reject` no llega. Y para que un correo pase DMARC no alcanza
 * con que SPF o DKIM den bien: alguno de los dos tiene que estar ALINEADO,
 * es decir, hablar del mismo dominio que aparece en el From. Eso es lo que
 * la gente no sabe y lo que este archivo explica.
 *
 * Lo otro que nadie mira: si los reportes van a una dirección de otro
 * dominio, ese otro dominio tiene que autorizarlo con un registro propio
 * (RFC 7489, §7.1). Si no lo publica, los reportes se descartan sin aviso, y
 * el dueño del dominio cree que nadie está falsificando su correo porque
 * nunca le llega nada.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * El registro DMARC de un dominio, parseado.
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
	$salida = array(
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

	$registros = array();

	foreach ( diluxone_mail_dns_txt( '_dmarc.' . $domain ) as $txt ) {
		if ( preg_match( '/^v=DMARC1(\s|;|$)/i', trim( $txt ) ) ) {
			$registros[] = trim( $txt );
		}
	}

	if ( array() === $registros ) {
		return $salida;
	}

	if ( count( $registros ) > 1 ) {
		$salida['errors'][] = __( 'There is more than one DMARC record. Receivers ignore all of them when that happens.', 'diluxone-mail' );
	}

	$salida['record'] = $registros[0];
	$tags             = array();

	foreach ( explode( ';', $registros[0] ) as $par ) {
		$par = trim( $par );

		if ( '' === $par || false === strpos( $par, '=' ) ) {
			continue;
		}

		list( $clave, $valor ) = explode( '=', $par, 2 );

		$tags[ strtolower( trim( $clave ) ) ] = trim( $valor );
	}

	$salida['policy']           = strtolower( (string) ( $tags['p'] ?? '' ) );
	$salida['subdomain_policy'] = strtolower( (string) ( $tags['sp'] ?? $salida['policy'] ) );
	$salida['pct']              = isset( $tags['pct'] ) ? max( 0, min( 100, (int) $tags['pct'] ) ) : 100;
	$salida['adkim']            = 's' === strtolower( (string) ( $tags['adkim'] ?? 'r' ) ) ? 's' : 'r';
	$salida['aspf']             = 's' === strtolower( (string) ( $tags['aspf'] ?? 'r' ) ) ? 's' : 'r';

	if ( '' === $salida['policy'] ) {
		$salida['errors'][] = __( 'The record has no policy (p=). Without it the record is invalid and receivers ignore it.', 'diluxone-mail' );
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

			// El sufijo !10m es el tamaño máximo del reporte, no parte de la
			// dirección.
			$direccion = strtolower( (string) preg_replace( '/!.*$/', '', substr( $uri, 7 ) ) );

			if ( is_email( $direccion ) ) {
				$salida[ $tag ][] = $direccion;
			}
		}
	}

	$salida['external'] = diluxone_mail_dmarc_external_authorizations( $domain, array_unique( array_merge( $salida['rua'], $salida['ruf'] ) ) );

	return $salida;
}

/**
 * ¿Los dominios ajenos que reciben reportes lo autorizaron?
 *
 * Para cada dirección cuyo dominio no es el del registro, tiene que existir
 * `<dominio>._report._dmarc.<dominio-del-reporte>` con `v=DMARC1`. Es un
 * registro que nadie recuerda publicar y que, faltando, tira los reportes a
 * la basura.
 *
 * @param array<int, string> $addresses
 * @return array<int, array{address: string, domain: string, authorized: bool}>
 */
function diluxone_mail_dmarc_external_authorizations( string $domain, array $addresses ): array {
	$salida = array();

	foreach ( $addresses as $direccion ) {
		$dominio_reporte = strtolower( (string) substr( $direccion, (int) strrpos( $direccion, '@' ) + 1 ) );

		// El mismo dominio, o un subdominio suyo, no necesita autorización:
		// el estándar los trata como la misma organización.
		if ( $dominio_reporte === $domain || str_ends_with( $dominio_reporte, '.' . $domain ) || str_ends_with( $domain, '.' . $dominio_reporte ) ) {
			continue;
		}

		$autorizado = false;

		foreach ( diluxone_mail_dns_txt( $domain . '._report._dmarc.' . $dominio_reporte ) as $txt ) {
			if ( preg_match( '/^v=DMARC1/i', trim( $txt ) ) ) {
				$autorizado = true;
				break;
			}
		}

		$salida[] = array(
			'address'    => $direccion,
			'domain'     => $dominio_reporte,
			'authorized' => $autorizado,
		);
	}

	return $salida;
}
