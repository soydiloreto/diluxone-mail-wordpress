<?php
/**
 * The four steps, over the list.
 *
 * A dialog rather than a screen: what is being set up is one row of what is
 * behind it, and leaving it puts you back on the list rather than somewhere
 * else. The way out is a link, so it works with the script and without it, and
 * it says Close rather than showing a cross — every other button in here saves
 * something and stays, and the one that leaves should not be read as a third
 * kind of save.
 *
 * `aria-modal` is a promise rather than a decoration: a screen reader stops
 * announcing what is behind, so the focus has to stay in here or somebody
 * tabs into a page they can no longer be told about. The panel carries
 * tabindex="-1" so the script can put the focus inside on open; the trapping
 * and Escape live with it in the admin script.
 *
 * @package DiluxOneMail
 * @var array<string, mixed> $data
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="diluxone-mail-backdrop">
	<div class="diluxone-mail-panel" role="dialog" aria-modal="true" tabindex="-1" aria-label="<?php esc_attr_e( 'Set up a provider', 'diluxone-mail' ); ?>">
		<div class="diluxone-mail-panel-head">
			<h2><?php esc_html_e( 'Set up a provider', 'diluxone-mail' ); ?></h2>
			<a class="button diluxone-mail-panel-close" href="<?php echo esc_url( '' === diluxone_mail_editing_id() ? (string) $data['list_url'] : add_query_arg( 'closed', diluxone_mail_editing_id(), (string) $data['list_url'] ) ); ?>">
				<?php esc_html_e( 'Close', 'diluxone-mail' ); ?>
			</a>
		</div>

		<div class="diluxone-mail-panel-body">
			<?php diluxone_mail_screen_notices(); ?>
			<?php diluxone_mail_view( 'admin-settings', $data ); ?>
		</div>
	</div>
</div>
