<?php
/**
 * De dónde sale cada valor del transporte.
 *
 * La idea entera del plugin en una frase: las credenciales de producción
 * viven en las variables del hosting y nunca tocan la base ni el repo. El
 * mismo código sirve para la máquina local y para producción sin que nadie
 * entre al admin a cambiar nada en cada despliegue.
 *
 * Por eso hay tres capas, de mayor a menor:
 *
 *   1. Una constante de PHP definida en wp-config.php     DILUXONE_MAIL_HOST
 *   2. Una variable de entorno con el mismo nombre        DILUXONE_MAIL_HOST
 *   3. La option de la base, editable desde el admin      diluxone_mail_host
 *
 * Y por eso cada lectura devuelve además de dónde salió el valor: el
 * formulario necesita saberlo para mostrar el control de sólo lectura con la
 * leyenda «definido por el entorno», y el guardado necesita saberlo para no
 * escribir en la base una credencial que igual no se va a usar.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Los campos del transporte, y cómo se llama cada uno en cada capa.
 *
 * La clave es el nombre corto que usa el resto del plugin; el valor es el
 * sufijo, que sirve para las dos cosas: `DILUXONE_MAIL_` + sufijo da la
 * constante y la variable de entorno, y `diluxone_mail_` + sufijo en
 * minúsculas da la option.
 *
 * @return array<string, string>
 */
function diluxone_mail_config_fields(): array {
	return array(
		'provider'   => 'PROVIDER',
		'host'       => 'HOST',
		'port'       => 'PORT',
		'user'       => 'USER',
		'pass'       => 'PASS',
		'encryption' => 'ENCRYPTION',
		'from'       => 'FROM',
		'from_name'  => 'FROM_NAME',
	);
}

/**
 * Un valor del transporte, con su procedencia.
 *
 * @param string $campo Una clave de diluxone_mail_config_fields().
 * @return array{value: string, source: string, origin: string}
 *         source: 'constant', 'env', 'option' o 'default'.
 *         origin: el nombre concreto de la constante, la variable o la option.
 */
function diluxone_mail_config_value( string $campo ): array {
	$campos = diluxone_mail_config_fields();

	if ( ! isset( $campos[ $campo ] ) ) {
		return array(
			'value'  => '',
			'source' => 'default',
			'origin' => '',
		);
	}

	$sufijo    = $campos[ $campo ];
	$constante = 'DILUXONE_MAIL_' . $sufijo;
	$option    = 'diluxone_mail_' . strtolower( $sufijo );

	if ( defined( $constante ) ) {
		return array(
			'value'  => (string) constant( $constante ),
			'source' => 'constant',
			'origin' => $constante,
		);
	}

	// getenv() devuelve false cuando no está definida, y cadena vacía cuando
	// está definida y vacía. Una variable vacía es lo mismo que no tenerla:
	// quien exporta DILUXONE_MAIL_HOST= no está configurando un host vacío,
	// está dejando el renglón a medio escribir.
	$entorno = getenv( $constante );

	if ( is_string( $entorno ) && '' !== $entorno ) {
		return array(
			'value'  => $entorno,
			'source' => 'env',
			'origin' => $constante,
		);
	}

	$guardado = get_option( $option, null );

	if ( null !== $guardado && '' !== (string) $guardado ) {
		return array(
			'value'  => (string) $guardado,
			'source' => 'option',
			'origin' => $option,
		);
	}

	$defaults = diluxone_mail_option_defaults();

	return array(
		'value'  => (string) ( $defaults[ $option ] ?? '' ),
		'source' => 'default',
		'origin' => $option,
	);
}

/**
 * Todos los valores del transporte ya resueltos.
 *
 * @return array<string, string>
 */
function diluxone_mail_config(): array {
	$config = array();

	foreach ( array_keys( diluxone_mail_config_fields() ) as $campo ) {
		$config[ $campo ] = diluxone_mail_config_value( $campo )['value'];
	}

	/**
	 * Filtra la configuración del transporte ya resuelta.
	 *
	 * @param array<string, string> $config
	 */
	return apply_filters( 'diluxone_mail_config', $config );
}

/**
 * ¿Esta option la manda el entorno, y por lo tanto no hay que guardarla?
 *
 * La usa el guardado del formulario. Recibe el nombre de la option y no el
 * del campo porque es lo que tiene a mano cuando recorre el POST.
 */
function diluxone_mail_option_from_environment( string $option_key ): bool {
	foreach ( array_keys( diluxone_mail_config_fields() ) as $campo ) {
		if ( 'diluxone_mail_' . strtolower( diluxone_mail_config_fields()[ $campo ] ) !== $option_key ) {
			continue;
		}

		return in_array( diluxone_mail_config_value( $campo )['source'], array( 'constant', 'env' ), true );
	}

	return false;
}

/**
 * Tapa la contraseña en cualquier texto que vaya a salir del servidor.
 *
 * Se usa en el historial, en la pantalla de estado, en el volcado del
 * SMTPDebug y en toda exportación. Busca el valor literal y lo reemplaza;
 * no alcanza con no imprimirla a propósito, porque el que la imprime sin
 * querer es siempre otro —una traza de error, el diálogo del servidor—.
 *
 * Lo segundo que reemplaza es la contraseña en base64, y ése es el caso que
 * importa de verdad: el diálogo SMTP no manda la contraseña en claro, manda
 * `AUTH LOGIN` y después el usuario y la contraseña codificados. Tapar sólo
 * el literal deja la credencial entera a la vista en el volcado, en una línea
 * que cualquiera decodifica en un segundo.
 */
function diluxone_mail_redact( string $texto ): string {
	$pass = diluxone_mail_config_value( 'pass' )['value'];

	if ( '' === $pass ) {
		return $texto;
	}

	return str_replace(
		array( $pass, base64_encode( $pass ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- No se está ofuscando nada: es el mismo formato en el que la contraseña aparece en el diálogo SMTP, y hay que encontrarla para taparla.
		'***',
		$texto
	);
}
