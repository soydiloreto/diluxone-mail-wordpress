<?php
/**
 * The settings screen, and the same screen on the network.
 *
 * It is a single form that knows who it is working for: a site, or the whole
 * network when the super administrator opens it from the network dashboard.
 * The difference is where it saves and which controls end up read-only: a
 * site cannot touch what the network fixed, and nobody can touch what the
 * environment provides.
 *
 * The password never goes back to the browser. The field renders empty with a
 * placeholder saying whether one is stored; if it arrives empty, the stored
 * one is kept.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * The settings this form edits, grouped the way the screen groups them.
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
 * What the form's view needs.
 *
 * For each field, its value and where it came from; for the transport ones,
 * with config.php's precedence. And whether it can be edited here or not.
 *
 * @return array<string, mixed>
 */
function diluxone_mail_settings_data( string $scope ): array {
	$fields   = array();
	$editable = 'network' === $scope || diluxone_mail_site_override_allowed();

	foreach ( diluxone_mail_settings_fields() as $group => $keys ) {
		foreach ( $keys as $key ) {
			$config_field = array_search( $key, array_map( static fn( string $s ): string => 'diluxone_mail_' . strtolower( $s ), diluxone_mail_config_fields() ), true );

			if ( false !== $config_field ) {
				$v = diluxone_mail_config_value( (string) $config_field );
			} else {
				$stored = diluxone_mail_option_stored( $key );
				$v      = array(
					'value'  => $stored['value'],
					'source' => $stored['scope'],
					'origin' => $key,
				);
			}

			$locked = in_array( $v['source'], array( 'constant', 'env' ), true )
				|| ( 'site' === $scope && ! $editable )
				|| ( 'site' === $scope && 'network' === $v['source'] && ! diluxone_mail_site_override_allowed() );

			$fields[ $key ] = array(
				'value'    => $v['value'],
				'source'   => $v['source'],
				'origin'   => $v['origin'],
				'label'    => diluxone_mail_source_label( $v['source'], $v['origin'] ),
				'readonly' => $locked,
				'group'    => $group,
			);
		}
	}

	$provider = diluxone_mail_config_value( 'provider' );

	return array(
		'scope'          => $scope,
		'editable'       => $editable,
		'fields'         => $fields,
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

/** A site's settings screen. */
function diluxone_mail_screen_settings(): void {
	diluxone_mail_screen_open( __( 'Settings', 'diluxone-mail' ) );
	diluxone_mail_view( 'admin-settings', diluxone_mail_settings_data( 'site' ) );
	diluxone_mail_screen_close();
}

/**
 * Who may save in each scope.
 *
 * Saving on the network belongs to the super administrator; saving on a site,
 * to whoever administers that site. The nonce is verified by each handler
 * before calling here, in plain sight: that way anybody reading the handler
 * sees it, and so does the wp.org security sniff, which does not follow calls
 * into functions.
 */
function diluxone_mail_settings_authorize( string $scope ): void {
	$cap = 'network' === $scope ? 'manage_network_options' : 'manage_options';

	if ( ! current_user_can( $cap ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'diluxone-mail' ) );
	}
}

/** The scope the POST carried, narrowed to the two that exist. */
function diluxone_mail_posted_scope(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The calling handler already verified the nonce; this only picks between two values.
	$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'site';

	return 'network' === $scope && is_multisite() ? 'network' : 'site';
}

/** Where to go back to after saving. */
function diluxone_mail_settings_redirect( string $scope, string $done ): void {
	$url = 'network' === $scope
		? add_query_arg( 'diluxone_mail_done', $done, network_admin_url( 'settings.php?page=diluxone-mail-network' ) )
		: diluxone_mail_admin_url( 'diluxone-mail', array( 'diluxone_mail_done' => $done ) );

	wp_safe_redirect( $url );
	exit;
}

/**
 * The "use this profile" button: it fills in host, port, encryption and user.
 *
 * It is a step apart from saving, on purpose: that way the form shows what
 * the profile put in, editable, before anybody pastes a key. And no
 * JavaScript is needed for the dropdown to fill anything in.
 */
function diluxone_mail_apply_provider(): void {
	check_admin_referer( 'diluxone_mail_settings' );

	$scope = diluxone_mail_posted_scope();

	diluxone_mail_settings_authorize( $scope );

	if ( 'site' === $scope && ! diluxone_mail_site_override_allowed() ) {
		diluxone_mail_settings_redirect( $scope, 'not-allowed' );
	}

	$key = sanitize_key( wp_unslash( $_POST['diluxone_mail_provider'] ?? '' ) );

	diluxone_mail_save_options( diluxone_mail_provider_defaults( $key ), $scope );
	diluxone_mail_settings_redirect( $scope, 'profile-applied' );
}
add_action( 'admin_post_diluxone_mail_apply_provider', 'diluxone_mail_apply_provider' );

/** Saves the form. */
function diluxone_mail_save_settings(): void {
	check_admin_referer( 'diluxone_mail_settings' );

	$scope = diluxone_mail_posted_scope();

	diluxone_mail_settings_authorize( $scope );

	if ( 'site' === $scope && ! diluxone_mail_site_override_allowed() ) {
		diluxone_mail_settings_redirect( $scope, 'not-allowed' );
	}

	$input = array();

	foreach ( diluxone_mail_settings_fields() as $keys ) {
		foreach ( $keys as $key ) {
			// A password arriving empty means "keep the one you have", not
			// "delete it".
			if ( 'diluxone_mail_pass' === $key ) {
				$pass = isset( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A password is not sanitised: every character is valid and touching it breaks it. It goes straight to update_option().

				if ( '' !== $pass ) {
					$input[ $key ] = $pass;
				}

				continue;
			}

			if ( 'diluxone_mail_dns_selectors' === $key ) {
				$raw           = isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : '';
				$parts         = preg_split( '/[\s,]+/', $raw );
				$input[ $key ] = array_filter( array_map( 'trim', false === $parts ? array() : $parts ) );

				continue;
			}

			// Checkboxes do not travel when they are off.
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

	// The password is stored as it is: save_options() would run it through
	// sanitize_textarea_field(), which would strip valid characters.
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
