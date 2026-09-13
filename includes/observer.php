<?php
/**
 * ¿Hay otro plugin gestionando el correo de este sitio?
 *
 * Hay siete millones de sitios con un plugin SMTP ya instalado y andando.
 * Nadie va a desinstalar el suyo para probar éste. Así que, si al arrancar
 * encuentra a otro manejando el envío, no pelea: se pone a registrar y a
 * diagnosticar sin tocar nada, y lo dice. Un sitio al que le anda el correo
 * no tiene por qué romperse para probar esto.
 *
 * Detectar al otro tiene dos partes. La lista de los conocidos —por nombre de
 * archivo— es la rápida y la que da un nombre legible. Pero la que vale es la
 * segunda: mirar quién está enganchado de verdad en los tres lugares por los
 * que se puede tomar el correo. Un plugin que no esté en la lista igual
 * aparece ahí, con el archivo del que sale.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Los plugins de correo conocidos, por archivo principal.
 *
 * Sólo los que se sabe cómo se llama su archivo. Uno que no esté acá no
 * queda invisible: lo agarra la detección por hooks de más abajo, sólo que
 * con el nombre de la carpeta en vez del nombre bonito.
 *
 * @return array<string, string>
 */
function diluxone_mail_known_mailers(): array {
	return array(
		'wp-mail-smtp/wp_mail_smtp.php'     => 'WP Mail SMTP',
		'wp-mail-smtp-pro/wp_mail_smtp.php' => 'WP Mail SMTP Pro',
		'fluent-smtp/fluent-smtp.php'       => 'FluentSMTP',
		'post-smtp/postman-smtp.php'        => 'Post SMTP',
		'easy-wp-smtp/easy-wp-smtp.php'     => 'Easy WP SMTP',
		'wp-ses/wp-ses.php'                 => 'WP Offload SES Lite',
		'wp-offload-ses/wp-offload-ses.php' => 'WP Offload SES',
		'mailgun/mailgun.php'               => 'Mailgun',
		'sendgrid-email-delivery-simplified/wpsendgrid.php' => 'SendGrid',
		'smtp-mailer/main.php'              => 'SMTP Mailer',
		'wp-mail-bank/wp-mail-bank.php'     => 'Mail Bank',
		'site-mailer/site-mailer.php'       => 'Site Mailer',
	);
}

/**
 * De dónde sale un callback: archivo, y si es de un plugin, cuál.
 *
 * Funciona también con funciones anónimas, que es el caso que importa: el
 * plugin de correo de Azure App Service engancha pre_wp_mail con una closure,
 * y sin esto no habría forma de decir quién fue.
 *
 * @param mixed $callback El callback tal como está en $wp_filter.
 * @return array{file: string, plugin: string, name: string}
 *         plugin: la carpeta dentro de wp-content/plugins, o '' si no es de un plugin.
 */
function diluxone_mail_callback_origin( $callback ): array {
	$archivo = '';

	try {
		if ( $callback instanceof Closure || ( is_string( $callback ) && function_exists( $callback ) ) ) {
			$archivo = (string) ( new ReflectionFunction( $callback ) )->getFileName();
		} elseif ( is_array( $callback ) && 2 === count( $callback ) ) {
			$archivo = (string) ( new ReflectionMethod( $callback[0], (string) $callback[1] ) )->getFileName();
		} elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
			$archivo = (string) ( new ReflectionMethod( $callback, '__invoke' ) )->getFileName();
		}
	} catch ( ReflectionException $e ) {
		$archivo = '';
	}

	$archivo = wp_normalize_path( $archivo );
	$plugins = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
	$plugin  = '';

	if ( '' !== $archivo && 0 === strpos( $archivo, $plugins ) ) {
		$plugin = (string) strtok( substr( $archivo, strlen( $plugins ) ), '/' );
	}

	$name = $plugin;

	foreach ( diluxone_mail_known_mailers() as $basename => $bonito ) {
		if ( '' !== $plugin && 0 === strpos( $basename, $plugin . '/' ) ) {
			$name = $bonito;
			break;
		}
	}

	return array(
		'file'   => $archivo,
		'plugin' => $plugin,
		'name'   => $name,
	);
}

/**
 * Quiénes están enganchados en un hook, sin contar a este plugin.
 *
 * @return array<int, array{file: string, plugin: string, name: string, priority: int, callback: mixed}>
 */
function diluxone_mail_hook_origins( string $hook ): array {
	global $wp_filter;

	if ( ! isset( $wp_filter[ $hook ] ) ) {
		return array();
	}

	$propio = wp_normalize_path( DILUXONE_MAIL_DIR );
	$salida = array();

	foreach ( $wp_filter[ $hook ]->callbacks as $prioridad => $enganchados ) {
		foreach ( $enganchados as $enganche ) {
			$origen = diluxone_mail_callback_origin( $enganche['function'] );

			if ( '' === $origen['file'] || 0 === strpos( $origen['file'], $propio ) ) {
				continue;
			}

			$salida[] = array_merge(
				$origen,
				array(
					'priority' => (int) $prioridad,
					'callback' => $enganche['function'],
				)
			);
		}
	}

	return $salida;
}

