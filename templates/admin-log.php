<?php
/**
 * La vista del historial global.
 *
 * @package DiluxOneMail
 * @var array<string, mixed> $data
 */

defined( 'ABSPATH' ) || exit;

if ( ! $data['log_enabled'] ) :
	?>
	<div class="notice notice-warning"><p><?php esc_html_e( 'The mail log is off. Turn it on in the settings to start recording.', 'diluxone-mail' ); ?></p></div>
	<?php
endif;
?>
<form method="get">
	<input type="hidden" name="page" value="diluxone-mail-log">
	<?php $data['table']->search_box( __( 'Search subject or address', 'diluxone-mail' ), 'diluxone-mail' ); ?>
	<?php $data['table']->display(); ?>
</form>
