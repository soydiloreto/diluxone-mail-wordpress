<?php
/**
 * La pantalla de estado.
 *
 * Una sola pantalla que contesta la pregunta de siempre: ¿este sitio manda
 * correo, por dónde, y por qué? Perfil activo, de dónde sale cada valor,
 * entorno detectado, si está en modo observador y quién más está en el
 * medio, y qué pasó la última vez que se intentó mandar algo.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Todo lo que muestra la pantalla, ya resuelto.
 *
 * @return array<string, mixed>
 */
function diluxone_mail_status(): array {
	$config = array();

	foreach ( array_keys( diluxone_mail_config_fields() ) as $campo ) {
		$v = diluxone_mail_config_value( $campo );

		$config[ $campo ] = array(
			'value' => 'pass' === $campo ? ( '' !== $v['value'] ? '***' : '' ) : $v['value'],
			'label' => diluxone_mail_source_label( $v['source'], $v['origin'] ),
		);
	}

	$ultimo = get_option( 'diluxone_mail_last_result', false );

	return array(
		'version'       => DILUXONE_MAIL_VERSION,
		'db_version'    => (int) get_site_option( 'diluxone_mail_db_version', 0 ),
		'environment'   => wp_get_environment_type(),
		'mode'          => (string) diluxone_mail_option( 'diluxone_mail_mode' ),
		'transport'     => diluxone_mail_transport_active(),
		'others'        => diluxone_mail_other_mailers(),
		'interceptors'  => diluxone_mail_pre_wp_mail_interceptors(),
		'unhooking'     => (bool) diluxone_mail_option( 'diluxone_mail_unhook_pre_wp_mail' ),
		'profile'       => diluxone_mail_provider( diluxone_mail_config()['provider'] ),
		'config'        => $config,
		'last'          => is_array( $ultimo ) ? $ultimo : null,
		'totals'        => diluxone_mail_log_totals( is_network_admin() ? null : get_current_blog_id() ),
		'log_enabled'   => (bool) diluxone_mail_option( 'diluxone_mail_log_enabled' ),
		'log_extended'  => (bool) diluxone_mail_option( 'diluxone_mail_log_extended' ),
		'log_body'      => (bool) diluxone_mail_option( 'diluxone_mail_log_body' ),
		'dns_system'    => diluxone_mail_dns_system_available(),
		'dns_domain'    => diluxone_mail_dns_domain(),
		'multisite'     => is_multisite(),
		'site_override' => diluxone_mail_site_override_allowed(),
		'next_purge'    => (int) wp_next_scheduled( 'diluxone_mail_purge' ),
	);
}

/** La pantalla. */
function diluxone_mail_screen_status(): void {
	diluxone_mail_screen_open( __( 'Status', 'diluxone-mail' ) );
	diluxone_mail_view( 'admin-status', diluxone_mail_status() );
	diluxone_mail_screen_close();
}
