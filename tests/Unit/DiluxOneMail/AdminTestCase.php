<?php
/**
 * Base for the screen and handler tests: loads the whole plugin the way the
 * main file loads it, and leaves clean state between tests.
 */

namespace Tests\Unit\DiluxOneMail;

use PHPUnit\Framework\TestCase;

foreach ( (array) glob( dirname( __DIR__, 3 ) . '/includes/*.php' ) as $diluxone_mail_test_file ) {
	require_once (string) $diluxone_mail_test_file;
}

abstract class AdminTestCase extends TestCase {

	protected \DiluxOne_Test_WPDB $db;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_test_wp_options']         = array();
		$GLOBALS['_test_wp_site_options']    = array();
		$GLOBALS['_test_wp_site_transients'] = array();
		$GLOBALS['_test_wp_transients']      = array();
		$GLOBALS['_test_multisite']          = false;
		$GLOBALS['_test_wp_mail_calls']      = array();
		$GLOBALS['_test_wp_mail_fails']      = '';
		$GLOBALS['_test_nonce_fails']        = false;
		$GLOBALS['_test_can']                = true;
		$GLOBALS['_test_users']              = array();
		$GLOBALS['_test_sites']              = array();
		$GLOBALS['_test_screen']             = null;
		$GLOBALS['_test_referer']            = false;
		$GLOBALS['_test_cli_items']           = array();
		$GLOBALS['wp_filter']                = array();
		$_GET                                = array();
		$_POST                               = array();

		\WP_CLI::reset();

		$this->db = $GLOBALS['wpdb'];
		$this->db->reset();

		\diluxone_mail_other_mailers( true );
		\diluxone_mail_current( null, true );
		\diluxone_mail_debug_enabled( false );
		\update_option( 'diluxone_mail_dns_resolver', 'doh' );

		foreach ( \diluxone_mail_config_fields() as $suffix ) {
			putenv( 'DILUXONE_MAIL_' . $suffix );
		}
	}

	protected function tearDown(): void {
		$GLOBALS['_test_multisite'] = false;
		$GLOBALS['_test_can']       = true;
		$GLOBALS['_test_blog_id']   = 1;

		parent::tearDown();
	}

	/** A person, using the stubs' WP_User. */
	protected function user( int $id, string $email ): \WP_User {
		$u             = ( new \ReflectionClass( \WP_User::class ) )->newInstanceWithoutConstructor();
		$u->ID         = $id;
		$u->user_email = $email;
		$u->user_login = 'u' . $id;

		return $u;
	}

	/**
	 * Walks the four steps of the settings screen without going through them.
	 *
	 * A screen test that wants to look at the last tab should not have to
	 * drive the first three, and the marks are the stored ones on purpose:
	 * this sets exactly what a real run through the wizard would leave.
	 */
	protected function configured( string $provider = 'mailjet' ): void {
		\update_option( 'diluxone_mail_provider', $provider );
		\update_option( 'diluxone_mail_host', 'smtp.' . $provider . '.test' );
		\update_option( 'diluxone_mail_port', 587 );
		\update_option( 'diluxone_mail_from', 'hello@x.test' );
		\update_option( 'diluxone_mail_from_name', 'X' );
		\diluxone_mail_verified( 'connection' );
	}

	/** Runs a handler that ends in a redirect and returns where to. */
	protected function redirect_of( callable $handler ): string {
		try {
			$handler();
		} catch ( \DiluxOne_Test_Redirect $e ) {
			return $e->getMessage();
		}

		$this->fail( 'the handler did not redirect' );
	}

	/** Captures what a screen prints. */
	protected function render( callable $screen ): string {
		ob_start();
		$screen();

		return (string) ob_get_clean();
	}

	/** A log row as the database would return it. */
	protected function row( array $extra = array() ): array {
		return array_merge(
			array(
				'id'          => 7,
				'site_id'     => 1,
				'sent_at'     => '2026-09-13 10:00:00',
				'email'       => 'ana@x.test',
				'kind'        => 'to',
				'from_email'  => 'hello@x.test',
				'subject'     => 'Hello',
				'status'      => 'sent',
				'error'       => '',
				'response'    => '250 OK',
				'provider'    => 'mailjet',
				'source'      => 'plugin:shop',
				'headers'     => '["X-Test: 1"]',
				'attachments' => '["a.pdf"]',
				'message_id'  => 'uuid-7',
				// The fake $wpdb returns the same row for the detail.
				'transcript'  => '',
			),
			$extra
		);
	}
}
