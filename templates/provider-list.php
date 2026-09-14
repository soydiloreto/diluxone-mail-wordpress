<?php
/**
 * The configured providers, in the order that decides everything.
 *
 * @package DiluxOneMail
 * @var array<string, mixed> $data
 */

defined( 'ABSPATH' ) || exit;
?>

<?php if ( null !== $data['closed'] ) : ?>
	<div class="notice notice-<?php echo esc_attr( (string) $data['closed']['kind'] ); ?> inline">
		<p><?php echo esc_html( (string) $data['closed']['text'] ); ?></p>
	</div>
<?php endif; ?>

<p class="description diluxone-mail-intro">
	<?php esc_html_e( 'The order is what decides: the first provider sends, and the one below it is what gets tried when a message will not go out. Drag to reorder, or use the arrows.', 'diluxone-mail' ); ?>
</p>

<?php if ( array() === $data['rows'] ) : ?>
	<div class="diluxone-mail-empty">
		<p><strong><?php esc_html_e( 'No provider configured yet.', 'diluxone-mail' ); ?></strong></p>
		<p class="description"><?php esc_html_e( 'Four steps: pick how this site sends and through whom, prove the provider accepts the credentials, say who the mail comes from, and send a message to check it arrives.', 'diluxone-mail' ); ?></p>
		<p><a class="button button-primary button-hero" href="<?php echo esc_url( (string) $data['new_url'] ); ?>"><?php esc_html_e( 'Add a provider', 'diluxone-mail' ); ?></a></p>
	</div>
<?php else : ?>
	<form method="post" action="<?php echo esc_url( (string) $data['action_url'] ); ?>" id="diluxone-mail-order">
		<input type="hidden" name="action" value="diluxone_mail_reorder">
		<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) $data['nonce'] ); ?>">
		<input type="hidden" name="order" value="">
	</form>

	<table class="widefat striped diluxone-mail-connections">
		<thead>
			<tr>
				<th class="diluxone-mail-handle-column"><span class="screen-reader-text"><?php esc_html_e( 'Order', 'diluxone-mail' ); ?></span></th>
				<th><?php esc_html_e( 'Provider', 'diluxone-mail' ); ?></th>
				<th><?php esc_html_e( 'Method', 'diluxone-mail' ); ?></th>
				<th><?php esc_html_e( 'Sends as', 'diluxone-mail' ); ?></th>
				<th><?php esc_html_e( 'State', 'diluxone-mail' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $data['rows'] as $diluxone_mail_row ) : ?>
				<tr draggable="true" data-id="<?php echo esc_attr( (string) $diluxone_mail_row['id'] ); ?>">
					<td class="diluxone-mail-handle-column">
						<span class="diluxone-mail-handle dashicons dashicons-menu" aria-hidden="true"></span>
						<span class="diluxone-mail-position">
							<?php if ( 0 === (int) $diluxone_mail_row['position'] ) : ?>
								<strong><?php esc_html_e( 'Sends', 'diluxone-mail' ); ?></strong>
							<?php elseif ( 1 === (int) $diluxone_mail_row['position'] ) : ?>
								<?php esc_html_e( 'If that fails', 'diluxone-mail' ); ?>
							<?php else : ?>
								<?php
								printf(
									/* translators: %d: the provider's place in the order */
									esc_html__( 'Then %d', 'diluxone-mail' ),
									(int) $diluxone_mail_row['position'] + 1
								);
								?>
							<?php endif; ?>
						</span>
					</td>
					<td>
						<strong><a href="<?php echo esc_url( diluxone_mail_connection_url( (string) $diluxone_mail_row['id'] ) ); ?>"><?php echo esc_html( (string) $diluxone_mail_row['label'] ); ?></a></strong>
					</td>
					<td><?php echo esc_html( (string) $diluxone_mail_row['transport'] ); ?></td>
					<td><?php echo esc_html( '' !== (string) $diluxone_mail_row['from'] ? (string) $diluxone_mail_row['from'] : '—' ); ?></td>
					<td>
						<?php if ( $diluxone_mail_row['tested'] ) : ?>
							<span class="diluxone-mail-ok"><?php esc_html_e( 'Tested', 'diluxone-mail' ); ?></span>
						<?php elseif ( $diluxone_mail_row['ready'] ) : ?>
							<span class="diluxone-mail-ok"><?php esc_html_e( 'Ready', 'diluxone-mail' ); ?></span>
						<?php else : ?>
							<span class="diluxone-mail-warn"><?php esc_html_e( 'Half set up', 'diluxone-mail' ); ?></span>
						<?php endif; ?>
					</td>
					<td class="diluxone-mail-row-actions">
						<form method="post" action="<?php echo esc_url( (string) $data['action_url'] ); ?>">
							<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) $data['nonce'] ); ?>">
							<input type="hidden" name="connection" value="<?php echo esc_attr( (string) $diluxone_mail_row['id'] ); ?>">
							<button type="submit" name="up" formaction="<?php echo esc_url( add_query_arg( 'action', 'diluxone_mail_move', (string) $data['action_url'] ) ); ?>" class="button button-small" <?php disabled( 0 === (int) $diluxone_mail_row['position'] ); ?>>
								<span class="screen-reader-text"><?php esc_html_e( 'Move up', 'diluxone-mail' ); ?></span><span aria-hidden="true">&uarr;</span>
							</button>
							<button type="submit" name="down" formaction="<?php echo esc_url( add_query_arg( 'action', 'diluxone_mail_move', (string) $data['action_url'] ) ); ?>" class="button button-small" <?php disabled( count( $data['rows'] ) - 1 === (int) $diluxone_mail_row['position'] ); ?>>
								<span class="screen-reader-text"><?php esc_html_e( 'Move down', 'diluxone-mail' ); ?></span><span aria-hidden="true">&darr;</span>
							</button>
							<button type="submit" formaction="<?php echo esc_url( add_query_arg( 'action', 'diluxone_mail_forget', (string) $data['action_url'] ) ); ?>" class="button button-small button-link-delete" onclick="return confirm( '<?php echo esc_js( __( 'Remove this provider? Its credentials are deleted with it.', 'diluxone-mail' ) ); ?>' );">
								<?php esc_html_e( 'Remove', 'diluxone-mail' ); ?>
							</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<p><a class="button button-primary" href="<?php echo esc_url( (string) $data['new_url'] ); ?>"><?php esc_html_e( 'Add a provider', 'diluxone-mail' ); ?></a></p>
<?php endif; ?>
