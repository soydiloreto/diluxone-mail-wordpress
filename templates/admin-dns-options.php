<?php
/**
 * The four values the diagnosis runs on.
 *
 * Its own file because it is the one part of this screen that does not come
 * from a report: it is here while the report is still being fetched, and it
 * is here when there is no domain to fetch one for. Those are precisely the
 * moments somebody opens it — the domain is wrong, or a selector is missing —
 * and a form that only appears once the answer has arrived is a form that is
 * missing whenever it is needed.
 *
 * @package DiluxOneMail
 * @var array<string, mixed> $data
 */

defined( 'ABSPATH' ) || exit;

$diluxone_mail_f = $data['fields'];
?>

<details class="diluxone-mail-dns-options">
	<summary><?php esc_html_e( 'Options of the diagnosis', 'diluxone-mail' ); ?></summary>

	<form method="post" action="<?php echo esc_url( (string) $data['action_url'] ); ?>" class="diluxone-mail-form">
		<?php wp_nonce_field( 'diluxone_mail_settings' ); ?>
		<input type="hidden" name="action" value="diluxone_mail_save_settings">
		<input type="hidden" name="scope" value="site">
		<input type="hidden" name="tab" value="dns">

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="diluxone_mail_dns_domain"><?php esc_html_e( 'Domain', 'diluxone-mail' ); ?></label></th>
				<td>
					<input type="text" class="regular-text code" id="diluxone_mail_dns_domain" name="diluxone_mail_dns_domain" value="<?php echo esc_attr( (string) $diluxone_mail_f['diluxone_mail_dns_domain']['value'] ); ?>" placeholder="<?php echo esc_attr( diluxone_mail_dns_domain() ); ?>" <?php wp_readonly( $diluxone_mail_f['diluxone_mail_dns_domain']['readonly'] ); ?>>
					<p class="description"><?php esc_html_e( 'Leave empty to use the From address\'s domain, or the site\'s domain if there is none.', 'diluxone-mail' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="diluxone_mail_dns_selectors"><?php esc_html_e( 'Extra DKIM selectors', 'diluxone-mail' ); ?></label></th>
				<td>
					<input type="text" class="regular-text code" id="diluxone_mail_dns_selectors" name="diluxone_mail_dns_selectors" value="<?php echo esc_attr( implode( ', ', array_map( 'strval', (array) $diluxone_mail_f['diluxone_mail_dns_selectors']['value'] ) ) ); ?>" <?php wp_readonly( $diluxone_mail_f['diluxone_mail_dns_selectors']['readonly'] ); ?>>
					<p class="description"><?php esc_html_e( 'Comma-separated. Selectors cannot be listed over DNS; the diagnosis probes the common ones plus your provider\'s plus these.', 'diluxone-mail' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="diluxone_mail_dns_resolver"><?php esc_html_e( 'Resolver', 'diluxone-mail' ); ?></label></th>
				<td>
					<select id="diluxone_mail_dns_resolver" name="diluxone_mail_dns_resolver" <?php disabled( $diluxone_mail_f['diluxone_mail_dns_resolver']['readonly'] ); ?>>
						<option value="auto" <?php selected( $diluxone_mail_f['diluxone_mail_dns_resolver']['value'], 'auto' ); ?>><?php esc_html_e( 'System, falling back to DNS-over-HTTPS', 'diluxone-mail' ); ?></option>
						<option value="system" <?php selected( $diluxone_mail_f['diluxone_mail_dns_resolver']['value'], 'system' ); ?>><?php esc_html_e( 'System only (dns_get_record)', 'diluxone-mail' ); ?></option>
						<option value="doh" <?php selected( $diluxone_mail_f['diluxone_mail_dns_resolver']['value'], 'doh' ); ?>><?php esc_html_e( 'DNS-over-HTTPS only', 'diluxone-mail' ); ?></option>
					</select>
					<input type="url" class="regular-text code" name="diluxone_mail_dns_doh_endpoint" value="<?php echo esc_attr( (string) $diluxone_mail_f['diluxone_mail_dns_doh_endpoint']['value'] ); ?>" <?php wp_readonly( $diluxone_mail_f['diluxone_mail_dns_doh_endpoint']['readonly'] ); ?>>
					<p class="description"><?php esc_html_e( 'Many hosts disable dns_get_record(). The DoH endpoint must answer the JSON format; Cloudflare and Google both do.', 'diluxone-mail' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="diluxone_mail_dns_cache_hours"><?php esc_html_e( 'Cache results for', 'diluxone-mail' ); ?></label></th>
				<td>
					<input type="number" min="1" max="168" class="small-text" id="diluxone_mail_dns_cache_hours" name="diluxone_mail_dns_cache_hours" value="<?php echo esc_attr( (string) $diluxone_mail_f['diluxone_mail_dns_cache_hours']['value'] ); ?>" <?php wp_readonly( $diluxone_mail_f['diluxone_mail_dns_cache_hours']['readonly'] ); ?>> <?php esc_html_e( 'hours', 'diluxone-mail' ); ?>
				</td>
			</tr>
		</table>

		<?php if ( $data['editable'] ) : ?>
			<?php submit_button( __( 'Save options', 'diluxone-mail' ), 'secondary' ); ?>
		<?php endif; ?>
	</form>
</details>
