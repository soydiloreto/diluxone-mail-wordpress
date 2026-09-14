<?php
/**
 * The view of the deliverability diagnosis.
 *
 * The conclusions first, in prose. Then the data they come from, for anybody
 * who wants to check them: the SPF tree with each branch's lookups, the DKIM
 * selectors probed, the parsed DMARC record.
 *
 * @package DiluxOneMail
 * @var array<string, mixed> $data
 */

defined( 'ABSPATH' ) || exit;

$diluxone_mail_r = $data['report'];

if ( ! is_array( $diluxone_mail_r ) && ! $data['pending'] ) :
	?>
	<p><?php esc_html_e( 'There is no domain to diagnose. Set a From address or a domain in the settings.', 'diluxone-mail' ); ?></p>
	<?php
	diluxone_mail_view( 'admin-dns-options', $data );

	return;
endif;

/*
 * Nothing cached yet: the shape of the answer, while the browser goes and
 * asks for it. It is the shape and not a spinner on purpose — the page you
 * are about to read is already there, in grey, so the wait is somewhere
 * rather than nowhere, and the layout does not jump when it arrives.
 */
if ( ! is_array( $diluxone_mail_r ) ) :
	?>
	<div class="diluxone-mail-skeleton" data-diluxone-mail-diagnose="<?php echo esc_attr( (string) $data['diagnose_nonce'] ); ?>">
		<p class="diluxone-mail-dns-meta" role="status">
			<?php
			printf(
				/* translators: %s: domain name */
				esc_html__( 'Reading the DNS of %s. Each SPF include, DKIM selector and DMARC record is a separate lookup, so this takes a few seconds the first time.', 'diluxone-mail' ),
				'<code>' . esc_html( (string) $data['domain'] ) . '</code>'
			);
			?>
		</p>

		<div class="diluxone-mail-findings" aria-hidden="true">
			<div class="diluxone-mail-finding diluxone-mail-finding--skeleton">
				<span class="diluxone-mail-skeleton-bar diluxone-mail-skeleton-bar--label"></span>
				<span class="diluxone-mail-skeleton-bar diluxone-mail-skeleton-bar--title"></span>
				<span class="diluxone-mail-skeleton-bar"></span>
				<span class="diluxone-mail-skeleton-bar diluxone-mail-skeleton-bar--short"></span>
			</div>
			<div class="diluxone-mail-finding diluxone-mail-finding--skeleton">
				<span class="diluxone-mail-skeleton-bar diluxone-mail-skeleton-bar--label"></span>
				<span class="diluxone-mail-skeleton-bar diluxone-mail-skeleton-bar--title"></span>
				<span class="diluxone-mail-skeleton-bar"></span>
			</div>
			<div class="diluxone-mail-finding diluxone-mail-finding--skeleton">
				<span class="diluxone-mail-skeleton-bar diluxone-mail-skeleton-bar--label"></span>
				<span class="diluxone-mail-skeleton-bar diluxone-mail-skeleton-bar--title"></span>
				<span class="diluxone-mail-skeleton-bar diluxone-mail-skeleton-bar--short"></span>
			</div>
		</div>

		<p class="diluxone-mail-skeleton-fallback">
			<noscript><?php esc_html_e( 'This page needs JavaScript to load the diagnosis in the background.', 'diluxone-mail' ); ?></noscript>
			<a href="<?php echo esc_url( (string) $data['wait_url'] ); ?>"><?php esc_html_e( 'Run it now and wait for the page instead', 'diluxone-mail' ); ?></a>
		</p>
	</div>
	<?php
	diluxone_mail_view( 'admin-dns-options', $data );

	return;
endif;

$diluxone_mail_levels = array(
	'error'   => __( 'Problem', 'diluxone-mail' ),
	'warning' => __( 'Warning', 'diluxone-mail' ),
	'info'    => __( 'Note', 'diluxone-mail' ),
	'ok'      => __( 'OK', 'diluxone-mail' ),
);
?>
<p class="diluxone-mail-dns-meta">
	<?php
	printf(
		/* translators: 1: domain, 2: how long ago, 3: resolver */
		esc_html__( 'Domain %1$s, checked %2$s ago via %3$s.', 'diluxone-mail' ),
		'<code>' . esc_html( (string) $diluxone_mail_r['domain'] ) . '</code>',
		esc_html( human_time_diff( (int) $diluxone_mail_r['generated_at'] ) ),
		esc_html( 'doh' === $diluxone_mail_r['resolver'] ? 'DNS-over-HTTPS' : 'dns_get_record()' )
	);
	?>
	<?php if ( '' !== (string) $diluxone_mail_r['provider'] ) : ?>
		<?php
		printf(
			/* translators: %s: provider name */
			esc_html__( 'Configured transport: %s.', 'diluxone-mail' ),
			esc_html( (string) $diluxone_mail_r['profile_name'] )
		);
		?>
	<?php else : ?>
		<?php esc_html_e( 'No transport configured here, so provider-specific checks are skipped.', 'diluxone-mail' ); ?>
	<?php endif; ?>
	<a class="button" href="<?php echo esc_url( $data['revalidate_url'] ); ?>"><?php esc_html_e( 'Revalidate', 'diluxone-mail' ); ?></a>
