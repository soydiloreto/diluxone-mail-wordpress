<?php
/**
 * Step 2: the server, and the proof that it answers.
 *
 * This tab has one button and it is not "Save". The values are tried against
 * the server first and stored only if it answered, so a stored transport is
 * always one that worked at least once. A failed attempt writes nothing and
 * comes back with what was typed — except the password, which is typed again
 * rather than kept in a second place.
 *
 * @package DiluxOneMail
 * @var array<string, mixed> $data
 */

defined( 'ABSPATH' ) || exit;

// The API path is a different second step: a key, not a server. Same place in
// the sequence, same rule about not storing what was not checked.
if ( 'api' === $data['transport_kind'] ) {
	diluxone_mail_view( 'settings-api', $data );

	return;
}

$diluxone_mail_f = $data['fields'];
$diluxone_mail_c = $data['connection'];
?>

<?php if ( is_array( $diluxone_mail_c ) ) : ?>
	<div class="notice notice-<?php echo $diluxone_mail_c['ok'] ? 'success' : 'error'; ?> inline">
		<p>
			<?php if ( $diluxone_mail_c['ok'] ) : ?>
				<?php
				printf(
					/* translators: %s: seconds */
					esc_html__( 'The server answered in %ss and accepted the credentials.', 'diluxone-mail' ),
					esc_html( (string) $diluxone_mail_c['seconds'] )
				);
				?>
			<?php else : ?>
				<strong><?php esc_html_e( 'The server did not accept the connection, so nothing was saved.', 'diluxone-mail' ); ?></strong>
				<?php echo esc_html( (string) $diluxone_mail_c['error'] ); ?>
			<?php endif; ?>
		</p>
		<?php if ( '' !== (string) $diluxone_mail_c['transcript'] ) : ?>
			<details class="diluxone-mail-transcript">
				<summary><?php esc_html_e( 'SMTP conversation', 'diluxone-mail' ); ?></summary>
				<pre><?php echo esc_html( (string) $diluxone_mail_c['transcript'] ); ?></pre>
			</details>
		<?php endif; ?>
	</div>
<?php endif; ?>

