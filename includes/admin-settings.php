<?php
/**
 * The settings screen, and the same screen on the network.
 *
 * It is one form per tab, each knowing who it is working for: a site, or the
 * whole network when the super administrator opens it from the network
 * dashboard. The difference is where it saves and which controls end up
 * read-only: a site cannot touch what the network fixed, and nobody can touch
 * what the environment provides.
 *
 * A tab submits only its own fields, so every handler is told which groups it
 * is looking at. Without that an unchecked box on the tab in front of you and
 * a box on a tab you are not looking at are the same thing — absent — and
 * saving one tab would switch off the settings of all the others.
 *
 * The password never goes back to the browser. The field renders empty with a
 * placeholder saying whether one is stored; if it arrives empty, the stored
 * one is kept.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * The settings this screen edits, grouped the way the tabs group them.
 *
 * @return array<string, array<int, string>>
 */
function diluxone_mail_settings_fields(): array {
	return array(
		'transport' => array( 'diluxone_mail_host', 'diluxone_mail_port', 'diluxone_mail_encryption', 'diluxone_mail_auth', 'diluxone_mail_user', 'diluxone_mail_pass', 'diluxone_mail_timeout' ),
		'from'      => array( 'diluxone_mail_from', 'diluxone_mail_from_name', 'diluxone_mail_force_from' ),
		'mode'      => array( 'diluxone_mail_mode', 'diluxone_mail_unhook_pre_wp_mail' ),
		'log'       => array( 'diluxone_mail_log_enabled', 'diluxone_mail_log_retention_days', 'diluxone_mail_log_extended', 'diluxone_mail_log_detail_retention_days' ),
		'dns'       => array( 'diluxone_mail_dns_domain', 'diluxone_mail_dns_selectors', 'diluxone_mail_dns_resolver', 'diluxone_mail_dns_doh_endpoint', 'diluxone_mail_dns_cache_hours' ),
		'privacy'   => array( 'diluxone_mail_privacy_export', 'diluxone_mail_privacy_erase' ),
	);
}

/**
 * What the screen's views need.
 *
 * For each field, its value and where it came from; for the transport ones,
 * with config.php's precedence. And whether it can be edited here or not.
 *
 * @return array<string, mixed>
 */
function diluxone_mail_settings_data( string $scope ): array {
	$editable = 'network' === $scope || diluxone_mail_site_override_allowed();
	$attempt  = diluxone_mail_attempt_take();
	$tab      = diluxone_mail_current_tab( $scope );
	$provider = diluxone_mail_config_value( 'provider' );

	return array(
		'scope'          => $scope,
		'tab'            => $tab,
		'progress'       => diluxone_mail_settings_progress(),
		'editable'       => $editable,
		'fields'         => diluxone_mail_settings_field_values( $scope, $attempt ),
		'provider'       => $provider,
		'profile'        => diluxone_mail_provider( $provider['value'] ),
		'providers'      => diluxone_mail_providers(),
		'has_password'   => '' !== diluxone_mail_config_value( 'pass' )['value'],
		'allow_override' => (bool) get_site_option( 'diluxone_mail_network_allow_override', 0 ),
		'verified'       => diluxone_mail_connection_verified(),
		'connection'     => is_array( $attempt ) ? $attempt : null,
		'test'           => diluxone_mail_test_result_take(),
		'action_url'     => admin_url( 'admin-post.php' ),
		'back_url'       => diluxone_mail_tab_url( $tab, $scope ),
	);
}

/**
 * Every field with its value, its provenance and whether it can be edited.
 *
 * Apart from settings_data() because the deliverability screen needs the
 * fields and nothing else: the rest of that array consumes the transients
 * holding the last test and the last connection attempt, and a screen that
 * does not show them would swallow results meant for the one that does.
 *
 * @param array<string, mixed>|null $attempt Values a failed connection test left behind.
 * @return array<string, array<string, mixed>>
 */
function diluxone_mail_settings_field_values( string $scope, ?array $attempt = null ): array {
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

			// A connection test that failed comes back with what was typed,
			// so the tab does not lose it. Never the password: that one is
			// typed again rather than kept anywhere a second time.
			$value = isset( $attempt['fields'][ $key ] ) && ! $locked ? $attempt['fields'][ $key ] : $v['value'];

			$fields[ $key ] = array(
				'value'    => $value,
				'source'   => $v['source'],
				'origin'   => $v['origin'],
				'label'    => diluxone_mail_source_label( $v['source'], $v['origin'] ),
				'readonly' => $locked,
				'group'    => $group,
			);
		}
	}

	return $fields;
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

