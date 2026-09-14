<?php
/**
 * The overview: what is set up, what is not, and what happened last.
 *
 * The first screen of the menu, and the one somebody opens when they are not
 * coming to change anything — they are coming to find out whether the site
 * sends mail. Everything on it is already answered elsewhere; what it adds is
 * having the four answers in one place and a way into whichever one is wrong.
 *
 * Nothing here goes looking: the deliverability card shows the diagnosis only
 * when it is already cached, because a dashboard that fires twenty DNS
 * lookups to draw itself is a dashboard nobody opens twice.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * The diagnosis, but only if it is already in the cache.
 *
 * @return array<string, mixed>|null
 */
function diluxone_mail_diagnosis_cached( string $domain ): ?array {
	$domain = strtolower( trim( $domain ) );

	if ( '' === $domain ) {
		return null;
	}

	$cached = get_site_transient( 'diluxone_mail_diagnosis_' . md5( $domain ) );

	return is_array( $cached ) ? $cached : null;
}

/**
 * How many findings of each level the cached diagnosis holds.
 *
 * @param array<string, mixed>|null $report
 * @return array<string, int>
 */
function diluxone_mail_findings_by_level( ?array $report ): array {
	$levels = array(
		'error'   => 0,
		'warning' => 0,
		'info'    => 0,
		'ok'      => 0,
	);

	if ( null === $report ) {
		return $levels;
	}

	foreach ( (array) ( $report['findings'] ?? array() ) as $finding ) {
		$level = (string) ( $finding['level'] ?? '' );

		if ( isset( $levels[ $level ] ) ) {
			++$levels[ $level ];
		}
	}

	return $levels;
}

/**
 * Everything the overview shows.
 *
 * @return array<string, mixed>
 */
function diluxone_mail_overview(): array {
	$domain = diluxone_mail_dns_domain();
	$report = diluxone_mail_diagnosis_cached( $domain );
	$last   = get_option( 'diluxone_mail_last_result', false );
	$config = diluxone_mail_config();

	return array(
		'progress'     => diluxone_mail_settings_progress(),
		'tabs'         => diluxone_mail_settings_tabs( 'site' ),
		'transport'    => diluxone_mail_transport_active(),
		'mode'         => (string) diluxone_mail_option( 'diluxone_mail_mode' ),
		'others'       => diluxone_mail_mailers_delivering(),
		'profile'      => diluxone_mail_provider( $config['provider'] ),
		'host'         => (string) $config['host'],
		'from'         => (string) $config['from'],
		'from_name'    => (string) $config['from_name'],
		'last'         => is_array( $last ) ? $last : null,
		'totals'       => diluxone_mail_log_totals( get_current_blog_id() ),
		'log_enabled'  => (bool) diluxone_mail_option( 'diluxone_mail_log_enabled' ),
		'domain'       => $domain,
		'findings'     => diluxone_mail_findings_by_level( $report ),
		'has_report'   => null !== $report,
		'provider_url' => diluxone_mail_admin_url( DILUXONE_MAIL_PROVIDER_PAGE ),
		'settings_url' => diluxone_mail_admin_url( DILUXONE_MAIL_SETTINGS ),
		'log_url'      => diluxone_mail_admin_url( 'diluxone-mail-log' ),
		'dns_url'      => diluxone_mail_admin_url( 'diluxone-mail-dns' ),
		'status_url'   => diluxone_mail_admin_url( 'diluxone-mail-status' ),
		'action_url'   => admin_url( 'admin-post.php' ),
		'test'         => diluxone_mail_test_result_take(),
	);
}

/** The screen. */
function diluxone_mail_screen_overview(): void {
	diluxone_mail_screen_open( __( 'Overview', 'diluxone-mail' ) );
	diluxone_mail_view( 'admin-overview', diluxone_mail_overview() );
	diluxone_mail_screen_close();
}
