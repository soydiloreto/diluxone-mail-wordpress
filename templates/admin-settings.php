<?php
/**
 * The settings screen: the row of tabs, and whichever tab is being shown.
 *
 * Everything that used to be one long form now lives in templates/settings-*,
 * one per tab. This file is only the frame: the tabs, the notice that applies
 * to the whole screen, and the include.
 *
 * @package DiluxOneMail
 * @var array<string, mixed> $data
 */

defined( 'ABSPATH' ) || exit;

diluxone_mail_tabs_nav( (string) $data['tab'], (string) $data['scope'], (string) $data['screen'] );
?>

<?php if ( 'site' === $data['scope'] && ! $data['editable'] ) : ?>
	<div class="notice notice-info inline">
		<p><?php esc_html_e( 'These settings are fixed by the network. You can see them here; changing them is done from the network settings.', 'diluxone-mail' ); ?></p>
	</div>
<?php endif; ?>

<div class="diluxone-mail-tab-body">
	<?php diluxone_mail_view( 'settings-' . (string) $data['tab'], $data ); ?>
</div>