/**
 * The form the POST came from, narrowed to the ones that exist.
 *
 * `dns` is not a tab of this screen: the options of the diagnosis live on the
 * deliverability screen, next to the diagnosis they configure. It saves
 * through the same handler because it is the same kind of form, and it is the
 * only form outside the tabs, so it is named here rather than given a
 * mechanism of its own.
 */
function diluxone_mail_posted_tab( string $scope ): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The calling handler already verified the nonce; this only picks which form was submitted.
	$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';

	if ( 'dns' === $tab ) {
		return 'dns';
	}

	return isset( diluxone_mail_settings_tabs( $scope )[ $tab ] ) ? $tab : 'profile';
}

/** Where to go back to after saving. */
function diluxone_mail_settings_redirect( string $scope, string $done, string $tab = '' ): void {
	$args = array( 'diluxone_mail_done' => $done );

	wp_safe_redirect(
		'' === $tab
			? add_query_arg( $args, 'network' === $scope ? network_admin_url( 'settings.php?page=diluxone-mail-network' ) : diluxone_mail_admin_url( DILUXONE_MAIL_SETTINGS ) )
			: diluxone_mail_tab_url( $tab, $scope, $args )
	);

	exit;
}

/**
 * What a failed connection attempt left behind, once.
 *
 * Short-lived and consumed when shown, like the test result: it exists only
 * so that a tab whose test failed comes back with what was typed in it.
 *
 * @return array<string, mixed>|null
 */
function diluxone_mail_attempt_take(): ?array {
	$key     = 'diluxone_mail_attempt_' . get_current_user_id();
	$attempt = get_transient( $key );

	if ( ! is_array( $attempt ) ) {
		return null;
	}

	delete_transient( $key );

	return $attempt;
}

/**
 * The transport values a POST carried, over the ones already stored.
 *
 * The password is the exception, as everywhere: empty means "the one you
 * have", not "no password".
 *
 * @return array<string, mixed>
 */