/**
 * Los plugins que están gestionando el correo, si hay alguno.
 *
 * Se miran los tres caminos por los que un plugin puede tomar el envío:
 *
 *   - Redefinir wp_mail() entera. Es pluggable, y WP Mail SMTP y Post SMTP
 *     hacen exactamente eso. Se detecta por el archivo en el que está
 *     definida: si no es wp-includes/pluggable.php, la reemplazó alguien.
 *   - Engancharse a phpmailer_init, que es lo que hace este plugin y casi
 *     todos los demás.
 *   - Cortar en pre_wp_mail, que es lo que hacen los que mandan por API HTTP
 *     en vez de por SMTP.
 *
 * La lista se arma una vez por petición: lo que cuesta es la reflexión, y el
 * resultado no cambia mientras dura la carga. $fresh la vuelve a armar, para
 * después de desenganchar a alguien.
 *
 * @return array<int, array{name: string, plugin: string, how: string}>
 */
function diluxone_mail_other_mailers( bool $fresh = false ): array {
	static $cache = null;

	if ( null !== $cache && ! $fresh ) {
		return $cache;
	}

	$vistos = array();

	// wp_mail() reemplazada.
	if ( function_exists( 'wp_mail' ) ) {
		try {
			$archivo = wp_normalize_path( (string) ( new ReflectionFunction( 'wp_mail' ) )->getFileName() );
		} catch ( ReflectionException $e ) {
			$archivo = '';
		}

		if ( '' !== $archivo && false === strpos( $archivo, '/wp-includes/' ) ) {
			$origen = diluxone_mail_callback_origin( 'wp_mail' );

			$vistos[ '' !== $origen['plugin'] ? $origen['plugin'] : $archivo ] = array(
				'name'   => '' !== $origen['name'] ? $origen['name'] : basename( $archivo ),
				'plugin' => $origen['plugin'],
				'how'    => 'wp_mail',
			);
		}
	}

	foreach ( array( 'phpmailer_init', 'pre_wp_mail' ) as $hook ) {
		foreach ( diluxone_mail_hook_origins( $hook ) as $origen ) {
			$clave = '' !== $origen['plugin'] ? $origen['plugin'] : $origen['file'];

			if ( isset( $vistos[ $clave ] ) ) {
				continue;
			}

			$vistos[ $clave ] = array(
				'name'   => '' !== $origen['name'] ? $origen['name'] : basename( $origen['file'] ),
				'plugin' => $origen['plugin'],
				'how'    => $hook,
			);
		}
	}

	/**
	 * Filtra la lista de plugins detectados gestionando el correo.
	 *
	 * @param array<int, array{name: string, plugin: string, how: string}> $mailers
	 */
	$cache = (array) apply_filters( 'diluxone_mail_other_mailers', array_values( $vistos ) );

	return $cache;
}

/**
 * ¿Este plugin manda el correo, o sólo mira?
 *
 * Es la pregunta que hace mailer.php antes de tocar PHPMailer, y la que
 * contesta la pantalla de estado.
 */
function diluxone_mail_transport_active(): bool {
	$modo = (string) diluxone_mail_option( 'diluxone_mail_mode' );

	if ( 'observe' === $modo ) {
		return false;
	}

	if ( 'transport' === $modo ) {
		return true;
	}

	return array() === diluxone_mail_other_mailers();
}

/**
 * El aviso de modo observador, en las pantallas del plugin.
 *
 * Dice quién está gestionando el envío y ofrece tomar el control. Si el
 * modo es 'observe' a mano, no hay nada que avisar: fue una decisión.
 */
function diluxone_mail_observer_notice(): void {
	if ( 'auto' !== (string) diluxone_mail_option( 'diluxone_mail_mode' ) ) {
		return;
	}

	$otros = diluxone_mail_other_mailers();

	if ( array() === $otros ) {
		return;
	}

	$nombres = implode( ', ', array_column( $otros, 'name' ) );
	$url     = wp_nonce_url( admin_url( 'admin-post.php?action=diluxone_mail_take_over' ), 'diluxone_mail_take_over' );
	?>
	<div class="notice notice-info">
		<p>
			<?php
			printf(
				/* translators: %s: nombre de los plugins detectados */
				esc_html__( '%s is handling this site\'s outgoing mail. DiluxOne Mail is logging and diagnosing without touching it.', 'diluxone-mail' ),
				esc_html( $nombres )
			);
			?>
			<a class="button button-small" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Take over', 'diluxone-mail' ); ?></a>
		</p>
	</div>
	<?php
}

/** El botón «Tomar el control»: pasa el modo a 'transport'. */
function diluxone_mail_take_over(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'diluxone-mail' ) );
	}

	check_admin_referer( 'diluxone_mail_take_over' );

	diluxone_mail_save_options( array( 'diluxone_mail_mode' => 'transport' ) );

	wp_safe_redirect( diluxone_mail_admin_url( 'diluxone-mail', array( 'diluxone_mail_done' => 'took-over' ) ) );
	exit;
}
add_action( 'admin_post_diluxone_mail_take_over', 'diluxone_mail_take_over' );
