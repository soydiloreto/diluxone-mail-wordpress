<?php
/**
 * What the screens work out before painting, and what can be painted without
 * WordPress: provenances, notices, the status, the form data, the test send,
 * privacy and cron.
 */

namespace Tests\Unit\DiluxOneMail;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../includes/options.php';
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/providers.php';
require_once __DIR__ . '/../../../includes/observer.php';
require_once __DIR__ . '/../../../includes/pre-wp-mail.php';
require_once __DIR__ . '/../../../includes/mailer.php';
require_once __DIR__ . '/../../../includes/log.php';
require_once __DIR__ . '/../../../includes/log-hooks.php';
require_once __DIR__ . '/../../../includes/dns.php';
require_once __DIR__ . '/../../../includes/admin.php';
require_once __DIR__ . '/../../../includes/admin-settings.php';
require_once __DIR__ . '/../../../includes/admin-status.php';
require_once __DIR__ . '/../../../includes/test-send.php';
require_once __DIR__ . '/../../../includes/privacy.php';
require_once __DIR__ . '/../../../includes/cron.php';

class AdminTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_test_wp_options']      = array();
		$GLOBALS['_test_wp_site_options'] = array();
		$GLOBALS['_test_wp_transients']   = array();
		$GLOBALS['_test_multisite']       = false;
		$GLOBALS['_test_wp_mail_calls']   = array();
		$GLOBALS['_test_wp_mail_fails']   = '';
		$GLOBALS['_test_cron']            = array();
		$GLOBALS['wp_filter']             = array();
		$_GET                             = array();

		$GLOBALS['wpdb']->reset();
		\diluxone_mail_other_mailers( true );
		\diluxone_mail_current( null, true );

		foreach ( \diluxone_mail_config_fields() as $suffix ) {
			putenv( 'DILUXONE_MAIL_' . $suffix );
		}
	}

	public function test_the_provenance_is_explained_by_name(): void {
		$this->assertStringContainsString( 'DILUXONE_MAIL_HOST', \diluxone_mail_source_label( 'constant', 'DILUXONE_MAIL_HOST' ) );
		$this->assertStringContainsString( 'variable DILUXONE_MAIL_HOST', \diluxone_mail_source_label( 'env', 'DILUXONE_MAIL_HOST' ) );
		$this->assertSame( 'set by the network', \diluxone_mail_source_label( 'network', '' ) );
		$this->assertSame( 'set on this site', \diluxone_mail_source_label( 'site', '' ) );
		$this->assertSame( 'default value', \diluxone_mail_source_label( 'default', '' ) );
	}

	public function test_the_titles_and_the_urls(): void {
		$this->assertSame( 'DiluxOne Mail | Settings', \diluxone_mail_screen_title( 'Settings' ) );
		$this->assertStringContainsString( 'page=diluxone-mail-log', \diluxone_mail_admin_url( 'diluxone-mail-log', array( 'view' => 3 ) ) );
		$this->assertStringContainsString( 'view=3', \diluxone_mail_admin_url( 'diluxone-mail-log', array( 'view' => 3 ) ) );
		$this->assertSame( 'x', \diluxone_mail_admin_title( 'x', 'Settings' ) );
		$this->assertCount( 6, \diluxone_mail_screens() );
	}

	public function test_the_notices_for_each_action(): void {
		$_GET['diluxone_mail_done'] = 'saved';
		ob_start();
		\diluxone_mail_done_notice();
		$this->assertStringContainsString( 'Settings saved.', ob_get_clean() );

		$_GET['diluxone_mail_done'] = 'does-not-exist';
		ob_start();
		\diluxone_mail_done_notice();
		$this->assertSame( '', ob_get_clean() );

		ob_start();
		\diluxone_mail_notice( '<b>hola</b>', 'error' );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( '&lt;b&gt;', $html );
	}

	public function test_a_setting_forced_by_code_is_detected_and_named(): void {
		$this->assertFalse( \diluxone_mail_option_forced( 'diluxone_mail_mode' ) );

		$plugin = WP_PLUGIN_DIR . '/cst-core/cst-core.php';
		@mkdir( dirname( $plugin ), 0777, true );
		file_put_contents( $plugin, "<?php\nfunction cst_core_test_force( \$value, \$key ) { return 'diluxone_mail_mode' === \$key ? 'observe' : \$value; }\n" );
		require_once $plugin;

		\add_filter( 'diluxone_mail_option', 'cst_core_test_force', 10, 2 );

		$this->assertTrue( \diluxone_mail_option_forced( 'diluxone_mail_mode' ) );
		$this->assertStringContainsString( 'cst-core/cst-core.php', \diluxone_mail_option_forced_by()[0] );

		ob_start();
		\diluxone_mail_forced_notice( 'diluxone_mail_mode' );
		$this->assertStringContainsString( 'fixes this from code', ob_get_clean() );
	}

	public function test_a_screen_header_and_footer(): void {
		ob_start();
		\diluxone_mail_screen_open( 'Status' );
		\diluxone_mail_screen_close();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<h1>DiluxOne Mail | Status</h1>', $html );
		$this->assertStringEndsWith( '</div>', $html );
	}

	public function test_the_form_data_flags_what_the_environment_dictates(): void {
		putenv( 'DILUXONE_MAIL_HOST=smtp.environment.test' );
		\update_option( 'diluxone_mail_provider', 'sendgrid' );
		\update_option( 'diluxone_mail_pass', 'algo' );

		$d = \diluxone_mail_settings_data( 'site' );

		$this->assertTrue( $d['editable'] );
		$this->assertTrue( $d['fields']['diluxone_mail_host']['readonly'] );
		$this->assertSame( 'smtp.environment.test', $d['fields']['diluxone_mail_host']['value'] );
		$this->assertFalse( $d['fields']['diluxone_mail_port']['readonly'] );
		$this->assertSame( 'SendGrid', $d['profile']['name'] );
		$this->assertTrue( $d['has_password'] );
		$this->assertNull( $d['test'] );
	}

	public function test_on_a_network_without_permission_the_site_sees_everything_read_only(): void {
		$GLOBALS['_test_multisite'] = true;
		\update_site_option( 'diluxone_mail_host', 'smtp.red.test' );

		$d = \diluxone_mail_settings_data( 'site' );

		$this->assertFalse( $d['editable'] );
		$this->assertTrue( $d['fields']['diluxone_mail_host']['readonly'] );
		$this->assertSame( 'network', $d['fields']['diluxone_mail_host']['source'] );

		$red = \diluxone_mail_settings_data( 'network' );
		$this->assertTrue( $red['editable'] );
		$this->assertStringContainsString( 'network', $red['back_url'] );
	}

	public function test_the_status_says_who_sends_and_where_each_value_comes_from(): void {
		\update_option( 'diluxone_mail_mode', 'transport' );
		\update_option( 'diluxone_mail_pass', 'secreta' );
		$GLOBALS['wpdb']->next_results = array( array( 'status' => 'sent', 'n' => 2 ) );

		$s = \diluxone_mail_status();

		$this->assertTrue( $s['transport'] );
		$this->assertSame( '***', $s['config']['pass']['value'] );
		$this->assertSame( 'set on this site', $s['config']['pass']['label'] );
		$this->assertSame( array( 'sent' => 2 ), $s['totals'] );
		$this->assertNull( $s['last'] );
		$this->assertSame( 'local', $s['environment'] );
	}

	public function test_the_test_send_reports_what_happened(): void {
		\add_action( 'wp_mail_failed', 'diluxone_mail_on_failed' );
		\update_option( 'diluxone_mail_mode', 'transport' );
		\update_option( 'diluxone_mail_provider', 'mailjet' );
		\update_option( 'diluxone_mail_host', 'in-v3.mailjet.com' );

		$ok = \diluxone_mail_send_test( 'somebody@example.test' );
		$this->assertTrue( $ok['ok'] );
		$this->assertSame( 'somebody@example.test', $ok['to'] );
		$this->assertStringContainsString( 'in-v3.mailjet.com', $GLOBALS['_test_wp_mail_calls'][0]['message'] );

		$GLOBALS['_test_wp_mail_fails'] = '535 Authentication failed';
		$fail                           = \diluxone_mail_send_test( 'somebody@example.test' );
		$this->assertFalse( $fail['ok'] );
		$this->assertSame( '535 Authentication failed', $fail['error'] );

		$this->assertFalse( \diluxone_mail_send_test( 'no-es-correo' )['ok'] );
		$this->assertFalse( \diluxone_mail_debug_enabled() );
	}

	public function test_the_test_result_is_consumed_once(): void {
		$this->assertNull( \diluxone_mail_test_result_take() );

		\set_transient( 'diluxone_mail_test_1', array( 'ok' => true ), 60 );

		$this->assertSame( array( 'ok' => true ), \diluxone_mail_test_result_take() );
		$this->assertNull( \diluxone_mail_test_result_take() );
	}

	public function test_privacy_hooks_are_registered_only_when_the_log_is_on(): void {
		$this->assertArrayHasKey( 'diluxone-mail', \diluxone_mail_register_exporter( array() ) );
		$this->assertArrayHasKey( 'diluxone-mail', \diluxone_mail_register_eraser( array() ) );

		\update_option( 'diluxone_mail_privacy_export', 0 );
		\update_option( 'diluxone_mail_privacy_erase', 0 );

		$this->assertSame( array(), \diluxone_mail_register_exporter( array() ) );
		$this->assertSame( array(), \diluxone_mail_register_eraser( array() ) );
	}

	public function test_the_exporter_and_the_eraser(): void {
		$GLOBALS['wpdb']->next_results = array( array( 'id' => 1, 'sent_at' => '2026-01-01 00:00:00', 'subject' => 'Hello', 'from_email' => 'a@x.test', 'status' => 'sent' ) );

		$e = \diluxone_mail_export_personal_data( 'a@x.test' );
		$this->assertCount( 1, $e['data'] );
		$this->assertTrue( $e['done'] );
		$this->assertSame( 'Sent', $e['data'][0]['data'][3]['value'] );

		$GLOBALS['wpdb']->rows_affected = 0;
		$this->assertFalse( \diluxone_mail_erase_personal_data( 'a@x.test' )['items_removed'] );
	}

	public function test_cron_is_scheduled_once_and_cleared_on_deactivation(): void {
		\diluxone_mail_schedule_purge();
		$primero = $GLOBALS['_test_cron']['diluxone_mail_purge'];

		\diluxone_mail_schedule_purge();
		$this->assertSame( $primero, $GLOBALS['_test_cron']['diluxone_mail_purge'] );

		\diluxone_mail_run_purge();
		$this->assertNotSame( array(), $GLOBALS['wpdb']->of( 'query' ) );

		\diluxone_mail_deactivate();
		$this->assertArrayNotHasKey( 'diluxone_mail_purge', $GLOBALS['_test_cron'] );
	}
}
