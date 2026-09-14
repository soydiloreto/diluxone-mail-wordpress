<?php
/**
 * The provider profiles.
 *
 * A dropdown picks the provider and fills in host, port, encryption and — if
 * the provider imposes one — the username; all that is left is pasting the
 * key. Everything here was checked against each provider's official
 * documentation and, for Mailjet and Mailpit, against the real server. A host
 * written from memory is a plugin that does not send mail, so every profile
 * carries the link to the page it came from.
 *
 * These are the SMTP profiles. Reaching the same providers over their HTTP
 * APIs is a different thing with different credentials, and it lives in
 * api-providers.php: a provider that can be used either way appears in both
 * files, and the method chosen on the first step decides which one is read.
 *
 * Nothing here is imposed after the fact. The profile fills the form once and
 * from then on every value is an ordinary, editable option — the host
 * included, because regional endpoints, EU tenants and on-premises relays are
 * all real and a field the plugin refuses to let you change is a site that
 * cannot send.
 *
 * The local profiles — Mailpit and MailHog — run without authentication and
 * without TLS on purpose, and with PHPMailer's autoTLS turned off. Without
 * turning it off PHPMailer sees the server advertise STARTTLS, tries to
 * upgrade, the self-signed certificate fails to validate, and the send fails
 * even though everything else is right. It is the most common mistake in a
 * local environment, and that is why it is solved here and not in the
 * documentation.
 *
 * The two DNS lists on each profile feed the diagnosis: the DKIM selectors to
 * probe, and the SPF `include`s that identify the provider inside the
 * domain's record, so the ones still declared but no longer used can be
 * pointed out. Both were confirmed by querying the providers' own domains,
 * not copied from a support article. They are empty where there is nothing
 * fixed to look for — Amazon SES mints a random selector per identity,
 * Mailtrap covers SPF through its verification record rather than an
 * `include`, Resend puts its SPF on a subdomain — and empty means "unknown",
 * never "has none": the screen says less rather than something wrong.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every profile, in the order of the dropdown.
 *
 * @return array<string, array<string, mixed>>
 */
