<?php
/**
 * La pantalla de ajustes, y la misma pantalla en la red.
 *
 * Es un solo formulario que sabe para quién trabaja: para un sitio, o para
 * la red entera cuando lo abre el superadministrador desde el escritorio de
 * la red. La diferencia es dónde se guarda y qué controles quedan de sólo
 * lectura: un sitio no puede tocar lo que la red fijó, y nadie puede tocar
 * lo que manda el entorno.
 *
 * La contraseña nunca vuelve al navegador. El campo se muestra vacío con un
 * placeholder que dice si hay una guardada; si llega vacío, se conserva la
 * que estaba.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Los ajustes que edita este formulario, agrupados como en la pantalla.
 *
 * @return array<string, array<int, string>>
 */
function diluxone_mail_settings_fields(): array {
	return array(
		'transport' => array( 'diluxone_mail_host', 'diluxone_mail_port', 'diluxone_mail_encryption', 'diluxone_mail_auth', 'diluxone_mail_user', 'diluxone_mail_pass', 'diluxone_mail_timeout' ),
		'from'      => array( 'diluxone_mail_from', 'diluxone_mail_from_name', 'diluxone_mail_force_from' ),
		'mode'      => array( 'diluxone_mail_mode', 'diluxone_mail_unhook_pre_wp_mail' ),
		'log'       => array( 'diluxone_mail_log_enabled', 'diluxone_mail_log_retention_days', 'diluxone_mail_log_extended', 'diluxone_mail_log_body', 'diluxone_mail_log_detail_retention_days' ),
		'dns'       => array( 'diluxone_mail_dns_domain', 'diluxone_mail_dns_selectors', 'diluxone_mail_dns_resolver', 'diluxone_mail_dns_doh_endpoint', 'diluxone_mail_dns_cache_hours' ),
		'privacy'   => array( 'diluxone_mail_privacy_export', 'diluxone_mail_privacy_erase' ),
	);
}

/**
 * Lo que necesita la vista del formulario.
 *
 * Para cada campo, su valor y de dónde salió; para los del transporte, con
 * la precedencia de config.php. Y si se puede editar acá o no.
 *
 * @return array<string, mixed>
 */
function diluxone_mail_settings_data( string $scope ): array {
	$campos   = array();
	$editable = 'network' === $scope || diluxone_mail_site_override_allowed();

	foreach ( diluxone_mail_settings_fields() as $grupo => $keys ) {
		foreach ( $keys as $key ) {
			$campo_config = array_search( $key, array_map( static fn( string $s ): string => 'diluxone_mail_' . strtolower( $s ), diluxone_mail_config_fields() ), true );

			if ( false !== $campo_config ) {
				$v = diluxone_mail_config_value( (string) $campo_config );
			} else {
				$stored = diluxone_mail_option_stored( $key );
				$v      = array(
					'value'  => $stored['value'],
					'source' => $stored['scope'],
					'origin' => $key,
				);
			}

			$bloqueado = in_array( $v['source'], array( 'constant', 'env' ), true )
				|| ( 'site' === $scope && ! $editable )
				|| ( 'site' === $scope && 'network' === $v['source'] && ! diluxone_mail_site_override_allowed() );

			$campos[ $key ] = array(
				'value'    => $v['value'],
				'source'   => $v['source'],
				'origin'   => $v['origin'],
				'label'    => diluxone_mail_source_label( $v['source'], $v['origin'] ),
				'readonly' => $bloqueado,
				'group'    => $grupo,
			);
		}
	}

	$provider = diluxone_mail_config_value( 'provider' );

	return array(
		'scope'          => $scope,
		'editable'       => $editable,
		'fields'         => $campos,
		'provider'       => $provider,
		'profile'        => diluxone_mail_provider( $provider['value'] ),
		'providers'      => diluxone_mail_providers(),
		'has_password'   => '' !== diluxone_mail_config_value( 'pass' )['value'],
		'allow_override' => (bool) get_site_option( 'diluxone_mail_network_allow_override', 0 ),
		'test'           => diluxone_mail_test_result_take(),
		'action_url'     => admin_url( 'admin-post.php' ),
		'back_url'       => 'network' === $scope ? network_admin_url( 'settings.php?page=diluxone-mail-network' ) : diluxone_mail_admin_url( 'diluxone-mail' ),
	);
}

/** La pantalla de ajustes de un sitio. */
function diluxone_mail_screen_settings(): void {
	diluxone_mail_screen_open( __( 'Settings', 'diluxone-mail' ) );
	diluxone_mail_view( 'admin-settings', diluxone_mail_settings_data( 'site' ) );
	diluxone_mail_screen_close();
}

/**
 * Quién puede guardar en cada alcance.
 *
 * Guardar en la red es del superadministrador; guardar en un sitio, de quien
 * administra ese sitio. Y siempre con nonce.
 */
