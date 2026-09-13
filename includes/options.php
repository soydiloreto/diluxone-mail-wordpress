<?php
/**
 * The plugin's settings, with their default values in a single place.
 *
 * Everything that in other plugins is a constant or a number written in the
 * middle of the code lives here and is edited from the dashboard.
 *
 * One thing to watch: for the eight transport values — host, port, user,
 * password, encryption, sender, sender name and provider — this list is the
 * last resort, not the source. A PHP constant and an environment variable
 * come first, and config.php handles that. Their defaults are here because
 * they have to be stored somewhere when an administrator types them by hand,
 * but they are never read directly: they are read through
 * diluxone_mail_config(), which honours the precedence.
 *
 * On a network there are also two places to store them: the network, which
 * the super administrator fixes for everyone, and each site. The mail server
 * is network infrastructure, not a per-site preference, so the network wins:
 * a site can only have its own if the network allows it. And when it does
 * not, the site's screen shows them read-only saying the network fixes them,
 * instead of letting somebody change something that changes nothing.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Default values. The key is the option name, prefixed.
 *
 * @return array<string, mixed>
 */
function diluxone_mail_option_defaults(): array {
	return array(
		// ── Transport ─────────────────────────────────────────────────
		// The chosen provider profile: 'mailjet', 'm365', 'mailpit'…
		// Empty means nobody has configured anything yet, which is different
		// from having picked "generic SMTP" by hand. The list lives in
		// providers.php.
		'diluxone_mail_provider'                  => '',
		'diluxone_mail_host'                      => '',
		'diluxone_mail_port'                      => 587,
		// 'none', 'ssl' — port 465, encrypted from the greeting — or 'tls'
		// — STARTTLS, which starts in the clear and upgrades. Local profiles
		// use 'none' on purpose: see providers.php.
		'diluxone_mail_encryption'                => 'tls',
		// Almost every provider asks for a user and a password. Mailpit and
		// MailHog do not, and there the profile turns this off by itself.
		'diluxone_mail_auth'                      => 1,
		'diluxone_mail_user'                      => '',
		// Never goes back to the browser, and is never stored here when it
		// comes from a constant or the environment. See config.php.
		'diluxone_mail_pass'                      => '',
		// Seconds to wait for the server before giving the send up as lost.
		// WordPress is loading the page meanwhile, so a high value is a page
		// that hangs.
		'diluxone_mail_timeout'                   => 30,

		// ── Who signs the mail ────────────────────────────────────────
		// Empty = whatever WordPress puts. Worth filling in: the default
		// sender is wordpress@thedomain, which usually does not exist and
		// which many providers reject as unauthorised.
		'diluxone_mail_from'                      => '',
		'diluxone_mail_from_name'                 => '',
		// Override the sender each send brings. On, it fixes the plugins that
		// send from made-up addresses; off, it respects the ones sending from
		// a real, different address on purpose — a shop replying from sales@,
		// for instance.
		'diluxone_mail_force_from'                => 0,

		// ── How this plugin takes part in sending ─────────────────────
		// 'auto'      if another plugin is handling the mail, do not touch
		// delivery: log and diagnose. If there is none, send.
		// 'observe'   never touch delivery, even with nobody else around.
		// 'transport' always send, even if another plugin is there. This is
		// what the "Take over" button sets.
		'diluxone_mail_mode'                      => 'auto',
		// Detach the plugin that cuts the send short in pre_wp_mail before
		// anything can be configured. Off by default and with its warning:
		// detaching the one actually delivering the mail leaves the site with
		// no mail. See pre-wp-mail.php.
		'diluxone_mail_unhook_pre_wp_mail'        => 0,

		// ── The log ───────────────────────────────────────────────────
		// The basic log: date, recipient, sender, subject, outcome, error if
		// there was one, provider and who originated the send. It is the
		// table that hangs off each person's profile, and it ships on because
		// it is the reason this plugin exists.
		'diluxone_mail_log_enabled'               => 1,
		// Days each send is kept. The purge runs on cron.
		'diluxone_mail_log_retention_days'        => 30,
		// The extended log: also the headers, the attachment names and the
		// full SMTP conversation with the provider — every command and every
		// reply. It is what you need to argue with the provider's support,
		// and capturing the dialogue on every send costs something, so it
		// ships off.
		'diluxone_mail_log_extended'              => 0,
		// Store the message body. Off on purpose: the body is personal data
		// — names, orders, sometimes a temporary password — and it is what
		// turns a technical log into a legal problem. Whoever turns it on
		// knows what they are doing. Without the body a message cannot be
		// resent, and the person's profile says so.
		'diluxone_mail_log_body'                  => 0,
		// The body and the SMTP dialogue are deleted before the rest of the
		// row: a few days are enough to diagnose "I did not get yesterday's",
		// and they are the heaviest and most sensitive thing stored.
		'diluxone_mail_log_detail_retention_days' => 7,

		// ── DNS diagnostics ───────────────────────────────────────────
		// Empty = the configured sender's domain or, failing that, the
		// site's. Another one can be pinned when the mail leaves from a
		// domain other than the one serving the pages.
		'diluxone_mail_dns_domain'                => '',
		// Extra DKIM selectors to probe, on top of the well-known ones and
		// the ones the active provider profile declares. They cannot be
		// listed over DNS: either you guess them or you ask.
		'diluxone_mail_dns_selectors'             => array(),
		// How DNS is queried: 'auto' uses the system resolver and falls back
		// to DNS-over-HTTPS when the host has dns_get_record() disabled — and
		// many do; 'system' and 'doh' force one or the other.
		'diluxone_mail_dns_resolver'              => 'auto',
		'diluxone_mail_dns_doh_endpoint'          => 'https://cloudflare-dns.com/dns-query',
		// Hours a cached result is good for. DNS does not change every time
		// somebody opens the dashboard, and there is a revalidate button for
		// when it does.
		'diluxone_mail_dns_cache_hours'           => 12,

		// ── Privacy ───────────────────────────────────────────────────
		// The log is personal data: what was sent to somebody and when. Both
		// ship on because that is what is right, and a site that would rather
		// handle those requests by hand turns them off.
		'diluxone_mail_privacy_export'            => 1,
		'diluxone_mail_privacy_erase'             => 1,

		// ── The network ───────────────────────────────────────────────
		// Only exists on a network. On, each site can override the network's
		// settings with its own; off, what the super administrator fixes
		// applies to everyone and each site's screens show it read-only.
		'diluxone_mail_network_allow_override'    => 0,
	);
}