function diluxone_mail_providers(): array {
	return array(
		'mailpit'          => array(
			'name'           => 'Mailpit',
			'group'          => __( 'Local development', 'diluxone-mail' ),
			'host'           => 'localhost',
			'port'           => 1025,
			'encryption'     => 'none',
			'auth'           => false,
			'autotls'        => false,
			'user'           => '',
			'user_hint'      => '',
			'pass_hint'      => '',
			'local'          => true,
			'dkim_selectors' => array(),
			'spf_includes'   => array(),
			'return_path'    => '',
			'docs'           => 'https://mailpit.axllent.org/docs/configuration/runtime-options/',
		),
		'mailhog'          => array(
			'name'           => 'MailHog',
			'group'          => __( 'Local development', 'diluxone-mail' ),
			'host'           => 'localhost',
			'port'           => 1025,
			'encryption'     => 'none',
			'auth'           => false,
			'autotls'        => false,
			'user'           => '',
			'user_hint'      => '',
			'pass_hint'      => '',
			'local'          => true,
			'dkim_selectors' => array(),
			'spf_includes'   => array(),
			'return_path'    => '',
			'docs'           => 'https://github.com/mailhog/MailHog',
		),
		'mailtrap_testing' => array(
			'name'           => 'Mailtrap — Email Testing (sandbox)',
			'group'          => __( 'Testing', 'diluxone-mail' ),
			'host'           => 'sandbox.smtp.mailtrap.io',
			'port'           => 2525,
			'encryption'     => 'tls',
			'auth'           => true,
			'autotls'        => true,
			'user'           => '',
			'user_hint'      => __( 'The sandbox inbox username, from its Integration tab.', 'diluxone-mail' ),
			'pass_hint'      => __( 'The sandbox inbox password.', 'diluxone-mail' ),
			'local'          => false,
			'dkim_selectors' => array(),
			'spf_includes'   => array(),
			'return_path'    => '',
			'docs'           => 'https://docs.mailtrap.io/getting-started/email-sandbox',
		),
		'mailtrap_sending' => array(
			'name'           => 'Mailtrap — Email Sending',
			'group'          => __( 'Providers', 'diluxone-mail' ),
			'host'           => 'live.smtp.mailtrap.io',
			'port'           => 587,
			'encryption'     => 'tls',
			'auth'           => true,
			'autotls'        => true,
			'user'           => 'api',
			'user_hint'      => __( 'Always the word "api".', 'diluxone-mail' ),
			'pass_hint'      => __( 'Your Mailtrap API token.', 'diluxone-mail' ),
			'local'          => false,
			'dkim_selectors' => array( 'rwmt1', 'rwmt2' ),
			'spf_includes'   => array(),
			'return_path'    => '',
			'docs'           => 'https://docs.mailtrap.io/getting-started/email-api-smtp',
		),
		'm365'             => array(
			'name'           => 'Microsoft 365 / Exchange Online',
			'group'          => __( 'Providers', 'diluxone-mail' ),
			'host'           => 'smtp.office365.com',
			'port'           => 587,
			'encryption'     => 'tls',
			'auth'           => true,
			'autotls'        => true,
			'user'           => '',
			'user_hint'      => __( 'The full address of the mailbox that sends. SMTP AUTH must be enabled for it.', 'diluxone-mail' ),
			'pass_hint'      => __( 'That mailbox\'s password. Microsoft is retiring basic authentication; check the docs for your tenant.', 'diluxone-mail' ),
			'local'          => false,
			'dkim_selectors' => array( 'selector1', 'selector2' ),
			'spf_includes'   => array( 'spf.protection.outlook.com' ),
			'return_path'    => '',
			'docs'           => 'https://learn.microsoft.com/exchange/mail-flow-best-practices/how-to-set-up-a-multifunction-device-or-application-to-send-email-using-microsoft-365-or-office-365',
		),
		'azure_acs'        => array(
			'name'           => 'Azure Communication Services',
			'group'          => __( 'Providers', 'diluxone-mail' ),
			'host'           => 'smtp.azurecomm.net',
			'port'           => 587,
			'encryption'     => 'tls',
			'auth'           => true,
			'autotls'        => true,
			'user'           => '',
			'user_hint'      => __( 'The SMTP Username created on the Communication Services resource.', 'diluxone-mail' ),
			'pass_hint'      => __( 'A client secret of the Entra application linked to that username.', 'diluxone-mail' ),
			'local'          => false,
			'dkim_selectors' => array( 'selector1-azurecomm-prod-net', 'selector2-azurecomm-prod-net' ),
			'spf_includes'   => array( 'spf.protection.outlook.com' ),
			'return_path'    => '',
			'docs'           => 'https://learn.microsoft.com/azure/communication-services/quickstarts/email/send-email-smtp/smtp-authentication',
		),
		'google'           => array(
			'name'           => 'Google Workspace / Gmail',
			'group'          => __( 'Providers', 'diluxone-mail' ),
			'host'           => 'smtp.gmail.com',
			'port'           => 587,
			'encryption'     => 'tls',
			'auth'           => true,
			'autotls'        => true,
			'user'           => '',
			'user_hint'      => __( 'The full Gmail or Workspace address.', 'diluxone-mail' ),
			'pass_hint'      => __( 'An app password, not the account password. Requires two-step verification on the account.', 'diluxone-mail' ),
			'local'          => false,
			'dkim_selectors' => array( 'google' ),
			'spf_includes'   => array( '_spf.google.com' ),
			'return_path'    => '',
			'docs'           => 'https://support.google.com/a/answer/176600',
		),
		'ses'              => array(
			'name'           => 'Amazon SES',
			'group'          => __( 'Providers', 'diluxone-mail' ),
			'host'           => 'email-smtp.us-east-1.amazonaws.com', // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- This is the SES SMTP server, not a resource served from elsewhere.
			'port'           => 587,
			'encryption'     => 'tls',
			'auth'           => true,
			'autotls'        => true,
			'user'           => '',
			'user_hint'      => __( 'The SMTP username generated in SES (not the IAM access key).', 'diluxone-mail' ),
			'pass_hint'      => __( 'The SMTP password generated with it.', 'diluxone-mail' ),
			'local'          => false,
			'dkim_selectors' => array(),
			'spf_includes'   => array( 'amazonses.com' ),
			'return_path'    => '',
			'docs'           => 'https://docs.aws.amazon.com/ses/latest/dg/smtp-connect.html',
		),
		'brevo'            => array(
			'name'           => 'Brevo',
			'group'          => __( 'Providers', 'diluxone-mail' ),
			'host'           => 'smtp-relay.brevo.com',
			'port'           => 587,
			'encryption'     => 'tls',
			'auth'           => true,
			'autotls'        => true,
			'user'           => '',
			'user_hint'      => __( 'Your Brevo login email.', 'diluxone-mail' ),
			'pass_hint'      => __( 'An SMTP key from SMTP & API settings — not an API key.', 'diluxone-mail' ),
			'local'          => false,
			'dkim_selectors' => array( 'mail' ),
			'spf_includes'   => array( 'spf.sendinblue.com', 'spf.brevo.com' ),
			'return_path'    => '',
			'docs'           => 'https://developers.brevo.com/docs/smtp-integration',
		),
		'sendgrid'         => array(
			'name'           => 'SendGrid',
			'group'          => __( 'Providers', 'diluxone-mail' ),
			'host'           => 'smtp.sendgrid.net',
			'port'           => 587,
			'encryption'     => 'tls',
			'auth'           => true,
			'autotls'        => true,
			'user'           => 'apikey',
			'user_hint'      => __( 'Always the word "apikey".', 'diluxone-mail' ),
			'pass_hint'      => __( 'An API key with at least Mail Send permission.', 'diluxone-mail' ),
			'local'          => false,
			'dkim_selectors' => array( 's1', 's2' ),
			'spf_includes'   => array( 'sendgrid.net' ),
			'return_path'    => '',
			'docs'           => 'https://www.twilio.com/docs/sendgrid/for-developers/sending-email/integrating-with-the-smtp-api',
		),
		'mailjet'          => array(
			'name'           => 'Mailjet',
			'group'          => __( 'Providers', 'diluxone-mail' ),
			'host'           => 'in-v3.mailjet.com',
			'port'           => 587,
			'encryption'     => 'tls',
			'auth'           => true,
			'autotls'        => true,
			'user'           => '',
			'user_hint'      => __( 'The API Key.', 'diluxone-mail' ),
			'pass_hint'      => __( 'The Secret Key.', 'diluxone-mail' ),
			'local'          => false,
			'dkim_selectors' => array( 'mailjet' ),
			'spf_includes'   => array( 'spf.mailjet.com' ),
			'return_path'    => '',
			'docs'           => 'https://dev.mailjet.com/smtp-relay/configuration/',
		),
		'postmark'         => array(
			'name'           => 'Postmark',
			'group'          => __( 'Providers', 'diluxone-mail' ),
			'host'           => 'smtp.postmarkapp.com',
			'port'           => 587,
			'encryption'     => 'tls',
			'auth'           => true,
			'autotls'        => true,
			'user'           => '',
			'user_hint'      => __( 'The Server API Token (used as both username and password), or an SMTP token Access Key.', 'diluxone-mail' ),
			'pass_hint'      => __( 'The same Server API Token, or the SMTP token Secret Key.', 'diluxone-mail' ),
			'local'          => false,
			'dkim_selectors' => array( 'pm' ),
			'spf_includes'   => array( 'spf.mtasv.net' ),
			'return_path'    => 'pm-bounces',
			'docs'           => 'https://postmarkapp.com/developer/user-guide/send-email-with-smtp',
		),
		'resend'           => array(
			'name'           => 'Resend',
			'group'          => __( 'Providers', 'diluxone-mail' ),
			'host'           => 'smtp.resend.com',
			'port'           => 587,
			'encryption'     => 'tls',
			'auth'           => true,
			'autotls'        => true,
			'user'           => 'resend',
			'user_hint'      => __( 'Always the word "resend".', 'diluxone-mail' ),
			'pass_hint'      => __( 'An API key.', 'diluxone-mail' ),
			'local'          => false,
			'dkim_selectors' => array( 'resend' ),
			'spf_includes'   => array(),
			'return_path'    => '',
			'docs'           => 'https://resend.com/docs/send-with-smtp',
		),
		'custom'           => array(
			'name'           => __( 'Other SMTP server', 'diluxone-mail' ),
			'group'          => __( 'Anything else', 'diluxone-mail' ),
			'host'           => '',
			'port'           => 587,
			'encryption'     => 'tls',
			'auth'           => true,
			'autotls'        => true,
			'user'           => '',
			'user_hint'      => '',
			'pass_hint'      => '',
			'local'          => false,
			'dkim_selectors' => array(),
			'spf_includes'   => array(),
			'return_path'    => '',
			'docs'           => '',
		),
	);
}

