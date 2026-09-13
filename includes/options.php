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
 * En una red —multisitio— hay además dos lugares donde guardar: la red, que
 * fija el superadministrador para todos, y cada sitio. El servidor de correo
 * es infraestructura de la red, no una preferencia de cada sitio, así que la
 * red manda: un sitio sólo puede tener lo suyo si la red se lo permite. Y
 * cuando no se lo permite, la pantalla del sitio lo muestra de sólo lectura
 * diciendo que lo fija la red, en vez de dejar cambiar algo que no cambia
 * nada.
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
		'diluxone_mail_provider'                  => '',
		'diluxone_mail_host'                      => '',
		'diluxone_mail_port'                      => 587,
		// 'none', 'ssl' —el puerto 465, cifrado desde el saludo— o 'tls'
		// —STARTTLS, que arranca en claro y sube—. Los perfiles locales van
		// en 'none' a propósito: ver providers.php.
		'diluxone_mail_encryption'                => 'tls',
		// Casi todos los proveedores piden usuario y contraseña. Mailpit y
		// MailHog no, y ahí esto se apaga solo desde el perfil.
		'diluxone_mail_auth'                      => 1,
		'diluxone_mail_user'                      => '',
		// Nunca vuelve al navegador y nunca se guarda acá si viene de una
		// constante o del entorno. Ver config.php.
		'diluxone_mail_pass'                      => '',
		// Segundos que se espera al servidor antes de dar por perdido el
		// envío. WordPress carga la página mientras tanto, así que un valor
		// alto es una página colgada.
		'diluxone_mail_timeout'                   => 30,

		// ── Quién firma el correo ─────────────────────────────────────
		// Vacío = el que ponga WordPress. Conviene llenarlo: el remitente por
		// defecto es wordpress@eldominio, que suele no existir y que muchos
		// proveedores rechazan por no estar autorizado.
		'diluxone_mail_from'                      => '',
		'diluxone_mail_from_name'                 => '',
		// Pisar el remitente que traiga cada envío. Prendido arregla los
		// plugins que mandan desde direcciones inventadas; apagado respeta a
		// los que mandan desde una dirección real y distinta a propósito
		// —una tienda que contesta desde ventas@, por ejemplo—.
		'diluxone_mail_force_from'                => 0,

		// ── Cómo se mete el plugin en el envío ────────────────────────
		// 'auto'      si hay otro plugin gestionando el correo, no toca el
		// envío: registra y diagnostica. Si no hay ninguno, manda él.
		// 'observe'   nunca toca el envío, aunque no haya nadie más.
		// 'transport' manda él siempre, aunque haya otro. Es lo que hace el
		// botón «Tomar el control».
		'diluxone_mail_mode'                      => 'auto',
		// Desenganchar al plugin que corta el envío en pre_wp_mail antes de
		// que llegue a configurarse nada. Apagado por defecto y con su
		// advertencia: desenganchar al que manda el correo de verdad deja el
		// sitio sin correo. Ver pre-wp-mail.php.
		'diluxone_mail_unhook_pre_wp_mail'        => 0,

		// ── El historial ──────────────────────────────────────────────
		// El historial común: fecha, destinatario, remitente, asunto, estado,
		// error si lo hubo, proveedor y quién originó el envío. Es la tabla
		// que cuelga de la ficha de cada persona y viene prendido porque es
		// la razón de ser del plugin.
		'diluxone_mail_log_enabled'               => 1,
		// Días que se guarda cada envío. La purga corre por cron.
		'diluxone_mail_log_retention_days'        => 30,
		// El historial extendido: además, las cabeceras, los nombres de los
		// adjuntos y el diálogo SMTP completo con el proveedor —cada comando y
		// cada respuesta—. Es lo que hace falta para discutir con el soporte
		// del proveedor, y cuesta capturar el diálogo en cada envío, así que
		// viene apagado.
		'diluxone_mail_log_extended'              => 0,
		// Guardar el cuerpo del mensaje. Apagado a propósito: el cuerpo es
		// dato personal —lleva nombres, pedidos, a veces una contraseña
		// temporal— y es lo que convierte un registro técnico en un problema
		// legal. Quien lo prenda sabe lo que hace. Sin el cuerpo no se puede
		// reenviar un mensaje, y la ficha de la persona lo dice.
		'diluxone_mail_log_body'                  => 0,
		// El cuerpo y el diálogo SMTP se borran antes que el resto de la
		// fila: para diagnosticar «no me llegó el de ayer» alcanza con unos
		// días, y son lo más pesado y lo más sensible que se guarda.
		'diluxone_mail_log_detail_retention_days' => 7,

		// ── El diagnóstico de DNS ─────────────────────────────────────
		// Vacío = el dominio del remitente configurado o, si no hay, el del
		// sitio. Se puede fijar otro cuando el correo sale desde un dominio
		// distinto al que sirve las páginas.
		'diluxone_mail_dns_domain'                => '',
		// Selectores DKIM extra para sondear, además de los conocidos y de
		// los que declare el perfil del proveedor activo. Por DNS no se
		// pueden enumerar: o se adivinan o se preguntan.
		'diluxone_mail_dns_selectors'             => array(),
		// Cómo se consulta el DNS: 'auto' usa el del sistema y cae a
		// DNS-over-HTTPS si el hosting tiene dns_get_record() deshabilitada
		// —que son muchos—; 'system' y 'doh' fuerzan uno u otro.
		'diluxone_mail_dns_resolver'              => 'auto',
		'diluxone_mail_dns_doh_endpoint'          => 'https://cloudflare-dns.com/dns-query',
		// Horas que vale el resultado cacheado. El DNS no cambia cada vez que
		// alguien abre el admin, y hay un botón de revalidar para cuando sí.
		'diluxone_mail_dns_cache_hours'           => 12,

		// ── Privacidad ────────────────────────────────────────────────
		// El historial es dato personal: qué se le mandó a alguien y cuándo.
		// Los dos vienen prendidos porque es lo que corresponde, y un sitio
		// que prefiera atender esos pedidos a mano los apaga.
		'diluxone_mail_privacy_export'            => 1,
		'diluxone_mail_privacy_erase'             => 1,

		// ── La red ────────────────────────────────────────────────────
		// Sólo existe en una red. Prendido, cada sitio puede pisar los
		// ajustes de la red con los suyos; apagado, lo que fija el
		// superadministrador vale para todos y las pantallas de cada sitio
		// lo muestran de sólo lectura.
		'diluxone_mail_network_allow_override'    => 0,
	);
}

