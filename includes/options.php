<?php
/**
 * Los ajustes del plugin, con sus valores por defecto en un solo lugar.
 *
 * Todo lo que en otros plugins es una constante o un número escrito en el
 * medio del código vive acá y se edita desde el admin.
 *
 * Ojo con una cosa: para los ocho valores del transporte —host, puerto,
 * usuario, contraseña, cifrado, remitente, nombre del remitente y proveedor—
 * esta lista es el último recurso, no la fuente. Antes mandan la constante de
 * PHP y la variable de entorno, y de eso se ocupa config.php. Acá están sus
 * valores por defecto porque hay que guardarlos en algún lado cuando quien
 * administra los escribe a mano, pero nunca se leen directamente: se leen con
 * diluxone_mail_config(), que respeta la precedencia.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Valores por defecto. La clave es el nombre de la option, con prefijo.
 *
 * @return array<string, mixed>
 */
function diluxone_mail_option_defaults(): array {
	return array(
		// ── El transporte ─────────────────────────────────────────────
		// El perfil de proveedor elegido: 'mailjet', 'm365', 'mailpit'…
		// Vacío significa que nadie configuró nada todavía, que es distinto
		// de haber elegido «SMTP genérico» a mano. La lista vive en
		// providers.php.
		'diluxone_mail_provider'                => '',
		'diluxone_mail_host'                    => '',
		'diluxone_mail_port'                    => 587,
		// 'none', 'ssl' —el puerto 465, cifrado desde el saludo— o 'tls'
		// —STARTTLS, que arranca en claro y sube—. Los perfiles locales van
		// en 'none' a propósito: ver providers.php.
		'diluxone_mail_encryption'              => 'tls',
		// Casi todos los proveedores piden usuario y contraseña. Mailpit y
		// MailHog no, y ahí esto se apaga solo desde el perfil.
		'diluxone_mail_auth'                    => 1,
		'diluxone_mail_user'                    => '',
		// Nunca vuelve al navegador y nunca se guarda acá si viene de una
		// constante o del entorno. Ver config.php.
		'diluxone_mail_pass'                    => '',
		// Segundos que se espera al servidor antes de dar por perdido el
		// envío. WordPress carga la página mientras tanto, así que un valor
		// alto es una página colgada.
		'diluxone_mail_timeout'                 => 30,

		// ── Quién firma el correo ─────────────────────────────────────
		// Vacío = el que ponga WordPress. Conviene llenarlo: el remitente por
		// defecto es wordpress@eldominio, que suele no existir y que muchos
		// proveedores rechazan por no estar autorizado.
		'diluxone_mail_from'                    => '',
		'diluxone_mail_from_name'               => '',
		// Pisar el remitente que traiga cada envío. Prendido arregla los
		// plugins que mandan desde direcciones inventadas; apagado respeta a
		// los que mandan desde una dirección real y distinta a propósito
		// —una tienda que contesta desde ventas@, por ejemplo—.
		'diluxone_mail_force_from'              => 0,

		// ── Cómo se mete el plugin en el envío ────────────────────────
		// 'auto'      si hay otro plugin gestionando el correo, no toca el
		// envío: registra y diagnostica. Si no hay ninguno, manda él.
		// 'observe'   nunca toca el envío, aunque no haya nadie más.
		// 'transport' manda él siempre, aunque haya otro. Es lo que hace el
		// botón «Tomar el control».
		'diluxone_mail_mode'                    => 'auto',
		// Desenganchar al plugin que corta el envío en pre_wp_mail antes de
		// que llegue a configurarse nada. Apagado por defecto y con su
		// advertencia: desenganchar al que manda el correo de verdad deja el
		// sitio sin correo. Ver pre-wp-mail.php.
		'diluxone_mail_unhook_pre_wp_mail'      => 0,

		// ── El historial ──────────────────────────────────────────────
		'diluxone_mail_log_enabled'             => 1,
		// Días que se guarda cada envío. La purga corre por cron.
		'diluxone_mail_log_retention_days'      => 30,
		// Guardar el cuerpo del mensaje. Apagado a propósito: el cuerpo es
		// dato personal —lleva nombres, pedidos, a veces una contraseña
		// temporal— y es lo que convierte un registro técnico en un problema
		// legal. Quien lo prenda sabe lo que hace.
		'diluxone_mail_log_body'                => 0,
		// Y si lo prende, el cuerpo se borra antes que el resto de la fila:
		// para diagnosticar «no me llegó el de ayer» alcanza con unos días.
		'diluxone_mail_log_body_retention_days' => 7,

		// ── El diagnóstico de DNS ─────────────────────────────────────
		// Vacío = el dominio del sitio. Se puede fijar otro cuando el correo
		// sale desde un dominio distinto al que sirve las páginas.
		'diluxone_mail_dns_domain'              => '',
		// Selectores DKIM extra para sondear, además de los conocidos y de
		// los que declare el perfil del proveedor activo. Por DNS no se
		// pueden enumerar: o se adivinan o se preguntan.
		'diluxone_mail_dns_selectors'           => array(),
		// Cómo se consulta el DNS: 'auto' usa el del sistema y cae a
		// DNS-over-HTTPS si el hosting tiene dns_get_record() deshabilitada
		// —que son muchos—; 'system' y 'doh' fuerzan uno u otro.
		'diluxone_mail_dns_resolver'            => 'auto',
		'diluxone_mail_dns_doh_endpoint'        => 'https://cloudflare-dns.com/dns-query',
		// Horas que vale el resultado cacheado. El DNS no cambia cada vez que
		// alguien abre el admin, y hay un botón de revalidar para cuando sí.
		'diluxone_mail_dns_cache_hours'         => 12,

		// ── Privacidad ────────────────────────────────────────────────
		// El historial es dato personal: qué se le mandó a alguien y cuándo.
		// Los dos vienen prendidos porque es lo que corresponde, y un sitio
		// que prefiera atender esos pedidos a mano los apaga.
		'diluxone_mail_privacy_export'          => 1,
		'diluxone_mail_privacy_erase'           => 1,
	);
}