/**
 * One profile, by its key.
 *
 * An unknown key — or an empty one, which means "nobody configured anything"
 * — returns the generic profile: everything by hand and no assumptions. That
 * is what keeps an old value stored in the database from breaking delivery
 * the day a profile gets renamed.
 *
 * @return array<string, mixed>
 */
function diluxone_mail_provider( string $key ): array {
	$profiles = diluxone_mail_providers();

	return $profiles[ $key ] ?? $profiles['custom'];
}

/**
 * The values a profile puts into the form when it is picked.
 *
 * This is not a precedence layer: the profile fills the options once, and
 * from then on they are ordinary, editable options. The only things the
 * profile imposes at send time are its fixed properties — auth and autoTLS —
 * which mailer.php reads from the profile and not from here.
 *
 * @return array<string, mixed>
 */
function diluxone_mail_provider_defaults( string $key ): array {
	$profile = diluxone_mail_provider( $key );

	return array(
		'diluxone_mail_provider'   => isset( diluxone_mail_providers()[ $key ] ) ? $key : 'custom',
		'diluxone_mail_host'       => (string) $profile['host'],
		'diluxone_mail_port'       => (int) $profile['port'],
		'diluxone_mail_encryption' => (string) $profile['encryption'],
		'diluxone_mail_auth'       => $profile['auth'] ? 1 : 0,
		'diluxone_mail_user'       => (string) $profile['user'],
	);
}
