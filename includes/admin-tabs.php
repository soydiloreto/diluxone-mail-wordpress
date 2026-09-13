<?php
/**
 * The settings screen as a sequence of steps.
 *
 * Configuring an SMTP transport has an order that cannot be avoided: the
 * profile decides the host, the host decides whether the credentials work,
 * and only a server that answers can be asked to deliver a message. The old
 * screen put all of it on one page and left the order implicit, so the usual
 * way through it was to fill everything in, save, and find out at the end
 * which of the eight fields was wrong.
 *
 * Here each step is a tab, and a tab opens when the step before it is done.
 * A locked tab is shown — greyed, with the reason — rather than hidden: what
 * is coming next is part of the explanation.
 *
 * The last tabs are not part of the chain. Logging and the sending mode are
 * settings, not steps: somebody who is only here to change the retention of
 * the log should not have to walk a wizard to reach it.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * The tabs, in the order they are meant to be walked.
 *
 * `step` numbers the ones that form the chain and is 0 for the ones that do
 * not. `needs` is the tab that has to be complete before this one opens.
 * `groups` are the groups of settings the tab's form is allowed to save.
 *
 * @return array<string, array{label: string, step: int, needs: string, groups: array<int, string>}>
 */
function diluxone_mail_settings_tabs( string $scope = 'site' ): array {
	$tabs = array(
		'profile' => array(
			'label'  => __( 'Provider', 'diluxone-mail' ),
			'step'   => 1,
			'needs'  => '',
			'groups' => array(),
		),
		// No groups: the transport settings have no plain-save path at all.
		// They are written by the connection test and by nothing else, which
		// is what makes "it was never saved without working" true rather
		// than merely encouraged by the layout of the screen.
		'server'  => array(
			'label'  => __( 'SMTP server', 'diluxone-mail' ),
			'step'   => 2,
			'needs'  => 'profile',
			'groups' => array(),
		),
		'sender'  => array(
			'label'  => __( 'Sender', 'diluxone-mail' ),
			'step'   => 3,
			'needs'  => 'server',
			'groups' => array( 'from' ),
		),
		'test'    => array(
			'label'  => __( 'Test message', 'diluxone-mail' ),
			'step'   => 4,
			'needs'  => 'sender',
			'groups' => array(),
		),
		'sending' => array(
			'label'  => __( 'Sending behaviour', 'diluxone-mail' ),
			'step'   => 0,
			'needs'  => '',
			'groups' => array( 'mode' ),
		),
		'logging' => array(
			'label'  => __( 'Log and privacy', 'diluxone-mail' ),
			'step'   => 0,
			'needs'  => '',
			'groups' => array( 'log', 'privacy' ),
		),
	);

	if ( 'network' === $scope ) {
		$tabs['sites'] = array(
			'label'  => __( 'Sites', 'diluxone-mail' ),
			'step'   => 0,
			'needs'  => '',
			'groups' => array(),
		);
	}

	return $tabs;
}

/**
 * Which steps are done.
 *
 * Each answer is read from what is actually stored, never from "the user
 * pressed the button earlier": a credential that was verified and then edited
 * is not verified any more, and the tab that depended on it closes again.
 *
 * @return array<string, bool>
 */
function diluxone_mail_settings_progress(): array {
	return array(
		'profile' => '' !== (string) diluxone_mail_config_value( 'provider' )['value'],
		'server'  => diluxone_mail_connection_verified(),
		'sender'  => '' !== (string) diluxone_mail_config_value( 'from' )['value'],
		'test'    => diluxone_mail_test_passed(),
		'sending' => true,
		'logging' => true,
		'sites'   => true,
	);
}

/**
 * Why a tab is closed, in the words of what to do about it.
 *
 * The tab that is missing something is never the tab being explained: it is
 * the one before it. So the sentence names the step to go back to.
 */
function diluxone_mail_tab_blocked_reason( string $tab ): string {
	switch ( $tab ) {
		case 'server':
			return __( 'Choose a provider profile first: it fills in the host, the port and the encryption this tab asks for.', 'diluxone-mail' );
		case 'sender':
			return __( 'The SMTP server has to answer first. Test the connection on the previous tab; until it does, there is nothing to send from.', 'diluxone-mail' );
		case 'test':
			return __( 'Set the From address first: a provider will not deliver a message sent from an address it has not verified.', 'diluxone-mail' );
		default:
			return '';
	}
}

