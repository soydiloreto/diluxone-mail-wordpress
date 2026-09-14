<?php
/**
 * Step 3: who the mail says it comes from.
 *
 * @package DiluxOneMail
 * @var array<string, mixed> $data
 */

defined( 'ABSPATH' ) || exit;

$diluxone_mail_f = $data['fields'];

// A list to choose from, or the plain field. Only a list with something usable
// in it counts: offering a dropdown where every option is disabled is worse
// than a text box.
$diluxone_mail_domains = (bool) $data['sender_domains']['ok'] && array() !== $data['sender_domains']['domains'];
?>

<form method="post" action="<?php echo esc_url( (string) $data['action_url'] ); ?>" class="diluxone-mail-form">
	<?php wp_nonce_field( 'diluxone_mail_settings' ); ?>
	<input type="hidden" name="action" value="diluxone_mail_save_settings">
	<input type="hidden" name="scope" value="<?php echo esc_attr( (string) $data['scope'] ); ?>">
	<input type="hidden" name="tab" value="sender">

	<h2><?php esc_html_e( 'Sender', 'diluxone-mail' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="<?php echo $diluxone_mail_domains ? 'diluxone_mail_from_local' : 'diluxone_mail_from'; ?>"><?php esc_html_e( 'From address', 'diluxone-mail' ); ?></label></th>
			<td>
				<?php if ( $diluxone_mail_domains && ! $diluxone_mail_f['diluxone_mail_from']['readonly'] ) : ?>
					<?php
					$diluxone_mail_at    = strrpos( (string) $diluxone_mail_f['diluxone_mail_from']['value'], '@' );
					$diluxone_mail_local = false === $diluxone_mail_at ? (string) $diluxone_mail_f['diluxone_mail_from']['value'] : substr( (string) $diluxone_mail_f['diluxone_mail_from']['value'], 0, $diluxone_mail_at );
					$diluxone_mail_dom   = false === $diluxone_mail_at ? '' : substr( (string) $diluxone_mail_f['diluxone_mail_from']['value'], $diluxone_mail_at + 1 );
					?>
					<input type="text" class="regular-text" id="diluxone_mail_from_local" name="diluxone_mail_from_local" value="<?php echo esc_attr( $diluxone_mail_local ); ?>" style="width:12em">
					<span aria-hidden="true">@</span>
					<label for="diluxone_mail_from_domain" class="screen-reader-text"><?php esc_html_e( 'Domain', 'diluxone-mail' ); ?></label>
					<select id="diluxone_mail_from_domain" name="diluxone_mail_from_domain">
						<?php foreach ( $data['sender_domains']['domains'] as $diluxone_mail_d ) : ?>
							<option value="<?php echo esc_attr( (string) $diluxone_mail_d['name'] ); ?>" <?php selected( $diluxone_mail_dom, (string) $diluxone_mail_d['name'] ); ?> <?php disabled( ! $diluxone_mail_d['usable'] ); ?>>
								<?php
								echo esc_html(
									'' === (string) $diluxone_mail_d['note']
										? (string) $diluxone_mail_d['name']
										/* translators: 1: domain name, 2: why it cannot be used */
										: sprintf( __( '%1$s — %2$s', 'diluxone-mail' ), (string) $diluxone_mail_d['name'], (string) $diluxone_mail_d['note'] )
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>
					<a class="button button-small" href="<?php echo esc_url( (string) $data['refresh_domains_url'] ); ?>"><?php esc_html_e( 'Refresh', 'diluxone-mail' ); ?></a>
					<p class="description">
						<?php esc_html_e( 'These are the domains the provider has, and only the ones it will accept mail from can be chosen. A domain missing from the list has not been added there yet.', 'diluxone-mail' ); ?>
					</p>
				<?php else : ?>
					<input type="email" class="regular-text" id="diluxone_mail_from" name="diluxone_mail_from" value="<?php echo esc_attr( (string) $diluxone_mail_f['diluxone_mail_from']['value'] ); ?>" <?php wp_readonly( $diluxone_mail_f['diluxone_mail_from']['readonly'] ); ?>>
					<?php diluxone_mail_source_caption( $diluxone_mail_f['diluxone_mail_from'] ); ?>
					<p class="description"><?php esc_html_e( 'WordPress signs as wordpress@yourdomain by default, which usually does not exist and which most providers reject. Use an address on a domain the provider has verified.', 'diluxone-mail' ); ?></p>
					<?php if ( '' !== (string) $data['sender_domains']['error'] ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: what the provider answered */
								esc_html__( 'The provider could not be asked which domains it has, so this is typed by hand: %s', 'diluxone-mail' ),
								esc_html( (string) $data['sender_domains']['error'] )
							);
							?>
						</p>
					<?php endif; ?>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="diluxone_mail_from_name"><?php esc_html_e( 'From name', 'diluxone-mail' ); ?></label></th>
			<td>
				<input type="text" class="regular-text" id="diluxone_mail_from_name" name="diluxone_mail_from_name" value="<?php echo esc_attr( (string) $diluxone_mail_f['diluxone_mail_from_name']['value'] ); ?>" <?php wp_readonly( $diluxone_mail_f['diluxone_mail_from_name']['readonly'] ); ?>>
				<?php diluxone_mail_source_caption( $diluxone_mail_f['diluxone_mail_from_name'] ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Force sender', 'diluxone-mail' ); ?></th>
			<td>
				<label><input type="checkbox" name="diluxone_mail_force_from" value="1" <?php checked( (int) $diluxone_mail_f['diluxone_mail_force_from']['value'], 1 ); ?> <?php disabled( $diluxone_mail_f['diluxone_mail_force_from']['readonly'] ); ?>> <?php esc_html_e( 'Replace the From address other plugins set, not only the WordPress default', 'diluxone-mail' ); ?></label>
				<p class="description"><?php esc_html_e( 'Off, the address above is used only where WordPress would have signed as wordpress@yourdomain. On, it also replaces one another plugin — or a snippet in the theme — decided for the message.', 'diluxone-mail' ); ?></p>
				<?php diluxone_mail_forced_notice( 'diluxone_mail_force_from' ); ?>
			</td>
		</tr>
	</table>

	<?php if ( $data['editable'] ) : ?>
		<?php submit_button( __( 'Save sender', 'diluxone-mail' ) ); ?>
	<?php endif; ?>
</form>
