<?php
/**
 * Deleting the plugin, and what goes with it.
 *
 * WordPress runs this file when somebody deletes the plugin from the Plugins
 * screen — not on deactivation, which is a different thing and leaves
 * everything where it is.
 *
 * Nothing is removed unless the site asked for it. A log of everything the
 * site has ever sent is not a cache: people uninstall to reinstall, to move
 * hosts, to try a version, and a year of mail history that disappears because
 * of that is the plugin's fault rather than theirs. So the setting ships off
 * and this file is a no-op until somebody turns it on, having read what it
 * says.
 *
 * When it is on, everything goes: both tables, every option on the site and,
 * on a network, on the network as well, the cached DNS answers, and the
 * scheduled purge. Half a cleanup that leaves twenty-five orphan rows in
 * wp_options is not a cleanup.
 *
 * This file stands alone. The plugin is already gone by the time it runs, so
 * it cannot call a single function of it, and everything it needs to know —
 * the option names, the table names — is written out here. That duplication
 * is the reason for the test that walks the real defaults and fails when a
 * new option is not listed.
 *
 * @package DiluxOneMail
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Everything this plugin stores, by name.
 *
 * @return array<int, string>
 */
function diluxone_mail_uninstall_options(): array {
	return array(
		'diluxone_mail_connections',
		'diluxone_mail_transport',
		'diluxone_mail_api_key',
		'diluxone_mail_provider',
		'diluxone_mail_host',
		'diluxone_mail_port',
		'diluxone_mail_encryption',
		'diluxone_mail_auth',
		'diluxone_mail_user',
		'diluxone_mail_pass',
		'diluxone_mail_timeout',
		'diluxone_mail_from',
		'diluxone_mail_from_name',
		'diluxone_mail_force_from',
		'diluxone_mail_mode',
		'diluxone_mail_unhook_pre_wp_mail',
		'diluxone_mail_log_enabled',
		'diluxone_mail_log_retention_days',
		'diluxone_mail_log_extended',
		'diluxone_mail_log_detail_retention_days',
		'diluxone_mail_dns_domain',
		'diluxone_mail_dns_selectors',
		'diluxone_mail_dns_resolver',
		'diluxone_mail_dns_doh_endpoint',
		'diluxone_mail_dns_cache_hours',
		'diluxone_mail_dns_cache_keys',
		'diluxone_mail_privacy_export',
		'diluxone_mail_privacy_erase',
		'diluxone_mail_delete_data_on_uninstall',
		'diluxone_mail_network_allow_override',
		'diluxone_mail_db_version',
	);
}

/**
 * Was this asked for?
 *
 * On a network the answer belongs to the network: deleting the plugin there
 * deletes it for every site at once, and one site's preference cannot decide
 * what happens to the shared log.
 */
function diluxone_mail_uninstall_wanted(): bool {
	if ( is_multisite() ) {
		return (bool) get_site_option( 'diluxone_mail_delete_data_on_uninstall', 0 );
	}

	return (bool) get_option( 'diluxone_mail_delete_data_on_uninstall', 0 );
}

/** The settings, wherever they were kept. */
function diluxone_mail_uninstall_options_delete(): void {
	foreach ( diluxone_mail_uninstall_options() as $option ) {
		delete_option( $option );
		delete_site_option( $option );
	}
}

/**
 * The cached DNS answers.
 *
 * They are transients, and transients cannot be listed by prefix without
 * going into the database — with an object cache, not even then. The plugin
 * keeps the list of keys it wrote for exactly this reason, so it is read
 * before the options go.
 */
function diluxone_mail_uninstall_cache_delete(): void {
	foreach ( (array) get_site_option( 'diluxone_mail_dns_cache_keys', array() ) as $key ) {
		delete_site_transient( (string) $key );
		delete_transient( (string) $key );
	}
}

/** The two tables. One pair for the whole network, as they were created. */
function diluxone_mail_uninstall_tables_drop(): void {
	global $wpdb;

	foreach ( array( 'diluxone_mail_log', 'diluxone_mail_detail' ) as $table ) {
		$name = $wpdb->base_prefix . $table;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- A table name cannot be a placeholder, and this one is built here rather than coming from a request.
		$wpdb->query( "DROP TABLE IF EXISTS `{$name}`" );
	}
}

/** Everything, in the order that leaves nothing unreachable behind. */
function diluxone_mail_uninstall(): void {
	if ( ! diluxone_mail_uninstall_wanted() ) {
		return;
	}

	wp_clear_scheduled_hook( 'diluxone_mail_purge' );
	diluxone_mail_uninstall_cache_delete();
	diluxone_mail_uninstall_tables_drop();

	// A site's own settings on a network live on each site, so each one has to
	// be visited; the log and the tables are shared and were dealt with above.
	if ( is_multisite() ) {
		foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site ) {
			switch_to_blog( (int) $site );
			diluxone_mail_uninstall_options_delete();
			restore_current_blog();
		}
	}

	diluxone_mail_uninstall_options_delete();
}

diluxone_mail_uninstall();
