<?php
/**
 * Purging the log, on cron.
 *
 * Once a day whatever has expired according to the retention settings is
 * deleted. It goes through WordPress cron and not on every request because a
 * DELETE over a large table is not something to do to whoever is waiting for
 * a page to load.
 *
 * On a network the tables are shared and cron belongs to each site: whichever
 * site runs it purges for everyone, using the retention it resolves. With the
 * settings fixed by the network that is the same for all of them; with sites
 * overriding the network, the first one to run wins, and that is fine:
 * retention is a maximum, not a promise to keep.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/** Schedules the daily purge if it is not scheduled already. */
function diluxone_mail_schedule_purge(): void {
	if ( false === wp_next_scheduled( 'diluxone_mail_purge' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'diluxone_mail_purge' );
	}
}
add_action( 'init', 'diluxone_mail_schedule_purge' );

/** The purge itself. */
function diluxone_mail_run_purge(): void {
	diluxone_mail_log_purge();
}
add_action( 'diluxone_mail_purge', 'diluxone_mail_run_purge' );

/**
 * On deactivation: the cron event is removed.
 *
 * The tables stay. Deactivating is not uninstalling, and a log that vanishes
 * because somebody switched the plugin off for five minutes to try something
 * is not a log.
 */
function diluxone_mail_deactivate(): void {
	$next = wp_next_scheduled( 'diluxone_mail_purge' );

	if ( false !== $next ) {
		wp_unschedule_event( $next, 'diluxone_mail_purge' );
	}
}
