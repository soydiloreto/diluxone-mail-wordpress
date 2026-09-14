<?php
/**
 * More than one provider configured at a time.
 *
 * A site that changes provider used to lose the one it had: applying a profile
 * overwrote the host, the port and the username, and the credential that was
 * still stored now pointed at somebody else's server. Trying a second provider
 * meant destroying the first, and going back meant setting it up again from
 * the documentation.
 *
 * So the settings of a provider are a record of their own, and a site keeps as
 * many as it likes, in an order it decides. The first one sends. The next one
 * is what gets tried when it will not. The rest sit there, configured and
 * idle, which is exactly what you want the day the first starts refusing mail.
 *
 * One concept rather than two: a list with an order says everything "default"
 * and "fallback" said, and it says it for the fourth provider as well.
 *
 * Everything above this keeps working unchanged because the seam is a low one:
 * `diluxone_mail_option_stored()` answers for the fields that belong to a
 * connection out of the active one, so the environment still wins over all of
 * it, the settings screen still renders provenance, and the fingerprints still
 * decide what has been verified.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * The option keys that describe a provider rather than the site.
 *
 * Everything else — the log, the diagnosis, the sending mode — belongs to the
 * site and stays where it was: those do not change when the mail starts going
 * out through somebody else.
 *
 * @return array<int, string>
 */
function diluxone_mail_connection_fields(): array {
	return array(
		'diluxone_mail_provider',
		'diluxone_mail_transport',
		'diluxone_mail_host',
		'diluxone_mail_port',
		'diluxone_mail_encryption',
		'diluxone_mail_auth',
		'diluxone_mail_user',
		'diluxone_mail_pass',
		'diluxone_mail_timeout',
		'diluxone_mail_api_key',
		'diluxone_mail_from',
		'diluxone_mail_from_name',
		'diluxone_mail_force_from',
	);
}

/** Does this option belong to a provider? */
function diluxone_mail_is_connection_field( string $key ): bool {
	return in_array( $key, diluxone_mail_connection_fields(), true );
}

/**
 * Where the list lives: with the network, or with the site.
 *
 * The same rule as every other setting. A network that has not handed over
 * control keeps one list for everybody; one that has lets each site keep its
 * own.
 */
function diluxone_mail_connections_scope(): string {
	return is_multisite() && ! diluxone_mail_site_override_allowed() ? 'network' : 'site';
}

/**
 * Every configured provider, keyed by id.
 *
 * @return array<string, array<string, mixed>>
 */
function diluxone_mail_connections(): array {
	$stored = 'network' === diluxone_mail_connections_scope()
		? get_site_option( 'diluxone_mail_connections', array() )
		: get_option( 'diluxone_mail_connections', array() );

	if ( ! is_array( $stored ) ) {
		return array();
	}

	$connections = array();

	foreach ( $stored as $id => $connection ) {
		if ( is_array( $connection ) ) {
			$connections[ (string) $id ] = $connection;
		}
	}

	return $connections;
}

/**
 * Writes the whole list back.
 *
 * @param array<string, array<string, mixed>> $connections
 */
function diluxone_mail_connections_put( array $connections ): void {
	if ( 'network' === diluxone_mail_connections_scope() ) {
		update_site_option( 'diluxone_mail_connections', $connections );
		return;
	}

	update_option( 'diluxone_mail_connections', $connections );
}

/**
 * One of them, or an empty array.
 *
 * @return array<string, mixed>
 */
function diluxone_mail_connection( string $id ): array {
	return diluxone_mail_connections()[ $id ] ?? array();
}

/**
 * The id of the one that sends: the first on the list.
 *
 * The order is the whole of it. There is no separate setting saying which one
 * is in charge, so there is no way for the list and that setting to disagree —
 * and moving a provider to the top is the same gesture as putting it in
 * charge, which is what somebody means when they do it.
 */
function diluxone_mail_default_id(): string {
	$connections = diluxone_mail_connections();

	return array() === $connections ? '' : (string) array_key_first( $connections );
}

/**
 * The ids to try, in order, after the one that failed.
 *
 * @return array<int, string>
 */
function diluxone_mail_connection_chain( string $after = '' ): array {
	$ids = array_keys( diluxone_mail_connections() );

	if ( '' === $after ) {
		return $ids;
	}

	$at = array_search( $after, $ids, true );

	return false === $at ? array() : array_slice( $ids, (int) $at + 1 );
}

