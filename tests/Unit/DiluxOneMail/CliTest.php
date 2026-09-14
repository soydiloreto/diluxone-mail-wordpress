<?php
/**
 * The WP-CLI commands against the fake WP-CLI.
 */

namespace Tests\Unit\DiluxOneMail;

class CliTest extends AdminTestCase {

	private \DiluxOne_Mail_CLI $cli;

	protected function setUp(): void {
		parent::setUp();

		$this->cli = new \DiluxOne_Mail_CLI();
		\update_option( 'diluxone_mail_mode', 'transport' );
		\update_option( 'diluxone_mail_provider', 'mailjet' );
		\update_option( 'diluxone_mail_host', 'in-v3.mailjet.com' );
	}

	public function test_the_command_is_registered(): void {
		$this->assertSame( 'DiluxOne_Mail_CLI', \WP_CLI::$commands['diluxone-mail'] );
	}

	public function test_test_sends_and_reports(): void {
		$this->cli->test( array( 'a@x.test' ) );

		$this->assertStringContainsString( 'Success: Sent to a@x.test', implode( "\n", \WP_CLI::$out ) );

		$GLOBALS['_test_wp_mail_fails'] = '535 nope';
		\add_action( 'wp_mail_failed', 'diluxone_mail_on_failed' );

		$this->expectException( \DiluxOne_Test_CLI_Error::class );
		$this->expectExceptionMessage( '535 nope' );
		$this->cli->test( array( 'a@x.test' ) );
	}

	public function test_test_with_an_invalid_address(): void {
		$this->expectException( \DiluxOne_Test_CLI_Error::class );
		$this->cli->test( array( 'nothing' ) );
	}

	public function test_status(): void {
		\update_option( 'diluxone_mail_last_result', array( 'ok' => 0, 'time' => time(), 'error' => 'boom', 'provider' => 'mailjet' ) );

		$this->cli->status( array(), array( 'format' => 'json' ) );

		$rows = array_column( $GLOBALS['_test_cli_items'][0]['items'], 'value', 'key' );
		$this->assertStringStartsWith( 'transport', $rows['mode'] );
		$this->assertStringContainsString( 'in-v3.mailjet.com', $rows['host'] );
		$this->assertStringContainsString( 'failed: boom', $rows['last send'] );
	}

	public function test_dns_as_a_table_and_as_json(): void {
		\update_option( 'diluxone_mail_from', 'hello@x.test' );

		$this->cli->dns( array(), array() );
		$this->assertStringContainsString( 'x.test — SPF: 0/10 lookups', \WP_CLI::$out[0] );
		$this->assertStringContainsString( '[ERROR]', implode( "\n", \WP_CLI::$out ) );

		\WP_CLI::reset();
		$this->cli->dns( array( 'other.test' ), array( 'format' => 'json', 'fresh' => true ) );
		$this->assertSame( 'other.test', json_decode( \WP_CLI::$out[0], true )['domain'] );
	}

	public function test_dns_with_no_domain(): void {
		\add_filter( 'diluxone_mail_option', static fn( $v, $k ) => 'diluxone_mail_dns_domain' === $k ? '' : $v, 10, 2 );

		\add_filter( 'diluxone_mail_config', static fn( array $c ) => array_merge( $c, array( 'from' => '' ) ) );

		// With the domain emptied by the filter and no sender, the site's own is
		// left; to force "no domain" an empty one is passed and the command
		// discards it.
		$this->expectException( \DiluxOne_Test_CLI_Error::class );
		$this->cli->dns( array( ' ' ), array() );
	}

	public function test_log_list(): void {
		$this->db->next_results = array( $this->row() );

		$this->cli->log( array( 'list' ), array( 'email' => 'ana@x.test', 'status' => 'sent', 'limit' => '5', 'format' => 'json' ) );

		$item = $GLOBALS['_test_cli_items'][0]['items'][0];
		$this->assertSame( 'ana@x.test', $item['to'] );
		$this->assertSame( 'plugin:shop', $item['source'] );
		$this->assertStringContainsString( 'LIMIT 5', $this->db->of( 'get_results' )[0]['sql'] );

		// A site with a fallback configured answers "it went out" and "it went
		// out through the second one" differently, and the terminal had no way
		// of asking the second question.
		$this->assertSame( 'Mailjet', $item['carrier'] );
	}

	public function test_log_without_list(): void {
		$this->expectException( \DiluxOne_Test_CLI_Error::class );
		$this->cli->log( array( 'purge' ), array() );
	}
}
