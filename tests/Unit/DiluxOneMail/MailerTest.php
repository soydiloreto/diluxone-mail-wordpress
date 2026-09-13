<?php
/**
 * El remitente y el buffer del diálogo SMTP.
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
		\update_option( 'diluxone_mail_from', 'hola@ejemplo.test' );
		\update_option( 'diluxone_mail_from_name', 'Ejemplo' );
	}

	public function test_el_remitente_por_defecto_de_wordpress_se_reemplaza(): void {
		$this->assertSame( 'hola@ejemplo.test', \diluxone_mail_from( 'wordpress@localhost' ) );
		$this->assertSame( 'hola@ejemplo.test', \diluxone_mail_from( '' ) );
		$this->assertSame( \diluxone_mail_config()['from_name'], \diluxone_mail_from_name( 'WordPress' ) );
	}

	public function test_un_remitente_propio_de_otro_plugin_se_respeta(): void {
		$this->assertSame( 'ventas@tienda.test', \diluxone_mail_from( 'ventas@tienda.test' ) );
		$this->assertSame( 'Tienda', \diluxone_mail_from_name( 'Tienda' ) );
	}

	public function test_forzar_pisa_tambien_al_propio(): void {
		\update_option( 'diluxone_mail_force_from', 1 );

		$this->assertSame( 'hola@ejemplo.test', \diluxone_mail_from( 'ventas@tienda.test' ) );
		$this->assertSame( \diluxone_mail_config()['from_name'], \diluxone_mail_from_name( 'Tienda' ) );
	}

	public function test_en_modo_observador_no_se_toca_nada(): void {
		\update_option( 'diluxone_mail_mode', 'observe' );

		$this->assertSame( 'wordpress@localhost', \diluxone_mail_from( 'wordpress@localhost' ) );
		$this->assertSame( 'WordPress', \diluxone_mail_from_name( 'WordPress' ) );
	}

	public function test_sin_remitente_configurado_o_invalido_no_se_toca_nada(): void {
		\update_option( 'diluxone_mail_from', '' );
		$this->assertSame( 'wordpress@localhost', \diluxone_mail_from( 'wordpress@localhost' ) );

		\update_option( 'diluxone_mail_from', 'no-es-una-direccion' );
		$this->assertSame( 'wordpress@localhost', \diluxone_mail_from( 'wordpress@localhost' ) );
	}

	public function test_el_nombre_solo_cambia_junto_con_la_direccion(): void {
		\update_option( 'diluxone_mail_from', '' );

		$this->assertSame( 'WordPress', \diluxone_mail_from_name( 'WordPress' ) );
	}

	public function test_el_buffer_acumula_y_se_vacia(): void {
		\diluxone_mail_debug_buffer( null, true );
		\diluxone_mail_debug_buffer( 'CLIENT -> SERVER: EHLO' );
		\diluxone_mail_debug_buffer( 'SERVER -> CLIENT: 250 OK  ' );

		$this->assertSame( "CLIENT -> SERVER: EHLO\nSERVER -> CLIENT: 250 OK\n", \diluxone_mail_debug_buffer() );

		\diluxone_mail_debug_buffer( null, true );
		$this->assertSame( '', \diluxone_mail_debug_buffer() );
	}

	public function test_la_captura_esta_apagada_salvo_que_alguien_la_prenda(): void {
		\diluxone_mail_debug_enabled( false );
		$this->assertFalse( \diluxone_mail_debug_enabled() );

		\diluxone_mail_debug_enabled( true );
		$this->assertTrue( \diluxone_mail_debug_enabled() );

		\diluxone_mail_debug_enabled( false );
	}

	public function test_phpmailer_init_ignora_lo_que_no_es_phpmailer(): void {
		// Sin PHPMailer en los tests, lo único que se puede afirmar es que un
		// objeto cualquiera no se toca y no rompe nada.
		$obj = new \stdClass();
		\diluxone_mail_phpmailer_init( $obj );

		$this->assertSame( array(), get_object_vars( $obj ) );
	}
}
