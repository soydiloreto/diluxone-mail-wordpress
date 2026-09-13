<?php
/**
 * Los perfiles de proveedor.
 *
 * Un desplegable elige el proveedor y rellena host, puerto, cifrado y —si el
 * proveedor lo impone— el usuario; sólo queda pegar la clave. Todo lo que
 * está acá se verificó contra la documentación oficial de cada uno y, en el
 * caso de Mailjet y Mailpit, contra el servidor de verdad. Un host escrito de
 * memoria es un plugin que no manda correo, así que cada perfil lleva el
 * enlace a la página de la que salió.
 *
 * Sólo SMTP. Con un único camino de código se llega a todos los proveedores
 * del mercado; las APIs HTTP no suman nada hoy y multiplican el código por
 * proveedor.
 *
 * Los perfiles locales —Mailpit y MailHog— van sin autenticación y sin TLS a
 * propósito, y con el autoTLS de PHPMailer apagado. Sin apagarlo, PHPMailer
 * ve que el servidor anuncia STARTTLS, intenta subir a cifrado, el
 * certificado autofirmado no valida, y el envío falla aunque el resto esté
 * bien. Es el error más común de un entorno local y por eso está resuelto
 * acá y no en la documentación.
 *
 * Las dos listas de DNS de cada perfil alimentan el diagnóstico: los
 * selectores DKIM que se sondean, y los `include` de SPF con los que se
 * reconoce al proveedor en el registro del dominio, para poder señalar los
 * que quedaron declarados y ya no se usan. Van vacías cuando no se pudieron
 * verificar —vacío es «no sé», nunca «no tiene»—.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Todos los perfiles, en el orden del desplegable.
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
			'host_editable'  => true,
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
			'host_editable'  => true,
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
			'host_editable'  => false,
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
			'host_editable'  => false,
			'local'          => false,
			'dkim_selectors' => array(),
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
			'host_editable'  => false,
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
			'host_editable'  => false,
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
			'host_editable'  => true,
			'local'          => false,
			'dkim_selectors' => array( 'google' ),
			'spf_includes'   => array( '_spf.google.com' ),
			'return_path'    => '',
			'docs'           => 'https://support.google.com/a/answer/176600',
		),
		'ses'              => array(
			'name'           => 'Amazon SES',
			'group'          => __( 'Providers', 'diluxone-mail' ),
			'host'           => 'email-smtp.us-east-1.amazonaws.com',
			'port'           => 587,
			'encryption'     => 'tls',
			'auth'           => true,
			'autotls'        => true,
			'user'           => '',
			'user_hint'      => __( 'The SMTP username generated in SES (not the IAM access key).', 'diluxone-mail' ),
			'pass_hint'      => __( 'The SMTP password generated with it.', 'diluxone-mail' ),
			'host_editable'  => true,
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
			'host_editable'  => false,
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
			'host_editable'  => false,
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
			'host_editable'  => false,
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
			'host_editable'  => false,
			'local'          => false,
			'dkim_selectors' => array(),
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
			'host_editable'  => false,
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
			'host_editable'  => true,
			'local'          => false,
			'dkim_selectors' => array(),
			'spf_includes'   => array(),
			'return_path'    => '',
			'docs'           => '',
		),
	);
}

/**
 * Un perfil por su clave.
 *
 * Una clave desconocida —o vacía, que es «nadie configuró nada»— devuelve el
 * perfil genérico: todo a mano y sin suposiciones. Es lo que hace que un
 * valor viejo guardado en la base no rompa el envío el día que se renombre un
 * perfil.
 *
 * @return array<string, mixed>
 */
function diluxone_mail_provider( string $key ): array {
	$perfiles = diluxone_mail_providers();

	return $perfiles[ $key ] ?? $perfiles['custom'];
}

/**
 * Los valores que un perfil pone en el formulario al elegirlo.
 *
 * No es una capa de precedencia: el perfil rellena las options una vez, y
 * desde ahí son options normales, editables. Lo único que el perfil impone
 * en tiempo de envío son sus propiedades fijas —auth y autoTLS—, que se leen
 * del perfil en mailer.php y no de acá.
 *
 * @return array<string, mixed>
 */
function diluxone_mail_provider_defaults( string $key ): array {
	$perfil = diluxone_mail_provider( $key );

	return array(
		'diluxone_mail_provider'   => isset( diluxone_mail_providers()[ $key ] ) ? $key : 'custom',
		'diluxone_mail_host'       => (string) $perfil['host'],
		'diluxone_mail_port'       => (int) $perfil['port'],
		'diluxone_mail_encryption' => (string) $perfil['encryption'],
		'diluxone_mail_auth'       => $perfil['auth'] ? 1 : 0,
		'diluxone_mail_user'       => (string) $perfil['user'],
	);
}