/**
 * Las options que sólo tienen sentido guardadas en la red.
 *
 * Un sitio no puede decidir si los sitios pueden pisar a la red: eso lo
 * decide la red. Guardarlo a nivel de sitio sería una option que nadie lee.
 *
 * @return array<int, string>
 */
function diluxone_mail_network_only_options(): array {
	return array( 'diluxone_mail_network_allow_override' );
}

/**
 * ¿Los ajustes de este sitio pueden pisar los de la red?
 *
 * Fuera de una red la pregunta no existe: el sitio es todo lo que hay.
 */
function diluxone_mail_site_override_allowed(): bool {
	if ( ! is_multisite() ) {
		return true;
	}

	return (bool) get_site_option( 'diluxone_mail_network_allow_override', 0 );
}

/**
 * Un ajuste tal como está guardado, y de qué capa salió.
 *
 * Es la única función que sabe que existen dos lugares donde guardar. Todo
 * lo demás pregunta acá y recibe un valor con su procedencia, que es lo que
 * las pantallas necesitan para decir «esto lo fija la red».
 *
 * @return array{value: mixed, scope: string}
 *         scope: 'site', 'network' o 'default'.
 */
function diluxone_mail_option_stored( string $key ): array {
	$defaults = diluxone_mail_option_defaults();

	if ( is_multisite() ) {
		if ( diluxone_mail_site_override_allowed() && ! in_array( $key, diluxone_mail_network_only_options(), true ) ) {
			$site = get_option( $key, null );

			if ( null !== $site ) {
				return array(
					'value' => $site,
					'scope' => 'site',
				);
			}
		}

		$network = get_site_option( $key, null );

		if ( null !== $network ) {
			return array(
				'value' => $network,
				'scope' => 'network',
			);
		}
	} else {
		$site = get_option( $key, null );

		if ( null !== $site ) {
			return array(
				'value' => $site,
				'scope' => 'site',
			);
		}
	}

	return array(
		'value' => $defaults[ $key ] ?? null,
		'scope' => 'default',
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
	$value = diluxone_mail_option_stored( $key )['value'] ?? $fallback;

	/**
	 * Filtra un ajuste del plugin.
	 *
	 * @param mixed  $value Valor resuelto.
	 * @param string $key   Nombre de la option.
	 */
	return apply_filters( 'diluxone_mail_option', $value, $key );
}

/**
 * Guarda los ajustes que llegan de una pantalla del admin.
 *
 * Tres reglas que no son obvias y que valen por toda la seguridad del
 * formulario:
 *
 * 1. Sólo se guarda lo que está en los defaults. Una clave que no esté ahí se
 *    descarta sin avisar, así un POST armado a mano no puede escribir
 *    cualquier option del sitio.
 * 2. Lo que manda el entorno no se guarda nunca. Si el host sale de una
 *    constante de PHP, escribir la option sería guardar un valor que no se usa
 *    y que además contradice al que sí: la base terminaría teniendo una
 *    credencial vieja que nadie está usando pero que cualquiera puede leer.
 * 3. En una red, un sitio no guarda nada si la red no lo dejó, y las options
 *    que son de la red no se guardan en un sitio.
 *
 * @param array<string, mixed> $input
 * @param string               $scope 'site' o 'network'.
 */
function diluxone_mail_save_options( array $input, string $scope = 'site' ): void {
	$defaults = diluxone_mail_option_defaults();

	if ( 'site' === $scope && ! diluxone_mail_site_override_allowed() ) {
		return;
	}

	foreach ( $input as $key => $value ) {
		if ( ! array_key_exists( $key, $defaults ) ) {
			continue;
		}

		if ( 'site' === $scope && in_array( $key, diluxone_mail_network_only_options(), true ) ) {
			continue;
		}

		if ( diluxone_mail_option_from_environment( $key ) ) {
			continue;
		}

		$default = $defaults[ $key ];

		if ( is_int( $default ) ) {
			$value = (int) $value;
		} elseif ( is_array( $default ) ) {
			// Listas de claves —los selectores DKIM que agrega el sitio—
			// saneadas elemento por elemento y sin índices sueltos.
			$value = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $value ) ) ) );
		} else {
			$value = sanitize_textarea_field( (string) $value );
		}

		if ( 'network' === $scope ) {
			update_site_option( $key, $value );
		} else {
			update_option( $key, $value );
		}
	}
}
