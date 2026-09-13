<?php
/**
 * Los perfiles de proveedor: que ninguno esté a medio escribir.
 */

namespace Tests\Unit\DiluxOneMail;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../includes/options.php';
require_once __DIR__ . '/../../../includes/providers.php';

class ProvidersTest extends TestCase {

	public function test_todos_los_perfiles_tienen_las_mismas_claves(): void {
		$claves = array( 'name', 'group', 'host', 'port', 'encryption', 'auth', 'autotls', 'user', 'user_hint', 'pass_hint', 'host_editable', 'local', 'dkim_selectors', 'spf_includes', 'return_path', 'docs' );

		foreach ( \diluxone_mail_providers() as $key => $perfil ) {
			$this->assertSame( $claves, array_keys( $perfil ), "el perfil {$key} no tiene las claves esperadas" );
			$this->assertContains( $perfil['encryption'], array( 'none', 'tls', 'ssl' ), $key );
			$this->assertIsInt( $perfil['port'] );
		}
	}

	/**
	 * Sin apagar el autoTLS, PHPMailer intenta STARTTLS contra Mailpit y el
	 * envío falla. Es el error más común de un entorno local.
	 */
	public function test_los_perfiles_locales_no_autentican_ni_intentan_tls(): void {
		foreach ( array( 'mailpit', 'mailhog' ) as $local ) {
			$p = \diluxone_mail_provider( $local );

			$this->assertTrue( $p['local'] );
			$this->assertFalse( $p['auth'] );
			$this->assertFalse( $p['autotls'] );
			$this->assertSame( 'none', $p['encryption'] );
			$this->assertSame( 1025, $p['port'] );
		}
	}

	public function test_los_remotos_cifran_y_autentican(): void {
		foreach ( \diluxone_mail_providers() as $key => $p ) {
			if ( $p['local'] || 'custom' === $key ) {
				continue;
			}

			$this->assertNotSame( 'none', $p['encryption'], $key );
			$this->assertTrue( $p['auth'], $key );
			$this->assertNotSame( '', $p['host'], $key );
			$this->assertStringStartsWith( 'https://', (string) $p['docs'], "$key sin documentación" );
		}
	}

	public function test_los_usuarios_fijos_de_cada_proveedor(): void {
		$this->assertSame( 'apikey', \diluxone_mail_provider( 'sendgrid' )['user'] );
		$this->assertSame( 'resend', \diluxone_mail_provider( 'resend' )['user'] );
		$this->assertSame( 'api', \diluxone_mail_provider( 'mailtrap_sending' )['user'] );
	}

	public function test_los_hosts_verificados(): void {
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

	public function test_un_perfil_desconocido_cae_al_generico(): void {
		$this->assertSame( \diluxone_mail_provider( 'custom' ), \diluxone_mail_provider( 'no-existe' ) );
		$this->assertSame( 'custom', \diluxone_mail_provider_defaults( 'no-existe' )['diluxone_mail_provider'] );
	}

	public function test_aplicar_un_perfil_rellena_solo_lo_suyo(): void {
		$valores = \diluxone_mail_provider_defaults( 'sendgrid' );

		$this->assertSame( 'smtp.sendgrid.net', $valores['diluxone_mail_host'] );
		$this->assertSame( 587, $valores['diluxone_mail_port'] );
		$this->assertSame( 'apikey', $valores['diluxone_mail_user'] );
		$this->assertArrayNotHasKey( 'diluxone_mail_pass', $valores );
		$this->assertArrayNotHasKey( 'diluxone_mail_from', $valores );
	}
}
