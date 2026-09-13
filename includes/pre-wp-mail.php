<?php
/**
 * La trampa de pre_wp_mail.
 *
 * WordPress ofrece dos enganches para el correo: pre_wp_mail, que intercepta
 * ANTES de armar PHPMailer, y phpmailer_init, que lo configura. Este plugin
 * usa el segundo. El problema es que otros enganchan el primero y cortan el
 * envío ahí, devolviendo false sin llegar nunca a PHPMailer: la configuración
 * SMTP no se aplica y el correo muere en silencio. Pasa en producción con el
 * plugin de correo de Azure App Service cuando le falta su connection string,
 * y encima registra el filtro con una función anónima, así que no se lo puede
 * sacar por nombre.
 *
 * Acá se lo detecta, se lo nombra, y —sólo si quien administra lo pidió— se
 * lo desengancha. Nunca un remove_all_filters(): eso deja sin correo a un
 * sitio donde el que intercepta es, justamente, el que lo manda.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Quiénes están enganchados en pre_wp_mail, aparte de este plugin.
 *
 * @return array<int, array{file: string, plugin: string, name: string, priority: int, callback: mixed}>
 */
function diluxone_mail_pre_wp_mail_interceptors(): array {
	return diluxone_mail_hook_origins( 'pre_wp_mail' );
}

/**
 * Desengancha a los interceptores, si el sitio lo pidió.
 *
 * Corre dentro del filtro wp_mail, que wp_mail() aplica justo antes de
 * pre_wp_mail, en cada envío. Hacerlo en init no alcanza: un plugin puede
 * engancharse más tarde, y lo que importa es el estado en el momento en que
 * se manda.
 *
 * Se saca cada callback por su identidad —remove_filter() acepta la misma
 * closure que se registró— y no todos los del hook, porque este plugin
 * también tiene los suyos ahí y porque «todos» incluiría a cualquiera que se
 * sume después.
 *
 * @param array<string, mixed> $atts
 * @return array<string, mixed>
 */
function diluxone_mail_pre_wp_mail_unhook( array $atts ): array {
	if ( ! (bool) diluxone_mail_option( 'diluxone_mail_unhook_pre_wp_mail' ) ) {
		return $atts;
	}

	foreach ( diluxone_mail_pre_wp_mail_interceptors() as $interceptor ) {
		remove_filter( 'pre_wp_mail', $interceptor['callback'], $interceptor['priority'] );
	}

	return $atts;
}
add_filter( 'wp_mail', 'diluxone_mail_pre_wp_mail_unhook', PHP_INT_MIN );

/**
 * El nombre del que cortó el envío, para el historial y el aviso.
 *
 * Cuando hay más de uno no se puede saber cuál devolvió el valor —el filtro
 * no lo dice—, así que se nombran todos.
 */
function diluxone_mail_pre_wp_mail_culprit(): string {
	$nombres = array();

	foreach ( diluxone_mail_pre_wp_mail_interceptors() as $interceptor ) {
		$nombres[] = '' !== $interceptor['name'] ? $interceptor['name'] : basename( $interceptor['file'] );
	}

	return implode( ', ', array_unique( $nombres ) );
}

/**
 * El aviso en las pantallas del plugin cuando hay alguien en pre_wp_mail.
 *
 * No dice «hay un problema»: dice quién está ahí y qué implica, que es lo
 * que hace falta para decidir si desengancharlo o no.
 */
function diluxone_mail_pre_wp_mail_notice(): void {
	$interceptores = diluxone_mail_pre_wp_mail_interceptors();

	if ( array() === $interceptores ) {
		return;
	}

	$desenganchado = (bool) diluxone_mail_option( 'diluxone_mail_unhook_pre_wp_mail' );
	?>
	<div class="notice <?php echo $desenganchado ? 'notice-warning' : 'notice-info'; ?>">
		<p>
			<?php
			printf(
				/* translators: %s: nombre de los plugins */
				esc_html__( '%s intercepts mail before it reaches PHPMailer (pre_wp_mail). If it returns without sending, the message dies silently and no SMTP configuration applies.', 'diluxone-mail' ),
				esc_html( diluxone_mail_pre_wp_mail_culprit() )
			);
			?>
			<?php if ( $desenganchado ) : ?>
				<strong><?php esc_html_e( 'It is being detached on every send, as configured.', 'diluxone-mail' ); ?></strong>
			<?php else : ?>
				<?php esc_html_e( 'Intercepted messages show up in the log as such. You can detach it from the settings screen.', 'diluxone-mail' ); ?>
			<?php endif; ?>
		</p>
	</div>
	<?php
}
