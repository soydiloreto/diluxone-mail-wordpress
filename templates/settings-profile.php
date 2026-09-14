<?php
/**
 * Step 1: which provider this site sends through.
 *
 * The profile is applied as a step of its own rather than on save: the form
 * then shows what the provider imposes — host, port, encryption, sometimes
 * the username — editable and in front of you, before anybody pastes a
 * credential. It also means the dropdown fills the next tab in with no
 * JavaScript at all.
 *
 * @package DiluxOneMail
 * @var array<string, mixed> $data
 */

defined( 'ABSPATH' ) || exit;

$diluxone_mail_locked = $data['fields']['diluxone_mail_host']['readonly'] || in_array( $data['provider']['source'], array( 'constant', 'env' ), true );
?>

<p class="description diluxone-mail-intro">
	<?php esc_html_e( 'Four steps: pick the provider, prove the server accepts the credentials, say who the mail comes from, and send a message to check it arrives. Each one opens the next.', 'diluxone-mail' ); ?>
</p>

<form method="post" action="<?php echo esc_url( (string) $data['action_url'] ); ?>" class="diluxone-mail-form">
	<?php wp_nonce_field( 'diluxone_mail_settings' ); ?>
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
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Provider', 'diluxone-mail' ); ?></h2>
	<p class="description diluxone-mail-api-only" hidden><?php esc_html_e( 'Only the providers with an API here are listed. The rest send over SMTP, which is the other option above.', 'diluxone-mail' ); ?></p>
	<p class="description"><?php esc_html_e( 'Choosing a profile fills in the host, port, encryption and — where the provider imposes one — the username. Every value here was checked against the provider\'s documentation. You then paste the credential on the next tab.', 'diluxone-mail' ); ?></p>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="diluxone_mail_provider"><?php esc_html_e( 'Profile', 'diluxone-mail' ); ?></label></th>
			<td>
				<select name="diluxone_mail_provider" id="diluxone_mail_provider" <?php disabled( $diluxone_mail_locked ); ?>>
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
				<?php if ( ! $diluxone_mail_locked ) : ?>
					<?php submit_button( __( 'Use this profile', 'diluxone-mail' ), 'primary', 'apply', false ); ?>
				<?php endif; ?>
				<?php diluxone_mail_source_caption( array_merge( $data['provider'], array( 'label' => diluxone_mail_source_label( $data['provider']['source'], $data['provider']['origin'] ) ) ) ); ?>
				<?php if ( '' !== (string) $data['profile']['docs'] ) : ?>
					<p class="description"><a href="<?php echo esc_url( (string) $data['profile']['docs'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Provider documentation', 'diluxone-mail' ); ?></a></p>
				<?php endif; ?>
			</td>
		</tr>
	</table>
</form>
