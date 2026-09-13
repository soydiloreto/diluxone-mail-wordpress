<?php
/**
 * El menú y lo que comparten sus pantallas.
 *
 * Cada entrada del menú es una pantalla con vida propia. Lo que está acá es
 * lo que todas repiten: el título con el nombre del plugin adelante, los
 * avisos, la URL de una pantalla, y los avisos de «esto no lo podés cambiar
 * desde acá» que son la parte más importante de un formulario que no miente.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

const DILUXONE_MAIL_MENU = 'diluxone-mail';

/**
 * El nombre con el que se presenta el plugin en el escritorio.
 *
 * Se escribe una sola vez: lo usan el menú, el título de cada pantalla y la
 * pestaña del navegador. Escrito en tres lados, tarde o temprano dicen tres
 * cosas distintas.
 */
function diluxone_mail_plugin_name(): string {
	return (string) apply_filters( 'diluxone_mail_plugin_name', __( 'DiluxOne Mail', 'diluxone-mail' ) );
}

/** El título de una pantalla, con el nombre del plugin adelante. */
function diluxone_mail_screen_title( string $title ): string {
	return sprintf(
		/* translators: 1: nombre del plugin, 2: nombre de la pantalla */
		_x( '%1$s | %2$s', 'título de una pantalla del escritorio', 'diluxone-mail' ),
		diluxone_mail_plugin_name(),
		$title
	);
}

/** Lo mismo, en la pestaña del navegador. */
function diluxone_mail_admin_title( string $admin_title, string $title ): string {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen instanceof WP_Screen || false === strpos( (string) $screen->id, DILUXONE_MAIL_MENU ) ) {
		return $admin_title;
	}

	return str_replace( $title, diluxone_mail_screen_title( $title ), $admin_title );
}
add_filter( 'admin_title', 'diluxone_mail_admin_title', 10, 2 );

/**
 * Las pantallas del menú, en orden.
 *
 * @return array<string, string>
 */
function diluxone_mail_screens(): array {
	return array(
		'diluxone-mail'        => __( 'Settings', 'diluxone-mail' ),
		'diluxone-mail-log'    => __( 'Mail log', 'diluxone-mail' ),
		'diluxone-mail-dns'    => __( 'Deliverability', 'diluxone-mail' ),
		'diluxone-mail-status' => __( 'Status', 'diluxone-mail' ),
	);
}

/** El menú. */
function diluxone_mail_menu(): void {
	add_menu_page(
		diluxone_mail_plugin_name(),
		diluxone_mail_plugin_name(),
		'manage_options',
		DILUXONE_MAIL_MENU,
		'diluxone_mail_screen_settings',
		'dashicons-email-alt',
		76
	);

	$callbacks = array(
		'diluxone-mail'        => 'diluxone_mail_screen_settings',
		'diluxone-mail-log'    => 'diluxone_mail_screen_log',
		'diluxone-mail-dns'    => 'diluxone_mail_screen_dns',
		'diluxone-mail-status' => 'diluxone_mail_screen_status',
	);

	foreach ( diluxone_mail_screens() as $slug => $title ) {
		add_submenu_page( DILUXONE_MAIL_MENU, $title, $title, 'manage_options', $slug, $callbacks[ $slug ] );
	}
}
add_action( 'admin_menu', 'diluxone_mail_menu' );

/**
 * La URL de una pantalla del plugin, con los argumentos que haga falta.
 *
 * @param array<string, mixed> $args
 */
function diluxone_mail_admin_url( string $screen, array $args = array() ): string {
	return add_query_arg( array_merge( array( 'page' => $screen ), $args ), admin_url( 'admin.php' ) );
}

/** ¿Estamos en la pantalla de la red? */
function diluxone_mail_is_network_screen(): bool {
	return is_multisite() && is_network_admin();
}

/**
 * La cabecera común: título y, si hay, los avisos que valen en todas.
 *
 * Los avisos de modo observador y de pre_wp_mail van en todas las pantallas
 * del plugin y no sólo en ajustes: quien mira el historial y ve «entregado a
 * FluentSMTP» tiene que tener la explicación a mano.
 */
function diluxone_mail_screen_open( string $title ): void {
	echo '<div class="wrap diluxone-mail-admin">';
	printf( '<h1>%s</h1>', esc_html( diluxone_mail_screen_title( $title ) ) );

	diluxone_mail_done_notice();
	diluxone_mail_observer_notice();
	diluxone_mail_pre_wp_mail_notice();
}

/** El cierre. */
function diluxone_mail_screen_close(): void {
	echo '</div>';
}

/** Un aviso corto arriba de la pantalla. */
function diluxone_mail_notice( string $text, string $type = 'success' ): void {
	printf(
		'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
		esc_attr( $type ),
		esc_html( $text )
	);
}

/**
 * El aviso que corresponde al `diluxone_mail_done` de la URL.
 *
 * Cada acción del admin redirige con una clave, y acá está el texto de cada
 * una. Un texto que no está acá no se muestra: la clave viene de la URL.
 */
