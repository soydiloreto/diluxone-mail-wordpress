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
 * @return array<string, array{label: string, step: int, needs: string, groups: array<int, string>, screen: string}>
 */
function diluxone_mail_settings_tabs( string $scope = 'site', string $screen = '' ): array {
	$tabs = array(
		'profile' => array(
			'label'  => __( 'Provider', 'diluxone-mail' ),
			'step'   => 1,
			'needs'  => '',
			'groups' => array(),
			'screen' => 'provider',
		),
		// No groups: the transport settings have no plain-save path at all.
		// They are written by the connection test and by nothing else, which
		// is what makes "it was never saved without working" true rather
		// than merely encouraged by the layout of the screen.
		'server'  => array(
			// The second step is the same step either way — prove the provider
			// accepts us — and it is named after whichever thing is being
			// proved, because "SMTP server" on a screen with no server is a
			// label that teaches the wrong thing.
			'label'  => 'api' === diluxone_mail_transport_kind() ? __( 'API key', 'diluxone-mail' ) : __( 'SMTP server', 'diluxone-mail' ),
			'step'   => 2,
			'needs'  => 'profile',
			'groups' => array(),
			'screen' => 'provider',
		),
		'sender'  => array(
			'label'  => __( 'Sender', 'diluxone-mail' ),
			'step'   => 3,
			'needs'  => 'server',
			'groups' => array( 'from' ),
			'screen' => 'provider',
		),
		'test'    => array(
			'label'  => __( 'Test message', 'diluxone-mail' ),
			'step'   => 4,
			'needs'  => 'sender',
			'groups' => array(),
			'screen' => 'provider',
		),
		'sending' => array(
			'label'  => __( 'Sending behaviour', 'diluxone-mail' ),
			'step'   => 0,
			'needs'  => '',
			'groups' => array( 'mode' ),
			'screen' => 'settings',
		),
		'logging' => array(
			'label'  => __( 'Log and privacy', 'diluxone-mail' ),
			'step'   => 0,
			'needs'  => '',
			'groups' => array( 'log', 'privacy' ),
			'screen' => 'settings',
		),
	);

	if ( 'network' === $scope ) {
		$tabs['sites'] = array(
			'label'  => __( 'Sites', 'diluxone-mail' ),
			'step'   => 0,
			'needs'  => '',
			'groups' => array(),
			'screen' => 'settings',
		);
	}

	/**
	 * Filters the tabs, in the order they are shown.
	 *
	 * A tab exists because it is registered here, not because a template has
	 * it written inside: the day a step of this belongs to a separate plugin,
	 * it adds its tab and the row picks it up. Nothing does that today.
	 *
	 * A tab that joins the chain needs a `step` and the `needs` of whatever
	 * comes before it, and whatever it declares in `groups` becomes savable
	 * from its form — which is why a group that is not in
	 * diluxone_mail_settings_fields() is dropped rather than trusted.
	 *
	 * @param array<string, array{label: string, step: int, needs: string, groups: array<int, string>, screen: string}> $tabs
	 * @param string                                                                                                    $scope 'site' or 'network'.
	 */
	/** @var array<array-key, mixed> $filtered */
	$filtered = (array) apply_filters( 'diluxone_mail_settings_tabs', $tabs, $scope );

	$tabs = array_filter( $filtered, 'diluxone_mail_tab_is_whole' );

	if ( '' === $screen ) {
		return $tabs;
	}

	// Narrowed to one screen: the four steps of choosing a provider are a
	// sequence somebody walks once, and the settings are things they come back
	// to change. Putting them in one row of tabs made the wizard look like a
	// place to rummage around in.
	return array_filter(
		$tabs,
		static fn( array $tab ): bool => $screen === $tab['screen']
	);
}

/**
 * Is this a tab the row can draw and the save routine can trust?
 *
 * Every key has to be there, because the row reads all five and a missing one
 * is a notice on every settings page. And `groups` is the part that matters
 * beyond tidiness: the save routine writes whatever the current tab's groups
 * name, so a group invented by a filter would be a way of writing settings
 * this plugin never declared. Only the ones it declares survive.
 *
 * @param mixed $tab
 */
