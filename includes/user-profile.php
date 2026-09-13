<?php
/**
 * The history on each person's profile.
 *
 * This is the plugin's reason for existing. Every competitor shows one global
 * list of sends; here, opening a user in the dashboard shows what was sent to
 * them: date, subject and status. It is the first thing
 * somebody on support should see when they are told "I never got the email".
 *
 * The lookup is by address, not by id: by their current one and by their
 * previous ones if the site keeps them — a plugin that records email changes
 * adds them through the `diluxone_mail_user_emails` filter. And on a network
 * it shows every site's, because the person belongs to the network.
 *
 * Whoever can edit that person can see it. It is not a separate capability:
 * if you can change their password, you can see what mail was sent to them.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * The addresses a person's history is looked up by.
 *
 * @return array<int, string>
 */
function diluxone_mail_user_emails( WP_User $user ): array {
	$emails = array( strtolower( (string) $user->user_email ) );

	/**
	 * Filters the addresses a person's history is looked up by.
	 *
	 * A plugin that keeps previous addresses adds them here.
	 *
	 * @param array<int, string> $emails
	 * @param WP_User            $user
	 */
	$emails = (array) apply_filters( 'diluxone_mail_user_emails', $emails, $user );

	return array_values( array_unique( array_filter( array_map( 'strtolower', array_map( 'strval', $emails ) ) ) ) );
}

/** The section on the profile. */
function diluxone_mail_user_profile_section( WP_User $user ): void {
	if ( ! current_user_can( 'edit_user', $user->ID ) ) {
		return;
	}

	$emails = diluxone_mail_user_emails( $user );
	$query  = diluxone_mail_log_query(
		array(
			'emails'   => $emails,
			'site_id'  => null,
			'per_page' => 25,
		)
	);

	diluxone_mail_view(
		'user-profile',
		array(
			'user'        => $user,
			'emails'      => $emails,
			'rows'        => $query['rows'],
			'total'       => $query['total'],
			'statuses'    => diluxone_mail_log_statuses(),
			'log_enabled' => (bool) diluxone_mail_option( 'diluxone_mail_log_enabled' ),
			'multisite'   => is_multisite(),
			'log_url'     => current_user_can( 'manage_options' ) ? diluxone_mail_admin_url( 'diluxone-mail-log', array( 's' => $user->user_email ) ) : '',
		)
	);
}
add_action( 'show_user_profile', 'diluxone_mail_user_profile_section', 100 );
add_action( 'edit_user_profile', 'diluxone_mail_user_profile_section', 100 );

/**
 * The plugin's notices on a person's profile.
 *
 * The plugin's own screens show them through diluxone_mail_screen_open(); the
 * user profile belongs to WordPress and its notices have to be hooked.
 */
function diluxone_mail_profile_notices(): void {
	$screen = get_current_screen();

	if ( ! $screen instanceof WP_Screen || ! in_array( $screen->id, array( 'profile', 'user-edit' ), true ) ) {
		return;
	}

	diluxone_mail_done_notice();
}
add_action( 'admin_notices', 'diluxone_mail_profile_notices' );