function diluxone_mail_done_notice(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sólo elige un texto de una lista cerrada; no cambia nada.
	$done = isset( $_GET['diluxone_mail_done'] ) ? sanitize_key( wp_unslash( $_GET['diluxone_mail_done'] ) ) : '';

	if ( '' === $done ) {
		return;
	}

	$textos = array(
		'saved'           => array( __( 'Settings saved.', 'diluxone-mail' ), 'success' ),
		'profile-applied' => array( __( 'Provider profile applied. Paste the credentials and save.', 'diluxone-mail' ), 'success' ),
		'took-over'       => array( __( 'DiluxOne Mail is now handling this site\'s outgoing mail.', 'diluxone-mail' ), 'success' ),
		'resent'          => array( __( 'Message resent.', 'diluxone-mail' ), 'success' ),
		'resend-failed'   => array( __( 'The message could not be resent. Check the mail log for the error.', 'diluxone-mail' ), 'error' ),
		'no-body'         => array( __( 'This message cannot be resent: its body was not stored. Turn on body storage in the settings to make future messages resendable.', 'diluxone-mail' ), 'warning' ),
		'revalidated'     => array( __( 'DNS cache cleared and the diagnosis run again.', 'diluxone-mail' ), 'success' ),
		'not-allowed'     => array( __( 'This site\'s settings are fixed by the network and cannot be changed here.', 'diluxone-mail' ), 'warning' ),
	);

	if ( isset( $textos[ $done ] ) ) {
		diluxone_mail_notice( $textos[ $done ][0], $textos[ $done ][1] );
	}
}

/**
 * Cómo se explica de dónde salió un valor.
 *
 * Es el texto que va al lado de cada control de sólo lectura, y el que usa
 * la pantalla de estado. Nombra el origen concreto porque «definido por el
 * entorno» sin decir cuál manda a la gente a buscar a ciegas.
 */
function diluxone_mail_source_label( string $source, string $origin ): string {
	switch ( $source ) {
		case 'constant':
			/* translators: %s: nombre de la constante */
			return sprintf( __( 'defined by the environment — PHP constant %s', 'diluxone-mail' ), $origin );
		case 'env':
			/* translators: %s: nombre de la variable */
			return sprintf( __( 'defined by the environment — variable %s', 'diluxone-mail' ), $origin );
		case 'network':
			return __( 'set by the network', 'diluxone-mail' );
		case 'site':
			return __( 'set on this site', 'diluxone-mail' );
		default:
			return __( 'default value', 'diluxone-mail' );
	}
}

/**
 * ¿Este ajuste lo está forzando el sitio desde código?
 *
 * Otro plugin puede fijar un valor por el filtro `diluxone_mail_option`
 * —porque en ese sitio no es una opción sino cómo funciona—. Cuando eso pasa,
 * el control del admin se guarda y no cambia nada, que es exactamente la clase
 * de mentira que hay que evitar en una pantalla de ajustes. Con esto se puede
 * mostrar al lado del control.
 */
function diluxone_mail_option_forced( string $key ): bool {
	return diluxone_mail_option( $key ) !== diluxone_mail_option_stored( $key )['value'];
}

/**
 * Quién está fijando un ajuste desde el código.
 *
 * «Algo del sitio decidió esto» no le sirve a nadie: quien lee eso quiere ir
 * a sacarlo, y no sabe dónde. Acá sale el archivo y la función.
 *
 * @return array<int, string>
 */
function diluxone_mail_option_forced_by(): array {
	$quienes = array();

	foreach ( diluxone_mail_hook_origins( 'diluxone_mail_option' ) as $origen ) {
		$quienes[] = ltrim( str_replace( wp_normalize_path( WP_PLUGIN_DIR ), '', $origen['file'] ), '/' );
	}

	return $quienes;
}

/** El aviso de que un ajuste lo fija el código, con quién lo fija. */
function diluxone_mail_forced_notice( string $key ): void {
	if ( ! diluxone_mail_option_forced( $key ) ) {
		return;
	}

	$quienes = diluxone_mail_option_forced_by();
	?>
	<p class="description diluxone-mail-forced">
		<?php esc_html_e( 'This site fixes this from code: whatever is chosen here, it stays as it is.', 'diluxone-mail' ); ?>
		<?php if ( array() !== $quienes ) : ?>
			<code><?php echo esc_html( implode( ', ', $quienes ) ); ?></code>
		<?php endif; ?>
	</p>
	<?php
}

/**
 * Carga una vista de templates/ con sus datos.
 *
 * @param array<string, mixed> $data
 */
function diluxone_mail_view( string $name, array $data = array() ): void {
	$archivo = DILUXONE_MAIL_DIR . 'templates/' . $name . '.php';

	if ( file_exists( $archivo ) ) {
		include $archivo;
	}
}

/** Los estilos del admin del plugin. */
function diluxone_mail_admin_styles( string $hook ): void {
	if ( false === strpos( $hook, 'diluxone-mail' ) && ! in_array( $hook, array( 'profile.php', 'user-edit.php' ), true ) ) {
		return;
	}

	$css = DILUXONE_MAIL_DIR . 'assets/diluxone-mail-admin.css';

	wp_enqueue_style(
		'diluxone-mail-admin',
		DILUXONE_MAIL_URL . 'assets/diluxone-mail-admin.css',
		array(),
		file_exists( $css ) ? (string) filemtime( $css ) : DILUXONE_MAIL_VERSION
	);
}
add_action( 'admin_enqueue_scripts', 'diluxone_mail_admin_styles' );
