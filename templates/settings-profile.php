<?php
/**
 * Step 1: what this provider is.
 *
 * Two shapes, and which one you get is decided by whether the record exists
 * yet. A new one asks the two questions that cannot be changed afterwards —
 * how this site sends, and through whom — because the credentials of the next
 * step are issued by one provider for one method. A stored one shows those
 * two as what they are, settled, and lets you rename it.
 *
 * Changing either on a saved provider would leave a record whose ticks refer
 * to something that is no longer there. Removing it and adding another is
 * cheaper and says what actually happened.
 *
 * @package DiluxOneMail
 * @var array<string, mixed> $data
 */

defined( 'ABSPATH' ) || exit;

$diluxone_mail_locked = (bool) $data['locked'];
$diluxone_mail_fixed  = $data['fields']['diluxone_mail_host']['readonly'] || in_array( $data['provider']['source'], array( 'constant', 'env' ), true );
?>

<?php if ( $diluxone_mail_locked ) : ?>

	<h2><?php esc_html_e( 'Provider', 'diluxone-mail' ); ?></h2>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Provider', 'diluxone-mail' ); ?></th>
			<td>
				<strong><?php echo esc_html( (string) $data['profile']['name'] ); ?></strong>
				<?php if ( '' !== (string) $data['profile']['docs'] ) : ?>
					— <a href="<?php echo esc_url( (string) $data['profile']['docs'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Provider documentation', 'diluxone-mail' ); ?></a>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Method', 'diluxone-mail' ); ?></th>
			<td>
				<strong><?php echo esc_html( 'api' === $data['transport_kind'] ? __( 'The provider\'s API', 'diluxone-mail' ) : __( 'SMTP', 'diluxone-mail' ) ); ?></strong>
				<p class="description">
					<?php esc_html_e( 'Neither of these changes on a provider that is already set up: the credentials on the next step were issued by this provider, for this method. To send another way, add a provider and remove this one — everything it holds goes with it, which is what you want to happen to a credential you have stopped using.', 'diluxone-mail' ); ?>
				</p>
			</td>
		</tr>
	</table>

	<form method="post" action="<?php echo esc_url( (string) $data['action_url'] ); ?>" class="diluxone-mail-form">
		<?php wp_nonce_field( 'diluxone_mail_connections' ); ?>
		<input type="hidden" name="action" value="diluxone_mail_rename">
		<input type="hidden" name="from_panel" value="1">
		<?php diluxone_mail_connection_field(); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="diluxone_mail_label"><?php esc_html_e( 'Name', 'diluxone-mail' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="diluxone_mail_label" name="label" value="<?php echo esc_attr( (string) $data['label'] ); ?>">
					<?php submit_button( __( 'Save name', 'diluxone-mail' ), 'secondary', 'rename', false ); ?>
					<p class="description"><?php esc_html_e( 'Optional, and only for you: it is what the list calls this one. Two accounts with the same provider look identical without it.', 'diluxone-mail' ); ?></p>
				</td>
			</tr>
		</table>
	</form>

<?php else : ?>

	<p class="description diluxone-mail-intro">
		<?php esc_html_e( 'Four steps: pick how this site sends and through whom, prove the provider accepts the credentials, say who the mail comes from, and send a message to check it arrives. Each one opens the next.', 'diluxone-mail' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( (string) $data['action_url'] ); ?>" class="diluxone-mail-form">
		<?php wp_nonce_field( 'diluxone_mail_settings' ); ?>
		<?php diluxone_mail_connection_field(); ?>
		<input type="hidden" name="action" value="diluxone_mail_apply_provider">
		<input type="hidden" name="scope" value="<?php echo esc_attr( (string) $data['scope'] ); ?>">
		<input type="hidden" name="tab" value="profile">

		<h2><?php esc_html_e( 'How this site sends', 'diluxone-mail' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Method', 'diluxone-mail' ); ?></th>
				<td>
					<fieldset>
						<label>
							<input type="radio" name="diluxone_mail_transport" id="diluxone_mail_transport_smtp" value="smtp" <?php checked( $data['transport_kind'], 'smtp' ); ?>>
							<strong><?php esc_html_e( 'SMTP', 'diluxone-mail' ); ?></strong> —
							<?php esc_html_e( 'the provider\'s mail server. Works with every provider here, and with any other one: choosing a profile only fills the server in for you.', 'diluxone-mail' ); ?>
						</label><br>
						<label>
							<input type="radio" name="diluxone_mail_transport" id="diluxone_mail_transport_api" value="api" <?php checked( $data['transport_kind'], 'api' ); ?>>
							<strong><?php esc_html_e( 'The provider\'s API', 'diluxone-mail' ); ?></strong> —
							<?php esc_html_e( 'over HTTPS, with an API key instead of a server. Only some providers, and it is the way out when the host blocks the SMTP ports — which many do. When a send is refused you get the reason in words instead of a numbered error.', 'diluxone-mail' ); ?>
						</label>
					</fieldset>
					<p class="description"><?php esc_html_e( 'Both are settled once this provider is saved: its credentials belong to whichever you pick here.', 'diluxone-mail' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Provider', 'diluxone-mail' ); ?></h2>

		<p class="description diluxone-mail-when-smtp" <?php echo 'smtp' === $data['transport_kind'] ? '' : 'hidden'; ?>>
			<?php esc_html_e( 'This only saves you typing: choosing a profile fills in the host, the port, the encryption and — where the provider imposes one — the username, all checked against the provider\'s documentation. Nothing else about the send changes, and any server not on the list works through "Other SMTP server".', 'diluxone-mail' ); ?>
		</p>

		<p class="description diluxone-mail-when-api" <?php echo 'api' === $data['transport_kind'] ? '' : 'hidden'; ?>>
			<?php esc_html_e( 'Here the provider is the transport, not a shortcut: the message is handed to this one over HTTPS. Only the providers with an API are listed — the rest send over SMTP, which is the other option above.', 'diluxone-mail' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="diluxone_mail_provider"><?php esc_html_e( 'Provider', 'diluxone-mail' ); ?></label></th>
				<td>
					<select name="diluxone_mail_provider" id="diluxone_mail_provider" <?php disabled( $diluxone_mail_fixed ); ?>>
						<option value=""><?php esc_html_e( '— choose —', 'diluxone-mail' ); ?></option>
						<?php
						$diluxone_mail_group = '';
						foreach ( $data['providers'] as $diluxone_mail_key => $diluxone_mail_p ) :
							if ( $diluxone_mail_p['group'] !== $diluxone_mail_group ) :
								if ( '' !== $diluxone_mail_group ) :
									echo '</optgroup>';
								endif;
								$diluxone_mail_group = (string) $diluxone_mail_p['group'];
								printf( '<optgroup label="%s">', esc_attr( $diluxone_mail_group ) );
							endif;
							?>
							<option value="<?php echo esc_attr( $diluxone_mail_key ); ?>" data-api="<?php echo in_array( $diluxone_mail_key, $data['api_providers'], true ) ? '1' : '0'; ?>" <?php selected( $data['provider']['value'], $diluxone_mail_key ); ?>><?php echo esc_html( (string) $diluxone_mail_p['name'] ); ?></option>
						<?php endforeach; ?>
						</optgroup>
					</select>
					<?php diluxone_mail_source_caption( array_merge( $data['provider'], array( 'label' => diluxone_mail_source_label( $data['provider']['source'], $data['provider']['origin'] ) ) ) ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="diluxone_mail_label"><?php esc_html_e( 'Name', 'diluxone-mail' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="diluxone_mail_label" name="label" value="<?php echo esc_attr( (string) $data['label'] ); ?>">
					<p class="description"><?php esc_html_e( 'Optional, and only for you: it is what the list calls this one. Two accounts with the same provider look identical without it.', 'diluxone-mail' ); ?></p>
				</td>
			</tr>
		</table>

		<?php if ( ! $diluxone_mail_fixed ) : ?>
			<?php submit_button( __( 'Save and continue', 'diluxone-mail' ), 'primary', 'apply', false ); ?>
		<?php endif; ?>
	</form>

<?php endif; ?>
