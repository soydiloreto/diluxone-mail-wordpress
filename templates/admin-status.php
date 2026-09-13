<?php
/**
 * The view of the status screen.
 *
 * @package DiluxOneMail
 * @var array<string, mixed> $data
 */

defined( 'ABSPATH' ) || exit;

$diluxone_mail_statuses = diluxone_mail_log_statuses();
?>
<table class="widefat striped diluxone-mail-status-table">
	<tbody>
		<tr>
			<th scope="row"><?php esc_html_e( 'Environment', 'diluxone-mail' ); ?></th>
			<td><code><?php echo esc_html( (string) $data['environment'] ); ?></code> <span class="description"><?php esc_html_e( '(wp_get_environment_type)', 'diluxone-mail' ); ?></span></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Who sends the mail', 'diluxone-mail' ); ?></th>
			<td>
				<?php if ( $data['transport'] ) : ?>
					<strong><?php esc_html_e( 'DiluxOne Mail', 'diluxone-mail' ); ?></strong>
					<?php
					printf(
						/* translators: %s: mode */
						esc_html__( '(mode: %s)', 'diluxone-mail' ),
						esc_html( (string) $data['mode'] )
					);
					?>
				<?php else : ?>
					<strong><?php esc_html_e( 'Observer mode', 'diluxone-mail' ); ?></strong>
					<?php if ( array() !== $data['others'] ) : ?>
						— <?php echo esc_html( implode( ', ', array_column( $data['others'], 'name' ) ) ); ?>
						<?php esc_html_e( 'is handling delivery. This plugin logs and diagnoses without touching it.', 'diluxone-mail' ); ?>
					<?php else : ?>
						<?php esc_html_e( '— set by hand. Nothing is sent through this plugin.', 'diluxone-mail' ); ?>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( array() !== $data['others'] && $data['transport'] ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: names */
							esc_html__( 'Also active and hooked into mail: %s. This plugin runs last and takes precedence.', 'diluxone-mail' ),
							esc_html( implode( ', ', array_column( $data['others'], 'name' ) ) )
						);
						?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php if ( array() !== $data['interceptors'] ) : ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'pre_wp_mail interceptors', 'diluxone-mail' ); ?></th>
				<td>
					<?php foreach ( $data['interceptors'] as $diluxone_mail_i ) : ?>
						<code><?php echo esc_html( '' !== $diluxone_mail_i['name'] ? $diluxone_mail_i['name'] : basename( $diluxone_mail_i['file'] ) ); ?></code>
						<span class="description"><?php echo esc_html( ltrim( str_replace( wp_normalize_path( WP_CONTENT_DIR ), '', $diluxone_mail_i['file'] ), '/' ) ); ?></span><br>
					<?php endforeach; ?>
					<?php if ( $data['unhooking'] ) : ?>
						<strong><?php esc_html_e( 'Being detached on every send.', 'diluxone-mail' ); ?></strong>
					<?php endif; ?>
				</td>
			</tr>
		<?php endif; ?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Provider profile', 'diluxone-mail' ); ?></th>
			<td><?php echo esc_html( (string) $data['profile']['name'] ); ?></td>
		</tr>
		<?php foreach ( $data['config'] as $diluxone_mail_field => $diluxone_mail_v ) : ?>
			<tr>
				<th scope="row"><code><?php echo esc_html( $diluxone_mail_field ); ?></code></th>
				<td>
					<code><?php echo esc_html( '' !== (string) $diluxone_mail_v['value'] ? (string) $diluxone_mail_v['value'] : '—' ); ?></code>
					<span class="description"><?php echo esc_html( (string) $diluxone_mail_v['label'] ); ?></span>
				</td>
			</tr>
		<?php endforeach; ?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Last send', 'diluxone-mail' ); ?></th>
			<td>
				<?php if ( ! is_array( $data['last'] ) ) : ?>
					<?php esc_html_e( 'Nothing has been sent since the plugin was activated.', 'diluxone-mail' ); ?>
				<?php elseif ( ! empty( $data['last']['ok'] ) ) : ?>
					<span class="diluxone-mail-status diluxone-mail-status--sent"><?php esc_html_e( 'Delivered to the server', 'diluxone-mail' ); ?></span>
					<?php echo esc_html( human_time_diff( (int) $data['last']['time'] ) ); ?> <?php esc_html_e( 'ago', 'diluxone-mail' ); ?>
				<?php else : ?>
					<span class="diluxone-mail-status diluxone-mail-status--failed"><?php esc_html_e( 'Failed', 'diluxone-mail' ); ?></span>
					<?php echo esc_html( human_time_diff( (int) $data['last']['time'] ) ); ?> <?php esc_html_e( 'ago', 'diluxone-mail' ); ?>
					— <code><?php echo esc_html( (string) $data['last']['error'] ); ?></code>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Mail log', 'diluxone-mail' ); ?></th>
			<td>
				<?php if ( ! $data['log_enabled'] ) : ?>
					<?php esc_html_e( 'Off.', 'diluxone-mail' ); ?>
				<?php else : ?>
					<?php foreach ( $data['totals'] as $diluxone_mail_status => $diluxone_mail_n ) : ?>
						<span class="diluxone-mail-status diluxone-mail-status--<?php echo esc_attr( (string) $diluxone_mail_status ); ?>"><?php echo esc_html( (string) ( $diluxone_mail_statuses[ $diluxone_mail_status ] ?? $diluxone_mail_status ) ); ?>: <?php echo esc_html( (string) $diluxone_mail_n ); ?></span>
					<?php endforeach; ?>
					<?php if ( array() === $data['totals'] ) : ?>
						<?php esc_html_e( 'Empty.', 'diluxone-mail' ); ?>
					<?php endif; ?>
					<p class="description">
						<?php echo $data['log_extended'] ? esc_html__( 'Extended log on.', 'diluxone-mail' ) : esc_html__( 'Basic log.', 'diluxone-mail' ); ?>
						<?php echo $data['log_body'] ? esc_html__( 'Bodies are stored.', 'diluxone-mail' ) : esc_html__( 'Bodies are not stored.', 'diluxone-mail' ); ?>
						<?php if ( $data['next_purge'] > 0 ) : ?>
							<?php
							printf(
								/* translators: %s: time */
								esc_html__( 'Next purge in %s.', 'diluxone-mail' ),
								esc_html( human_time_diff( $data['next_purge'] ) )
							);
							?>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'DNS', 'diluxone-mail' ); ?></th>
			<td>
				<?php
				printf(
					/* translators: %s: domain */
					esc_html__( 'Diagnosing %s.', 'diluxone-mail' ),
					'<code>' . esc_html( (string) $data['dns_domain'] ) . '</code>'
				);
				?>
				<?php echo $data['dns_system'] ? esc_html__( 'dns_get_record() is available.', 'diluxone-mail' ) : esc_html__( 'dns_get_record() is unavailable on this host; queries go over DNS-over-HTTPS.', 'diluxone-mail' ); ?>
			</td>
		</tr>
		<?php if ( $data['multisite'] ) : ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Network', 'diluxone-mail' ); ?></th>
				<td><?php echo $data['site_override'] ? esc_html__( 'Sites may override the network settings.', 'diluxone-mail' ) : esc_html__( 'Settings are fixed by the network for every site.', 'diluxone-mail' ); ?></td>
			</tr>
		<?php endif; ?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Version', 'diluxone-mail' ); ?></th>
			<td><?php echo esc_html( (string) $data['version'] ); ?> <span class="description">(<?php esc_html_e( 'schema', 'diluxone-mail' ); ?> <?php echo esc_html( (string) $data['db_version'] ); ?>)</span></td>
		</tr>
	</tbody>
</table>
