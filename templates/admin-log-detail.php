<?php
/**
 * The view of one message's detail.
 *
 * @package DiluxOneMail
 * @var array<string, mixed> $data
 */

defined( 'ABSPATH' ) || exit;

$diluxone_mail_row    = $data['row'];
$diluxone_mail_detail = $data['detail'];
?>
<p><a href="<?php echo esc_url( $data['back_url'] ); ?>">&larr; <?php esc_html_e( 'Back to the log', 'diluxone-mail' ); ?></a></p>

<table class="widefat striped diluxone-mail-status-table">
	<tbody>
		<tr><th scope="row"><?php esc_html_e( 'Subject', 'diluxone-mail' ); ?></th><td><?php echo esc_html( (string) $diluxone_mail_row['subject'] ); ?></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'Date', 'diluxone-mail' ); ?></th><td><?php echo esc_html( get_date_from_gmt( (string) $diluxone_mail_row['sent_at'], (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ) ) ); ?></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'From', 'diluxone-mail' ); ?></th><td><?php echo esc_html( (string) $diluxone_mail_row['from_email'] ); ?></td></tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Recipients', 'diluxone-mail' ); ?></th>
			<td>
				<?php foreach ( $data['recipients'] as $diluxone_mail_r ) : ?>
					<?php echo esc_html( (string) $diluxone_mail_r['email'] ); ?>
					<span class="description">(<?php echo esc_html( strtoupper( (string) $diluxone_mail_r['kind'] ) ); ?>)</span>
					<span class="diluxone-mail-status diluxone-mail-status--<?php echo esc_attr( (string) $diluxone_mail_r['status'] ); ?>"><?php echo esc_html( (string) ( $data['statuses'][ (string) $diluxone_mail_r['status'] ] ?? $diluxone_mail_r['status'] ) ); ?></span><br>
				<?php endforeach; ?>
			</td>
		</tr>
		<?php if ( '' !== (string) $diluxone_mail_row['error'] ) : ?>
			<tr><th scope="row"><?php esc_html_e( 'Error', 'diluxone-mail' ); ?></th><td><code><?php echo esc_html( (string) $diluxone_mail_row['error'] ); ?></code></td></tr>
		<?php endif; ?>
		<?php if ( '' !== (string) $diluxone_mail_row['response'] ) : ?>
			<tr><th scope="row"><?php esc_html_e( 'Server response', 'diluxone-mail' ); ?></th><td><code><?php echo esc_html( (string) $diluxone_mail_row['response'] ); ?></code></td></tr>
		<?php endif; ?>
		<tr><th scope="row"><?php esc_html_e( 'Provider', 'diluxone-mail' ); ?></th><td><?php echo esc_html( 'observer' === (string) $diluxone_mail_row['provider'] ? __( 'another plugin', 'diluxone-mail' ) : (string) diluxone_mail_provider( (string) $diluxone_mail_row['provider'] )['name'] ); ?></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'Sent by', 'diluxone-mail' ); ?></th><td><code><?php echo esc_html( (string) $diluxone_mail_row['source'] ); ?></code></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'Message-ID', 'diluxone-mail' ); ?></th><td><code><?php echo esc_html( (string) $diluxone_mail_row['message_id'] ); ?></code></td></tr>
		<?php if ( array() !== $data['headers'] ) : ?>
			<tr><th scope="row"><?php esc_html_e( 'Headers', 'diluxone-mail' ); ?></th><td><pre><?php echo esc_html( implode( "\n", $data['headers'] ) ); ?></pre></td></tr>
		<?php endif; ?>
		<?php if ( array() !== $data['attachments'] ) : ?>
			<tr><th scope="row"><?php esc_html_e( 'Attachments', 'diluxone-mail' ); ?></th><td><?php echo esc_html( implode( ', ', $data['attachments'] ) ); ?></td></tr>
		<?php endif; ?>
	</tbody>
</table>

<h2><?php esc_html_e( 'Body', 'diluxone-mail' ); ?></h2>
<?php if ( is_array( $diluxone_mail_detail ) && '' !== $diluxone_mail_detail['body'] ) : ?>
	<?php if ( 'text/html' === $diluxone_mail_detail['body_type'] ) : ?>
		<iframe class="diluxone-mail-body" sandbox="" srcdoc="<?php echo esc_attr( $diluxone_mail_detail['body'] ); ?>" title="<?php esc_attr_e( 'Message body', 'diluxone-mail' ); ?>"></iframe>
	<?php else : ?>
		<pre class="diluxone-mail-body"><?php echo esc_html( $diluxone_mail_detail['body'] ); ?></pre>
	<?php endif; ?>
	<p><a class="button" href="<?php echo esc_url( $data['resend_url'] ); ?>"><?php esc_html_e( 'Resend to this recipient', 'diluxone-mail' ); ?></a></p>
<?php else : ?>
	<p class="description"><?php esc_html_e( 'The body was not stored — body storage is off in the settings — so this message cannot be resent.', 'diluxone-mail' ); ?></p>
<?php endif; ?>

<?php if ( is_array( $diluxone_mail_detail ) && '' !== $diluxone_mail_detail['transcript'] ) : ?>
	<h2><?php esc_html_e( 'SMTP conversation', 'diluxone-mail' ); ?></h2>
	<pre class="diluxone-mail-transcript"><?php echo esc_html( $diluxone_mail_detail['transcript'] ); ?></pre>
<?php endif; ?>
