<?php
/**
 * The overview screen.
 *
 * A welcome that says what the plugin is for, and four cards: whether the site
 * sends, how far the setup got, what the log has seen, and what the
 * deliverability diagnosis said the last time it ran. Each card carries its
 * own state — green, amber or red — in the icon, the border and the headline,
 * so the answer is readable before anything is read.
 *
 * @package DiluxOneMail
 * @var array<string, mixed> $data
 */

defined( 'ABSPATH' ) || exit;

$diluxone_mail_sent   = (int) ( $data['totals']['sent'] ?? 0 );
$diluxone_mail_failed = (int) ( $data['totals']['failed'] ?? 0 );
$diluxone_mail_steps  = array( 'profile', 'server', 'sender', 'test' );
$diluxone_mail_left   = 0;

foreach ( $diluxone_mail_steps as $diluxone_mail_step ) {
	if ( ! $data['progress'][ $diluxone_mail_step ] ) {
		++$diluxone_mail_left;
	}
}

// Being the transport is not the same as being able to send: with nothing
// configured yet there is no other plugin in the way either, and "this plugin
// sends the mail of this site" would be true of a plugin with no server to
// send it through.
$diluxone_mail_sending = $data['transport'] && '' !== (string) $data['host'];

/**
 * One card, opened.
 *
 * @param string $state ok, warn, bad or plain.
 */
$diluxone_mail_card = static function ( string $state, string $icon, string $title ): void {
	printf(
		'<div class="diluxone-mail-card diluxone-mail-card--%1$s"><div class="diluxone-mail-card-icon"><span class="dashicons %2$s"></span></div><div class="diluxone-mail-card-body"><h3>%3$s</h3>',
		esc_attr( $state ),
		esc_attr( $icon ),
		esc_html( $title )
	);
};
?>