function diluxone_mail_posted_transport(): array {
	$config = diluxone_mail_config();

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- The calling handler verified the nonce.
	$posted = array(
		'provider'   => $config['provider'],
		'host'       => isset( $_POST['diluxone_mail_host'] ) ? sanitize_text_field( wp_unslash( $_POST['diluxone_mail_host'] ) ) : $config['host'],
		'port'       => isset( $_POST['diluxone_mail_port'] ) ? (int) sanitize_text_field( wp_unslash( $_POST['diluxone_mail_port'] ) ) : (int) $config['port'],
		'encryption' => isset( $_POST['diluxone_mail_encryption'] ) ? sanitize_text_field( wp_unslash( $_POST['diluxone_mail_encryption'] ) ) : $config['encryption'],
		'auth'       => isset( $_POST['diluxone_mail_auth'] ) ? 1 : 0,
		'user'       => isset( $_POST['diluxone_mail_user'] ) ? sanitize_text_field( wp_unslash( $_POST['diluxone_mail_user'] ) ) : $config['user'],
		'timeout'    => isset( $_POST['diluxone_mail_timeout'] ) ? (int) sanitize_text_field( wp_unslash( $_POST['diluxone_mail_timeout'] ) ) : (int) diluxone_mail_option( 'diluxone_mail_timeout' ),
	);

	// A password is not sanitised: every character is valid and touching it
	// breaks it.
	$pass = isset( $_POST['diluxone_mail_pass'] ) ? (string) wp_unslash( $_POST['diluxone_mail_pass'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- See above.
	// phpcs:enable

	$posted['pass'] = '' !== $pass ? $pass : $config['pass'];

	return $posted;
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
		diluxone_mail_settings_redirect( $scope, 'not-allowed', 'profile' );
	}

	$key = sanitize_key( wp_unslash( $_POST['diluxone_mail_provider'] ?? '' ) );

	diluxone_mail_save_options( diluxone_mail_provider_defaults( $key ), $scope );
	diluxone_mail_settings_redirect( $scope, 'profile-applied', 'server' );
}
add_action( 'admin_post_diluxone_mail_apply_provider', 'diluxone_mail_apply_provider' );

/**
 * The one way the transport settings get stored: by proving they work.
 *
 * The values are tried against the server before anything is written, and
 * written only if it answered. A configuration that was never able to open a
 * session is not a configuration, it is a draft — and a settings screen that
 * accepts it is a screen that says "saved" about something that cannot send
 * a single message.
 */
function diluxone_mail_connection_action(): void {
	check_admin_referer( 'diluxone_mail_settings' );

	$scope = diluxone_mail_posted_scope();

	diluxone_mail_settings_authorize( $scope );

	if ( 'site' === $scope && ! diluxone_mail_site_override_allowed() ) {
		diluxone_mail_settings_redirect( $scope, 'not-allowed', 'server' );
	}

	$posted = diluxone_mail_posted_transport();
	$result = diluxone_mail_test_connection( $posted );

	// What was typed goes back to the form either way; the password never
	// travels with it.
	$result['fields'] = array(
		'diluxone_mail_host'       => $posted['host'],
		'diluxone_mail_port'       => $posted['port'],
		'diluxone_mail_encryption' => $posted['encryption'],
		'diluxone_mail_auth'       => $posted['auth'],
		'diluxone_mail_user'       => $posted['user'],
		'diluxone_mail_timeout'    => $posted['timeout'],
	);

	set_transient( 'diluxone_mail_attempt_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );

	if ( ! $result['ok'] ) {
		diluxone_mail_settings_redirect( $scope, 'connection-failed', 'server' );
	}

	diluxone_mail_save_options(
		array(
			'diluxone_mail_host'       => $posted['host'],
			'diluxone_mail_port'       => $posted['port'],
			'diluxone_mail_encryption' => $posted['encryption'],
			'diluxone_mail_auth'       => $posted['auth'],
			'diluxone_mail_user'       => $posted['user'],
			'diluxone_mail_timeout'    => $posted['timeout'],
		),
		$scope
	);

	diluxone_mail_store_password( $posted['pass'], $scope );
	diluxone_mail_verified( 'connection' );

	diluxone_mail_settings_redirect( $scope, 'connected', 'sender' );
}
add_action( 'admin_post_diluxone_mail_connection', 'diluxone_mail_connection_action' );

/**
 * Stores the password as it is.
 *
 * Save_options() would run it through sanitize_textarea_field(), which strips
 * characters that are perfectly valid in a credential.
 */
function diluxone_mail_store_password( string $pass, string $scope ): void {
	if ( '' === $pass || diluxone_mail_option_from_environment( 'diluxone_mail_pass' ) ) {
		return;
	}

	if ( 'network' === $scope ) {
		update_site_option( 'diluxone_mail_pass', $pass );
		return;
	}

	update_option( 'diluxone_mail_pass', $pass );
}

/** Saves one tab. */
function diluxone_mail_save_settings(): void {
	check_admin_referer( 'diluxone_mail_settings' );

	$scope = diluxone_mail_posted_scope();

	diluxone_mail_settings_authorize( $scope );

	$tab = diluxone_mail_posted_tab( $scope );

	if ( 'site' === $scope && ! diluxone_mail_site_override_allowed() ) {
		diluxone_mail_settings_redirect( $scope, 'not-allowed', $tab );
	}

	$groups = 'dns' === $tab ? array( 'dns' ) : diluxone_mail_tab_groups( $tab, $scope );
	$all    = diluxone_mail_settings_fields();
	$input  = array();

	foreach ( $groups as $group ) {
		foreach ( $all[ $group ] ?? array() as $key ) {
			// The transport group is never saved from here: it is saved by
			// the connection test, which is the only thing that knows the
			// values work.
			if ( 'diluxone_mail_pass' === $key ) {
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

	if ( 'network' === $scope && 'sites' === $tab ) {
		$input['diluxone_mail_network_allow_override'] = isset( $_POST['diluxone_mail_network_allow_override'] ) ? 1 : 0;
	}

	diluxone_mail_save_options( $input, $scope );

	if ( 'dns' === $tab ) {
		wp_safe_redirect( diluxone_mail_admin_url( 'diluxone-mail-dns', array( 'diluxone_mail_done' => 'saved' ) ) );
		exit;
	}

	diluxone_mail_settings_redirect( $scope, 'saved', $tab );
}
add_action( 'admin_post_diluxone_mail_save_settings', 'diluxone_mail_save_settings' );
