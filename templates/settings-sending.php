<?php
/**
 * How this plugin takes part in sending.
 *
 * Not a step: a site that already sends can come here to change its mind, and
 * a site that does not can read it to understand why.
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
	<input type="hidden" name="tab" value="sending">

	<h2><?php esc_html_e( 'How this plugin takes part in sending', 'diluxone-mail' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Mode', 'diluxone-mail' ); ?></th>
			<td>
				<fieldset>
					<?php
					$diluxone_mail_modes = array(
						'auto'      => __( 'Automatic — send only when no other mail plugin is active; otherwise log and diagnose without touching delivery', 'diluxone-mail' ),
						'transport' => __( 'Always send through this plugin, even if another mail plugin is active', 'diluxone-mail' ),
						'observe'   => __( 'Never send — only log and diagnose', 'diluxone-mail' ),
					);
					foreach ( $diluxone_mail_modes as $diluxone_mail_value => $diluxone_mail_text ) :
						?>
						<label><input type="radio" name="diluxone_mail_mode" value="<?php echo esc_attr( $diluxone_mail_value ); ?>" <?php checked( $diluxone_mail_f['diluxone_mail_mode']['value'], $diluxone_mail_value ); ?> <?php disabled( $diluxone_mail_f['diluxone_mail_mode']['readonly'] ); ?>> <?php echo esc_html( $diluxone_mail_text ); ?></label><br>
					<?php endforeach; ?>
				</fieldset>
				<p class="description"><?php esc_html_e( 'Another plugin counts as handling the mail when it replaces wp_mail() or answers pre_wp_mail — the two that end the send before this plugin is reached. One that only hooks phpmailer_init to add a header is not taking delivery away and does not change the mode.', 'diluxone-mail' ); ?></p>
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

	<?php if ( $data['editable'] ) : ?>
		<?php submit_button( __( 'Save', 'diluxone-mail' ) ); ?>
	<?php endif; ?>
</form>