<div class="diluxone-mail-welcome">
	<h2><?php esc_html_e( 'Welcome to DiluxOne Mail', 'diluxone-mail' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Send WordPress mail through your own SMTP provider, keep a log of every message, and find out why the ones that leave still do not arrive.', 'diluxone-mail' ); ?>
	</p>
	<p class="diluxone-mail-welcome-actions">
		<a class="button button-primary" href="<?php echo esc_url( (string) $data['provider_url'] ); ?>">
			<?php echo $diluxone_mail_left > 0 ? esc_html__( 'Continue the setup', 'diluxone-mail' ) : esc_html__( 'Provider', 'diluxone-mail' ); ?>
		</a>
		<a class="button" href="<?php echo esc_url( (string) $data['settings_url'] ); ?>"><?php esc_html_e( 'Settings', 'diluxone-mail' ); ?></a>
		<a class="button" href="<?php echo esc_url( (string) $data['log_url'] ); ?>"><?php esc_html_e( 'Mail log', 'diluxone-mail' ); ?></a>
		<a class="button" href="<?php echo esc_url( (string) $data['dns_url'] ); ?>"><?php esc_html_e( 'Deliverability', 'diluxone-mail' ); ?></a>
	</p>
</div>

<?php if ( is_array( $data['test'] ) ) : ?>
	<div class="notice notice-<?php echo $data['test']['ok'] ? 'success' : 'error'; ?> inline">
		<p>
			<?php if ( $data['test']['ok'] ) : ?>
				<?php
				printf(
					/* translators: 1: recipient, 2: seconds */
					esc_html__( 'Test message handed to the server for %1$s in %2$ss. Check the inbox — and the spam folder.', 'diluxone-mail' ),
					esc_html( (string) $data['test']['to'] ),
					esc_html( (string) $data['test']['seconds'] )
				);
				?>
			<?php else : ?>
				<strong><?php esc_html_e( 'The test message was not sent.', 'diluxone-mail' ); ?></strong>
				<?php echo esc_html( (string) $data['test']['error'] ); ?>
			<?php endif; ?>
		</p>
	</div>
<?php endif; ?>

<div class="diluxone-mail-cards">

	<?php
	$diluxone_mail_card(
		$diluxone_mail_sending ? 'ok' : 'warn',
		$diluxone_mail_sending ? 'dashicons-email-alt' : 'dashicons-visibility',
		__( 'Sending', 'diluxone-mail' )
	);
	?>
		<?php if ( $diluxone_mail_sending ) : ?>
			<p class="diluxone-mail-card-headline"><?php esc_html_e( 'This plugin sends the mail of this site.', 'diluxone-mail' ); ?></p>
			<p class="diluxone-mail-card-detail">
				<?php
				printf(
					/* translators: 1: provider name, 2: SMTP host */
					esc_html__( 'Through %1$s, at %2$s.', 'diluxone-mail' ),
					esc_html( (string) $data['profile']['name'] ),
					esc_html( (string) $data['host'] )
				);
				?>
			</p>
			<?php if ( '' !== $data['from'] ) : ?>
				<p class="diluxone-mail-card-detail">
					<?php
					printf(
						/* translators: %s: the From address */
						esc_html__( 'Signed as %s.', 'diluxone-mail' ),
						esc_html( '' !== $data['from_name'] ? $data['from_name'] . ' <' . $data['from'] . '>' : $data['from'] )
					);
					?>
				</p>
			<?php endif; ?>
		<?php else : ?>
			<p class="diluxone-mail-card-headline"><?php esc_html_e( 'This plugin is watching, not sending.', 'diluxone-mail' ); ?></p>
			<p class="diluxone-mail-card-detail">
				<?php if ( '' === (string) $data['host'] ) : ?>
					<?php esc_html_e( 'There is no server configured yet, so nothing can go out through it. The setup card says which step is next.', 'diluxone-mail' ); ?>
				<?php elseif ( 'observe' === $data['mode'] ) : ?>
					<?php esc_html_e( 'The mode is set to never send. Messages are logged and diagnosed, and delivery is left to whoever else is doing it.', 'diluxone-mail' ); ?>
				<?php else : ?>
					<?php
					printf(
						/* translators: %s: names of the plugins handling delivery */
						esc_html__( '%s is handling delivery: it ends the send before this plugin is reached.', 'diluxone-mail' ),
						esc_html( implode( ', ', array_column( $data['others'], 'name' ) ) )
					);
					?>
				<?php endif; ?>
			</p>
		<?php endif; ?>
		<p class="diluxone-mail-card-link"><a href="<?php echo esc_url( (string) $data['status_url'] ); ?>"><?php esc_html_e( 'Full status', 'diluxone-mail' ); ?> <span aria-hidden="true">&rarr;</span></a></p>
	</div></div>

	<?php
	$diluxone_mail_card(
		0 === $diluxone_mail_left ? 'ok' : 'warn',
		0 === $diluxone_mail_left ? 'dashicons-yes-alt' : 'dashicons-list-view',
		__( 'Setup', 'diluxone-mail' )
	);
	?>
		<?php if ( 0 === $diluxone_mail_left ) : ?>
			<p class="diluxone-mail-card-headline"><?php esc_html_e( 'All four steps are done.', 'diluxone-mail' ); ?></p>
		<?php else : ?>
			<p class="diluxone-mail-card-headline">
				<?php
				printf(
					/* translators: %d: how many steps are left */
					esc_html( _n( '%d step left.', '%d steps left.', $diluxone_mail_left, 'diluxone-mail' ) ),
					(int) $diluxone_mail_left
				);
				?>
			</p>
		<?php endif; ?>
		<ol class="diluxone-mail-steps">
			<?php foreach ( $diluxone_mail_steps as $diluxone_mail_step ) : ?>
				<li class="<?php echo $data['progress'][ $diluxone_mail_step ] ? 'is-done' : 'is-pending'; ?>">
					<span class="dashicons <?php echo $data['progress'][ $diluxone_mail_step ] ? 'dashicons-yes' : 'dashicons-minus'; ?>" aria-hidden="true"></span>
					<a href="<?php echo esc_url( diluxone_mail_tab_url( $diluxone_mail_step ) ); ?>"><?php echo esc_html( (string) $data['tabs'][ $diluxone_mail_step ]['label'] ); ?></a>
				</li>
			<?php endforeach; ?>
		</ol>
	</div></div>

	<?php
	$diluxone_mail_card(
		$diluxone_mail_failed > 0 ? 'warn' : 'plain',
		'dashicons-chart-bar',
		__( 'Messages', 'diluxone-mail' )
	);
	?>
		<?php if ( ! $data['log_enabled'] ) : ?>
			<p class="diluxone-mail-card-headline diluxone-mail-card-headline--quiet"><?php esc_html_e( 'The log is off, so there is nothing to count.', 'diluxone-mail' ); ?></p>
		<?php else : ?>
			<p class="diluxone-mail-card-headline">
				<?php
				printf(
					/* translators: 1: messages sent, 2: messages failed */
					esc_html__( '%1$d sent, %2$d failed', 'diluxone-mail' ),
					(int) $diluxone_mail_sent,
					(int) $diluxone_mail_failed
				);
				?>
			</p>
		<?php endif; ?>
		<?php if ( is_array( $data['last'] ) ) : ?>
			<p class="diluxone-mail-card-detail">
				<?php if ( (bool) $data['last']['ok'] ) : ?>
					<?php
					printf(
						/* translators: %s: how long ago */
						esc_html__( 'Last send went out %s ago.', 'diluxone-mail' ),
						esc_html( human_time_diff( (int) $data['last']['time'] ) )
					);
					?>
				<?php else : ?>
					<span class="diluxone-mail-bad">
						<?php
						printf(
							/* translators: 1: how long ago, 2: the error */
							esc_html__( 'Last send failed %1$s ago: %2$s', 'diluxone-mail' ),
							esc_html( human_time_diff( (int) $data['last']['time'] ) ),
							esc_html( (string) $data['last']['error'] )
						);
						?>
					</span>
				<?php endif; ?>
			</p>
		<?php endif; ?>
		<p class="diluxone-mail-card-link"><a href="<?php echo esc_url( (string) $data['log_url'] ); ?>"><?php esc_html_e( 'Mail log', 'diluxone-mail' ); ?> <span aria-hidden="true">&rarr;</span></a></p>
	</div></div>

	<?php
	$diluxone_mail_dns_state = 'plain';
	$diluxone_mail_dns_icon  = 'dashicons-shield';

	if ( $data['has_report'] ) {
		if ( $data['findings']['error'] > 0 ) {
			$diluxone_mail_dns_state = 'bad';
			$diluxone_mail_dns_icon  = 'dashicons-warning';
		} elseif ( $data['findings']['warning'] > 0 ) {
			$diluxone_mail_dns_state = 'warn';
			$diluxone_mail_dns_icon  = 'dashicons-warning';
		} else {
			$diluxone_mail_dns_state = 'ok';
			$diluxone_mail_dns_icon  = 'dashicons-shield-alt';
		}
	}

	$diluxone_mail_card( $diluxone_mail_dns_state, $diluxone_mail_dns_icon, __( 'Deliverability', 'diluxone-mail' ) );
	?>
		<?php if ( '' === (string) $data['domain'] ) : ?>
			<p class="diluxone-mail-card-headline diluxone-mail-card-headline--quiet"><?php esc_html_e( 'There is no domain to check yet: it comes from the From address.', 'diluxone-mail' ); ?></p>
		<?php elseif ( ! $data['has_report'] ) : ?>
			<p class="diluxone-mail-card-headline diluxone-mail-card-headline--quiet"><?php esc_html_e( 'Not diagnosed yet.', 'diluxone-mail' ); ?></p>
			<p class="diluxone-mail-card-detail">
				<?php
				printf(
					/* translators: %s: the domain */
					esc_html__( '%s has not been diagnosed yet. The check runs on the deliverability screen; it is twenty DNS lookups, so it is not done to draw this page.', 'diluxone-mail' ),
					esc_html( (string) $data['domain'] )
				);
				?>
			</p>
		<?php else : ?>
			<?php if ( $data['findings']['error'] > 0 ) : ?>
				<p class="diluxone-mail-card-headline">
					<?php
					printf(
						/* translators: %d: number of problems */
						esc_html( _n( '%d problem found.', '%d problems found.', (int) $data['findings']['error'], 'diluxone-mail' ) ),
						(int) $data['findings']['error']
					);
					?>
				</p>
			<?php elseif ( $data['findings']['warning'] > 0 ) : ?>
				<p class="diluxone-mail-card-headline">
					<?php
					printf(
						/* translators: %d: number of warnings */
						esc_html( _n( '%d warning.', '%d warnings.', (int) $data['findings']['warning'], 'diluxone-mail' ) ),
						(int) $data['findings']['warning']
					);
					?>
				</p>
			<?php else : ?>
				<p class="diluxone-mail-card-headline"><?php esc_html_e( 'Nothing to fix.', 'diluxone-mail' ); ?></p>
			<?php endif; ?>
			<p class="diluxone-mail-card-detail">
				<?php
				printf(
					/* translators: %s: the domain */
					esc_html__( 'SPF, DKIM and DMARC for %s.', 'diluxone-mail' ),
					esc_html( (string) $data['domain'] )
				);
				?>
			</p>
		<?php endif; ?>
		<p class="diluxone-mail-card-link"><a href="<?php echo esc_url( (string) $data['dns_url'] ); ?>"><?php esc_html_e( 'Deliverability', 'diluxone-mail' ); ?> <span aria-hidden="true">&rarr;</span></a></p>
	</div></div>

</div>

<form method="post" action="<?php echo esc_url( (string) $data['action_url'] ); ?>" class="diluxone-mail-form diluxone-mail-test">
	<?php wp_nonce_field( 'diluxone_mail_test' ); ?>
	<input type="hidden" name="action" value="diluxone_mail_test">
	<input type="hidden" name="scope" value="site">
	<input type="hidden" name="return" value="overview">

	<h2><?php esc_html_e( 'Send a test message', 'diluxone-mail' ); ?></h2>
	<p>
		<label for="diluxone_mail_test_to" class="screen-reader-text"><?php esc_html_e( 'Send to', 'diluxone-mail' ); ?></label>
		<input type="email" class="regular-text" id="diluxone_mail_test_to" name="diluxone_mail_test_to" value="<?php echo esc_attr( (string) wp_get_current_user()->user_email ); ?>">
		<?php submit_button( __( 'Send test', 'diluxone-mail' ), 'secondary', 'send', false ); ?>
	</p>
</form>
