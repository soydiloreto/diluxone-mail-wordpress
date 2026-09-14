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
		<tr><th scope="row"><?php esc_html_e( 'Provider', 'diluxone-mail' ); ?></th><td><?php echo esc_html( diluxone_mail_log_carrier( $diluxone_mail_row ) ); ?></td></tr>
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

<p class="description"><?php esc_html_e( 'The content of the message is not stored. What a mail log would keep is every password-reset link the site has ever sent, which is a key to an account rather than a record of one.', 'diluxone-mail' ); ?></p>

<?php if ( is_array( $diluxone_mail_detail ) && '' !== $diluxone_mail_detail['transcript'] ) : ?>
	<h2><?php esc_html_e( 'SMTP conversation', 'diluxone-mail' ); ?></h2>
	<pre class="diluxone-mail-transcript"><?php echo esc_html( $diluxone_mail_detail['transcript'] ); ?></pre>
<?php endif; ?>