function diluxone_mail_settings_authorize( string $scope, string $nonce_action ): void {
	$cap = 'network' === $scope ? 'manage_network_options' : 'manage_options';

	if ( ! current_user_can( $cap ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'diluxone-mail' ) );
	}

	check_admin_referer( $nonce_action );
}

/** El alcance que vino en el POST, saneado a los dos que existen. */
function diluxone_mail_posted_scope(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- El nonce se verifica en quien llama, con el alcance ya resuelto.
	$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'site';

	return 'network' === $scope && is_multisite() ? 'network' : 'site';
}

/** Adónde volver después de guardar. */
function diluxone_mail_settings_redirect( string $scope, string $done ): void {
	$url = 'network' === $scope
		? add_query_arg( 'diluxone_mail_done', $done, network_admin_url( 'settings.php?page=diluxone-mail-network' ) )
		: diluxone_mail_admin_url( 'diluxone-mail', array( 'diluxone_mail_done' => $done ) );

	wp_safe_redirect( $url );
	exit;
}

/**
 * El botón «usar este perfil»: rellena host, puerto, cifrado y usuario.
 *
 * Es un paso aparte del guardado, a propósito: así el formulario muestra lo
 * que el perfil puso, editable, antes de que nadie pegue una clave. Y no
 * hace falta JavaScript para que el desplegable rellene nada.
 */
function diluxone_mail_apply_provider(): void {
	$scope = diluxone_mail_posted_scope();

	diluxone_mail_settings_authorize( $scope, 'diluxone_mail_settings' );

	if ( 'site' === $scope && ! diluxone_mail_site_override_allowed() ) {
		diluxone_mail_settings_redirect( $scope, 'not-allowed' );
	}

	$key = sanitize_key( wp_unslash( $_POST['diluxone_mail_provider'] ?? '' ) );

	diluxone_mail_save_options( diluxone_mail_provider_defaults( $key ), $scope );
	diluxone_mail_settings_redirect( $scope, 'profile-applied' );
}
add_action( 'admin_post_diluxone_mail_apply_provider', 'diluxone_mail_apply_provider' );

/** Guarda el formulario. */
function diluxone_mail_save_settings(): void {
	$scope = diluxone_mail_posted_scope();

	diluxone_mail_settings_authorize( $scope, 'diluxone_mail_settings' );

	if ( 'site' === $scope && ! diluxone_mail_site_override_allowed() ) {
		diluxone_mail_settings_redirect( $scope, 'not-allowed' );
	}

	$input = array();

	foreach ( diluxone_mail_settings_fields() as $keys ) {
		foreach ( $keys as $key ) {
			// La contraseña que llega vacía es «dejá la que está», no «borrala».
			if ( 'diluxone_mail_pass' === $key ) {
				$pass = isset( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Una contraseña no se sanea: cualquier carácter es válido y tocarla la rompe. Va directo a update_option().

				if ( '' !== $pass ) {
					$input[ $key ] = $pass;
				}

				continue;
			}

			if ( 'diluxone_mail_dns_selectors' === $key ) {
				$crudo         = isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : '';
				$partes        = preg_split( '/[\s,]+/', $crudo );
				$input[ $key ] = array_filter( array_map( 'trim', false === $partes ? array() : $partes ) );

				continue;
			}

			// Las casillas no viajan cuando están apagadas.
			$default = diluxone_mail_option_defaults()[ $key ];

			if ( is_int( $default ) && in_array( $default, array( 0, 1 ), true ) && ! in_array( $key, array( 'diluxone_mail_port', 'diluxone_mail_timeout' ), true ) ) {
				$input[ $key ] = isset( $_POST[ $key ] ) ? 1 : 0;
				continue;
			}

			if ( isset( $_POST[ $key ] ) ) {
				$input[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}
	}

	if ( 'network' === $scope ) {
		$input['diluxone_mail_network_allow_override'] = isset( $_POST['diluxone_mail_network_allow_override'] ) ? 1 : 0;
	}

	// La contraseña se guarda tal cual: save_options() la pasaría por
	// sanitize_textarea_field(), que le sacaría caracteres válidos.
	if ( isset( $input['diluxone_mail_pass'] ) && ! diluxone_mail_option_from_environment( 'diluxone_mail_pass' ) ) {
		if ( 'network' === $scope ) {
			update_site_option( 'diluxone_mail_pass', $input['diluxone_mail_pass'] );
		} else {
			update_option( 'diluxone_mail_pass', $input['diluxone_mail_pass'] );
		}
	}

	unset( $input['diluxone_mail_pass'] );

	diluxone_mail_save_options( $input, $scope );
	diluxone_mail_settings_redirect( $scope, 'saved' );
}
add_action( 'admin_post_diluxone_mail_save_settings', 'diluxone_mail_save_settings' );
