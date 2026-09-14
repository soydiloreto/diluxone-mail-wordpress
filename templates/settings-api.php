<?php
/**
 * Step 2, when the provider is reached over HTTP: the key.
 *
 * The SMTP half of this step asks a server whether it accepts a password. This
 * asks the provider whether it knows a key, which is the same question in the
 * other protocol — and the answer is a sentence rather than a number.
 *
 * @package DiluxOneMail
 * @var array<string, mixed> $data
 */

defined( 'ABSPATH' ) || exit;

$diluxone_mail_f   = $data['fields'];
$diluxone_mail_c   = $data['connection'];
$diluxone_mail_api = $data['api'];
?>

<?php if ( is_array( $diluxone_mail_c ) && ! $diluxone_mail_c['ok'] ) : ?>
	<div class="notice notice-error inline">
		<p>
			<strong><?php esc_html_e( 'The key was not accepted, so nothing was saved.', 'diluxone-mail' ); ?></strong>
			<?php echo esc_html( (string) $diluxone_mail_c['error'] ); ?>
		</p>
	</div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( (string) $data['action_url'] ); ?>" class="diluxone-mail-form">
	<?php wp_nonce_field( 'diluxone_mail_settings' ); ?>
	<?php diluxone_mail_connection_field(); ?>
	<input type="hidden" name="action" value="diluxone_mail_api_key">
	<input type="hidden" name="scope" value="<?php echo esc_attr( (string) $data['scope'] ); ?>">
	<input type="hidden" name="tab" value="server">

	<h2><?php esc_html_e( 'API key', 'diluxone-mail' ); ?></h2>
	<p class="description">
		<?php
		printf(
			/* translators: %s: provider name */
			esc_html__( 'Messages go out over HTTPS to %s. There is no server, no port and no SMTP password: one key does all of it, and the provider answers in words when it refuses a message.', 'diluxone-mail' ),
			esc_html( (string) $data['profile']['name'] )
		);
		?>
	</p>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="diluxone_mail_api_key"><?php echo esc_html( (string) ( $diluxone_mail_api['key_label'] ?? __( 'Key', 'diluxone-mail' ) ) ); ?></label></th>
			<td>
				<?php if ( $diluxone_mail_f['diluxone_mail_api_key']['readonly'] ) : ?>
					<input type="text" class="regular-text code" value="<?php echo esc_attr( $data['has_api_key'] ? '••••••••' : '' ); ?>" readonly>
					<?php diluxone_mail_source_caption( $diluxone_mail_f['diluxone_mail_api_key'] ); ?>
				<?php else : ?>
					<input type="password" class="regular-text code" id="diluxone_mail_api_key" name="diluxone_mail_api_key" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $data['has_api_key'] ? __( 'stored — leave empty to keep it', 'diluxone-mail' ) : __( 'not set', 'diluxone-mail' ) ); ?>">
				<?php endif; ?>
				<?php if ( isset( $diluxone_mail_api['key_hint'] ) ) : ?>
					<p class="description"><?php echo esc_html( (string) $diluxone_mail_api['key_hint'] ); ?></p>
				<?php endif; ?>
				<p class="description">
					<?php esc_html_e( 'Stored encrypted with a key derived from this site\'s WordPress salts, and never shown again.', 'diluxone-mail' ); ?>
					<?php if ( isset( $diluxone_mail_api['docs'] ) ) : ?>
						<a href="<?php echo esc_url( (string) $diluxone_mail_api['docs'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Provider documentation', 'diluxone-mail' ); ?></a>
					<?php endif; ?>
				</p>
			</td>
		</tr>
	</table>

	<?php if ( $data['editable'] ) : ?>
		<?php submit_button( __( 'Check the key and save', 'diluxone-mail' ) ); ?>
	<?php endif; ?>
</form>
