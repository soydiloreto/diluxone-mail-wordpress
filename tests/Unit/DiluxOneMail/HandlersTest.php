<?php
/**
 * The admin_post handlers: capability, nonce, what they save and where they go back to.
 */

namespace Tests\Unit\DiluxOneMail;

class HandlersTest extends AdminTestCase {

	public function test_applying_a_profile_fills_in_and_returns(): void {
		$_POST = array( 'scope' => 'site', 'diluxone_mail_provider' => 'sendgrid' );

		$url = $this->redirect_of( 'diluxone_mail_apply_provider' );

		$this->assertStringContainsString( 'profile-applied', $url );
		$this->assertSame( 'smtp.sendgrid.net', \diluxone_mail_option( 'diluxone_mail_host' ) );
		$this->assertSame( 'apikey', \diluxone_mail_option( 'diluxone_mail_user' ) );
	}

	public function test_a_tab_saves_its_own_settings_and_leaves_the_others_alone(): void {
		\update_option( 'diluxone_mail_mode', 'transport' );
		\update_option( 'diluxone_mail_privacy_erase', 1 );

		$_POST = array(
			'scope'                     => 'site',
			'tab'                       => 'logging',
			'diluxone_mail_log_enabled' => '1',
			// Not on this tab: it must survive untouched even though an
			// unchecked box and an absent one look the same in a POST.
			'diluxone_mail_mode'        => 'observe',
		);

		$url = $this->redirect_of( 'diluxone_mail_save_settings' );

		$this->assertStringContainsString( 'diluxone_mail_done=saved', $url );
		$this->assertStringContainsString( 'tab=logging', $url );
		$this->assertSame( 1, \get_option( 'diluxone_mail_log_enabled' ) );
		// Checkboxes of this tab that did not travel end up at 0.
		$this->assertSame( 0, \get_option( 'diluxone_mail_log_extended' ) );
		$this->assertSame( 0, \get_option( 'diluxone_mail_privacy_erase' ) );
		// A setting of another tab is not touched, neither by the value it
		// carried nor by being absent.
		$this->assertSame( 'transport', \get_option( 'diluxone_mail_mode' ) );
	}

	public function test_the_transport_is_never_written_by_a_plain_save(): void {
		$_POST = array(
			'scope'              => 'site',
			'tab'                => 'server',
			'diluxone_mail_host' => 'smtp.sneaky.test',
			'diluxone_mail_pass' => 'p@ss "rara"',
		);

		$this->redirect_of( 'diluxone_mail_save_settings' );

		$this->assertSame( array(), \diluxone_mail_connections() );
		$this->assertFalse( \diluxone_mail_connection_verified() );
	}

	public function test_the_dns_options_save_from_the_deliverability_screen(): void {
		$_POST = array(
			'scope'                       => 'site',
			'tab'                         => 'dns',
			'diluxone_mail_dns_selectors' => 'uno, dos  tres',
			'diluxone_mail_dns_domain'    => 'x.test',
		);

		$url = $this->redirect_of( 'diluxone_mail_save_settings' );

		$this->assertStringContainsString( 'page=diluxone-mail-dns', $url );
		$this->assertSame( array( 'uno', 'dos', 'tres' ), \get_option( 'diluxone_mail_dns_selectors' ) );
		$this->assertSame( 'x.test', \get_option( 'diluxone_mail_dns_domain' ) );
	}

	public function test_saving_on_the_network_includes_the_per_site_permission(): void {
		$GLOBALS['_test_multisite'] = true;
		$_POST                      = array( 'scope' => 'network', 'tab' => 'sites', 'diluxone_mail_network_allow_override' => '1' );

		$url = $this->redirect_of( 'diluxone_mail_save_settings' );

		$this->assertStringContainsString( 'network', $url );
		$this->assertSame( 1, \get_site_option( 'diluxone_mail_network_allow_override' ) );
	}

	public function test_the_connection_test_is_what_saves_the_transport(): void {
		\PHPMailer\PHPMailer\PHPMailer::$connects = true;
		\update_option( 'diluxone_mail_provider', 'mailjet' );

		$_POST = array(
			'scope'                    => 'site',
			'tab'                      => 'server',
			'diluxone_mail_host'       => 'smtp.x.test',
			'diluxone_mail_port'       => '2525',
			'diluxone_mail_encryption' => 'tls',
			'diluxone_mail_auth'       => '1',
			'diluxone_mail_user'       => 'apikey',
			'diluxone_mail_pass'       => 'p@ss "rara"',
			'diluxone_mail_timeout'    => '30',
		);

		$url = $this->redirect_of( 'diluxone_mail_connection_action' );

		$this->assertStringContainsString( 'diluxone_mail_done=connected', $url );
		// It moves on to the next step by itself.
		$this->assertStringContainsString( 'tab=sender', $url );
		$this->assertSame( 'smtp.x.test', \diluxone_mail_option( 'diluxone_mail_host' ) );
		$this->assertSame( 2525, \diluxone_mail_option( 'diluxone_mail_port' ) );
		// The password comes back as it was typed, quotes and all — but not
		// from the column: what is in there is ciphertext.
		$this->assertSame( 'p@ss "rara"', \diluxone_mail_config_value( 'pass' )['value'] );
		$this->assertStringNotContainsString( 'p@ss', (string) \diluxone_mail_connection( \diluxone_mail_default_id() )['diluxone_mail_pass'] );
		$this->assertTrue( \diluxone_mail_is_encrypted( (string) \diluxone_mail_connection( \diluxone_mail_default_id() )['diluxone_mail_pass'] ) );
		$this->assertTrue( \diluxone_mail_connection_verified() );
	}

