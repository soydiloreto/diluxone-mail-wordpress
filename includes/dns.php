<?php
/**
 * Cómo se le pregunta al DNS.
 *
 * Toda consulta del diagnóstico pasa por acá, y por una razón: muchos
 * hostings tienen dns_get_record() deshabilitada, y sin un camino
 * alternativo el diagnóstico no funciona en medio mercado. Así que se
 * intenta primero con la función de PHP y, si no está o falla, se cae a
 * DNS-over-HTTPS contra un resolver público con wp_remote_get(). El
 * formato JSON de DoH lo sirven igual Cloudflare y Google, así que el
 * endpoint es un ajuste.
 *
 * Los resultados se cachean. El DNS no cambia cada vez que alguien abre el
 * admin, y cada diagnóstico son veinte consultas o más; hay un botón de
 * revalidar para cuando sí cambió.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/** Los tipos de registro que se consultan, con su código numérico de DNS. */
/**
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
 * ¿Se puede usar dns_get_record() en este hosting?
 *
 * No alcanza con function_exists(): la función existe y está en la lista de
 * disable_functions, y llamarla es un aviso y un false.
 */
function diluxone_mail_dns_system_available(): bool {
	if ( ! function_exists( 'dns_get_record' ) ) {
		return false;
	}

	$deshabilitadas = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

	return ! in_array( 'dns_get_record', $deshabilitadas, true );
}

/**
 * El dominio que se diagnostica.
 *
 * El configurado si hay; si no, el del remitente del transporte, que es el
 * que aparece en el From y por el que se juzga el correo; y si tampoco hay,
 * el del sitio.
 */
