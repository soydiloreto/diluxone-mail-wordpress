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
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php if ( (int) $data['total'] > count( $data['rows'] ) ) : ?>
		<p class="diluxone-mail-profile-more">
			<?php
			printf(
				/* translators: 1: how many messages are shown, 2: how many there are */
				esc_html__( 'Showing the last %1$d of %2$d.', 'diluxone-mail' ),
				count( $data['rows'] ),
				(int) $data['total']
			);
			?>
			<?php if ( '' !== (string) $data['log_url'] ) : ?>
				<a class="button button-small" href="<?php echo esc_url( (string) $data['log_url'] ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'All of them in the mail log', 'diluxone-mail' ); ?>
					<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'diluxone-mail' ); ?></span>
				</a>
			<?php endif; ?>
		</p>
	<?php endif; ?>
<?php endif; ?>
