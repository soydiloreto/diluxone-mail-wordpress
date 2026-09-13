<?php
/**
 * The log section on a person's profile.
 *
 * @package DiluxOneMail
 * @var array<string, mixed> $data
 */

defined( 'ABSPATH' ) || exit;
?>
<h2><?php esc_html_e( 'Mail sent to this person', 'diluxone-mail' ); ?></h2>

<?php if ( ! $data['log_enabled'] ) : ?>
	<p class="description"><?php esc_html_e( 'The mail log is off, so nothing is being recorded. Turn it on in DiluxOne Mail → Settings.', 'diluxone-mail' ); ?></p>
	<?php return; ?>
<?php endif; ?>

<p class="description">
	<?php
	printf(
		/* translators: 1: count, 2: addresses */
		esc_html( _n( '%1$d message to %2$s.', '%1$d messages to %2$s.', (int) $data['total'], 'diluxone-mail' ) ),
		(int) $data['total'],
		'<code>' . esc_html( implode( '</code>, <code>', array_map( 'esc_html', $data['emails'] ) ) ) . '</code>'
	);
	?>
	<?php if ( ! $data['log_body'] ) : ?>
		<?php esc_html_e( 'Bodies are not stored, so messages cannot be resent from here; turn on body storage in the settings to change that for future messages.', 'diluxone-mail' ); ?>
	<?php endif; ?>
</p>

<?php if ( array() === $data['rows'] ) : ?>
	<p><?php esc_html_e( 'Nothing yet.', 'diluxone-mail' ); ?></p>
<?php else : ?>
	<table class="widefat striped diluxone-mail-profile-log">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Date', 'diluxone-mail' ); ?></th>
				<th><?php esc_html_e( 'Subject', 'diluxone-mail' ); ?></th>
				<th><?php esc_html_e( 'Status', 'diluxone-mail' ); ?></th>
				<?php
				if ( $data['multisite'] ) :
					?>
					<th><?php esc_html_e( 'Site', 'diluxone-mail' ); ?></th><?php endif; ?>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $data['rows'] as $diluxone_mail_row ) : ?>
				<?php $diluxone_mail_status = (string) $diluxone_mail_row['status']; ?>
				<tr>
					<td><?php echo esc_html( get_date_from_gmt( (string) $diluxone_mail_row['sent_at'], (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ) ) ); ?></td>
					<td><?php echo esc_html( '' !== (string) $diluxone_mail_row['subject'] ? (string) $diluxone_mail_row['subject'] : __( '(no subject)', 'diluxone-mail' ) ); ?></td>
					<td>
						<span class="diluxone-mail-status diluxone-mail-status--<?php echo esc_attr( $diluxone_mail_status ); ?>"><?php echo esc_html( (string) ( $data['statuses'][ $diluxone_mail_status ] ?? $diluxone_mail_status ) ); ?></span>
						<?php if ( '' !== (string) $diluxone_mail_row['error'] ) : ?>
							<br><code><?php echo esc_html( (string) $diluxone_mail_row['error'] ); ?></code>
						<?php endif; ?>
					</td>
					<?php if ( $data['multisite'] ) : ?>
						<?php $diluxone_mail_site = get_site( (int) $diluxone_mail_row['site_id'] ); ?>
						<td><?php echo esc_html( $diluxone_mail_site instanceof WP_Site ? (string) $diluxone_mail_site->blogname : (string) $diluxone_mail_row['site_id'] ); ?></td>
					<?php endif; ?>
					<td>
						<?php if ( $data['log_body'] ) : ?>
							<a class="button button-small" href="<?php echo esc_url( diluxone_mail_resend_url( (int) $diluxone_mail_row['id'] ) ); ?>"><?php esc_html_e( 'Resend', 'diluxone-mail' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php if ( '' !== (string) $data['log_url'] && (int) $data['total'] > count( $data['rows'] ) ) : ?>
		<p><a href="<?php echo esc_url( (string) $data['log_url'] ); ?>"><?php esc_html_e( 'See all in the mail log', 'diluxone-mail' ); ?></a></p>
	<?php endif; ?>
<?php endif; ?>
