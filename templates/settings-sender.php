<?php
/**
 * Step 3: who the mail says it comes from.
 *
 * @package DiluxOneMail
 * @var array<string, mixed> $data
 */

defined( 'ABSPATH' ) || exit;

$diluxone_mail_f = $data['fields'];
?>

<form method="post" action="<?php echo esc_url( (string) $data['action_url'] ); ?>" class="diluxone-mail-form">
	<?php wp_nonce_field( 'diluxone_mail_settings' ); ?>
	<input type="hidden" name="action" value="diluxone_mail_save_settings">
	<input type="hidden" name="scope" value="<?php echo esc_attr( (string) $data['scope'] ); ?>">
	<input type="hidden" name="tab" value="sender">

	<h2><?php esc_html_e( 'Sender', 'diluxone-mail' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="diluxone_mail_from"><?php esc_html_e( 'From address', 'diluxone-mail' ); ?></label></th>
			<td>
				<input type="email" class="regular-text" id="diluxone_mail_from" name="diluxone_mail_from" value="<?php echo esc_attr( (string) $diluxone_mail_f['diluxone_mail_from']['value'] ); ?>" <?php wp_readonly( $diluxone_mail_f['diluxone_mail_from']['readonly'] ); ?>>
				<?php diluxone_mail_source_caption( $diluxone_mail_f['diluxone_mail_from'] ); ?>
				<p class="description"><?php esc_html_e( 'WordPress signs as wordpress@yourdomain by default, which usually does not exist and which most providers reject. Use an address on a domain the provider has verified.', 'diluxone-mail' ); ?></p>
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
