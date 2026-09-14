<?php
/**
 * The deliverability screen.
 *
 * It shows the report from diagnostics.php and has a single button:
 * revalidate, which empties the cache and asks again. Everything else is
 * read-only.
 *
 * The diagnosis is slow — an SPF tree expanded recursively, a probe per DKIM
 * selector, DMARC, and over DNS-over-HTTPS each of those is a round trip to
 * somebody else's resolver. Running it inside the render meant the screen
 * answered a click with nothing at all for several seconds, which reads as a
 * site that has hung rather than as work being done.
 *
 * So the screen never runs it. It draws what is cached, and when there is
 * nothing cached it draws the shape of the answer and lets the browser go and
 * ask. There is exactly one place that renders a report, and it is the same
 * one as before: the fetch stores the result and the page comes back to read
 * it, rather than a second renderer in JavaScript drifting away from this one.
 *
 * Without JavaScript the skeleton would sit there forever, so the fallback is
 * a plain link that asks for the old behaviour — slow, blocking, correct.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/** The screen. */
function diluxone_mail_screen_dns(): void {
	diluxone_mail_screen_open( __( 'Deliverability', 'diluxone-mail' ) );

	$domain = diluxone_mail_dns_domain();

	// The one way to make this screen block on purpose: what the no-script
	// fallback link asks for, and what somebody lands on who would rather wait
	// than watch a skeleton.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- It only decides whether to wait for a read-only lookup; nothing is written by looking.
	$wait = isset( $_GET['diluxone_mail_wait'] );

	$report = '' === $domain
		? null
		: ( $wait ? diluxone_mail_diagnose( $domain ) : diluxone_mail_diagnosis_cached( $domain ) );

	diluxone_mail_view(
		'admin-dns',
		array(
			'report'         => $report,
			'domain'         => $domain,
			// There is a domain and no report yet: the screen has something to
			// draw the shape of, and something for the browser to go and ask.
			'pending'        => null === $report && '' !== $domain,
			'wait_url'       => diluxone_mail_admin_url( 'diluxone-mail-dns', array( 'diluxone_mail_wait' => '1' ) ),
			'diagnose_nonce' => wp_create_nonce( 'diluxone_mail_diagnose' ),
			'revalidate_url' => wp_nonce_url( admin_url( 'admin-post.php?action=diluxone_mail_revalidate' ), 'diluxone_mail_revalidate' ),
			'settings_url'   => diluxone_mail_tab_url( 'sender' ),
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

/**
 * Runs the diagnosis for the browser that is waiting on the skeleton.
 *
 * It answers whether it worked and nothing else. The report is in the cache
 * by then and the page reads it from there on the way back in — one renderer,
 * not two.
 */
function diluxone_mail_diagnose_ajax(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'diluxone-mail' ) ), 403 );
	}

	check_ajax_referer( 'diluxone_mail_diagnose' );

	$domain = diluxone_mail_dns_domain();

	if ( '' === $domain ) {
		wp_send_json_error( array( 'message' => __( 'There is no domain to diagnose.', 'diluxone-mail' ) ), 400 );
	}

	$report = diluxone_mail_diagnose( $domain );

	wp_send_json_success(
		array(
			'domain'   => $domain,
			'findings' => count( (array) $report['findings'] ),
		)
	);
}
add_action( 'wp_ajax_diluxone_mail_diagnose', 'diluxone_mail_diagnose_ajax' );

/** The revalidate button. */
function diluxone_mail_revalidate(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'diluxone-mail' ) );
	}

	check_admin_referer( 'diluxone_mail_revalidate' );

	// Only the cache is emptied. Running the diagnosis here would put the wait
	// back where it was — in a request with nothing on screen — and the screen
	// already knows what to do when there is nothing cached.
	diluxone_mail_dns_flush();

	wp_safe_redirect( diluxone_mail_admin_url( 'diluxone-mail-dns', array( 'diluxone_mail_done' => 'revalidated' ) ) );
	exit;
}
add_action( 'admin_post_diluxone_mail_revalidate', 'diluxone_mail_revalidate' );
