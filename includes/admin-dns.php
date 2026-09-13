<?php
/**
 * The deliverability screen.
 *
 * It shows the report from diagnostics.php and has a single button:
 * revalidate, which empties the cache and asks again. Everything else is
 * read-only.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/** The screen. */
function diluxone_mail_screen_dns(): void {
	diluxone_mail_screen_open( __( 'Deliverability', 'diluxone-mail' ) );

	$domain = diluxone_mail_dns_domain();

	diluxone_mail_view(
		'admin-dns',
		array(
			'report'         => '' !== $domain ? diluxone_mail_diagnose( $domain ) : null,
			'domain'         => $domain,
			'revalidate_url' => wp_nonce_url( admin_url( 'admin-post.php?action=diluxone_mail_revalidate' ), 'diluxone_mail_revalidate' ),
			'settings_url'   => diluxone_mail_admin_url( DILUXONE_MAIL_SETTINGS ),
			// The four values the diagnosis runs on are edited here, on the
			// screen that uses them, rather than on a settings tab that says
			// "DNS diagnostics" and shows no diagnosis.
			'fields'         => diluxone_mail_settings_field_values( 'site' ),
			'editable'       => diluxone_mail_site_override_allowed(),
			'action_url'     => admin_url( 'admin-post.php' ),
		)
	);

	diluxone_mail_screen_close();
}

/** The revalidate button. */
function diluxone_mail_revalidate(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'diluxone-mail' ) );
	}

	check_admin_referer( 'diluxone_mail_revalidate' );

	$domain = diluxone_mail_dns_domain();

	if ( '' !== $domain ) {
		diluxone_mail_diagnose( $domain, true );
	}

	wp_safe_redirect( diluxone_mail_admin_url( 'diluxone-mail-dns', array( 'diluxone_mail_done' => 'revalidated' ) ) );
	exit;
}
add_action( 'admin_post_diluxone_mail_revalidate', 'diluxone_mail_revalidate' );