<?php if ( $data['pass_unreadable'] ) : ?>
	<div class="notice notice-warning inline">
		<p>
			<strong><?php esc_html_e( 'The stored password can no longer be decrypted.', 'diluxone-mail' ); ?></strong>
			<?php esc_html_e( 'It is encrypted with a key derived from this site\'s WordPress salts, and those have changed since it was saved — a new wp-config.php, a restored copy, a rotation. Nothing is broken and nothing leaked: type the password again and test the connection.', 'diluxone-mail' ); ?>
		</p>
	</div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( (string) $data['action_url'] ); ?>" class="diluxone-mail-form">
	<?php wp_nonce_field( 'diluxone_mail_settings' ); ?>
	<input type="hidden" name="action" value="diluxone_mail_connection">
	<input type="hidden" name="scope" value="<?php echo esc_attr( (string) $data['scope'] ); ?>">
	<input type="hidden" name="tab" value="server">

	<h2><?php esc_html_e( 'SMTP server', 'diluxone-mail' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'These values are saved by testing them. The button opens a session against the server, upgrades to TLS, authenticates and hangs up — no message is sent and no mailbox is needed. If it fails, nothing is stored and you get the server\'s own words for why.', 'diluxone-mail' ); ?>
	</p>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="diluxone_mail_host"><?php esc_html_e( 'Host', 'diluxone-mail' ); ?></label></th>
			<td>
				<input type="text" class="regular-text code" id="diluxone_mail_host" name="diluxone_mail_host" value="<?php echo esc_attr( (string) $diluxone_mail_f['diluxone_mail_host']['value'] ); ?>" <?php wp_readonly( $diluxone_mail_f['diluxone_mail_host']['readonly'] ); ?>>
				<?php diluxone_mail_source_caption( $diluxone_mail_f['diluxone_mail_host'] ); ?>
				<?php if ( 'ses' === $data['provider']['value'] ) : ?>
					<p class="description"><?php esc_html_e( 'Replace the region in the host with the one your SES identity lives in.', 'diluxone-mail' ); ?></p>
				<?php endif; ?>
				<?php if ( (bool) $data['profile']['local'] ) : ?>
					<p class="description"><?php esc_html_e( 'The profile assumes the mailbox runs next to PHP. Under Docker, DDEV, Lando or wp-env it does not: the host is the name of that service on the compose network — usually "mailpit" or "mailhog" — and "localhost" there means this container, where nothing is listening.', 'diluxone-mail' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="diluxone_mail_port"><?php esc_html_e( 'Port', 'diluxone-mail' ); ?></label></th>
			<td>
				<input type="number" min="1" max="65535" class="small-text" id="diluxone_mail_port" name="diluxone_mail_port" value="<?php echo esc_attr( (string) $diluxone_mail_f['diluxone_mail_port']['value'] ); ?>" <?php wp_readonly( $diluxone_mail_f['diluxone_mail_port']['readonly'] ); ?>>
				<?php diluxone_mail_source_caption( $diluxone_mail_f['diluxone_mail_port'] ); ?>
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
				<?php diluxone_mail_source_caption( $diluxone_mail_f['diluxone_mail_encryption'] ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Authentication', 'diluxone-mail' ); ?></th>
			<td>
				<label><input type="checkbox" id="diluxone_mail_auth" name="diluxone_mail_auth" value="1" <?php checked( (int) $diluxone_mail_f['diluxone_mail_auth']['value'], 1 ); ?> <?php disabled( $diluxone_mail_f['diluxone_mail_auth']['readonly'] || (bool) $data['profile']['local'] ); ?>> <?php esc_html_e( 'The server requires a username and password', 'diluxone-mail' ); ?></label>
				<?php if ( (bool) $data['profile']['local'] ) : ?>
					<p class="description"><?php esc_html_e( 'Local profiles never authenticate and never try to upgrade to TLS, whatever the server offers. That is what makes Mailpit and MailHog work out of the box.', 'diluxone-mail' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="diluxone_mail_user"><?php esc_html_e( 'Username', 'diluxone-mail' ); ?></label></th>
			<td>
				<input type="text" class="regular-text code" id="diluxone_mail_user" name="diluxone_mail_user" value="<?php echo esc_attr( (string) $diluxone_mail_f['diluxone_mail_user']['value'] ); ?>" autocomplete="off" <?php wp_readonly( $diluxone_mail_f['diluxone_mail_user']['readonly'] ); ?>>
				<?php diluxone_mail_source_caption( $diluxone_mail_f['diluxone_mail_user'] ); ?>
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
					<?php diluxone_mail_source_caption( $diluxone_mail_f['diluxone_mail_pass'] ); ?>
				<?php else : ?>
					<input type="password" class="regular-text code" id="diluxone_mail_pass" name="diluxone_mail_pass" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $data['has_password'] ? __( 'stored — leave empty to keep it', 'diluxone-mail' ) : __( 'not set', 'diluxone-mail' ) ); ?>">
				<?php endif; ?>
				<?php if ( '' !== (string) $data['profile']['pass_hint'] ) : ?>
					<p class="description"><?php echo esc_html( (string) $data['profile']['pass_hint'] ); ?></p>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'The password never comes back to the browser. It is stored encrypted with a key derived from this site\'s WordPress salts, and it is redacted from the log, the status screen and any SMTP transcript.', 'diluxone-mail' ); ?></p>
				<p class="description">
					<?php esc_html_e( 'Better still, keep it out of the database altogether: define it in wp-config.php and the plugin reads it from there and never stores it.', 'diluxone-mail' ); ?>
					<code>define( 'DILUXONE_MAIL_PASS', '…' );</code>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="diluxone_mail_timeout"><?php esc_html_e( 'Timeout', 'diluxone-mail' ); ?></label></th>
			<td>
				<input type="number" min="5" max="120" class="small-text" id="diluxone_mail_timeout" name="diluxone_mail_timeout" value="<?php echo esc_attr( (string) $diluxone_mail_f['diluxone_mail_timeout']['value'] ); ?>" <?php wp_readonly( $diluxone_mail_f['diluxone_mail_timeout']['readonly'] ); ?>> <?php esc_html_e( 'seconds', 'diluxone-mail' ); ?>
			</td>
		</tr>
	</table>

	<?php if ( $data['editable'] ) : ?>
		<?php submit_button( __( 'Test connection and save', 'diluxone-mail' ) ); ?>
	<?php endif; ?>
</form>
