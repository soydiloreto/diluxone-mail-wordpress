<?php
/**
 * The provider profiles: that none of them is half written.
 */

namespace Tests\Unit\DiluxOneMail;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../includes/options.php';
require_once __DIR__ . '/../../../includes/providers.php';

class ProvidersTest extends TestCase {

	public function test_every_profile_has_the_same_keys(): void {
		$keys = array( 'name', 'group', 'host', 'port', 'encryption', 'auth', 'autotls', 'user', 'user_hint', 'pass_hint', 'host_editable', 'local', 'dkim_selectors', 'spf_includes', 'return_path', 'docs' );

		foreach ( \diluxone_mail_providers() as $key => $profile ) {
			$this->assertSame( $keys, array_keys( $profile ), "profile {$key} does not have the expected keys" );
			$this->assertContains( $profile['encryption'], array( 'none', 'tls', 'ssl' ), $key );
			$this->assertIsInt( $profile['port'] );
		}
	}

	/**
	 * Without turning autoTLS off, PHPMailer attempts STARTTLS against Mailpit
	 * and the send fails. It is the most common mistake in a local
	 * environment.
	 */
	public function test_local_profiles_neither_authenticate_nor_attempt_tls(): void {
		foreach ( array( 'mailpit', 'mailhog' ) as $local ) {
			$p = \diluxone_mail_provider( $local );

			$this->assertTrue( $p['local'] );
			$this->assertFalse( $p['auth'] );
			$this->assertFalse( $p['autotls'] );
			$this->assertSame( 'none', $p['encryption'] );
			$this->assertSame( 1025, $p['port'] );
		}
	}

	public function test_remote_profiles_encrypt_and_authenticate(): void {
		foreach ( \diluxone_mail_providers() as $key => $p ) {
			if ( $p['local'] || 'custom' === $key ) {
				continue;
			}

			$this->assertNotSame( 'none', $p['encryption'], $key );
			$this->assertTrue( $p['auth'], $key );
			$this->assertNotSame( '', $p['host'], $key );
			$this->assertStringStartsWith( 'https://', (string) $p['docs'], "$key has no docs URL" );
		}
	}

	public function test_each_providers_fixed_username(): void {
		$this->assertSame( 'apikey', \diluxone_mail_provider( 'sendgrid' )['user'] );
		$this->assertSame( 'resend', \diluxone_mail_provider( 'resend' )['user'] );
		$this->assertSame( 'api', \diluxone_mail_provider( 'mailtrap_sending' )['user'] );
	}

	public function test_the_verified_hosts(): void {
		$esperados = array(
			'mailjet'          => 'in-v3.mailjet.com',
			'm365'             => 'smtp.office365.com',
			'azure_acs'        => 'smtp.azurecomm.net',
			'google'           => 'smtp.gmail.com',
			'brevo'            => 'smtp-relay.brevo.com',
			'sendgrid'         => 'smtp.sendgrid.net',
			'postmark'         => 'smtp.postmarkapp.com',
			'resend'           => 'smtp.resend.com',
			'mailtrap_testing' => 'sandbox.smtp.mailtrap.io',
			'mailtrap_sending' => 'live.smtp.mailtrap.io',
		);

		foreach ( $esperados as $key => $host ) {
			$this->assertSame( $host, \diluxone_mail_provider( $key )['host'] );
		}
	}

	public function test_an_unknown_profile_falls_back_to_the_generic_one(): void {
		$this->assertSame( \diluxone_mail_provider( 'custom' ), \diluxone_mail_provider( 'no-existe' ) );
		$this->assertSame( 'custom', \diluxone_mail_provider_defaults( 'no-existe' )['diluxone_mail_provider'] );
	}

	public function test_applying_a_profile_fills_in_only_its_own_fields(): void {
		$valores = \diluxone_mail_provider_defaults( 'sendgrid' );

		$this->assertSame( 'smtp.sendgrid.net', $valores['diluxone_mail_host'] );
		$this->assertSame( 587, $valores['diluxone_mail_port'] );
		$this->assertSame( 'apikey', $valores['diluxone_mail_user'] );
		$this->assertArrayNotHasKey( 'diluxone_mail_pass', $valores );
		$this->assertArrayNotHasKey( 'diluxone_mail_from', $valores );
	}
}
