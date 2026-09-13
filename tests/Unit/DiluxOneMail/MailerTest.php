<?php
/**
 * The sender and the SMTP dialogue buffer.
 */

namespace Tests\Unit\DiluxOneMail;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../includes/options.php';
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/providers.php';
require_once __DIR__ . '/../../../includes/observer.php';
require_once __DIR__ . '/../../../includes/mailer.php';

class MailerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_test_wp_options']      = array();
		$GLOBALS['_test_wp_site_options'] = array();
		$GLOBALS['_test_multisite']       = false;
		$GLOBALS['wp_filter']             = array();

		\diluxone_mail_other_mailers( true );
		\update_option( 'diluxone_mail_mode', 'transport' );
		\update_option( 'diluxone_mail_from', 'hello@example.test' );
		\update_option( 'diluxone_mail_from_name', 'Ejemplo' );
	}

	public function test_wordpresss_default_sender_is_replaced(): void {
		$this->assertSame( 'hello@example.test', \diluxone_mail_from( 'wordpress@localhost' ) );
		$this->assertSame( 'hello@example.test', \diluxone_mail_from( '' ) );
		$this->assertSame( \diluxone_mail_config()['from_name'], \diluxone_mail_from_name( 'WordPress' ) );
	}

	public function test_another_plugins_own_sender_is_respected(): void {
		$this->assertSame( 'ventas@shop.test', \diluxone_mail_from( 'ventas@shop.test' ) );
		$this->assertSame( 'Tienda', \diluxone_mail_from_name( 'Tienda' ) );
	}

	public function test_forcing_overrides_that_one_too(): void {
		\update_option( 'diluxone_mail_force_from', 1 );

		$this->assertSame( 'hello@example.test', \diluxone_mail_from( 'ventas@shop.test' ) );
		$this->assertSame( \diluxone_mail_config()['from_name'], \diluxone_mail_from_name( 'Tienda' ) );
	}

	public function test_in_observer_mode_nothing_is_touched(): void {
		\update_option( 'diluxone_mail_mode', 'observe' );

		$this->assertSame( 'wordpress@localhost', \diluxone_mail_from( 'wordpress@localhost' ) );
		$this->assertSame( 'WordPress', \diluxone_mail_from_name( 'WordPress' ) );
	}

	public function test_with_no_configured_or_an_invalid_sender_nothing_is_touched(): void {
		\update_option( 'diluxone_mail_from', '' );
		$this->assertSame( 'wordpress@localhost', \diluxone_mail_from( 'wordpress@localhost' ) );

		\update_option( 'diluxone_mail_from', 'no-es-una-direccion' );
		$this->assertSame( 'wordpress@localhost', \diluxone_mail_from( 'wordpress@localhost' ) );
	}

	public function test_the_name_only_changes_together_with_the_address(): void {
		\update_option( 'diluxone_mail_from', '' );

		$this->assertSame( 'WordPress', \diluxone_mail_from_name( 'WordPress' ) );
	}

	public function test_the_buffer_accumulates_and_is_emptied(): void {
		\diluxone_mail_debug_buffer( null, true );
		\diluxone_mail_debug_buffer( 'CLIENT -> SERVER: EHLO' );
		\diluxone_mail_debug_buffer( 'SERVER -> CLIENT: 250 OK  ' );

		$this->assertSame( "CLIENT -> SERVER: EHLO\nSERVER -> CLIENT: 250 OK\n", \diluxone_mail_debug_buffer() );

		\diluxone_mail_debug_buffer( null, true );
		$this->assertSame( '', \diluxone_mail_debug_buffer() );
	}

	public function test_capture_is_off_unless_somebody_turns_it_on(): void {
		\diluxone_mail_debug_enabled( false );
		$this->assertFalse( \diluxone_mail_debug_enabled() );

		\diluxone_mail_debug_enabled( true );
		$this->assertTrue( \diluxone_mail_debug_enabled() );

		\diluxone_mail_debug_enabled( false );
	}

	public function test_phpmailer_init_ignores_anything_that_is_not_a_phpmailer(): void {
		// With no PHPMailer in the tests, all that can be asserted is that an
		// arbitrary object is left alone and nothing breaks.
		$obj = new \stdClass();
		\diluxone_mail_phpmailer_init( $obj );

		$this->assertSame( array(), get_object_vars( $obj ) );
	}
}