function diluxone_mail_dns_domain(): string {
	$configurado = strtolower( trim( (string) diluxone_mail_option( 'diluxone_mail_dns_domain' ) ) );

	if ( '' !== $configurado ) {
		return $configurado;
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
 * Consulta con dns_get_record().
 *
 * @return array<int, string>|null null si no se pudo consultar.
 */
function diluxone_mail_dns_system( string $name, string $type ): ?array {
	if ( ! diluxone_mail_dns_system_available() ) {
		return null;
	}

	$codigos = array(
		'A'     => DNS_A,
		'CNAME' => DNS_CNAME,
		'MX'    => DNS_MX,
		'TXT'   => DNS_TXT,
	);

	if ( ! isset( $codigos[ $type ] ) ) {
		return null;
	}

	// El @ es a propósito: un dominio que no existe hace que dns_get_record()
	// tire un aviso además de devolver false, y el aviso no le sirve a nadie.
	$registros = @dns_get_record( $name, $codigos[ $type ] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	if ( false === $registros ) {
		return null;
	}

	$salida = array();

	foreach ( $registros as $registro ) {
		switch ( $type ) {
			case 'TXT':
				// 'txt' trae las partes ya unidas; un registro largo viene
				// partido en cadenas de 255 y hay que verlo entero.
				$salida[] = (string) ( $registro['txt'] ?? implode( '', (array) ( $registro['entries'] ?? array() ) ) );
				break;
			case 'CNAME':
				$salida[] = strtolower( rtrim( (string) ( $registro['target'] ?? '' ), '.' ) );
				break;
			case 'MX':
				$salida[] = (int) ( $registro['pri'] ?? 0 ) . ' ' . strtolower( rtrim( (string) ( $registro['target'] ?? '' ), '.' ) );
				break;
			default:
				$salida[] = (string) ( $registro['ip'] ?? '' );
		}
	}

	return array_values( array_filter( $salida, static fn( string $v ): bool => '' !== $v ) );
}

/**
 * Consulta por DNS-over-HTTPS.
 *
 * @return array<int, string>|null null si no se pudo consultar.
 */
function diluxone_mail_dns_doh( string $name, string $type ): ?array {
	$endpoint = (string) diluxone_mail_option( 'diluxone_mail_dns_doh_endpoint' );

	if ( '' === $endpoint ) {
		return null;
	}

	$respuesta = wp_remote_get(
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

	if ( is_wp_error( $respuesta ) || 200 !== (int) wp_remote_retrieve_response_code( $respuesta ) ) {
		return null;
	}

	$json = json_decode( (string) wp_remote_retrieve_body( $respuesta ), true );

	if ( ! is_array( $json ) || ! isset( $json['Status'] ) ) {
		return null;
	}

	// 3 es NXDOMAIN: el nombre no existe. Es una respuesta válida y vacía,
	// no un fallo del resolver.
	if ( 3 === (int) $json['Status'] ) {
		return array();
	}

	if ( 0 !== (int) $json['Status'] ) {
		return null;
	}

	$codigo = diluxone_mail_dns_types()[ $type ];
	$salida = array();

	foreach ( (array) ( $json['Answer'] ?? array() ) as $registro ) {
		if ( ! is_array( $registro ) || (int) ( $registro['type'] ?? 0 ) !== $codigo ) {
			continue;
		}

		$data = (string) ( $registro['data'] ?? '' );

		if ( 'TXT' === $type ) {
			// Viene con las comillas de la sintaxis de zona: "abc" "def".
			// Se sacan y se unen, que es lo que hace un cliente de correo.
			$trozos = preg_split( '/"\s+"/', trim( $data, '"' ) );
			$data   = implode( '', array_map( 'stripcslashes', false === $trozos ? array() : $trozos ) );
		} elseif ( 'CNAME' === $type ) {
			$data = strtolower( rtrim( $data, '.' ) );
		} elseif ( 'MX' === $type ) {
			$data = strtolower( rtrim( $data, '.' ) );
		}

		if ( '' !== $data ) {
			$salida[] = $data;
		}
	}

	return $salida;
}

/**
 * Una consulta, cacheada, por el camino que haya.
 *
 * @return array{records: array<int, string>, source: string, error: string}
 *         source: 'system', 'doh' o 'cache'; error: vacío o el motivo.
 */
function diluxone_mail_dns_lookup( string $name, string $type ): array {
	$name  = strtolower( rtrim( trim( $name ), '.' ) );
	$type  = strtoupper( $type );
	$clave = 'diluxone_mail_dns_' . md5( $type . '|' . $name );

	$cacheado = get_site_transient( $clave );

	if ( is_array( $cacheado ) && isset( $cacheado['records'] ) ) {
		return array(
			'records' => (array) $cacheado['records'],
			'source'  => 'cache',
			'error'   => '',
		);
	}

	$modo      = (string) diluxone_mail_option( 'diluxone_mail_dns_resolver' );
	$registros = null;
	$fuente    = '';

	if ( 'doh' !== $modo ) {
		$registros = diluxone_mail_dns_system( $name, $type );
		$fuente    = 'system';
	}

	if ( null === $registros && 'system' !== $modo ) {
		$registros = diluxone_mail_dns_doh( $name, $type );
		$fuente    = 'doh';
	}

	if ( null === $registros ) {
		return array(
			'records' => array(),
			'source'  => $fuente,
			'error'   => __( 'The DNS could not be queried: dns_get_record() is unavailable and the DNS-over-HTTPS resolver did not answer.', 'diluxone-mail' ),
		);
	}

	$horas = max( 1, (int) diluxone_mail_option( 'diluxone_mail_dns_cache_hours' ) );

	set_site_transient( $clave, array( 'records' => $registros ), $horas * HOUR_IN_SECONDS );
	diluxone_mail_dns_remember_key( $clave );

	return array(
		'records' => $registros,
		'source'  => $fuente,
		'error'   => '',
	);
}

/**
 * Anota qué transients hay, para poder vaciarlos todos con el botón.
 *
 * WordPress no lista transients por prefijo sin ir a la base directo, y
 * con un caché de objetos ni eso sirve. Una lista es más barata y más
 * honesta.
 */
function diluxone_mail_dns_remember_key( string $clave ): void {
	$claves = (array) get_site_option( 'diluxone_mail_dns_cache_keys', array() );

	if ( in_array( $clave, $claves, true ) ) {
		return;
	}

	$claves[] = $clave;

	update_site_option( 'diluxone_mail_dns_cache_keys', array_slice( $claves, -500 ) );
}

/** Vacía el caché del DNS. Es lo que hace el botón «revalidar». */
function diluxone_mail_dns_flush(): void {
	foreach ( (array) get_site_option( 'diluxone_mail_dns_cache_keys', array() ) as $clave ) {
		delete_site_transient( (string) $clave );
	}

	delete_site_option( 'diluxone_mail_dns_cache_keys' );
	delete_site_transient( 'diluxone_mail_diagnosis_' . md5( diluxone_mail_dns_domain() ) );
}

/** Los TXT de un nombre. Atajo del diagnóstico. */
/**
 * @return array<int, string>
 */
function diluxone_mail_dns_txt( string $name ): array {
	return diluxone_mail_dns_lookup( $name, 'TXT' )['records'];
}