/** The next one to try when this one will not send, if there is one. */
function diluxone_mail_fallback_id( string $after = '' ): string {
	$chain = diluxone_mail_connection_chain( '' === $after ? diluxone_mail_default_id() : $after );

	return $chain[0] ?? '';
}

/**
 * Puts the list in the given order.
 *
 * Ids that are not on the list are ignored and ids left out keep their place
 * at the end: a reorder arriving from a browser is not a reason to lose a
 * provider somebody configured.
 *
 * @param array<int, string> $ids
 */
function diluxone_mail_connections_reorder( array $ids ): void {
	$connections = diluxone_mail_connections();
	$ordered     = array();

	foreach ( $ids as $id ) {
		$id = (string) $id;

		if ( isset( $connections[ $id ] ) ) {
			$ordered[ $id ] = $connections[ $id ];
		}
	}

	foreach ( $connections as $id => $connection ) {
		if ( ! isset( $ordered[ $id ] ) ) {
			$ordered[ $id ] = $connection;
		}
	}

	diluxone_mail_connections_put( $ordered );
}

/**
 * The answer that means "a provider that does not exist yet".
 *
 * An empty id already meant "whichever is in charge", so adding one needed a
 * word of its own. Without it the four steps of a new provider showed the
 * progress of the first one on the list: its ticks, its verified credential,
 * its sender — for a record nobody had created.
 */
const DILUXONE_MAIL_NEW = '__new__';

/**
 * Which connection answers for the fields right now.
 *
 * Normally the first on the list. During a failover it is whichever one the
 * retry is using, for as long as that lasts — every layer above reads the
 * configuration through this, so switching it is all a retry has to do.
 *
 * @param string|null $set An id to switch to, or '' to go back to the default.
 */
function diluxone_mail_active_id( ?string $set = null ): string {
	$override = diluxone_mail_focus( $set );

	if ( DILUXONE_MAIL_NEW === $override ) {
		return '';
	}

	$connections = diluxone_mail_connections();

	if ( '' !== $override && isset( $connections[ $override ] ) ) {
		return $override;
	}

	return diluxone_mail_default_id();
}

/**
 * The override itself, which is not the same as the id it resolves to.
 *
 * Anything that points the request somewhere for a moment and then puts it
 * back has to save and restore this, not the answer: the answer to "which one
 * is active" is a real id even when nothing was overridden, so restoring that
 * pins the request to a provider it was only ever defaulting to. The list
 * screen does exactly that once per row, and the panel drawn afterwards
 * inherited it — which is how adding a provider ended up showing the ticks and
 * the second step of the one already in charge.
 *
 * @param string|null $set An id, DILUXONE_MAIL_NEW, or '' to stop overriding.
 */
function diluxone_mail_focus( ?string $set = null ): string {
	static $override = '';

	if ( null !== $set ) {
		$override = $set;
	}

	return $override;
}

/**
 * The active connection's value for one option, or null when it has none.
 *
 * Null rather than an empty string on purpose: "this provider does not set a
 * username" and "there is no provider" have to be told apart by the caller,
 * which is what lets the stored-value layer fall through to the old flat
 * options on a site that has not been migrated yet.
 *
 * @return mixed
 */
function diluxone_mail_connection_value( string $key ) {
	if ( ! diluxone_mail_is_connection_field( $key ) ) {
		return null;
	}

	$connection = diluxone_mail_connection( diluxone_mail_active_id() );

	return array_key_exists( $key, $connection ) ? $connection[ $key ] : null;
}

/**
 * The connection the screen is working on.
 *
 * Not the same question as "which one sends": somebody can be setting up a
 * third provider while the first keeps delivering the site's mail. The wizard
 * says which one it means in the request, and an empty answer is a new one
 * that has not been written yet.
 */
