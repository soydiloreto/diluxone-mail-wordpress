<?php
/**
 * What each site of the network may decide for itself.
 *
 * @package DiluxOneMail
 * @var array<string, mixed> $data
 */

defined( 'ABSPATH' ) || exit;
?>

<form method="post" action="<?php echo esc_url( (string) $data['action_url'] ); ?>" class="diluxone-mail-form">
	<?php wp_nonce_field( 'diluxone_mail_settings' ); ?>
	<input type="hidden" name="action" value="diluxone_mail_save_settings">
	<input type="hidden" name="scope" value="<?php echo esc_attr( (string) $data['scope'] ); ?>">
	<input type="hidden" name="tab" value="sites">

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

	<?php submit_button( __( 'Save', 'diluxone-mail' ) ); ?>
</form>