/**
 * Is this tab open?
 *
 * A tab with no prerequisite is always open. One with a prerequisite opens
 * when that step is done — and stays open from then on, because coming back
 * to correct something already configured is the normal case, not an escape.
 *
 * @param array<string, bool> $progress
 */
function diluxone_mail_tab_open( string $tab, array $progress, string $scope = 'site' ): bool {
	$tabs = diluxone_mail_settings_tabs( $scope );

	if ( ! isset( $tabs[ $tab ] ) ) {
		return false;
	}

	$needs = $tabs[ $tab ]['needs'];

	return '' === $needs ? true : (bool) ( $progress[ $needs ] ?? false );
}

/**
 * The tab being shown.
 *
 * Out of the closed ones it falls back to the first step that is not done,
 * which is where somebody arriving for the first time has to start anyway.
 */
function diluxone_mail_current_tab( string $scope = 'site' ): string {
	$tabs     = diluxone_mail_settings_tabs( $scope );
	$progress = diluxone_mail_settings_progress();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- It only picks which tab to render; it changes nothing.
	$asked = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

	if ( isset( $tabs[ $asked ] ) && diluxone_mail_tab_open( $asked, $progress, $scope ) ) {
		return $asked;
	}

	foreach ( $tabs as $slug => $tab ) {
		if ( 0 !== $tab['step'] && ! ( $progress[ $slug ] ?? false ) ) {
			return $slug;
		}
	}

	return 'profile';
}

/**
 * The URL of one tab, on whichever of the two screens is asking.
 *
 * @param array<string, mixed> $args
 */
function diluxone_mail_tab_url( string $tab, string $scope = 'site', array $args = array() ): string {
	$base = 'network' === $scope
		? network_admin_url( 'settings.php?page=diluxone-mail-network' )
		: diluxone_mail_admin_url( DILUXONE_MAIL_SETTINGS );

	return add_query_arg( array_merge( array( 'tab' => $tab ), $args ), $base );
}

/**
 * The groups of settings one tab is allowed to save.
 *
 * The form of a tab carries only its own fields, so the handler has to be
 * told what it is looking at. Without this a tab with two checkboxes would
 * switch off every checkbox on the other tabs, which do not travel with it:
 * an unchecked box and an absent one look the same in a POST.
 *
 * @return array<int, string>
 */
function diluxone_mail_tab_groups( string $tab, string $scope = 'site' ): array {
	$tabs = diluxone_mail_settings_tabs( $scope );

	return $tabs[ $tab ]['groups'] ?? array();
}

/** The row of tabs. */
function diluxone_mail_tabs_nav( string $current, string $scope ): void {
	$tabs     = diluxone_mail_settings_tabs( $scope );
	$progress = diluxone_mail_settings_progress();

	echo '<nav class="nav-tab-wrapper diluxone-mail-tabs">';

	foreach ( $tabs as $slug => $tab ) {
		$open  = diluxone_mail_tab_open( $slug, $progress, $scope );
		$done  = 0 !== $tab['step'] && ( $progress[ $slug ] ?? false );
		$label = 0 !== $tab['step']
			/* translators: 1: step number, 2: name of the step */
			? sprintf( _x( '%1$d. %2$s', 'numbered step in the settings screen', 'diluxone-mail' ), $tab['step'], $tab['label'] )
			: $tab['label'];

		$classes = 'nav-tab';

		if ( $slug === $current ) {
			$classes .= ' nav-tab-active';
		}

		if ( ! $open ) {
			$classes .= ' diluxone-mail-tab-locked';
		}

		if ( $done ) {
			$classes .= ' diluxone-mail-tab-done';
		}

		if ( ! $open ) {
			printf(
				'<span class="%1$s" aria-disabled="true" title="%2$s">%3$s</span>',
				esc_attr( $classes ),
				esc_attr( diluxone_mail_tab_blocked_reason( $slug ) ),
				esc_html( $label ) . ' <span aria-hidden="true">&#128274;</span>'
			);

			continue;
		}

		printf(
			'<a href="%1$s" class="%2$s">%3$s</a>',
			esc_url( diluxone_mail_tab_url( $slug, $scope ) ),
			esc_attr( $classes ),
			esc_html( $label ) . ( $done ? ' <span aria-hidden="true">&#10003;</span>' : '' )
		);
	}

	echo '</nav>';
}
