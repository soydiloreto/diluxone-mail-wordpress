<?php
/**
 * La precedencia sitio/red en una red.
 *
 * El servidor de correo es de la red; un sitio sólo tiene lo suyo si la red
 * se lo permite. Si esto se rompe, o un sitio queda pisando a la red sin
 * permiso, o la red queda sin poder fijar nada.
 */

namespace Tests\Unit\DiluxOneMail;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../includes/options.php';
require_once __DIR__ . '/../../../includes/config.php';

class NetworkOptionsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_test_wp_options']      = array();
		$GLOBALS['_test_wp_site_options'] = array();
		$GLOBALS['_test_multisite']       = true;

		foreach ( \diluxone_mail_config_fields() as $sufijo ) {
			putenv( 'DILUXONE_MAIL_' . $sufijo );
		}
	}

	protected function tearDown(): void {
		$GLOBALS['_test_multisite'] = false;

		parent::tearDown();
	}

	public function test_la_red_manda_cuando_el_sitio_no_tiene_permiso(): void {
		\update_site_option( 'diluxone_mail_host', 'smtp.red.test' );
		\update_option( 'diluxone_mail_host', 'smtp.sitio.test' );

		$v = \diluxone_mail_config_value( 'host' );

		$this->assertSame( 'smtp.red.test', $v['value'] );
		$this->assertSame( 'network', $v['source'] );
	}

	public function test_el_sitio_pisa_a_la_red_solo_con_permiso(): void {
		\update_site_option( 'diluxone_mail_network_allow_override', 1 );
		\update_site_option( 'diluxone_mail_host', 'smtp.red.test' );
		\update_option( 'diluxone_mail_host', 'smtp.sitio.test' );

		$v = \diluxone_mail_config_value( 'host' );

		$this->assertSame( 'smtp.sitio.test', $v['value'] );
		$this->assertSame( 'site', $v['source'] );
	}

	public function test_con_permiso_pero_sin_valor_propio_hereda_de_la_red(): void {
		\update_site_option( 'diluxone_mail_network_allow_override', 1 );
		\update_site_option( 'diluxone_mail_from', 'red@ejemplo.test' );

		$this->assertSame( 'red@ejemplo.test', \diluxone_mail_config_value( 'from' )['value'] );
	}

	public function test_el_entorno_le_gana_a_la_red(): void {
		\update_site_option( 'diluxone_mail_host', 'smtp.red.test' );
		putenv( 'DILUXONE_MAIL_HOST=smtp.entorno.test' );

		$this->assertSame( 'env', \diluxone_mail_config_value( 'host' )['source'] );
	}

	public function test_guardar_en_un_sitio_sin_permiso_no_escribe_nada(): void {
		\diluxone_mail_save_options( array( 'diluxone_mail_host' => 'smtp.sitio.test' ), 'site' );

		$this->assertArrayNotHasKey( 'diluxone_mail_host', $GLOBALS['_test_wp_options'] );
	}

	public function test_guardar_en_la_red_escribe_en_la_red(): void {
		\diluxone_mail_save_options( array( 'diluxone_mail_host' => 'smtp.red.test' ), 'network' );

		$this->assertSame( 'smtp.red.test', \get_site_option( 'diluxone_mail_host' ) );
		$this->assertArrayNotHasKey( 'diluxone_mail_host', $GLOBALS['_test_wp_options'] );
	}

	/**
	 * Un sitio no puede decidir si los sitios pueden pisar a la red.
	 */
	public function test_la_option_de_permiso_no_se_guarda_desde_un_sitio(): void {
		\update_site_option( 'diluxone_mail_network_allow_override', 1 );
		\diluxone_mail_save_options( array( 'diluxone_mail_network_allow_override' => 0 ), 'site' );

		$this->assertSame( 1, \get_site_option( 'diluxone_mail_network_allow_override' ) );
		$this->assertArrayNotHasKey( 'diluxone_mail_network_allow_override', $GLOBALS['_test_wp_options'] );
	}

	public function test_fuera_de_una_red_el_sitio_es_todo_lo_que_hay(): void {
		$GLOBALS['_test_multisite'] = false;

		\update_option( 'diluxone_mail_host', 'smtp.sitio.test' );

		$this->assertSame( 'site', \diluxone_mail_config_value( 'host' )['source'] );
		$this->assertTrue( \diluxone_mail_site_override_allowed() );
	}
}
