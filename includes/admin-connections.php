<?php
/**
 * The list of configured providers, and what can be done to it.
 *
 * The screen is the list. Setting one up happens over it, in a panel, because
 * the four steps are a detour from the list and not a place: you come back to
 * where you were, and what you were looking at is still behind.
 *
 * Order is the only thing that decides anything here — the first sends, the
 * next is the one tried when it will not — so reordering is an action of its
 * own and not a field buried in a form.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Everything the list shows about one provider.
 *
 * The state is read through the same functions the wizard uses, against that
 * record: a row saying "verified" while the wizard says the step is open would
 * be two answers to one question.
 *
 * @param array<string, mixed> $connection
 * @return array<string, mixed>
 */
function diluxone_mail_connection_row( string $id, array $connection, int $position ): array {
	$was = diluxone_mail_active_id();

	diluxone_mail_active_id( $id );

	$row = array(
		'id'        => $id,
		'label'     => diluxone_mail_connection_label( $connection ),
		'provider'  => (string) diluxone_mail_provider( (string) ( $connection['diluxone_mail_provider'] ?? '' ) )['name'],
		'transport' => 'api' === (string) ( $connection['diluxone_mail_transport'] ?? 'smtp' ) ? __( 'API', 'diluxone-mail' ) : __( 'SMTP', 'diluxone-mail' ),
		'from'      => (string) ( $connection['diluxone_mail_from'] ?? '' ),
		'ready'     => diluxone_mail_connection_verified() && '' !== (string) ( $connection['diluxone_mail_from'] ?? '' ),
		'tested'    => diluxone_mail_test_passed(),
		'position'  => $position,
	);

	diluxone_mail_active_id( $was );

	return $row;
}

/**
 * What the list screen needs.
 *
 * @return array<string, mixed>
 */
function diluxone_mail_connections_data(): array {
	$rows     = array();
	$position = 0;

	foreach ( diluxone_mail_connections() as $id => $connection ) {
		$rows[] = diluxone_mail_connection_row( (string) $id, $connection, $position );
		++$position;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- It only decides whether the panel is open; nothing is written by looking.
	$editing = isset( $_GET['connection'] ) || isset( $_GET['new'] );

	return array(
		'rows'       => $rows,
		'editing'    => $editing,
		'list_url'   => diluxone_mail_admin_url( DILUXONE_MAIL_PROVIDER_PAGE ),
		'new_url'    => diluxone_mail_admin_url( DILUXONE_MAIL_PROVIDER_PAGE, array( 'new' => '1' ) ),
		'action_url' => admin_url( 'admin-post.php' ),
		'nonce'      => wp_create_nonce( 'diluxone_mail_connections' ),
	);
}

/** The URL that opens one of them. */
function diluxone_mail_connection_url( string $id ): string {
	return diluxone_mail_admin_url( DILUXONE_MAIL_PROVIDER_PAGE, array( 'connection' => $id ) );
}

/**
 * Who may rearrange a site's providers.
 *
 * The same answer as everywhere else: whoever administers the site, unless the
 * network keeps the mail to itself.
 */
function diluxone_mail_connections_authorize(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'diluxone-mail' ) );
	}

	if ( 'network' === diluxone_mail_connections_scope() && ! current_user_can( 'manage_network_options' ) ) {
		wp_die( esc_html__( 'This site\'s providers are fixed by the network.', 'diluxone-mail' ) );
	}
}

/** Back to the list, with something to say. */
function diluxone_mail_connections_redirect( string $done ): void {
	wp_safe_redirect( diluxone_mail_admin_url( DILUXONE_MAIL_PROVIDER_PAGE, array( 'diluxone_mail_done' => $done ) ) );
	exit;
}

/** The order, as the list was left. */
function diluxone_mail_connections_reorder_action(): void {
	check_admin_referer( 'diluxone_mail_connections' );
	diluxone_mail_connections_authorize();

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
	$order = isset( $_POST['order'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_POST['order'] ) ) ) : array();

	diluxone_mail_connections_reorder( array_map( 'sanitize_key', $order ) );
	diluxone_mail_connections_redirect( 'reordered' );
}
add_action( 'admin_post_diluxone_mail_reorder', 'diluxone_mail_connections_reorder_action' );

/** One step up the list, which is also one step closer to being in charge. */
function diluxone_mail_connection_move_action(): void {
	check_admin_referer( 'diluxone_mail_connections' );
	diluxone_mail_connections_authorize();

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
	$id = isset( $_POST['connection'] ) ? sanitize_key( wp_unslash( $_POST['connection'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
	$up = isset( $_POST['up'] );

	$ids = array_keys( diluxone_mail_connections() );
	$at  = array_search( $id, $ids, true );

	if ( false === $at ) {
		diluxone_mail_connections_redirect( 'reordered' );
	}

	$to = $up ? (int) $at - 1 : (int) $at + 1;

	if ( $to >= 0 && $to < count( $ids ) ) {
		$swap       = $ids[ $to ];
		$ids[ $to ] = $id;
		$ids[ $at ] = $swap;

		diluxone_mail_connections_reorder( $ids );
	}

	diluxone_mail_connections_redirect( 'reordered' );
}
add_action( 'admin_post_diluxone_mail_move', 'diluxone_mail_connection_move_action' );

/** Removes one from the list. */
function diluxone_mail_connection_forget_action(): void {
	check_admin_referer( 'diluxone_mail_connections' );
	diluxone_mail_connections_authorize();

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
	$id = isset( $_POST['connection'] ) ? sanitize_key( wp_unslash( $_POST['connection'] ) ) : '';

	diluxone_mail_connection_forget( $id );
	diluxone_mail_connections_redirect( 'forgotten' );
}
add_action( 'admin_post_diluxone_mail_forget', 'diluxone_mail_connection_forget_action' );

/** The name somebody gives one, to tell two of the same provider apart. */
function diluxone_mail_connection_rename_action(): void {
	check_admin_referer( 'diluxone_mail_connections' );
	diluxone_mail_connections_authorize();

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
	$id = isset( $_POST['connection'] ) ? sanitize_key( wp_unslash( $_POST['connection'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
	$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';

	if ( '' !== $id && array() !== diluxone_mail_connection( $id ) ) {
		diluxone_mail_connection_put( $id, array( 'label' => $label ) );
	}

	diluxone_mail_connections_redirect( 'renamed' );
}
add_action( 'admin_post_diluxone_mail_rename', 'diluxone_mail_connection_rename_action' );
