<?php
/**
 * SPF: qué dice el registro, y cuántos lookups gasta.
 *
 * El detalle que casi nadie sabe y que rompe el correo de muchísimos sitios:
 * el estándar (RFC 7208, §4.6.4) permite como máximo 10 consultas de DNS para
 * evaluar un SPF, contando las de los `include` recursivamente. Pasarse no
 * degrada nada: el receptor devuelve «permerror» y el SPF entero deja de
 * valer, como si no existiera. Y nadie avisa. Un sitio suma un proveedor, y
 * otro, y otro, y un día Gmail empieza a mandar todo a spam.
 *
 * Cuentan `include`, `a`, `mx`, `ptr`, `exists` y el modificador `redirect`.
 * No cuentan `ip4`, `ip6` ni `all`. Acá se expande el árbol completo y se
 * cuenta igual que lo cuenta un receptor.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/** El límite del estándar. */
const DILUXONE_MAIL_SPF_MAX_LOOKUPS = 10;

/**
 * El registro SPF de un dominio.
 *
 * @return array{record: string|null, error: string}
 *         Dos registros SPF es un error por sí mismo —el estándar lo
 *         invalida— y se informa como tal.
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
 * Los términos de un registro, uno por uno.
 *
 * @return array<int, array{qualifier: string, mechanism: string, value: string, counts: bool, follow: string}>
 *         follow: el dominio al que hay que ir a mirar (include/redirect), o vacío.
 */
function diluxone_mail_spf_terms( string $record ): array {
	$terms = array();

	$partes = preg_split( '/\s+/', trim( $record ) );

	foreach ( false === $partes ? array() : $partes as $i => $term ) {
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

		// exp= y cualquier modificador desconocido: no cuentan y no se siguen.
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
 * Expande el árbol de un dominio y cuenta los lookups.
 *
 * @param string                           $domain
 * @param int                              $depth
 * @param array<string, true>              $seen   Dominios ya visitados, contra los bucles.
 * @param array<int, array<string, mixed>> $tree  Se llena de arriba a abajo.
 * @return int Lookups de este dominio y de todo lo que cuelga de él.
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

	// Veinte niveles es más de lo que ningún SPF sano tiene; sirve para no
	// seguir un árbol roto para siempre.
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

	$terms   = diluxone_mail_spf_terms( $spf['record'] );
	$propios = count( array_filter( $terms, static fn( array $t ): bool => $t['counts'] ) );
	$indice  = count( $tree );

	$tree[] = array(
		'depth'   => $depth,
		'domain'  => $domain,
		'record'  => $spf['record'],
		'lookups' => $propios,
		'error'   => $spf['error'],
	);

	$total = $propios;

	foreach ( $terms as $term ) {
		if ( '' !== $term['follow'] ) {
			$total += diluxone_mail_spf_walk( $term['follow'], $depth + 1, $seen, $tree );
		}
	}

	$tree[ $indice ]['subtotal'] = $total;

	return $total;
}

/**
 * El análisis completo del SPF de un dominio.
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
	$raiz    = $tree[0] ?? null;
	$record  = null !== $raiz && '' !== $raiz['record'] ? (string) $raiz['record'] : null;
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
		'error'      => null !== $raiz ? (string) $raiz['error'] : '',
		'lookups'    => $lookups,
		'over_limit' => $lookups > DILUXONE_MAIL_SPF_MAX_LOOKUPS,
		'all'        => $all,
		'includes'   => array_values( array_unique( $incl ) ),
		'tree'       => $tree,
	);
}