</p>

<div class="diluxone-mail-findings">
	<?php foreach ( $diluxone_mail_r['findings'] as $diluxone_mail_h ) : ?>
		<div class="diluxone-mail-finding diluxone-mail-finding--<?php echo esc_attr( (string) $diluxone_mail_h['level'] ); ?>">
			<span class="diluxone-mail-finding__level"><?php echo esc_html( (string) ( $diluxone_mail_levels[ (string) $diluxone_mail_h['level'] ] ?? $diluxone_mail_h['level'] ) ); ?></span>
			<strong><?php echo esc_html( (string) $diluxone_mail_h['title'] ); ?></strong>
			<p><?php echo esc_html( (string) $diluxone_mail_h['text'] ); ?></p>
		</div>
	<?php endforeach; ?>
</div>

<h2>SPF</h2>
<?php if ( null === $diluxone_mail_r['spf']['record'] ) : ?>
	<p class="description"><?php esc_html_e( 'No record.', 'diluxone-mail' ); ?></p>
<?php else : ?>
	<p>
		<?php
		printf(
			/* translators: 1: lookups, 2: limit */
			esc_html__( '%1$d of %2$d DNS lookups.', 'diluxone-mail' ),
			(int) $diluxone_mail_r['spf']['lookups'],
			(int) DILUXONE_MAIL_SPF_MAX_LOOKUPS
		);
		?>
	</p>
	<table class="widefat striped diluxone-mail-spf-tree">
		<thead><tr><th><?php esc_html_e( 'Domain', 'diluxone-mail' ); ?></th><th><?php esc_html_e( 'Record', 'diluxone-mail' ); ?></th><th><?php esc_html_e( 'Lookups', 'diluxone-mail' ); ?></th></tr></thead>
		<tbody>
			<?php foreach ( $diluxone_mail_r['spf']['tree'] as $diluxone_mail_node ) : ?>
				<tr>
					<td style="padding-left: <?php echo esc_attr( (string) ( 10 + 20 * (int) $diluxone_mail_node['depth'] ) ); ?>px"><code><?php echo esc_html( (string) $diluxone_mail_node['domain'] ); ?></code></td>
					<td>
						<?php if ( '' !== (string) $diluxone_mail_node['record'] ) : ?>
							<code><?php echo esc_html( (string) $diluxone_mail_node['record'] ); ?></code>
						<?php endif; ?>
						<?php if ( '' !== (string) $diluxone_mail_node['error'] ) : ?>
							<span class="diluxone-mail-status diluxone-mail-status--failed"><?php echo esc_html( (string) $diluxone_mail_node['error'] ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( (string) $diluxone_mail_node['lookups'] ); ?>
					<?php
					if ( isset( $diluxone_mail_node['subtotal'] ) && (int) $diluxone_mail_node['subtotal'] !== (int) $diluxone_mail_node['lookups'] ) :
						?>
						<span class="description">(<?php echo esc_html( (string) $diluxone_mail_node['subtotal'] ); ?> <?php esc_html_e( 'with children', 'diluxone-mail' ); ?>)</span><?php endif; ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php if ( array() !== $diluxone_mail_r['spf_senders'] ) : ?>
		<p>
			<?php esc_html_e( 'Senders declared:', 'diluxone-mail' ); ?>
			<?php foreach ( $diluxone_mail_r['spf_senders'] as $diluxone_mail_s ) : ?>
				<code><?php echo esc_html( (string) $diluxone_mail_s['include'] ); ?></code>
				<?php
				if ( '' !== (string) $diluxone_mail_s['name'] ) :
					?>
					(<?php echo esc_html( (string) $diluxone_mail_s['name'] ); ?><?php echo $diluxone_mail_s['active'] ? ', ' . esc_html__( 'configured transport', 'diluxone-mail' ) : ''; ?>)<?php endif; ?>
			<?php endforeach; ?>
		</p>
	<?php endif; ?>
<?php endif; ?>

<h2>DKIM</h2>
<table class="widefat striped">
	<thead><tr><th><?php esc_html_e( 'Selector', 'diluxone-mail' ); ?></th><th><?php esc_html_e( 'Found', 'diluxone-mail' ); ?></th><th><?php esc_html_e( 'Details', 'diluxone-mail' ); ?></th></tr></thead>
	<tbody>
		<?php foreach ( $diluxone_mail_r['dkim'] as $diluxone_mail_d ) : ?>
			<tr>
				<td><code><?php echo esc_html( (string) $diluxone_mail_d['selector'] ); ?></code></td>
				<td><?php echo $diluxone_mail_d['found'] ? '✔' : '—'; ?></td>
				<td>
					<?php if ( $diluxone_mail_d['found'] ) : ?>
						<?php echo esc_html( 'cname' === $diluxone_mail_d['via'] ? 'CNAME → ' . (string) $diluxone_mail_d['cname'] : 'TXT' ); ?>
						<?php
						if ( (int) $diluxone_mail_d['bits'] > 0 ) :
							?>
							· <?php echo esc_html( (string) $diluxone_mail_d['bits'] ); ?> bits<?php endif; ?>
						<?php
						if ( $diluxone_mail_d['revoked'] ) :
							?>
							· <span class="diluxone-mail-status diluxone-mail-status--failed"><?php esc_html_e( 'revoked', 'diluxone-mail' ); ?></span><?php endif; ?>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<h2>DMARC</h2>
<?php if ( null === $diluxone_mail_r['dmarc']['record'] ) : ?>
	<p class="description"><?php esc_html_e( 'No record.', 'diluxone-mail' ); ?></p>
<?php else : ?>
	<p><code><?php echo esc_html( (string) $diluxone_mail_r['dmarc']['record'] ); ?></code></p>
	<table class="widefat striped diluxone-mail-status-table">
		<tbody>
			<tr><th scope="row"><?php esc_html_e( 'Policy', 'diluxone-mail' ); ?></th><td><code>p=<?php echo esc_html( (string) $diluxone_mail_r['dmarc']['policy'] ); ?></code> · <?php esc_html_e( 'subdomains', 'diluxone-mail' ); ?> <code>sp=<?php echo esc_html( (string) $diluxone_mail_r['dmarc']['subdomain_policy'] ); ?></code> · <?php echo esc_html( (string) $diluxone_mail_r['dmarc']['pct'] ); ?>%</td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Alignment', 'diluxone-mail' ); ?></th><td>DKIM <?php echo esc_html( 's' === $diluxone_mail_r['dmarc']['adkim'] ? __( 'strict', 'diluxone-mail' ) : __( 'relaxed', 'diluxone-mail' ) ); ?> · SPF <?php echo esc_html( 's' === $diluxone_mail_r['dmarc']['aspf'] ? __( 'strict', 'diluxone-mail' ) : __( 'relaxed', 'diluxone-mail' ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Aggregate reports (rua)', 'diluxone-mail' ); ?></th><td><?php echo esc_html( array() !== $diluxone_mail_r['dmarc']['rua'] ? implode( ', ', $diluxone_mail_r['dmarc']['rua'] ) : '—' ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Forensic reports (ruf)', 'diluxone-mail' ); ?></th><td><?php echo esc_html( array() !== $diluxone_mail_r['dmarc']['ruf'] ? implode( ', ', $diluxone_mail_r['dmarc']['ruf'] ) : '—' ); ?></td></tr>
			<?php foreach ( $diluxone_mail_r['dmarc']['external'] as $diluxone_mail_e ) : ?>
				<tr><th scope="row"><?php esc_html_e( 'External report authorisation', 'diluxone-mail' ); ?></th><td><code><?php echo esc_html( (string) $diluxone_mail_e['domain'] ); ?></code> <?php echo $diluxone_mail_e['authorized'] ? '✔' : '<span class="diluxone-mail-status diluxone-mail-status--failed">' . esc_html__( 'missing', 'diluxone-mail' ) . '</span>'; ?></td></tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<h2>MX</h2>
<p><?php echo esc_html( array() !== $diluxone_mail_r['mx'] ? implode( ' · ', array_map( 'strval', $diluxone_mail_r['mx'] ) ) : __( 'No MX record.', 'diluxone-mail' ) ); ?></p>

<?php diluxone_mail_view( 'admin-dns-options', $data ); ?>
