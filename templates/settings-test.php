<?php
/**
 * Step 4: a real message, to a real mailbox.
 *
 * The connection test proves the server talks to us; only this proves it
 * delivers. They fail for different reasons — a provider that authenticates
 * happily still refuses a From address it has not verified — which is why
 * this is a step of its own and not a flourish at the end of the previous
 * one.
 *
 * @package DiluxOneMail
 * @var array<string, mixed> $data
 */

defined( 'ABSPATH' ) || exit;

$diluxone_mail_test = $data['test'];
?>

<?php if ( is_array( $diluxone_mail_test ) ) : ?>
	<div class="notice notice-<?php echo $diluxone_mail_test['ok'] ? 'success' : 'error'; ?> inline">
		<p>
			<?php if ( $diluxone_mail_test['ok'] ) : ?>
				<?php
				printf(
					/* translators: 1: recipient, 2: seconds */
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

<form method="post" action="<?php echo esc_url( (string) $data['action_url'] ); ?>" class="diluxone-mail-form diluxone-mail-test">
	<?php wp_nonce_field( 'diluxone_mail_test' ); ?>
	<?php diluxone_mail_connection_field(); ?>
	<input type="hidden" name="action" value="diluxone_mail_test">
	<input type="hidden" name="scope" value="<?php echo esc_attr( (string) $data['scope'] ); ?>">

	<h2><?php esc_html_e( 'Send a test message', 'diluxone-mail' ); ?></h2>
	<p>
		<label for="diluxone_mail_test_to" class="screen-reader-text"><?php esc_html_e( 'Send to', 'diluxone-mail' ); ?></label>
		<input type="email" class="regular-text" id="diluxone_mail_test_to" name="diluxone_mail_test_to" value="<?php echo esc_attr( (string) wp_get_current_user()->user_email ); ?>">
		<?php submit_button( __( 'Send test', 'diluxone-mail' ), 'primary', 'send', false ); ?>
	</p>
	<p class="description"><?php esc_html_e( 'If it fails you get the full SMTP error and the whole conversation with the server, with the password redacted.', 'diluxone-mail' ); ?></p>
</form>

<?php if ( $data['progress']['test'] ) : ?>
	<p class="description diluxone-mail-done-note">
		<?php esc_html_e( 'A message has already gone out with this configuration. Anything you change on the earlier tabs asks for the test again — the mark belongs to the settings, not to the button.', 'diluxone-mail' ); ?>
	</p>
<?php endif; ?>