/**
 * The options that only make sense stored on the network.
 *
 * A site cannot decide whether sites may override the network: the network
 * decides that. Storing it per site would be an option nobody reads.
 *
 * @return array<int, string>
 */
function diluxone_mail_network_only_options(): array {
	return array( 'diluxone_mail_network_allow_override' );
}

/**
 * May this site's settings override the network's?
 *
 * Outside a network the question does not exist: the site is all there is.
 */
function diluxone_mail_site_override_allowed(): bool {
	if ( ! is_multisite() ) {
		return true;
	}

	return (bool) get_site_option( 'diluxone_mail_network_allow_override', 0 );
}

/**
 * A setting as stored, and which layer it came from.
 *
 * This is the only function that knows there are two places to store things.
 * Everything else asks here and gets a value with its provenance, which is
 * what the screens need in order to say "the network fixes this".
 *
 * @return array{value: mixed, scope: string}
 *         scope: 'site', 'network' or 'default'.
 */
function diluxone_mail_option_stored( string $key ): array {
	$defaults = diluxone_mail_option_defaults();

	if ( is_multisite() ) {
		if ( diluxone_mail_site_override_allowed() && ! in_array( $key, diluxone_mail_network_only_options(), true ) ) {
			$site = get_option( $key, null );

			if ( null !== $site ) {
				return array(
					'value' => $site,
					'scope' => 'site',
				);
			}
		}

		$network = get_site_option( $key, null );

		if ( null !== $network ) {
			return array(
				'value' => $network,
				'scope' => 'network',
			);
		}
	} else {
		$site = get_option( $key, null );

		if ( null !== $site ) {
			return array(
				'value' => $site,
				'scope' => 'site',
			);
		}
	}

	return array(
		'value' => $defaults[ $key ] ?? null,
		'scope' => 'default',
	);
}

/**
 * A setting, with its default value.
 *
 * @param string $key      Option name, prefixed.
 * @param mixed  $fallback Value if there is neither an option nor a default.
 * @return mixed
 */
function diluxone_mail_option( string $key, $fallback = null ) {
	$value = diluxone_mail_option_stored( $key )['value'] ?? $fallback;

	/**
	 * Filters one of the plugin's settings.
	 *
	 * @param mixed  $value Resolved value.
	 * @param string $key   Option name.
	 */
	return apply_filters( 'diluxone_mail_option', $value, $key );
}

/**
 * Saves the settings coming from a dashboard screen.
 *
 * Three rules that are not obvious and that carry the whole security of the
 * form:
 *
 * 1. Only what is in the defaults gets saved. A key that is not there is
 *    dropped silently, so a hand-crafted POST cannot write just any option of
 *    the site.
 * 2. What the environment provides is never stored. If the host comes from a
 *    PHP constant, writing the option would store a value that is not used
 *    and that contradicts the one that is: the database would end up holding
 *    an old credential nobody uses but anybody can read.
 * 3. On a network a site stores nothing if the network did not allow it, and
 *    the options that belong to the network are not stored on a site.
 *
 * @param array<string, mixed> $input
 * @param string               $scope 'site' or 'network'.
 */
function diluxone_mail_save_options( array $input, string $scope = 'site' ): void {
	$defaults = diluxone_mail_option_defaults();

	if ( 'site' === $scope && ! diluxone_mail_site_override_allowed() ) {
		return;
	}

	foreach ( $input as $key => $value ) {
		if ( ! array_key_exists( $key, $defaults ) ) {
			continue;
		}

		if ( 'site' === $scope && in_array( $key, diluxone_mail_network_only_options(), true ) ) {
			continue;
		}

		if ( diluxone_mail_option_from_environment( $key ) ) {
			continue;
		}

		$default = $defaults[ $key ];

		if ( is_int( $default ) ) {
			$value = (int) $value;
		} elseif ( is_array( $default ) ) {
			// Lists of keys — the DKIM selectors the site adds — sanitised
			// element by element and with no stray indexes.
			$value = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $value ) ) ) );
		} else {
			$value = sanitize_textarea_field( (string) $value );
		}

		if ( 'network' === $scope ) {
			update_site_option( $key, $value );
		} else {
			update_option( $key, $value );
		}
	}
}