function diluxone_mail_editing_id(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- It only picks which record the screen is looking at; every handler verifies its own nonce before writing.
	$asked = isset( $_REQUEST['connection'] ) ? sanitize_key( wp_unslash( $_REQUEST['connection'] ) ) : '';

	if ( '' !== $asked ) {
		return isset( diluxone_mail_connections()[ $asked ] ) ? $asked : '';
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- It only decides whether the screen is looking at a new record or an existing one.
	if ( isset( $_REQUEST['new'] ) ) {
		return '';
	}

	return diluxone_mail_default_id();
}

/**
 * Stores one connection, keeping what it does not carry.
 *
 * @param array<string, mixed> $values
 * @return string The id, made up if this is a new one.
 */
function diluxone_mail_connection_put( string $id, array $values ): string {
	$connections = diluxone_mail_connections();

	if ( '' === $id || ! isset( $connections[ $id ] ) ) {
		$id = 'cn_' . substr( md5( uniqid( 'diluxone', true ) ), 0, 12 );
	}

	$connections[ $id ] = array_merge( $connections[ $id ] ?? array(), $values );

	diluxone_mail_connections_put( $connections );

	return $id;
}

/**
 * Points this request at the record the screen is working on.
 *
 * One rule in one place, because getting it wrong is invisible: a provider
 * being added must answer out of nothing, not out of whichever one is in
 * charge, or its four steps arrive carrying somebody else's ticks, somebody
 * else's verified credential and somebody else's sender.
 *
 * @return string The id, empty when it is one that does not exist yet.
 */
function diluxone_mail_focus_editing(): string {
	$editing = diluxone_mail_editing_id();

	diluxone_mail_active_id( '' === $editing ? DILUXONE_MAIL_NEW : $editing );

	return $editing;
}

/**
 * Writes into the record the screen is working on, and stays on it.
 *
 * A new provider has no id until the first thing is written, and everything
 * after that — the credential, what has been verified, what the next step
 * renders — has to land on the record that was just created rather than on
 * whichever one is in charge. So writing also settles which one the rest of
 * this request is about.
 *
 * @param array<string, mixed> $values
 * @return string The id written to.
 */
function diluxone_mail_connection_write( array $values ): string {
	$id = diluxone_mail_connection_put( diluxone_mail_editing_id(), $values );

	diluxone_mail_active_id( $id );

	return $id;
}

/**
 * Removes one.
 *
 * Whoever was behind it moves up, which is the same rule as everywhere else
 * here: the list decides, and a shorter list still has a first element.
 */
function diluxone_mail_connection_forget( string $id ): void {
	$connections = diluxone_mail_connections();

	unset( $connections[ $id ] );

	diluxone_mail_connections_put( $connections );
}

/** Moves one to the top, which is what putting it in charge means. */
function diluxone_mail_connection_promote( string $id ): void {
	diluxone_mail_connections_reorder( array( $id ) );
}

/**
 * What to call a connection on a list.
 *
 * Whatever it was named, or the provider and the sender, which between them
 * say which of two Mailtraps this is.
 *
 * @param array<string, mixed> $connection
 */
function diluxone_mail_connection_label( array $connection ): string {
	$label = trim( (string) ( $connection['label'] ?? '' ) );

	if ( '' !== $label ) {
		return $label;
	}

	$profile = diluxone_mail_provider( (string) ( $connection['diluxone_mail_provider'] ?? '' ) );
	$from    = (string) ( $connection['diluxone_mail_from'] ?? '' );

	if ( '' === $from ) {
		return (string) $profile['name'];
	}

	/* translators: 1: provider name, 2: the From address */
	return sprintf( __( '%1$s — %2$s', 'diluxone-mail' ), (string) $profile['name'], $from );
}

/**
 * Turns a site's single configuration into the first of a list.
 *
 * Runs once, on the request after updating. What it moves is exactly the
 * fields a connection owns, so a site that was sending before the update is
 * sending after it, through the same provider, with the same credential — the
 * one thing a migration of this kind has to get right.
 */
function diluxone_mail_connections_migrate(): void {
	if ( array() !== diluxone_mail_connections() ) {
		return;
	}

	$values   = array();
	$anything = false;

	foreach ( diluxone_mail_connection_fields() as $key ) {
		$stored = is_multisite() ? get_site_option( $key, null ) : null;
		$stored = null === $stored ? get_option( $key, null ) : $stored;

		if ( null === $stored ) {
			continue;
		}

		$values[ $key ] = $stored;

		if ( in_array( $key, array( 'diluxone_mail_host', 'diluxone_mail_api_key' ), true ) && '' !== (string) $stored ) {
			$anything = true;
		}
	}

	// Nothing configured: there is nothing to carry over, and an empty
	// connection on the list would be a provider somebody never added.
	if ( ! $anything ) {
		return;
	}

	$verified = get_option( 'diluxone_mail_verified', array() );

	if ( is_array( $verified ) && array() !== $verified ) {
		$values['verified'] = $verified;
	}

	diluxone_mail_connection_put( '', $values );

	foreach ( diluxone_mail_connection_fields() as $key ) {
		delete_option( $key );
		delete_site_option( $key );
	}

	delete_option( 'diluxone_mail_verified' );
	delete_site_option( 'diluxone_mail_verified' );
}
add_action( 'admin_init', 'diluxone_mail_connections_migrate', 5 );
