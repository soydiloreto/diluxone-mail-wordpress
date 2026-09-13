<?php
/**
 * What the transport configures on PHPMailer, using a fake PHPMailer.
 */

namespace Tests\Unit\DiluxOneMail;

use PHPMailer\PHPMailer\PHPMailer;

class PhpMailerTest extends AdminTestCase {

	public function test_a_remote_provider_with_auth_and_starttls(): void {
		\update_option( 'diluxone_mail_mode', 'transport' );
		\update_option( 'diluxone_mail_provider', 'mailjet' );
		\update_option( 'diluxone_mail_host', 'in-v3.mailjet.com' );
		\update_option( 'diluxone_mail_port', 587 );
		\update_option( 'diluxone_mail_encryption', 'tls' );
		\update_option( 'diluxone_mail_user', 'key' );
		\update_option( 'diluxone_mail_pass', 'secret' );
		\update_option( 'diluxone_mail_timeout', 12 );

		$m = new PHPMailer();
		\diluxone_mail_phpmailer_init( $m );

		$this->assertSame( 'smtp', $m->Mailer );
		$this->assertSame( 'in-v3.mailjet.com', $m->Host );
		$this->assertSame( 587, $m->Port );
		$this->assertSame( 'tls', $m->SMTPSecure );
		$this->assertTrue( $m->SMTPAutoTLS );
		$this->assertTrue( $m->SMTPAuth );
		$this->assertSame( 'key', $m->Username );
		$this->assertSame( 'secret', $m->Password );
		$this->assertSame( 12, $m->Timeout );
		$this->assertSame( 0, $m->SMTPDebug );
	}

	public function test_a_local_profile_turns_off_auth_and_autotls(): void {
		\update_option( 'diluxone_mail_mode', 'transport' );
		\update_option( 'diluxone_mail_provider', 'mailpit' );
		\update_option( 'diluxone_mail_host', 'mailpit' );
		\update_option( 'diluxone_mail_port', 0 );
		\update_option( 'diluxone_mail_encryption', 'none' );
		\update_option( 'diluxone_mail_user', 'ignorado' );

		$m = new PHPMailer();
		\diluxone_mail_phpmailer_init( $m );

		$this->assertSame( '', $m->SMTPSecure );
		$this->assertFalse( $m->SMTPAutoTLS );
		$this->assertFalse( $m->SMTPAuth );
		$this->assertSame( 587, $m->Port );
	}

	public function test_in_observer_mode_or_without_a_host_nothing_is_touched_but_debug_is(): void {
		\update_option( 'diluxone_mail_mode', 'observe' );
		\diluxone_mail_debug_enabled( true );

		$m = new PHPMailer();
		\diluxone_mail_phpmailer_init( $m );

		$this->assertSame( 'mail', $m->Mailer );
		$this->assertSame( 2, $m->SMTPDebug );
		( $m->Debugoutput )( 'SERVER -> CLIENT: 220', 2 );
		$this->assertStringContainsString( '220', \diluxone_mail_debug_buffer() );

		\diluxone_mail_debug_enabled( false );
		\update_option( 'diluxone_mail_mode', 'transport' );
		$m = new PHPMailer();
		\diluxone_mail_phpmailer_init( $m );
		$this->assertSame( 'mail', $m->Mailer );
	}

	public function test_the_message_id_and_the_last_reply(): void {
		\diluxone_mail_current( array( 'uuid' => 'abc-123' ) );

		$m = new PHPMailer();
		\diluxone_mail_stamp_message_id( $m );
		$this->assertSame( '<abc-123@' . parse_url( \home_url(), PHP_URL_HOST ) . '>', $m->MessageID );

		$GLOBALS['phpmailer'] = $m;
		$this->assertSame( '250 OK queued as test-id', \diluxone_mail_last_smtp_reply() );
		unset( $GLOBALS['phpmailer'] );
		\diluxone_mail_current( null, true );
	}

	public function test_a_complete_send_stores_the_servers_reply(): void {
		\update_option( 'diluxone_mail_mode', 'transport' );
		\update_option( 'diluxone_mail_provider', 'mailjet' );
		\update_option( 'diluxone_mail_host', 'in-v3.mailjet.com' );
		\add_filter( 'wp_mail', 'diluxone_mail_capture', PHP_INT_MAX );
		\add_action( 'wp_mail_succeeded', 'diluxone_mail_on_succeeded' );
		$GLOBALS['phpmailer'] = new PHPMailer();

		\wp_mail( 'a@x.test', 'Hello', 'Body' );

		$this->assertSame( '250 OK queued as test-id', $this->db->of( 'update' )[0]['args']['data']['response'] );
		unset( $GLOBALS['phpmailer'] );
	}
}
