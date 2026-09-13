<?php
/**
 * The pre_wp_mail trap.
 *
 * WordPress offers two hooks for mail: pre_wp_mail, which intercepts BEFORE
 * PHPMailer is built, and phpmailer_init, which configures it. This plugin
 * uses the second. The problem is that others hook the first and cut the send
 * short there, returning false without ever reaching PHPMailer: the SMTP
 * configuration never applies and the mail dies silently. It happens in
 * production with the Azure App Service mail plugin when its connection
 * string is missing, and on top of that it registers the filter with an
 * anonymous function, so it cannot be removed by name.
 *
 * Here it is detected, named, and — only if the administrator asked for it —
 * detached. Never a remove_all_filters(): that leaves a site with no mail at
 * all when the one intercepting is precisely the one delivering.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Who is hooked into pre_wp_mail, this plugin aside.
 *
 * @return array<int, array{file: string, plugin: string, name: string, priority: int, callback: mixed}>
 */
function diluxone_mail_pre_wp_mail_interceptors(): array {
	return diluxone_mail_hook_origins( 'pre_wp_mail' );
}

/**
 * Detaches the interceptors, if the site asked for it.
 *
 * It runs inside the wp_mail filter, which wp_mail() applies right before
 * pre_wp_mail, on every send. Doing it on init is not enough: a plugin can
 * hook later, and what matters is the state at the moment of sending.
 *
 * Each callback is removed by its identity — remove_filter() accepts the very
 * closure that was registered — and not all of the hook's, because this
 * plugin has its own there too and because "all" would include anybody
 * joining afterwards.
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
 * The name of whoever cut the send short, for the log and for the notice.
 *
 * When there is more than one there is no way to tell which returned the
 * value — the filter does not say — so all of them are named.
 */
function diluxone_mail_pre_wp_mail_culprit(): string {
	$names = array();

	foreach ( diluxone_mail_pre_wp_mail_interceptors() as $interceptor ) {
		$names[] = '' !== $interceptor['name'] ? $interceptor['name'] : basename( $interceptor['file'] );
	}

	return implode( ', ', array_unique( $names ) );
}

/**
 * The notice on the plugin's screens when somebody is on pre_wp_mail.
 *
 * It does not say "there is a problem": it says who is there and what that
 * implies, which is what you need in order to decide whether to detach them.
 */
function diluxone_mail_pre_wp_mail_notice(): void {
	$interceptors = diluxone_mail_pre_wp_mail_interceptors();

	if ( array() === $interceptors ) {
		return;
	}

	$detaching = (bool) diluxone_mail_option( 'diluxone_mail_unhook_pre_wp_mail' );
	?>
	<div class="notice <?php echo $detaching ? 'notice-warning' : 'notice-info'; ?>">
		<p>
			<?php
			printf(
				/* translators: %s: names of the plugins */
				esc_html__( '%s intercepts mail before it reaches PHPMailer (pre_wp_mail). If it returns without sending, the message dies silently and no SMTP configuration applies.', 'diluxone-mail' ),
				esc_html( diluxone_mail_pre_wp_mail_culprit() )
			);
			?>
			<?php if ( $detaching ) : ?>
				<strong><?php esc_html_e( 'It is being detached on every send, as configured.', 'diluxone-mail' ); ?></strong>
			<?php else : ?>
				<?php esc_html_e( 'Intercepted messages show up in the log as such. You can detach it from the settings screen.', 'diluxone-mail' ); ?>
			<?php endif; ?>
		</p>
	</div>
	<?php
}