function diluxone_mail_tab_is_whole( $tab ): bool {
	if ( ! is_array( $tab ) || ! isset( $tab['label'], $tab['step'], $tab['needs'], $tab['groups'], $tab['screen'] ) ) {
		return false;
	}

	return array() === array_diff( (array) $tab['groups'], array_keys( diluxone_mail_settings_fields() ) );
}

/** Which screen a tab is shown on. */
function diluxone_mail_tab_screen( string $tab, string $scope = 'site' ): string {
	return (string) ( diluxone_mail_settings_tabs( $scope )[ $tab ]['screen'] ?? 'settings' );
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
 * Keyed by the step that comes after the missing one, so the sentence names
 * what to go back and do rather than where you are standing.
 */
function diluxone_mail_tab_blocked_reason( string $tab ): string {
	switch ( $tab ) {
		case 'server':
			return 'api' === diluxone_mail_transport_kind()
				? __( 'Choose how this site sends and which provider first: the key belongs to one of them.', 'diluxone-mail' )
				: __( 'Choose a provider profile first: it fills in the host, the port and the encryption this tab asks for.', 'diluxone-mail' );
		case 'sender':
			return 'api' === diluxone_mail_transport_kind()
				? __( 'The provider has to accept the key first. Check it on the previous tab; until it does, there is nothing to send with.', 'diluxone-mail' )
				: __( 'The SMTP server has to answer first. Test the connection on the previous tab; until it does, there is nothing to send from.', 'diluxone-mail' );
		case 'test':
			return __( 'Set the From address first: a provider will not deliver a message sent from an address it has not verified.', 'diluxone-mail' );
		default:
			return '';
	}
}

/**
 * The first step of the chain that is not done yet.
 *
 * Not the one immediately before: a step opens when everything before it is
 * done, not when its neighbour is. Asking only the neighbour let the last step
 * open over an unverified server, because "there is a From address" was still
 * true from an earlier configuration — the screen offered to send a test
 * message through a server that had never answered.
 *
 * @param array<string, bool> $progress
 */
function diluxone_mail_first_unfinished_step( int $before, array $progress, string $scope = 'site' ): string {
	foreach ( diluxone_mail_settings_tabs( $scope ) as $slug => $tab ) {
		if ( 0 === $tab['step'] || $tab['step'] >= $before ) {
			continue;
		}

		if ( ! ( $progress[ $slug ] ?? false ) ) {
			return $slug;
		}
	}

	return '';
}

/**
 * Is this tab open?
 *
 * A tab that is not part of the chain is always open. One that is opens when
 * every step before it is done — and stays open from then on, because coming
 * back to correct something already configured is the normal case, not an
 * escape.
 *
 * @param array<string, bool> $progress
 */
function diluxone_mail_tab_open( string $tab, array $progress, string $scope = 'site' ): bool {
	$tabs = diluxone_mail_settings_tabs( $scope );

	if ( ! isset( $tabs[ $tab ] ) ) {
		return false;
	}

	if ( 0 === $tabs[ $tab ]['step'] ) {
		return true;
	}

	return '' === diluxone_mail_first_unfinished_step( $tabs[ $tab ]['step'], $progress, $scope );
}

/**
 * The tab being shown.
 *
 * Out of the closed ones it falls back to the first step that is not done,
 * which is where somebody arriving for the first time has to start anyway.
 */
function diluxone_mail_current_tab( string $scope = 'site', string $screen = '' ): string {
	$tabs     = diluxone_mail_settings_tabs( $scope, $screen );
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

	return (string) array_key_first( $tabs );
}

/**
 * The URL of one tab, on whichever of the two screens is asking.
 *
 * @param array<string, mixed> $args
 */
function diluxone_mail_tab_url( string $tab, string $scope = 'site', array $args = array() ): string {
	if ( 'network' === $scope ) {
		// One screen on the network: the super administrator is configuring
		// the whole network's mail in one sitting, not walking a site's setup.
		return add_query_arg( array_merge( array( 'tab' => $tab ), $args ), network_admin_url( 'settings.php?page=diluxone-mail-network' ) );
	}

	$page = 'provider' === diluxone_mail_tab_screen( $tab, $scope ) ? DILUXONE_MAIL_PROVIDER_PAGE : DILUXONE_MAIL_SETTINGS;

	// A step of the wizard belongs to the provider being set up, and every
	// link between steps has to say which one or the panel reopens on
	// somebody else's.
	if ( DILUXONE_MAIL_PROVIDER_PAGE === $page ) {
		$editing = diluxone_mail_editing_id();
		$args    = array_merge( $args, '' === $editing ? array( 'new' => '1' ) : array( 'connection' => $editing ) );
	}

	return diluxone_mail_admin_url( $page, array_merge( array( 'tab' => $tab ), $args ) );
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
function diluxone_mail_tabs_nav( string $current, string $scope, string $screen = '' ): void {
	$tabs     = diluxone_mail_settings_tabs( $scope, $screen );
	$progress = diluxone_mail_settings_progress();

	// The only markup a label may carry: the second step's two names, one of
	// them hidden.
	$allowed = array(
		'span' => array(
			'class'  => array(),
			'hidden' => array(),
		),
	);

	echo '<nav class="nav-tab-wrapper diluxone-mail-tabs">';

	foreach ( $tabs as $slug => $tab ) {
		$open  = diluxone_mail_tab_open( $slug, $progress, $scope );
		$done  = 0 !== $tab['step'] && ( $progress[ $slug ] ?? false );
		$label = 0 !== $tab['step']
			/* translators: 1: step number, 2: name of the step */
			? sprintf( esc_html( _x( '%1$d. %2$s', 'numbered step in the settings screen', 'diluxone-mail' ) ), (int) $tab['step'], esc_html( (string) $tab['label'] ) )
			: esc_html( (string) $tab['label'] );

		$classes = 'nav-tab';

		// The second step carries both of its names, and the one on screen is
		// decided by a radio button on the first step. Rendering only the
		// stored one leaves the tab describing the method the person has just
		// stopped choosing, until a round-trip catches up.
		if ( 'server' === $slug ) {
			$label = sprintf(
				/* translators: 1: step number, 2: name of the step */
				esc_html( _x( '%1$d. %2$s', 'numbered step in the settings screen', 'diluxone-mail' ) ),
				(int) $tab['step'],
				sprintf(
					'<span class="diluxone-mail-when-smtp"%1$s>%2$s</span><span class="diluxone-mail-when-api"%3$s>%4$s</span>',
					'smtp' === diluxone_mail_transport_kind() ? '' : ' hidden',
					esc_html__( 'SMTP server', 'diluxone-mail' ),
					'api' === diluxone_mail_transport_kind() ? '' : ' hidden',
					esc_html__( 'API key', 'diluxone-mail' )
				)
			);
		}

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
			// The reason belongs to the step that is missing, which on a tab
			// further down the chain is not the one right before it.
			$missing = diluxone_mail_first_unfinished_step( $tab['step'], $progress, $scope );
			$after   = array_keys( $tabs );
			$after   = (string) ( $after[ (int) array_search( $missing, $after, true ) + 1 ] ?? $slug );

			printf(
				'<span class="%1$s" aria-disabled="true" title="%2$s">%3$s</span>',
				esc_attr( $classes ),
				esc_attr( diluxone_mail_tab_blocked_reason( $after ) ),
				wp_kses( $label, $allowed ) . ' <span aria-hidden="true">&#128274;</span>'
			);

			continue;
		}

		printf(
			'<a href="%1$s" class="%2$s">%3$s</a>',
			esc_url( diluxone_mail_tab_url( $slug, $scope ) ),
			esc_attr( $classes ),
			wp_kses( $label, $allowed ) . ( $done ? ' <span aria-hidden="true">&#10003;</span>' : '' )
		);
	}

	echo '</nav>';
}