/**
 * Las options que no se editan: las escribe el plugin para sí mismo.
 *
 * Están fuera de los defaults a propósito. Si estuvieran adentro, el guardado
 * del formulario las aceptaría como si fueran un ajuste más, y un POST armado
 * a mano podría escribir la versión del esquema de la base.
 *
 * @return array<int, string>
 */
function diluxone_mail_internal_options(): array {
	return array(
		// Versión del esquema de las tablas, para saber cuándo migrar.
		'diluxone_mail_db_version',
		// Lo que pasó en el último envío, para la pantalla de estado.
		'diluxone_mail_last_result',
	);
}

/**
 * Un ajuste, con su valor por defecto.
 *
 * @param string $key      Nombre de la option, con prefijo.
 * @param mixed  $fallback Valor si no hay ni option ni default.
 * @return mixed
 */
function diluxone_mail_option( string $key, $fallback = null ) {
	$defaults = diluxone_mail_option_defaults();
	$value    = get_option( $key, null );

	if ( null === $value ) {
		$value = $defaults[ $key ] ?? $fallback;
	}

	/**
	 * Filtra un ajuste del plugin.
	 *
	 * @param mixed  $value Valor resuelto.
	 * @param string $key   Nombre de la option.
	 */
	return apply_filters( 'diluxone_mail_option', $value, $key );
}

/**
 * ¿Este ajuste lo está forzando el sitio desde código?
 *
 * Otro plugin puede fijar un valor por el filtro `diluxone_mail_option`
 * —porque en ese sitio no es una opción sino cómo funciona—. Cuando eso pasa,
 * el control del admin se guarda y no cambia nada, que es exactamente la clase
 * de mentira que hay que evitar en una pantalla de ajustes. Con esto se puede
 * mostrar al lado del control.
 *
 * Ojo que esto es el filtro, no la precedencia de entorno: que un valor venga
 * de una constante o de una variable de entorno lo contesta config.php, y se
 * muestra distinto porque se arregla en otro lado.
 */
function diluxone_mail_option_forced( string $key ): bool {
	$defaults = diluxone_mail_option_defaults();
	$stored   = get_option( $key, null );
	$stored   = null === $stored ? ( $defaults[ $key ] ?? null ) : $stored;

	return diluxone_mail_option( $key ) !== $stored;
}

/**
 * Quién está fijando un ajuste desde el código.
 *
 * «Algo del sitio decidió esto» no le sirve a nadie: quien lee eso quiere ir
 * a sacarlo, y no sabe dónde. Acá sale el archivo y la función, que es lo que
 * hace falta para encontrarlo. Se listan todos los enganchados al filtro
 * porque cualquiera de ellos puede ser el que manda; cuál de todos, lo dice
 * abrir el archivo.
 *
 * @return array<int, string>
 */
function diluxone_mail_option_forced_by(): array {
	global $wp_filter;

	if ( ! isset( $wp_filter['diluxone_mail_option'] ) ) {
		return array();
	}

	$quienes = array();

	foreach ( $wp_filter['diluxone_mail_option']->callbacks as $enganchados ) {
		foreach ( $enganchados as $enganche ) {
			$fn = $enganche['function'];

			if ( ! is_string( $fn ) || ! function_exists( $fn ) ) {
				continue;
			}

			try {
				$archivo = (string) ( new ReflectionFunction( $fn ) )->getFileName();
			} catch ( ReflectionException $e ) {
				continue;
			}

			$quienes[] = sprintf(
				'%s() — %s',
				$fn,
				ltrim( str_replace( wp_normalize_path( WP_PLUGIN_DIR ), '', wp_normalize_path( $archivo ) ), '/' )
			);
		}
	}

	return $quienes;
}

/**
 * Guarda los ajustes que llegan de una pantalla del admin.
 *
 * Dos reglas que no son obvias y que valen por toda la seguridad del
 * formulario:
 *
 * 1. Sólo se guarda lo que está en los defaults. Una clave que no esté ahí se
 *    descarta sin avisar, así un POST armado a mano no puede escribir
 *    cualquier option del sitio.
 * 2. Lo que manda el entorno no se guarda nunca. Si el host sale de una
 *    constante de PHP, escribir la option sería guardar un valor que no se usa
 *    y que además contradice al que sí: la base terminaría teniendo una
 *    credencial vieja que nadie está usando pero que cualquiera puede leer.
 *
 * @param array<string, mixed> $input
 */
function diluxone_mail_save_options( array $input ): void {
	$defaults = diluxone_mail_option_defaults();

	foreach ( $input as $key => $value ) {
		if ( ! array_key_exists( $key, $defaults ) ) {
			continue;
		}

		if ( diluxone_mail_option_from_environment( $key ) ) {
			continue;
		}

		$default = $defaults[ $key ];

		if ( is_int( $default ) ) {
			update_option( $key, (int) $value );
			continue;
		}

		// Listas de claves —los selectores DKIM que agrega el sitio— saneadas
		// elemento por elemento y sin índices sueltos.
		if ( is_array( $default ) ) {
			update_option( $key, array_values( array_unique( array_map( 'sanitize_key', (array) $value ) ) ) );
			continue;
		}

		update_option( $key, sanitize_textarea_field( (string) $value ) );
	}
}
