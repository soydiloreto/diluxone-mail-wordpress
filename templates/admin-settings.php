<?php
/**
 * La vista del formulario de ajustes, de un sitio o de la red.
 *
 * Recibe $data de diluxone_mail_settings_data(). Cada control mira su
 * propia procedencia y se pone de sólo lectura solo: no hay una regla
 * general de «este formulario está bloqueado», hay un motivo por control.
 *
 * @package DiluxOneMail
 * @var array<string, mixed> $data
 */

defined( 'ABSPATH' ) || exit;

$diluxone_mail_f    = $data['fields'];
$diluxone_mail_test = $data['test'];

/**
 * Un control con su leyenda de procedencia.
 *
 * @param array<string, mixed> $campo
 */
$diluxone_mail_source = static function ( array $campo ): void {
	if ( 'site' === $campo['source'] || 'default' === $campo['source'] ) {
		return;
	}

	printf( ' <span class="description diluxone-mail-source">— %s</span>', esc_html( (string) $campo['label'] ) );
};
?>

<?php if ( is_array( $diluxone_mail_test ) ) : ?>
	<div class="notice notice-<?php echo $diluxone_mail_test['ok'] ? 'success' : 'error'; ?>">
		<p>
			<?php if ( $diluxone_mail_test['ok'] ) : ?>
				<?php
				printf(
					/* translators: 1: destinatario, 2: segundos */
					esc_html__( 'Test message handed to the server for %1$s in %2$ss. Check the inbox — and the spam folder.', 'diluxone-mail' ),
					esc_html( (string) $diluxone_mail_test['to'] ),
					esc_html( (string) $diluxone_mail_test['seconds'] )
				);
				?>
			<?php else : ?>
				<strong><?php esc_html_e( 'The test message was not sent.', 'diluxone-mail' ); ?></strong>
				<?php echo esc_html( (string) $diluxone_mail_test['error'] ); ?>
			<?php endif; ?>
		</p>
		<?php if ( '' !== (string) $diluxone_mail_test['transcript'] ) : ?>
			<details class="diluxone-mail-transcript">
				<summary><?php esc_html_e( 'SMTP conversation', 'diluxone-mail' ); ?></summary>
				<pre><?php echo esc_html( (string) $diluxone_mail_test['transcript'] ); ?></pre>
			</details>
		<?php endif; ?>
	</div>
<?php endif; ?>

<?php if ( 'site' === $data['scope'] && ! $data['editable'] ) : ?>
	<div class="notice notice-info">
		<p><?php esc_html_e( 'These settings are fixed by the network. You can see them here; changing them is done from the network settings.', 'diluxone-mail' ); ?></p>
	</div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( $data['action_url'] ); ?>" class="diluxone-mail-form">
	<?php wp_nonce_field( 'diluxone_mail_settings' ); ?>
	<input type="hidden" name="action" value="diluxone_mail_apply_provider">
	<input type="hidden" name="scope" value="<?php echo esc_attr( $data['scope'] ); ?>">

	<h2><?php esc_html_e( 'Provider', 'diluxone-mail' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Choosing a profile fills in the host, port, encryption and — where the provider imposes one — the username. Every value here was checked against the provider\'s documentation. You then paste the credential and save.', 'diluxone-mail' ); ?></p>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="diluxone_mail_provider"><?php esc_html_e( 'Profile', 'diluxone-mail' ); ?></label></th>
			<td>
				<?php $diluxone_mail_bloqueado = $data['fields']['diluxone_mail_host']['readonly'] || in_array( $data['provider']['source'], array( 'constant', 'env' ), true ); ?>
				<select name="diluxone_mail_provider" id="diluxone_mail_provider" <?php disabled( $diluxone_mail_bloqueado ); ?>>
					<option value=""><?php esc_html_e( '— choose —', 'diluxone-mail' ); ?></option>
					<?php
					$diluxone_mail_grupo = '';
					foreach ( $data['providers'] as $diluxone_mail_key => $diluxone_mail_p ) :
						if ( $diluxone_mail_p['group'] !== $diluxone_mail_grupo ) :
							if ( '' !== $diluxone_mail_grupo ) :
								echo '</optgroup>';
							endif;
							$diluxone_mail_grupo = (string) $diluxone_mail_p['group'];
							printf( '<optgroup label="%s">', esc_attr( $diluxone_mail_grupo ) );
						endif;
						?>
						<option value="<?php echo esc_attr( $diluxone_mail_key ); ?>" <?php selected( $data['provider']['value'], $diluxone_mail_key ); ?>><?php echo esc_html( (string) $diluxone_mail_p['name'] ); ?></option>
					<?php endforeach; ?>
					</optgroup>
				</select>
				<?php if ( ! $diluxone_mail_bloqueado ) : ?>
					<?php submit_button( __( 'Use this profile', 'diluxone-mail' ), 'secondary', 'apply', false ); ?>
				<?php endif; ?>
				<?php $diluxone_mail_source( array_merge( $data['provider'], array( 'label' => diluxone_mail_source_label( $data['provider']['source'], $data['provider']['origin'] ) ) ) ); ?>
				<?php if ( '' !== (string) $data['profile']['docs'] ) : ?>
					<p class="description"><a href="<?php echo esc_url( (string) $data['profile']['docs'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Provider documentation', 'diluxone-mail' ); ?></a></p>
				<?php endif; ?>
			</td>
		</tr>
	</table>
