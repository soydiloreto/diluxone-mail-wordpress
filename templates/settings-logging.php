<?php
/**
 * What the log keeps, and for how long.
 *
 * Privacy sits on the same tab because it is the same decision seen from the
 * other side: what is stored about a person, and what happens to it when they
 * ask.
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
	<input type="hidden" name="tab" value="logging">

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
			<th scope="row"><label for="diluxone_mail_log_detail_retention_days"><?php esc_html_e( 'Keep transcripts for', 'diluxone-mail' ); ?></label></th>
			<td>
				<input type="number" min="1" max="365" class="small-text" id="diluxone_mail_log_detail_retention_days" name="diluxone_mail_log_detail_retention_days" value="<?php echo esc_attr( (string) $diluxone_mail_f['diluxone_mail_log_detail_retention_days']['value'] ); ?>" <?php wp_readonly( $diluxone_mail_f['diluxone_mail_log_detail_retention_days']['readonly'] ); ?>> <?php esc_html_e( 'days', 'diluxone-mail' ); ?>
			</td>
		</tr>
	</table>

	<p class="description diluxone-mail-privacy-note">
		<strong><?php esc_html_e( 'The content of the messages is never stored.', 'diluxone-mail' ); ?></strong>
		<?php esc_html_e( 'There is no setting for it. A log that kept bodies would be keeping every password-reset link the site has ever sent — and a reset link is not a record of what happened, it is a key to the account, valid for whoever reads the table next. What is kept is who was written to, when, about what, and how it went.', 'diluxone-mail' ); ?>
	</p>

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

	<?php if ( $data['editable'] ) : ?>
		<?php submit_button( __( 'Save', 'diluxone-mail' ) ); ?>
	<?php endif; ?>
</form>
