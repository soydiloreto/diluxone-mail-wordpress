<?php
/**
 * The whole send, from wp_mail() to the log, using the fake wp_mail() that
 * reproduces WordPress's hook sequence.
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

class LogHooksTest extends TestCase {

	private \DiluxOne_Test_WPDB $db;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_test_wp_options']      = array();
		$GLOBALS['_test_wp_site_options'] = array();
		$GLOBALS['_test_multisite']       = false;
		$GLOBALS['_test_wp_mail_calls']   = array();
		$GLOBALS['_test_wp_mail_fails']   = '';
		$GLOBALS['wp_filter']             = array();

		$this->db = $GLOBALS['wpdb'];
		$this->db->reset();

		// The plugin's hooks, as it registers them on load.
		\add_filter( 'wp_mail', 'diluxone_mail_capture', PHP_INT_MAX );
		\add_filter( 'pre_wp_mail', 'diluxone_mail_maybe_suppress', 1, 2 );
		\add_filter( 'pre_wp_mail', 'diluxone_mail_watch_pre_wp_mail', PHP_INT_MAX, 2 );
		\add_action( 'wp_mail_succeeded', 'diluxone_mail_on_succeeded' );
		\add_action( 'wp_mail_failed', 'diluxone_mail_on_failed' );

		\diluxone_mail_other_mailers( true );
		\update_option( 'diluxone_mail_mode', 'transport' );
		\update_option( 'diluxone_mail_provider', 'mailjet' );
		\update_option( 'diluxone_mail_from', 'hello@example.test' );
		\diluxone_mail_current( null, true );
	}

	public function test_a_successful_send_is_recorded_as_sent(): void {
		$ok = \wp_mail( 'a@x.test', 'Hello', 'Body', array( 'Cc: b@x.test' ) );

		$this->assertTrue( $ok );
		$this->assertCount( 2, $this->db->of( 'insert' ) );
		$this->assertSame( 'mailjet', $this->db->of( 'insert' )[0]['args']['provider'] );
		$this->assertSame( 'hello@example.test', $this->db->of( 'insert' )[0]['args']['from_email'] );
		$this->assertSame( 'sent', $this->db->of( 'update' )[0]['args']['data']['status'] );
		$this->assertNull( \diluxone_mail_current() );
		$this->assertSame( 1, \get_option( 'diluxone_mail_last_result' )['ok'] );
	}

	public function test_a_failed_send_stores_the_error(): void {
		$GLOBALS['_test_wp_mail_fails'] = 'SMTP Error: Could not connect';

		$this->assertFalse( \wp_mail( 'a@x.test', 'Hello', 'Body' ) );
		$this->assertSame( 'failed', $this->db->of( 'update' )[0]['args']['data']['status'] );
		$this->assertStringContainsString( 'Could not connect', $this->db->of( 'update' )[0]['args']['data']['error'] );
		$this->assertSame( 0, \get_option( 'diluxone_mail_last_result' )['ok'] );
	}

	public function test_should_send_suppresses_and_records_it(): void {
		\add_filter( 'diluxone_mail_should_send', static fn(): bool => false );

		$this->assertFalse( \wp_mail( 'a@x.test', 'Hello', 'Body' ) );
		$this->assertSame( array(), $GLOBALS['_test_wp_mail_calls'] );
		$this->assertSame( 'suppressed', $this->db->of( 'update' )[0]['args']['data']['status'] );
	}

	public function test_another_plugin_short_circuiting_pre_wp_mail_is_recorded_as_intercepted(): void {
		\add_filter( 'pre_wp_mail', static fn( $pre ) => true, 10 );

		$this->assertTrue( \wp_mail( 'a@x.test', 'Hello', 'Body' ) );
		$this->assertSame( array(), $GLOBALS['_test_wp_mail_calls'] );
		$this->assertSame( 'intercepted', $this->db->of( 'update' )[0]['args']['data']['status'] );
	}

	public function test_the_atts_filter_transforms_the_message(): void {
		\add_filter( 'diluxone_mail_atts', static function ( array $a ): array { $a['subject'] = '[Brand] ' . $a['subject']; return $a; } );

		\wp_mail( 'a@x.test', 'Hello', 'Body' );

		$this->assertSame( '[Brand] Hello', $GLOBALS['_test_wp_mail_calls'][0]['subject'] );
		$this->assertSame( '[Brand] Hello', $this->db->of( 'insert' )[0]['args']['subject'] );
	}

	public function test_with_the_log_off_nothing_is_written_but_the_filter_still_runs(): void {
		\update_option( 'diluxone_mail_log_enabled', 0 );

		\wp_mail( 'a@x.test', 'Hello', 'Body' );

		$this->assertSame( array(), $this->db->of( 'insert' ) );
		$this->assertCount( 1, $GLOBALS['_test_wp_mail_calls'] );
	}

	public function test_extended_stores_headers_and_the_dialogue_and_the_body_when_asked(): void {
		\update_option( 'diluxone_mail_log_extended', 1 );
		\update_option( 'diluxone_mail_log_body', 1 );

		\wp_mail( 'a@x.test', 'Hello', 'Body', array( 'X-Test: 1' ), array( '/tmp/attachment.pdf' ) );

		$i = $this->db->of( 'insert' )[0]['args'];
		$this->assertStringContainsString( 'X-Test', $i['headers'] );
		$this->assertStringContainsString( 'attachment.pdf', $i['attachments'] );

		$replaces = $this->db->of( 'replace' );
		$this->assertSame( 'Body', $replaces[0]['args']['body'] );
		$this->assertCount( 2, $replaces );
	}

	public function test_with_no_valid_recipients_nothing_is_recorded(): void {
		\wp_mail( 'not-an-address', 'Hello', 'Body' );

		$this->assertSame( array(), $this->db->of( 'insert' ) );
	}

	public function test_in_observer_mode_the_provider_is_observer(): void {
		\update_option( 'diluxone_mail_mode', 'observe' );

		\wp_mail( 'a@x.test', 'Hello', 'Body' );

		$this->assertSame( 'observer', $this->db->of( 'insert' )[0]['args']['provider'] );
	}

	public function test_who_called(): void {
		$this->assertSame( 'core', \diluxone_mail_caller() );

		$plugin = WP_PLUGIN_DIR . '/shop/shop.php';
		@mkdir( dirname( $plugin ), 0777, true );
		file_put_contents( $plugin, "<?php\nfunction shop_test_caller() { return diluxone_mail_caller(); }\n" );
		require_once $plugin;

		$this->assertSame( 'plugin:shop', \shop_test_caller() );
	}

	public function test_the_slot_of_the_send_in_flight(): void {
		\diluxone_mail_current( array( 'uuid' => 'x' ) );
		$this->assertSame( 'x', \diluxone_mail_current()['uuid'] );

		\diluxone_mail_current( null, true );
		$this->assertNull( \diluxone_mail_current() );
	}

	public function test_without_phpmailer_there_is_no_last_reply_and_no_message_id(): void {
		$this->assertSame( '', \diluxone_mail_last_smtp_reply() );

		$obj = new \stdClass();
		\diluxone_mail_stamp_message_id( $obj );
		$this->assertSame( array(), get_object_vars( $obj ) );
	}
}