	public function test_a_connection_that_fails_stores_nothing(): void {
		\PHPMailer\PHPMailer\PHPMailer::$connects = '535 Authentication failed';
		\update_option( 'diluxone_mail_provider', 'mailjet' );

		$_POST = array(
			'scope'              => 'site',
			'tab'                => 'server',
			'diluxone_mail_host' => 'smtp.x.test',
			'diluxone_mail_port' => '2525',
			'diluxone_mail_user' => 'apikey',
			'diluxone_mail_pass' => 'wrong',
		);

		$url = $this->redirect_of( 'diluxone_mail_connection_action' );

		$this->assertStringContainsString( 'connection-failed', $url );
		$this->assertStringContainsString( 'tab=server', $url );
		$this->assertSame( array(), \diluxone_mail_connections() );
		$this->assertFalse( \diluxone_mail_connection_verified() );

		// What was typed comes back; the password does not travel with it.
		$attempt = \get_transient( 'diluxone_mail_attempt_1' );
		$this->assertSame( 'smtp.x.test', $attempt['fields']['diluxone_mail_host'] );
		$this->assertArrayNotHasKey( 'diluxone_mail_pass', $attempt['fields'] );
		$this->assertStringContainsString( '535', $attempt['error'] );
	}

	public function test_an_empty_password_keeps_the_stored_one_and_the_environment_is_left_alone(): void {
		\PHPMailer\PHPMailer\PHPMailer::$connects = true;
		$this->conexion( array( 'diluxone_mail_pass' => \diluxone_mail_encrypt( 'vieja' ) ) );

		$_POST = array( 'scope' => 'site', 'tab' => 'server', 'diluxone_mail_host' => 'smtp.x.test', 'diluxone_mail_pass' => '' );
		$this->redirect_of( 'diluxone_mail_connection_action' );
		$this->assertSame( 'vieja', \diluxone_mail_config_value( 'pass' )['value'] );

		putenv( 'DILUXONE_MAIL_PASS=del-entorno' );
		$_POST = array( 'scope' => 'site', 'tab' => 'server', 'diluxone_mail_host' => 'smtp.x.test', 'diluxone_mail_pass' => 'attempt' );
		$this->redirect_of( 'diluxone_mail_connection_action' );

		putenv( 'DILUXONE_MAIL_PASS' );
		$this->assertSame( 'vieja', \diluxone_mail_config_value( 'pass' )['value'] );
	}

	public function test_a_site_without_permission_on_the_network_does_not_save(): void {
		$GLOBALS['_test_multisite'] = true;
		$_POST = array( 'scope' => 'site', 'diluxone_mail_host' => 'smtp.site.test' );

		$this->assertStringContainsString( 'not-allowed', $this->redirect_of( 'diluxone_mail_save_settings' ) );
		$this->assertStringContainsString( 'not-allowed', $this->redirect_of( 'diluxone_mail_apply_provider' ) );
		$this->assertSame( '', (string) \diluxone_mail_option( 'diluxone_mail_host' ) );
	}

	public function test_without_capability_it_stops(): void {
		$GLOBALS['_test_can'] = false;
		$_POST                = array( 'scope' => 'site' );

		$this->expectException( \DiluxOne_Test_Die::class );
		\diluxone_mail_save_settings();
	}

	public function test_without_a_nonce_it_stops(): void {
		$GLOBALS['_test_nonce_fails'] = true;

		$this->expectException( \DiluxOne_Test_Die::class );
		\diluxone_mail_apply_provider();
	}

	public function test_the_test_send_stores_the_result_for_the_screen(): void {
		$_POST = array( 'scope' => 'site', 'diluxone_mail_test_to' => '' );

		$url = $this->redirect_of( 'diluxone_mail_test_action' );

		// The step shows the result itself, so the redirect carries no headline.
		$this->assertStringContainsString( 'tab=test', $url );
		$this->assertStringNotContainsString( 'diluxone_mail_done', $url );
		$r = \get_transient( 'diluxone_mail_test_1' );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 'admin@example.test', $r['to'] );
	}

	public function test_taking_over(): void {
		$url = $this->redirect_of( 'diluxone_mail_take_over' );

		$this->assertStringContainsString( 'took-over', $url );
		$this->assertSame( 'transport', \get_option( 'diluxone_mail_mode' ) );

		$GLOBALS['_test_can'] = false;
		$this->expectException( \DiluxOne_Test_Die::class );
		\diluxone_mail_take_over();
	}

	public function test_revalidating_the_dns(): void {
		\update_option( 'diluxone_mail_from', 'hello@x.test' );
		\set_site_transient( 'diluxone_mail_diagnosis_' . md5( 'x.test' ), array( 'cached' => true ) );

		$url = $this->redirect_of( 'diluxone_mail_revalidate' );

		$this->assertStringContainsString( 'revalidated', $url );
		$this->assertFalse( \get_site_transient( 'diluxone_mail_diagnosis_' . md5( 'x.test' ) )['cached'] ?? false );

		$GLOBALS['_test_can'] = false;
		$this->expectException( \DiluxOne_Test_Die::class );
		\diluxone_mail_revalidate();
	}
}
