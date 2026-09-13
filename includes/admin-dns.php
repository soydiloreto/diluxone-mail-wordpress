<?php
/**
 * La pantalla de entregabilidad.
 *
 * Muestra el informe de diagnostics.php y tiene un solo botón: revalidar,
 * que vacía el caché y vuelve a preguntar. Todo lo demás es lectura.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/** La pantalla. */
function diluxone_mail_screen_dns(): void {
	diluxone_mail_screen_open( __( 'Deliverability', 'diluxone-mail' ) );

	$dominio = diluxone_mail_dns_domain();

	diluxone_mail_view(
		'admin-dns',
		array(
			'report'         => '' !== $dominio ? diluxone_mail_diagnose( $dominio ) : null,
			'domain'         => $dominio,
			'revalidate_url' => wp_nonce_url( admin_url( 'admin-post.php?action=diluxone_mail_revalidate' ), 'diluxone_mail_revalidate' ),
			'settings_url'   => diluxone_mail_admin_url( 'diluxone-mail' ),
		)
	);

	diluxone_mail_screen_close();
}

/** El botón de revalidar. */
function diluxone_mail_revalidate(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'diluxone-mail' ) );
	}

	check_admin_referer( 'diluxone_mail_revalidate' );

	$dominio = diluxone_mail_dns_domain();

	if ( '' !== $dominio ) {
		diluxone_mail_diagnose( $dominio, true );
	}

	wp_safe_redirect( diluxone_mail_admin_url( 'diluxone-mail-dns', array( 'diluxone_mail_done' => 'revalidated' ) ) );
	exit;
}
add_action( 'admin_post_diluxone_mail_revalidate', 'diluxone_mail_revalidate' );