</form>

<form method="post" action="<?php echo esc_url( $data['action_url'] ); ?>" class="diluxone-mail-form">
	<?php wp_nonce_field( 'diluxone_mail_settings' ); ?>
	<input type="hidden" name="action" value="diluxone_mail_save_settings">
	<input type="hidden" name="scope" value="<?php echo esc_attr( $data['scope'] ); ?>">

	<h2><?php esc_html_e( 'SMTP server', 'diluxone-mail' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="diluxone_mail_host"><?php esc_html_e( 'Host', 'diluxone-mail' ); ?></label></th>
			<td>
				<input type="text" class="regular-text code" id="diluxone_mail_host" name="diluxone_mail_host" value="<?php echo esc_attr( (string) $diluxone_mail_f['diluxone_mail_host']['value'] ); ?>" <?php wp_readonly( $diluxone_mail_f['diluxone_mail_host']['readonly'] ); ?>>
				<?php $diluxone_mail_source( $diluxone_mail_f['diluxone_mail_host'] ); ?>
				<?php if ( 'ses' === $data['provider']['value'] ) : ?>
					<p class="description"><?php esc_html_e( 'Replace the region in the host with the one your SES identity lives in.', 'diluxone-mail' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="diluxone_mail_port"><?php esc_html_e( 'Port', 'diluxone-mail' ); ?></label></th>
			<td>
				<input type="number" min="1" max="65535" class="small-text" id="diluxone_mail_port" name="diluxone_mail_port" value="<?php echo esc_attr( (string) $diluxone_mail_f['diluxone_mail_port']['value'] ); ?>" <?php wp_readonly( $diluxone_mail_f['diluxone_mail_port']['readonly'] ); ?>>
				<?php $diluxone_mail_source( $diluxone_mail_f['diluxone_mail_port'] ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="diluxone_mail_encryption"><?php esc_html_e( 'Encryption', 'diluxone-mail' ); ?></label></th>
			<td>
				<select id="diluxone_mail_encryption" name="diluxone_mail_encryption" <?php disabled( $diluxone_mail_f['diluxone_mail_encryption']['readonly'] ); ?>>
					<option value="tls" <?php selected( $diluxone_mail_f['diluxone_mail_encryption']['value'], 'tls' ); ?>><?php esc_html_e( 'STARTTLS (usually port 587)', 'diluxone-mail' ); ?></option>
					<option value="ssl" <?php selected( $diluxone_mail_f['diluxone_mail_encryption']['value'], 'ssl' ); ?>><?php esc_html_e( 'SSL/TLS from the start (usually port 465)', 'diluxone-mail' ); ?></option>
					<option value="none" <?php selected( $diluxone_mail_f['diluxone_mail_encryption']['value'], 'none' ); ?>><?php esc_html_e( 'None (local servers only)', 'diluxone-mail' ); ?></option>
				</select>
				<?php $diluxone_mail_source( $diluxone_mail_f['diluxone_mail_encryption'] ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Authentication', 'diluxone-mail' ); ?></th>
			<td>
				<label><input type="checkbox" name="diluxone_mail_auth" value="1" <?php checked( (int) $diluxone_mail_f['diluxone_mail_auth']['value'], 1 ); ?> <?php disabled( $diluxone_mail_f['diluxone_mail_auth']['readonly'] || (bool) $data['profile']['local'] ); ?>> <?php esc_html_e( 'The server requires a username and password', 'diluxone-mail' ); ?></label>
				<?php if ( (bool) $data['profile']['local'] ) : ?>
					<p class="description"><?php esc_html_e( 'Local profiles never authenticate and never try to upgrade to TLS, whatever the server offers. That is what makes Mailpit and MailHog work out of the box.', 'diluxone-mail' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="diluxone_mail_user"><?php esc_html_e( 'Username', 'diluxone-mail' ); ?></label></th>
			<td>
				<input type="text" class="regular-text code" id="diluxone_mail_user" name="diluxone_mail_user" value="<?php echo esc_attr( (string) $diluxone_mail_f['diluxone_mail_user']['value'] ); ?>" autocomplete="off" <?php wp_readonly( $diluxone_mail_f['diluxone_mail_user']['readonly'] ); ?>>
				<?php $diluxone_mail_source( $diluxone_mail_f['diluxone_mail_user'] ); ?>
				<?php if ( '' !== (string) $data['profile']['user_hint'] ) : ?>
					<p class="description"><?php echo esc_html( (string) $data['profile']['user_hint'] ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="diluxone_mail_pass"><?php esc_html_e( 'Password', 'diluxone-mail' ); ?></label></th>
			<td>
				<?php if ( $diluxone_mail_f['diluxone_mail_pass']['readonly'] ) : ?>
					<input type="text" class="regular-text code" value="<?php echo esc_attr( $data['has_password'] ? '••••••••' : '' ); ?>" readonly>
					<?php $diluxone_mail_source( $diluxone_mail_f['diluxone_mail_pass'] ); ?>
				<?php else : ?>
					<input type="password" class="regular-text code" id="diluxone_mail_pass" name="diluxone_mail_pass" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $data['has_password'] ? __( 'stored — leave empty to keep it', 'diluxone-mail' ) : __( 'not set', 'diluxone-mail' ) ); ?>">
				<?php endif; ?>
				<?php if ( '' !== (string) $data['profile']['pass_hint'] ) : ?>
					<p class="description"><?php echo esc_html( (string) $data['profile']['pass_hint'] ); ?></p>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'The password never comes back to the browser. It is redacted from the log, the status screen and any SMTP transcript.', 'diluxone-mail' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="diluxone_mail_timeout"><?php esc_html_e( 'Timeout', 'diluxone-mail' ); ?></label></th>
			<td>
				<input type="number" min="5" max="120" class="small-text" id="diluxone_mail_timeout" name="diluxone_mail_timeout" value="<?php echo esc_attr( (string) $diluxone_mail_f['diluxone_mail_timeout']['value'] ); ?>" <?php wp_readonly( $diluxone_mail_f['diluxone_mail_timeout']['readonly'] ); ?>> <?php esc_html_e( 'seconds', 'diluxone-mail' ); ?>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Sender', 'diluxone-mail' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="diluxone_mail_from"><?php esc_html_e( 'From address', 'diluxone-mail' ); ?></label></th>
			<td>
				<input type="email" class="regular-text" id="diluxone_mail_from" name="diluxone_mail_from" value="<?php echo esc_attr( (string) $diluxone_mail_f['diluxone_mail_from']['value'] ); ?>" <?php wp_readonly( $diluxone_mail_f['diluxone_mail_from']['readonly'] ); ?>>
				<?php $diluxone_mail_source( $diluxone_mail_f['diluxone_mail_from'] ); ?>
				<p class="description"><?php esc_html_e( 'WordPress signs as wordpress@yourdomain by default, which usually does not exist and which most providers reject. Use an address on a domain the provider has verified.', 'diluxone-mail' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="diluxone_mail_from_name"><?php esc_html_e( 'From name', 'diluxone-mail' ); ?></label></th>
			<td>
				<input type="text" class="regular-text" id="diluxone_mail_from_name" name="diluxone_mail_from_name" value="<?php echo esc_attr( (string) $diluxone_mail_f['diluxone_mail_from_name']['value'] ); ?>" <?php wp_readonly( $diluxone_mail_f['diluxone_mail_from_name']['readonly'] ); ?>>
				<?php $diluxone_mail_source( $diluxone_mail_f['diluxone_mail_from_name'] ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Force sender', 'diluxone-mail' ); ?></th>
			<td>
				<label><input type="checkbox" name="diluxone_mail_force_from" value="1" <?php checked( (int) $diluxone_mail_f['diluxone_mail_force_from']['value'], 1 ); ?> <?php disabled( $diluxone_mail_f['diluxone_mail_force_from']['readonly'] ); ?>> <?php esc_html_e( 'Replace the From address other plugins set, not only the WordPress default', 'diluxone-mail' ); ?></label>
				<?php diluxone_mail_forced_notice( 'diluxone_mail_force_from' ); ?>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'How this plugin takes part in sending', 'diluxone-mail' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Mode', 'diluxone-mail' ); ?></th>
			<td>
				<fieldset>
					<?php
					$diluxone_mail_modos = array(
						'auto'      => __( 'Automatic — send only when no other mail plugin is active; otherwise log and diagnose without touching delivery', 'diluxone-mail' ),
						'transport' => __( 'Always send through this plugin, even if another mail plugin is active', 'diluxone-mail' ),
						'observe'   => __( 'Never send — only log and diagnose', 'diluxone-mail' ),
					);
					foreach ( $diluxone_mail_modos as $diluxone_mail_valor => $diluxone_mail_texto ) :
						?>
						<label><input type="radio" name="diluxone_mail_mode" value="<?php echo esc_attr( $diluxone_mail_valor ); ?>" <?php checked( $diluxone_mail_f['diluxone_mail_mode']['value'], $diluxone_mail_valor ); ?> <?php disabled( $diluxone_mail_f['diluxone_mail_mode']['readonly'] ); ?>> <?php echo esc_html( $diluxone_mail_texto ); ?></label><br>
					<?php endforeach; ?>
				</fieldset>
				<?php diluxone_mail_forced_notice( 'diluxone_mail_mode' ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'pre_wp_mail interceptors', 'diluxone-mail' ); ?></th>
			<td>
				<label><input type="checkbox" name="diluxone_mail_unhook_pre_wp_mail" value="1" <?php checked( (int) $diluxone_mail_f['diluxone_mail_unhook_pre_wp_mail']['value'], 1 ); ?> <?php disabled( $diluxone_mail_f['diluxone_mail_unhook_pre_wp_mail']['readonly'] ); ?>> <?php esc_html_e( 'Detach plugins that intercept mail before PHPMailer, on every send', 'diluxone-mail' ); ?></label>
				<p class="description"><?php esc_html_e( 'Only for the case where another plugin hooks pre_wp_mail and returns without sending — the message dies silently and no SMTP settings apply. If the intercepting plugin is the one actually delivering your mail, turning this on leaves the site with no mail at all. Each intercepting callback is removed individually; nothing else on that hook is touched.', 'diluxone-mail' ); ?></p>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Mail log', 'diluxone-mail' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Log', 'diluxone-mail' ); ?></th>
			<td>
				<label><input type="checkbox" name="diluxone_mail_log_enabled" value="1" <?php checked( (int) $diluxone_mail_f['diluxone_mail_log_enabled']['value'], 1 ); ?> <?php disabled( $diluxone_mail_f['diluxone_mail_log_enabled']['readonly'] ); ?>> <?php esc_html_e( 'Keep a log of every message: date, recipients, sender, subject, outcome, error, provider and which plugin sent it', 'diluxone-mail' ); ?></label>
				<p class="description"><?php esc_html_e( 'This is what shows up on each user\'s profile.', 'diluxone-mail' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="diluxone_mail_log_retention_days"><?php esc_html_e( 'Keep for', 'diluxone-mail' ); ?></label></th>
			<td>
				<input type="number" min="1" max="3650" class="small-text" id="diluxone_mail_log_retention_days" name="diluxone_mail_log_retention_days" value="<?php echo esc_attr( (string) $diluxone_mail_f['diluxone_mail_log_retention_days']['value'] ); ?>" <?php wp_readonly( $diluxone_mail_f['diluxone_mail_log_retention_days']['readonly'] ); ?>> <?php esc_html_e( 'days', 'diluxone-mail' ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Extended log', 'diluxone-mail' ); ?></th>
			<td>
				<label><input type="checkbox" name="diluxone_mail_log_extended" value="1" <?php checked( (int) $diluxone_mail_f['diluxone_mail_log_extended']['value'], 1 ); ?> <?php disabled( $diluxone_mail_f['diluxone_mail_log_extended']['readonly'] ); ?>> <?php esc_html_e( 'Also keep the headers, attachment names and the full SMTP conversation with the provider for each message', 'diluxone-mail' ); ?></label>
				<p class="description"><?php esc_html_e( 'Useful when arguing with the provider\'s support. Costs capturing the SMTP dialogue on every send.', 'diluxone-mail' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Message body', 'diluxone-mail' ); ?></th>
			<td>
				<label><input type="checkbox" name="diluxone_mail_log_body" value="1" <?php checked( (int) $diluxone_mail_f['diluxone_mail_log_body']['value'], 1 ); ?> <?php disabled( $diluxone_mail_f['diluxone_mail_log_body']['readonly'] ); ?>> <?php esc_html_e( 'Store the body of each message', 'diluxone-mail' ); ?></label>
				<p class="description"><strong><?php esc_html_e( 'Off by default on purpose.', 'diluxone-mail' ); ?></strong> <?php esc_html_e( 'The body is personal data — names, orders, sometimes a temporary password — and storing it is what turns a technical log into a legal liability. It is also the only way to resend a message, so the resend button only works for messages logged while this is on.', 'diluxone-mail' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="diluxone_mail_log_detail_retention_days"><?php esc_html_e( 'Keep bodies and transcripts for', 'diluxone-mail' ); ?></label></th>
			<td>
				<input type="number" min="1" max="365" class="small-text" id="diluxone_mail_log_detail_retention_days" name="diluxone_mail_log_detail_retention_days" value="<?php echo esc_attr( (string) $diluxone_mail_f['diluxone_mail_log_detail_retention_days']['value'] ); ?>" <?php wp_readonly( $diluxone_mail_f['diluxone_mail_log_detail_retention_days']['readonly'] ); ?>> <?php esc_html_e( 'days', 'diluxone-mail' ); ?>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'DNS diagnostics', 'diluxone-mail' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="diluxone_mail_dns_domain"><?php esc_html_e( 'Domain', 'diluxone-mail' ); ?></label></th>
			<td>
				<input type="text" class="regular-text code" id="diluxone_mail_dns_domain" name="diluxone_mail_dns_domain" value="<?php echo esc_attr( (string) $diluxone_mail_f['diluxone_mail_dns_domain']['value'] ); ?>" placeholder="<?php echo esc_attr( diluxone_mail_dns_domain() ); ?>" <?php wp_readonly( $diluxone_mail_f['diluxone_mail_dns_domain']['readonly'] ); ?>>
				<p class="description"><?php esc_html_e( 'Leave empty to use the From address\'s domain, or the site\'s domain if there is none.', 'diluxone-mail' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="diluxone_mail_dns_selectors"><?php esc_html_e( 'Extra DKIM selectors', 'diluxone-mail' ); ?></label></th>
			<td>
				<input type="text" class="regular-text code" id="diluxone_mail_dns_selectors" name="diluxone_mail_dns_selectors" value="<?php echo esc_attr( implode( ', ', array_map( 'strval', (array) $diluxone_mail_f['diluxone_mail_dns_selectors']['value'] ) ) ); ?>" <?php wp_readonly( $diluxone_mail_f['diluxone_mail_dns_selectors']['readonly'] ); ?>>
				<p class="description"><?php esc_html_e( 'Comma-separated. Selectors cannot be listed over DNS; the diagnosis probes the common ones plus your provider\'s plus these.', 'diluxone-mail' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="diluxone_mail_dns_resolver"><?php esc_html_e( 'Resolver', 'diluxone-mail' ); ?></label></th>
			<td>
				<select id="diluxone_mail_dns_resolver" name="diluxone_mail_dns_resolver" <?php disabled( $diluxone_mail_f['diluxone_mail_dns_resolver']['readonly'] ); ?>>
					<option value="auto" <?php selected( $diluxone_mail_f['diluxone_mail_dns_resolver']['value'], 'auto' ); ?>><?php esc_html_e( 'System, falling back to DNS-over-HTTPS', 'diluxone-mail' ); ?></option>
					<option value="system" <?php selected( $diluxone_mail_f['diluxone_mail_dns_resolver']['value'], 'system' ); ?>><?php esc_html_e( 'System only (dns_get_record)', 'diluxone-mail' ); ?></option>
					<option value="doh" <?php selected( $diluxone_mail_f['diluxone_mail_dns_resolver']['value'], 'doh' ); ?>><?php esc_html_e( 'DNS-over-HTTPS only', 'diluxone-mail' ); ?></option>
				</select>
				<input type="url" class="regular-text code" name="diluxone_mail_dns_doh_endpoint" value="<?php echo esc_attr( (string) $diluxone_mail_f['diluxone_mail_dns_doh_endpoint']['value'] ); ?>" <?php wp_readonly( $diluxone_mail_f['diluxone_mail_dns_doh_endpoint']['readonly'] ); ?>>
				<p class="description"><?php esc_html_e( 'Many hosts disable dns_get_record(). The DoH endpoint must answer the JSON format; Cloudflare and Google both do.', 'diluxone-mail' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="diluxone_mail_dns_cache_hours"><?php esc_html_e( 'Cache results for', 'diluxone-mail' ); ?></label></th>
			<td>
				<input type="number" min="1" max="168" class="small-text" id="diluxone_mail_dns_cache_hours" name="diluxone_mail_dns_cache_hours" value="<?php echo esc_attr( (string) $diluxone_mail_f['diluxone_mail_dns_cache_hours']['value'] ); ?>" <?php wp_readonly( $diluxone_mail_f['diluxone_mail_dns_cache_hours']['readonly'] ); ?>> <?php esc_html_e( 'hours', 'diluxone-mail' ); ?>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Privacy', 'diluxone-mail' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Personal data requests', 'diluxone-mail' ); ?></th>
			<td>
				<label><input type="checkbox" name="diluxone_mail_privacy_export" value="1" <?php checked( (int) $diluxone_mail_f['diluxone_mail_privacy_export']['value'], 1 ); ?> <?php disabled( $diluxone_mail_f['diluxone_mail_privacy_export']['readonly'] ); ?>> <?php esc_html_e( 'Include a person\'s mail history in their data export', 'diluxone-mail' ); ?></label><br>
				<label><input type="checkbox" name="diluxone_mail_privacy_erase" value="1" <?php checked( (int) $diluxone_mail_f['diluxone_mail_privacy_erase']['value'], 1 ); ?> <?php disabled( $diluxone_mail_f['diluxone_mail_privacy_erase']['readonly'] ); ?>> <?php esc_html_e( 'Delete a person\'s mail history when they ask to be erased', 'diluxone-mail' ); ?></label>
			</td>
		</tr>
	</table>

	<?php if ( 'network' === $data['scope'] ) : ?>
		<h2><?php esc_html_e( 'Sites', 'diluxone-mail' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Per-site overrides', 'diluxone-mail' ); ?></th>
				<td>
					<label><input type="checkbox" name="diluxone_mail_network_allow_override" value="1" <?php checked( $data['allow_override'] ); ?>> <?php esc_html_e( 'Let each site override these settings with its own', 'diluxone-mail' ); ?></label>
					<p class="description"><?php esc_html_e( 'Off, every site uses exactly this and sees it read-only. On, a site administrator can set a different provider or sender for their site; anything they do not set still comes from here.', 'diluxone-mail' ); ?></p>
				</td>
			</tr>
		</table>
	<?php endif; ?>

	<?php if ( $data['editable'] ) : ?>
		<?php submit_button( __( 'Save settings', 'diluxone-mail' ) ); ?>
	<?php endif; ?>
</form>

<form method="post" action="<?php echo esc_url( $data['action_url'] ); ?>" class="diluxone-mail-form diluxone-mail-test">
	<?php wp_nonce_field( 'diluxone_mail_test' ); ?>
	<input type="hidden" name="action" value="diluxone_mail_test">
	<input type="hidden" name="scope" value="<?php echo esc_attr( $data['scope'] ); ?>">
	<h2><?php esc_html_e( 'Send a test message', 'diluxone-mail' ); ?></h2>
	<p>
		<label for="diluxone_mail_test_to" class="screen-reader-text"><?php esc_html_e( 'Send to', 'diluxone-mail' ); ?></label>
		<input type="email" class="regular-text" id="diluxone_mail_test_to" name="diluxone_mail_test_to" value="<?php echo esc_attr( (string) wp_get_current_user()->user_email ); ?>">
		<?php submit_button( __( 'Send test', 'diluxone-mail' ), 'secondary', 'send', false ); ?>
	</p>
	<p class="description"><?php esc_html_e( 'If it fails you get the full SMTP error and the whole conversation with the server, with the password redacted.', 'diluxone-mail' ); ?></p>
</form>
