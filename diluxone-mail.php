<?php
/**
 * Plugin Name:       DiluxOne Mail – SMTP, Email Log & Deliverability Diagnostics
 * Plugin URI:        https://pablodiloreto.com
 * Description:       Connect WordPress to any SMTP provider, keep a log of every message — including one per person, on their user profile — and find out whether the domain's SPF, DKIM and DMARC records actually let that mail arrive.
 * Version:           1.0.0
 * Author:            Pablo Ariel Di Loreto
 * Author URI:        https://pablodiloreto.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       diluxone-mail
 * Domain Path:       /languages
 * Requires at least: 6.2
 * Requires PHP:      8.1
 *
 * @package DiluxOneMail
 *
 * ---------------------------------------------------------------------------
 * Why this exists
 *
 * WordPress sends mail through the PHP function that asks the operating
 * system to deliver it, and on today's hosting that reaches nobody: with no
 * server to authenticate it, Gmail and Outlook drop it without telling
 * anyone. The site works, the form says "thank you", and the email never
 * existed.
 *
 * Connecting an SMTP server is something eight plugins already do, and do
 * well. This one does it too, boring and solid, because it is the floor: over
 * SMTP or over the provider's own API, with as many providers configured at
 * once as a site wants. They are a list and the order is the whole of it —
 * the first sends, the next is what gets tried when it will not — because
 * "default" and "fallback" are two names for a thing an order already says,
 * and an order keeps saying it for the fourth provider too.
 *
 * But the floor is not the product: the two things this plugin exists for are
 * the ones none of those eight does.
 *
 * The first is that the mail history hangs off each person's profile. They
 * all show one global list of sends; none lets you open a user and see what
 * was sent to them. When somebody writes "I never got the email", the answer
 * is on their profile, not in a list of ten thousand rows.
 *
 * The second is that it reads the domain's DNS and explains what is broken:
 * how many of the standard's ten DNS lookups the SPF record burns, which
 * providers it still declares but no longer uses, whether DKIM is published,
 * and what the DMARC policy in place actually implies.
 *
 * And one decision about how it arrives: if another plugin is already
 * handling the mail when this one is activated, it does not fight. It logs
 * and diagnoses without touching delivery, and says so. A site whose mail
 * works should not have to break to try this out.
 *
 * What it does not do and will not do: send the mail itself. That is a
 * sending service, with its infrastructure, its IP reputation and its bounce
 * handling. Here you connect the provider the site already has, full stop.
 *
 * Nor does it keep the body of a message, and there is no setting for it. The
 * mail WordPress sends most often is the password reset, and that link is not
 * a record of what happened: it is a key to the account for as long as it is
 * valid, for whoever reads the table next.
 * ---------------------------------------------------------------------------
 */

defined( 'ABSPATH' ) || exit;

define( 'DILUXONE_MAIL_VERSION', '1.0.0' );
define( 'DILUXONE_MAIL_DIR', plugin_dir_path( __FILE__ ) );
define( 'DILUXONE_MAIL_URL', plugin_dir_url( __FILE__ ) );
define( 'DILUXONE_MAIL_FILE', __FILE__ );

/**
 * Translations.
 *
 * Strings in the code are English and the Spanish translation ships with the
 * plugin, in languages/. It is what makes the DNS diagnosis read as prose
 * rather than as a dump of DNS records.
 *
 * The wordpress.org Plugin Check warns that this call has not been needed
 * since WordPress 4.6, and for a plugin that does NOT ship its own
 * translations it is right: the ones from translate.wordpress.org load by
 * themselves. But WP_Textdomain_Registry::get_paths_for_domain() only looks
 * at WP_LANG_DIR/plugins, WP_LANG_DIR/themes and a custom path "if somebody
 * registered one" — and the only thing that registers one is this function.
 * Without it nobody loads the .mo that ships in languages/, and the plugin
 * stays in English for anyone installing it from GitHub, or before the
 * translation lives on wordpress.org.
 *
 * The day the translation lives on translate.wordpress.org, this function is
 * deleted and the warning goes with it.
 */
function diluxone_mail_load_textdomain(): void {
	// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- See above: this is the only thing that registers the path of the .mo the plugin ships.
	load_plugin_textdomain(
		'diluxone-mail',
		false,
		dirname( plugin_basename( DILUXONE_MAIL_FILE ) ) . '/languages'
	);
}
add_action( 'init', 'diluxone_mail_load_textdomain' );

/**
 * Every file in includes/ is independent and only registers hooks. They are
 * loaded in alphabetical order on purpose: if one needed another to have run
 * first, that would be a coupling to solve with a hook, not with the order.
 */
foreach ( (array) glob( DILUXONE_MAIL_DIR . 'includes/*.php' ) as $diluxone_mail_file ) {
	require_once (string) $diluxone_mail_file;
}

/**
 * On activation: the log tables.
 *
 * Creating them lives in includes/log.php next to the schema, because it is
 * also needed when the plugin changes version — where this hook does not run
 * — and two copies of the same CREATE TABLE drift apart at the first new
 * column.
 */
register_activation_hook( __FILE__, 'diluxone_mail_install' );

/**
 * On deactivation: the purge is taken off cron. The tables stay; see
 * includes/cron.php.
 */
register_deactivation_hook( __FILE__, 'diluxone_mail_deactivate' );
